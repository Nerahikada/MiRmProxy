@echo off
TITLE MirmProxy
cd /d %~dp0

if exist bin\php\php.exe (
	set PHP_BINARY=bin\php\php.exe
) else (
	set PHP_BINARY=php
)

if exist src\pocketmine\PocketMine.php (
	set MIRMPROXY_FILE=src\pocketmine\PocketMine.php
) else (
	echo PocketMine.php not found
	pause
	exit 1
)

%PHP_BINARY% -c bin\php %MIRMPROXY_FILE% %* || pause