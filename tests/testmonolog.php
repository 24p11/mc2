<?php 
require __DIR__.'/../vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

date_default_timezone_set('Europe/Paris');

// create a log channel
$log = new Logger('name');
$log->pushHandler(new StreamHandler('your.log', Logger::WARNING));

// add records to the log
$log->addInfo('Foo');
$log->addWarning('Bar');
$log->addError('ERRRRR');
$log->addAlert('ALERTE!');