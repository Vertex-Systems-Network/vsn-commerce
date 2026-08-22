<?php
namespace App\Logging;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
/** Defines the JsonLogFormatter class and its project responsibilities. */
class JsonLogFormatter{public function __invoke(Logger $logger):void{foreach($logger->getHandlers() as $handler){$handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON,true));}}}
