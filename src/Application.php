<?php

use Kdyby\Curl;
use Nette\Neon\Neon;
use Nette\Utils\Finder;
use Nette\Utils\FileSystem;

class Application {

    private $config;
    private $rootDir;
    private $api;
    private $update;
    private $verbose = FALSE;
    private $tileSaved = 0;

    const LAST_TILE = 'lastTile.backup';

    public function __construct($rootDir, $params) {
	$this->rootDir = $rootDir;
	$this->readParams($params);
    }

    private function readParams($params) {
	if (in_array('-v', $params)) {
	    $this->verbose = TRUE;
	}
    }

    public function run($confiPath) {
	$this->readConfig($confiPath);
	print_r($this->config);
	$this->api = new Curl\Request($this->config['url']);
	$this->api->setTimeout(0);
	foreach (Finder::find('*.ql')->in($this->getQueriesDir()) as $queryFilePath) {
	    $this->processQueryType($queryFilePath);
	}
    }

    private function processQueryType($queryFilePath) {
	$config = $this->config;
	$baseQuery = file_get_contents($queryFilePath);
	$outputDir = $this->getOutputDir($queryFilePath);
	echo $outputDir . PHP_EOL;
	$tiles = $config['tiles'];
	$lonStep = $tiles['lon']['step']; //todo delitelnost
	$latStep = $tiles['lat']['step'];
	$latCount = $this->getTilesCount($tiles['lat']);
	$lonCount = $this->getTilesCount($tiles['lon']);
	$tilesCount = $latCount * $lonCount;
	echo "total tiles: $tilesCount\n";
	$cnt = 0;
	$this->tileSaved = 0;
	$timer = microtime(true);
	for ($lat = $tiles['lat']['min']; $lat < $tiles['lat']['max']; $lat+=$latStep) {
	    for ($lon = $tiles['lon']['min']; $lon < $tiles['lon']['max']; $lon+=$lonStep) {
		$bottom = $lat;
		$top = $lat + $latStep;
		$left = $lon;
		$right = $lon + $lonStep;
		$this->processTile($bottom, $left, $top, $right, $baseQuery, $outputDir);
		$cnt++;
		$this->printProgess($cnt, $tilesCount, $timer);
	    }
	}
	$totalTime = microtime(true) - $timer;
	echo "\ntotal time: " . $this->formatTime($totalTime) . "\n";
	echo "tiles saved: " . $this->tileSaved . " of " . $tilesCount;
    }

    private function readConfig($configPath) {
	$this->config = Neon::decode(file_get_contents($configPath));
	$this->update = $this->config['update'];
    }

    private function getQueriesDir() {
	return $this->rootDir . '/' . $this->config['dirs']['queries'];
    }

    private function getOutputDir($queryFilePath) {
	$dir = $this->rootDir . '/' . $this->config['dirs']['output'] . '/' . basename($queryFilePath, ".ql");
	if (!is_dir($dir)) {
	    FileSystem::createDir($dir);
	}
	return $dir;
    }

    private function getTilesCount($tileConfig) {
	return round(($tileConfig['max'] - $tileConfig['min']) / $tileConfig['step'], 0, PHP_ROUND_HALF_UP);
    }

    private function saveTile($filePathName, Curl\Response $response) {
	$data = $response->getResponse();
	$xml = simplexml_load_string($data);
	$count = $xml->count();
	if ($count > 2 || $this->config['saveEmpty']) {
	    file_put_contents($filePathName, $data);
	    $this->tileSaved++;
	    return TRUE;
	} else {
	    return FALSE;
	}
    }

    private function processQuery($query, $filePathName) {
	try {
	    $response = $this->api->post($query);
	} catch (Kdyby\Curl\CurlException $ex) {
	    fwrite(STDERR, "Api response: " . $ex->getMessage().", check api url and queries");
	    exit(1);
	}
	$this->vPrint("response: " . $response->getCode() . "\n");
	if ($response->getCode() == 200) {
	    if (!$this->saveTile($filePathName, $response)) {
		$this->vPrint("no data found\n");
	    }
	} else {
	    $this->vPrint("bad response\n");
	}
    }

    private function setQueryBox($bottom, $left, $top, $right, $baseQuery) {
	return str_replace("[[box]]", "($bottom, $left, $top, $right)", $baseQuery);
    }

    private function processTile($bottom, $left, $top, $right, $baseQuery, $outputDir) {

	$fileName = "$bottom;$left;$top;$right";
	$this->vPrint("filename: \"" . $fileName . "\"\n");
	$filePathName = "$outputDir/$fileName.osm";
	$query = $this->setQueryBox($bottom, $left, $top, $right, $baseQuery);
	$this->saveProgress($fileName);
	if ($this->update || !file_exists($filePathName)) {
	    $this->vPrint("downloading...\n");
	    $this->processQuery($query, $filePathName);
	} else {
	    $this->vPrint("tile found, update FALSE\n");
	}
    }

    private function printProgess($cnt, $tilesCount, $timer) {
	$prog = "progress: $cnt / $tilesCount ( " . (int) ( $cnt / $tilesCount * 100) . " % )";
	$elTime = microtime(true) - $timer;
	$timePerTile = $elTime / $cnt;
	$tilesLeft = $tilesCount - $cnt;
	$estTime = $tilesLeft * $timePerTile;

	$prog .=" - time left: " . $this->formatTime($estTime);
	if ($this->verbose) {
	    $prog.="\n";
	} else {
	    $prog.="\r";
	}

	echo $prog;
    }

    private function formatTime($mtime) {
	return gmdate("H:i:s", $mtime);
    }

    private function vPrint($str) {
	if ($this->verbose) {
	    echo $str;
	}
    }

    private function saveProgress($fileName) {
	file_put_contents($this->rootDir . "/" . self::LAST_TILE, $fileName);
    }

}
