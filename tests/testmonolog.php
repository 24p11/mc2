<?php 
require __DIR__.'/../vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

date_default_timezone_set('Europe/Paris');

// create a log channel
$log = new Logger('name');
$log->pushHandler(new StreamHandler('your.log', Logger::WARNING));

// add records to the log
$log->info('Foo');
$log->warning('Bar');
$log->error('ERRRRR');
$log->addAlert('ALERTE!');