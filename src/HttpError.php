<?php
declare(strict_types=1);
namespace Iluma\Gateway;
final class HttpError extends \RuntimeException {
 public function __construct(public readonly int $status,string $message){parent::__construct($message);}
}