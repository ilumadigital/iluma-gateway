<?php
declare(strict_types=1);
namespace Iluma\Gateway;
use PDO;
final class Security {
 public static function id(int $bytes=16): string { return bin2hex(random_bytes($bytes)); }
 public static function encrypt(string $plain): string {
  $key=self::key();$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));
 }
 public static function decrypt(string $encoded): string {
  $all=base64_decode($encoded,true);if($all===false || strlen($all)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new \RuntimeException('Invalid encrypted value');
  $nonce=substr($all,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$v=sodium_crypto_secretbox_open(substr($all,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,self::key());
  if($v===false) throw new \RuntimeException('Secret decryption failed');return $v;
 }
 private static function key(): string {
  $v=base64_decode(Config::must('MASTER_KEY_B64'),true);
  if($v===false || strlen($v)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Invalid master key');return $v;
 }
 public static function json(array $v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
 public static function signature(string $secret,string $time,string $nonce,string $method,string $path,string $raw): string {
  return hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$time."\n".$nonce."\n".hash('sha256',$raw),$secret);
 }
 public static function credentialFromHeaders(array $h,string $raw,string $method,string $path): array {
  $key=$h['x-iluma-key']??''; $time=$h['x-iluma-timestamp']??'';$nonce=$h['x-iluma-nonce']??'';$provided=$h['x-iluma-signature']??'';
  if(!preg_match('/^ilg_[a-f0-9]{48}$/D',$key) || !preg_match('/^[0-9]{10}$/D',$time) || !preg_match('/^[a-f0-9]{32}$/D',$nonce) || !preg_match('/^[a-f0-9]{64}$/D',$provided)) throw new HttpError(401,'Invalid API signature');
  if(abs(time()-(int)$time)>300) throw new HttpError(401,'Timestamp outside window');
  $db=Db::get('registry');
  $record=Db::one($db,'SELECT c.*, a.slug FROM application_credentials c JOIN applications a ON a.id=c.application_id WHERE c.key_hash=? AND c.enabled=1 AND a.enabled=1',[hash('sha256',$key)]);
  if(!$record) throw new HttpError(401,'Invalid API credential');
  $calculated=self::signature(self::decrypt($record['secret_box']),$time,$nonce,$method,$path,$raw);
  if(!hash_equals($calculated,$provided)) throw new HttpError(401,'Invalid API signature');
  try {Db::exec($db,'INSERT INTO api_nonces(credential_id,nonce,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 MINUTE))',[(int)$record['id'],$nonce]);}
  catch(\PDOException $e) { if($e->getCode()==='23000') throw new HttpError(409,'Request replay detected');throw $e; }
  Db::exec($db,'UPDATE application_credentials SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[(int)$record['id']]);
  if($record['environment']==='production' && !Config::liveAllowed()) throw new HttpError(503,'Production payments are locked');
  return $record;
 }
 public static function headers(): array { $h=[];foreach($_SERVER as $k=>$v) if(str_starts_with($k,'HTTP_')) $h[strtolower(str_replace('_','-',substr($k,5)))]=(string)$v;return $h; }
 public static function jsonRequest(string $raw): array {
  if(strlen($raw)>65536) throw new HttpError(413,'Payload too large');
  try{$j=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(400,'Invalid JSON');}
  if(!is_array($j)||array_is_list($j)) throw new HttpError(400,'JSON object required');return $j;
 }
 public static function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
 public static function verifyCsrf(): void {
  // Missing tokens must never validate: hash_equals('', '') returns true.
  $expected=$_SESSION['csrf']??null;
  $provided=$_POST['_csrf']??null;
  if(!is_string($expected)||$expected===''||!is_string($provided)||!hash_equals($expected,$provided))
   throw new HttpError(403,'Invalid CSRF token');
 }
 public static function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
 public static function otpVerify(string $secret,string $userCode): bool {
  if(!preg_match('/^[0-9]{6}$/D',$userCode)) return false;
  $key=base64_decode($secret,true);if($key===false)return false;
  for($delta=-1;$delta<=1;$delta++) {
   $slot=intdiv(time(),30)+$delta;$parts=pack('N2',($slot>>32)&0xffffffff,$slot&0xffffffff);$hash=hash_hmac('sha1',$parts,$key,true);$offset=ord(substr($hash,-1))&15;
   $number=(unpack('N',substr($hash,$offset,4))[1]&0x7fffffff)%1000000;
   if(hash_equals(sprintf('%06d',$number),$userCode))return true;
  }return false;
 }
}