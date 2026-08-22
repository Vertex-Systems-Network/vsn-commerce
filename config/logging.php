<?php
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
return [
 'default'=>env('LOG_CHANNEL','stack'),
 'deprecations'=>['channel'=>env('LOG_DEPRECATIONS_CHANNEL','null'),'trace'=>false],
 'channels'=>[
  'stack'=>['driver'=>'stack','channels'=>explode(',', (string) env('LOG_STACK', env('APP_ENV', 'production') === 'production' ? 'stderr_json' : 'single')),'ignore_exceptions'=>false],
  'single'=>['driver'=>'single','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','debug'),'replace_placeholders'=>true],
  'daily'=>['driver'=>'daily','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','debug'),'days'=>(int)env('LOG_DAILY_DAYS',14),'replace_placeholders'=>true],
  'stderr_json'=>['driver'=>'monolog','level'=>env('LOG_LEVEL','info'),'handler'=>StreamHandler::class,'handler_with'=>['stream'=>'php://stderr'],'tap'=>[App\Logging\JsonLogFormatter::class],'processors'=>[Monolog\Processor\PsrLogMessageProcessor::class]],
  'null'=>['driver'=>'monolog','handler'=>NullHandler::class],
 ],
];
