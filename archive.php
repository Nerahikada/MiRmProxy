<?php

function preg_quote_array(array $strings, string $delim = null) : array{
	return array_map(function(string $str) use ($delim) : string{ return preg_quote($str, $delim); }, $strings);
}

function buildPhar(string $pharPath, string $basePath, array $includedPaths, array $metadata, string $stub, int $signatureAlgo = \Phar::SHA1, ?int $compression = null){
	if(file_exists($pharPath)){
		yield "Phar file already exists, overwriting...";
		try{
			\Phar::unlinkArchive($pharPath);
		}catch(\PharException $e){
			//unlinkArchive() doesn't like dodgy phars
			unlink($pharPath);
		}
	}
	yield "Adding files...";
	$start = microtime(true);
	$phar = new \Phar($pharPath);
	$phar->setMetadata($metadata);
	$phar->setStub($stub);
	$phar->setSignatureAlgorithm($signatureAlgo);
	$phar->startBuffering();
	//If paths contain any of these, they will be excluded
	$excludedSubstrings = preg_quote_array([
		realpath($pharPath), //don't add the phar to itself
	], '/');
	$folderPatterns = preg_quote_array([
		DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR,
		DIRECTORY_SEPARATOR . '.' //"Hidden" files, git dirs etc
	], '/');
	//Only exclude these within the basedir, otherwise the project won't get built if it itself is in a directory that matches these patterns
	$basePattern = preg_quote(rtrim($basePath, DIRECTORY_SEPARATOR), '/');
	foreach($folderPatterns as $p){
		$excludedSubstrings[] = $basePattern . '.*' . $p;
	}
	$regex = sprintf('/^(?!.*(%s))^%s(%s).*/i',
		 implode('|', $excludedSubstrings), //String may not contain any of these substrings
		 preg_quote($basePath, '/'), //String must start with this path...
		 implode('|', preg_quote_array($includedPaths, '/')) //... and must be followed by one of these relative paths, if any were specified. If none, this will produce a null capturing group which will allow anything.
	);
	$directory = new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::CURRENT_AS_PATHNAME); //can't use fileinfo because of symlinks
	$iterator = new \RecursiveIteratorIterator($directory);
	$regexIterator = new \RegexIterator($iterator, $regex);
	$count = count($phar->buildFromIterator($regexIterator, $basePath));
	yield "Added $count files";
	if($compression !== null){
		yield "Checking for compressible files...";
		foreach($phar as $file => $finfo){
			/** @var \PharFileInfo $finfo */
			if($finfo->getSize() > (1024 * 512)){
				yield "Compressing " . $finfo->getFilename();
				$finfo->compress($compression);
			}
		}
	}
	$phar->stopBuffering();
	yield "Done in " . round(microtime(true) - $start, 3) . "s";
}

define('PATH', dirname(__FILE__, 1) . DIRECTORY_SEPARATOR);
define('DEVTOOLS_REQUIRE_FILE_STUB', '<?php require("phar://" . __FILE__ . "/%s"); __HALT_COMPILER();');

$pharPath = PATH . "mochikomi.phar";
$metadata = [
	"name" => "MiRmProxy",
	"version" => "1.0.2",
	"api" => "0.0.0",
	"minecraft" => "v1.8.0",
	"creationDate" => time(),
	"protocol" => 313
];
$stub = sprintf(DEVTOOLS_REQUIRE_FILE_STUB, "src/pocketmine/PocketMine.php");
$filePath = realpath(PATH) . DIRECTORY_SEPARATOR;
$filePath = rtrim($filePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
foreach(buildPhar($pharPath, $filePath, ['src', 'vendor'], $metadata, $stub, \Phar::SHA1, \Phar::GZ) as $line){
	echo $line . "\n";
}
echo "Phar file has been created!\n";