<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class Config {
 public static function get(string $name, string $fallback=''): string { $v=getenv($name); return $v===false ? $fallback : (string)$v; }
 public static function must(string $name): string { $s=self::get($name); if($s==='' || str_starts_with($s,'REPLACE')) throw new \RuntimeException('Missing configuration: '.$name); return $s; }
 public static function base(string $env): string {
  if(!in_array($env,['staging','production'],true)) throw new \InvalidArgumentException('Environment');
  $v=self::get('NEXI_'.strtoupper($env).'_BASE');
  $expected=$env==='staging'?'https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp':'https://xpay.nexigroup.com/api/phoenix-0.0/psp';
  if($v!==$expected) throw new \RuntimeException('Nexi base URL mismatch; fail closed');
  return $expected;
 }
 public static function webhook(string $env): string {
  $expected=$env==='staging'?'https://stage.iradios.gr/':'https://play.iradios.gr/';
  $v=self::must('IRADIOS_'.strtoupper($env).'_WEBHOOK');
  if(!str_starts_with($v,$expected)) throw new \RuntimeException('Untrusted webhook destination');
  return $v;
 }
 public static function liveAllowed(): bool { return self::get('PRODUCTION_ENABLED')==='1' && self::get('APP_ENV')==='production'; }
}