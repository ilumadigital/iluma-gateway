<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;
final class Db {
 private static array $pool=[];
 public static function get(string $environment): PDO {
  if(!in_array($environment,['registry','staging','production'],true)) throw new \InvalidArgumentException('Unrecognized DB');
  if(isset(self::$pool[$environment])) return self::$pool[$environment];
  $prefix=strtoupper($environment);
  $db=new PDO(Config::must($prefix.'_DSN'),Config::must($prefix.'_USER'),Config::must($prefix.'_PASSWORD'),[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
   PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_PERSISTENT=>false
  ]);
  $db->exec("SET time_zone='+00:00'");
  return self::$pool[$environment]=$db;
 }
 public static function one(PDO $db,string $sql,array $args=[]): array|false { $q=$db->prepare($sql);$q->execute($args);return $q->fetch(); }
 public static function all(PDO $db,string $sql,array $args=[]): array { $q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(); }
 public static function exec(PDO $db,string $sql,array $args=[]): int { $q=$db->prepare($sql);$q->execute($args);return $q->rowCount(); }
}