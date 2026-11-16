<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * ONE deliberately authorized real-card Nexi Production QA checkout, initiated
 * from the isolated iRadios staging listener. Not a production-wide override.
 * Existing production customer payments keep their own webhook and return URLs.
 */
final class ProductionQa {
 public const MODE='iradios-production-qa-v1';
 public const SKU='L-PREM';
 public const CENTS=399;
 public const WEBHOOK='https://stage.iradios.gr/api/gateway/production-qa/webhook';

 public static function isCredential(array $credential):bool {
  $expected=Config::get('IRADIOS_PROD_QA_KEY_SHA256');
  return ($credential['environment']??'')==='production' &&
   ($credential['slug']??'')==='iradiosgr' &&
   preg_match('/^[a-f0-9]{64}$/D',$expected)===1 &&
   preg_match('/^[a-f0-9]{64}$/D',(string)($credential['key_hash']??''))===1 &&
   hash_equals($expected,(string)$credential['key_hash']);
 }

 public static function restrictApi(array $credential,string $method,string $path):void {
  if(!self::isCredential($credential))return;
  if($method==='POST' && $path==='/api/v1/payments/checkout')return;
  if($method==='GET' && (preg_match('#^/api/v1/payments/[a-f0-9]{32}$#D',$path) ||
   preg_match('#^/api/v1/payments/by-request-key/[A-Za-z0-9._:-]{1,128}$#D',$path)))return;
  throw new HttpError(403,'Production QA credential is checkout/read-only only');
 }

 /** Returns the exact credential id to persist on THIS payment, or null. */
 public static function checkoutScope(array $credential,array $request,string $requestKey):?array {
  $isQa=self::isCredential($credential);
  if(!$isQa){
   if(array_key_exists('qa_mode',$request) ||
      str_starts_with((string)($request['customer_id']??''),'iradios-qa-') ||
      str_starts_with($requestKey,'iradios-qa-'))
    throw new HttpError(403,'Production QA requires dedicated authorized credential');
   return null;
  }
  if(!Config::liveAllowed() || Config::get('IRADIOS_PROD_QA_CHECKOUT_ENABLED')!=='1')
   throw new HttpError(503,'Production QA checkout is disabled');
  $listener=Config::get('IRADIOS_PROD_QA_LISTENER_ID');
  if(!preg_match('/^[a-f0-9]{24}$/D',$listener))
   throw new HttpError(503,'Production QA listener allowlist unavailable');
  $customer='iradios-qa-'.$listener;
  $allowed=['qa_mode','customer_id','product_sku','amount_cents','currency','email','name'];
  if(array_diff(array_keys($request),$allowed) ||
   ($request['qa_mode']??null)!==self::MODE ||
   ($request['customer_id']??null)!==$customer ||
   ($request['product_sku']??null)!==self::SKU ||
   ($request['amount_cents']??null)!==self::CENTS ||
   ($request['currency']??null)!=='EUR' ||
   !preg_match('/^iradios-qa-[a-f0-9]{24}-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$requestKey) ||
   !str_starts_with($requestKey,$customer.'-'))
   throw new HttpError(422,'Production QA scope, amount, or consent invalid');
  return ['credential_id'=>(int)$credential['id'],'run_key'=>hash('sha256',$customer.':'.self::SKU)];
 }

 public static function verifyProduct(array $product):void {
  if((string)($product['sku']??'')!==self::SKU ||
     (int)($product['amount_cents']??0)!==self::CENTS ||
     ($product['currency']??'')!=='EUR' ||
     (int)($product['enabled']??0)!==1)
    throw new HttpError(422,'Production QA catalog must be exact EUR 3.99 L-PREM');
 }

 public static function webhook():string {
  // Never use the general IRADIOS_PRODUCTION_WEBHOOK, and never allow dynamic
  // URLs or a browser-selected callback. Keep this for pending outbox retries
  // even after new QA checkouts have been disabled.
  if(!Config::liveAllowed() || Config::get('IRADIOS_PROD_QA_WEBHOOK')!==self::WEBHOOK)
   throw new \RuntimeException('Production QA webhook route is not configured');
  return self::WEBHOOK;
 }
}
