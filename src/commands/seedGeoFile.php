<?php

namespace Igaster\LaravelCities\commands;

use Illuminate\Console\Command;
use Igaster\LaravelCities\commands\helpers\geoItem;
use Igaster\LaravelCities\commands\helpers\geoCollection;

class seedGeoFile extends Command
{
    protected $signature = 'geo:seed {country?} {--append}';
    protected $description = 'Load + Parse + Save to DB a geodata file.';

    private $pdo;

    public function __construct()
    {
        parent::__construct();

        $this->pdo = \DB::connection()->getPdo(\PDO::FETCH_ASSOC);

        if (!\Schema::hasTable('geo')) {
            return;
        }
    }

    public function sql($sql)
    {
        $result = $this->pdo->query($sql);
        if ($result === false) {
            throw new Exception("Error in SQL : '$sql'\n".PDO::errorInfo(), 1);
        }

        return $result->fetch();
    }

    public function buildDbTree($item, $count = 1, $depth = 0)
    {
        $item->left = $count++;
        $item->depth = $depth;
        foreach ($item->getChildren() as $child) {
            $count = $this->buildDbTree($child, $count, $depth+1);
        }
        $item->right = $count++;

        return $count;
    }

    public function printTree($item)
    {
        $levelStr= str_repeat('--', $item->depth);
        $this->info(sprintf("%s %s [%d,%d]", $levelStr, $item->getName(),$item->left,$item->right));
        foreach ($item->getChildren() as $child) {
            $this->printTree($child);
        }
    }

    public function handle()
    {
        $this->geoItems = new geoCollection();

        $start = microtime(true);
        $country = strtoupper($this->argument('country'));
        $sourceName = $country ? $country : 'allCountries';
        $fileName = storage_path("geo/{$sourceName}.txt");
        $isAppend = $this->option('append');

        $this->info("Start seeding for $country");

        // Read Raw file
        $this->info("Reading File '$fileName'");
        $filesize = filesize($fileName);
        $handle = fopen($fileName, 'r');
        $count = 0;

        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);

        while (($line = fgets($handle)) !== false) {
            // ignore empty lines and comments
            if (!$line || $line === '' || strpos($line, '#') === 0) {
                continue;
            }

            // Convert TAB sepereted line to array
            $line = explode("\t", $line);

            // Check for errors
            if (count($line) !== 19) {
                dd($line[0],$line[2]);
            }

            switch ($line[7]) {
                case 'PCLI':    // Country
                case 'PPLC':    // Capital
                case 'ADM1':
                case 'ADM2':
                case 'ADM3':   // 8 sec
                case 'PPLA':   // областные центры
                case 'PPLA2':  // Корсунь
                    //case 'PPL':    // Яблунівка
                    // 185 sec
                    $this->geoItems->add(new geoItem($line, $this->geoItems));
                    $count++;
                    break;
            }
            $progress = ftell($handle)/$filesize*100;
            $progressBar->setProgress($progress);
        }

        $progressBar->finish();

        $this->info(" Finished Reading File. $count items loaded</info>");

        // Read hierarchy
        //ini_set('xdebug.max_nesting_level', 5000);
        $fileName = storage_path("geo/hierarchy-$country.txt");
        $this->info("Opening File '$fileName'</info>");
        $handle = fopen($fileName, 'r');
        $filesize = filesize($fileName);
        $count = 0;
        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        while (($line = fgetcsv($handle, 0, "\t")) !== false) {
            $parent = $this->geoItems->findGeoId($line[0]);
            $child  = $this->geoItems->findGeoId($line[1]);

            if ($parent !== null && $child !== null) {
                $parent->addChild($line[1]);
                $child->setParent($line[0]);
                $count++;
            }

            $progress = ftell($handle)/$filesize*100;
            $progressBar->setProgress($progress);
        }
        $this->info(" Hierarcy building completed. $count items loaded</info>");


        // Build Tree
        $count = 0; $countOrphan = 0;
        $sql = 'SELECT MAX(`right`) as maxRight FROM geo';
        $result = $this->sql($sql);
        $maxBoundary = isset($result['maxRight']) ?  $result['maxRight']+1 : 0;
        foreach ($this->geoItems->items as $item) {
            if ($item->parentId === null){

                if ($item->data[7] !== 'PCLI') {
                    // $this->info("- Skiping Orphan {$item->data[2]} #{$item->data[0]}");
                    $countOrphan++;
                    continue;
                }

                $count++;
                $this->info("+ Building Tree for Country: {$item->data[2]} #{$item->data[0]}");

                $maxBoundary=$this->buildDbTree($item,$maxBoundary,0);
                // $this->printTree($item,$output);
            }
        }
        $this->info("Finished: {$count} Countries imported.  $countOrphan orphan items skiped</info>");


        // Clear Table
        if (!$isAppend){
            $this->info("Truncating 'geo' table...");
            \DB::table('geo')->truncate();
        }

        // Store Tree in DB
        $this->info("Writing in Database</info>");
        $sql = "INSERT INTO geo (`id`, `parent_id`, `left`, `right`, `depth`, `name`, `alternames`, `country`, `a1code`, `level`, `population`, `lat`, `long`, `timezone`) VALUES (:id, :parent_id, :left, :right, :depth, :name, :alternames, :country, :a1code, :level, :population, :lat, :long, :timezone)";
        $stmt = $this->pdo->prepare($sql);


        $count = 0;
        $totalCount = count($this->geoItems->items);

        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        foreach ($this->geoItems->items as $item)
        {
            $params = [
                ':id'           => $item->getId(),
                ':parent_id'    => $item->parentId,
                ':left'         => $item->left,
                ':right'        => $item->right,
                ':depth'        => $item->depth,
                ':name'         => substr($item->data[2],0,40),
                ':alternames'   => $item->data[3],
                ':country'      => $item->data[8],
                ':a1code'       => $item->data[10],
                ':level'        => $item->data[7],
                ':population'   => $item->data[14],
                ':lat'          => $item->data[4],
                ':long'         => $item->data[5],
                ':timezone'     => $item->data[17],
            ];

            if ($stmt->execute($params) === false) {
                $error = "Error in SQL : '$sql'\n".PDO::errorInfo()."\nParams: \n$params";
                throw new \Exception($error, 1);
            }

            $progress = $count++/$totalCount*100;
            $progressBar->setProgress($progress);
        }
        $progressBar->finish();

        $this->info(" Done</info>");
        $time_elapsed_secs = microtime(true) - $start;
        $this->info("Timing: $time_elapsed_secs sec</info>");
    }
}
