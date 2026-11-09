<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
require_once __DIR__ . '/EnvLoader.php';
\Iluma\Gateway\EnvLoader::load(dirname(__DIR__));
spl_autoload_register(static function(string $class):void{
 $prefix='Iluma\\Gateway\\';if(!str_starts_with($class,$prefix))return;
 $path=__DIR__.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;
});
set_exception_handler(static function(\Throwable $e):void{error_log('ILUMA uncaught '.get_class($e).': '.$e->getMessage());http_response_code(500);echo 'Internal server error';});
