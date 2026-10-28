<?php

namespace App\Services\Domain;

class DomainException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 409, public array $details = [])
    {
        parent::__construct($message);
    }
}
