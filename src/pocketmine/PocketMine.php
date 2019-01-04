<?php

declare(strict_types=1);

namespace {
	const INT32_MIN = -0x80000000;
	const INT32_MAX = 0x7fffffff;
}

namespace pocketmine {

	const NAME = "MiRmProxy";
	const VERSION = "1.0.2";
	const PROTOCOL = 313;

	define('pocketmine\PATH', dirname(__FILE__, 3) . DIRECTORY_SEPARATOR);

	$bootstrap = \pocketmine\PATH . 'vendor/autoload.php';
	define('pocketmine\COMPOSER_AUTOLOADER_PATH', $bootstrap);

	if(\pocketmine\COMPOSER_AUTOLOADER_PATH !== false && is_file(\pocketmine\COMPOSER_AUTOLOADER_PATH)){
		require_once(\pocketmine\COMPOSER_AUTOLOADER_PATH);
	}else{
		echo "[ERROR] Composer autoloader not found at " . $bootstrap . PHP_EOL;
		exit(1);
	}

	set_time_limit(0);

	ini_set("allow_url_fopen", '1');
	ini_set("display_errors", '1');
	ini_set("display_startup_errors", '1');
	ini_set("default_charset", "utf-8");

	ini_set("memory_limit", '-1');

	define('pocketmine\DATA', realpath(getcwd()) . DIRECTORY_SEPARATOR);

	new MiRmProxy();
}