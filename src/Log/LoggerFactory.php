<?php
namespace MC2\Core\Log;
use MC2\Core\Helper\DateHelper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\JsonFormatter;
use Monolog\ErrorHandler;
// use Monolog\Processor\WebProcessor;
// use Monolog\Processor\IntrospectionProcessor;
class LoggerFactory{

    public static function create_logger($name = null, $path = null, $log_level = null){
        $name = ($name === null) ? "log" : $name;
        $path = ($path === null) ? __DIR__."/../../log" : $path;
        $log_level = ($log_level === null) ? Logger::INFO : $log_level;
        $logger = new Logger($name);

        $output = "[%datetime%][%channel%][%level_name%] %message% %context% %extra%\n";
        $line_formatter = new LineFormatter($output, DateHelper::MYSQL_FORMAT);
        $line_formatter->ignoreEmptyContextAndExtra(true);

        // Logs records to a file and creates one logfile per day.
        $rotating_handler = new RotatingFileHandler("{$path}/{$name}.log", 0, $log_level);
        $rotating_handler->setFormatter($line_formatter);

        $rotating_error_Handler = new RotatingFileHandler("{$path}/{$name}-error.log", 0, Logger::ERROR);
        $rotating_error_Handler->setFormatter($line_formatter);

        // Logs records to a single file
        // $logger->pushHandler(new StreamHandler("{$name}.log", Logger::INFO));

        // Output logs to console
        $console_handler = new StreamHandler('php://stdout', $log_level);
        $console_handler->setFormatter($line_formatter);

        // Encodes a log record into json.
        $json_handler = new StreamHandler("{$path}/{$name}-json.log", $log_level);
        $json_handler->setFormatter(new JsonFormatter());

        $logger->pushHandler($rotating_handler);
        $logger->pushHandler($rotating_error_Handler);
        $logger->pushHandler($console_handler);
        $logger->pushHandler($json_handler);

        // Error Handler
        ErrorHandler::register($logger);

        // Adds the current request URI, request method and client IP to a log record.
        // $logger->pushProcessor(new WebProcessor);

        // Adds the line/file/class/method from which the log call originated.
        // $logger->pushProcessor(new IntrospectionProcessor);
        
        return $logger;
    }
}