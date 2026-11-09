<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class Http {
 public static function respond(array $data,int $code=200): never {http_response_code($code);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo Security::json($data);exit;}
 public static function error(\Throwable $e): never {
  $status=$e instanceof HttpError ? $e->status : 500;
  if($status===500) error_log('ILUMA error '.get_class($e).' '.$e->getMessage());
  self::respond(['error'=>$status===500?'Internal server error':$e->getMessage()],$status);
 }
}