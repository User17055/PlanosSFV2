<?php

namespace App\Services\Integrations;

class TudoPraPetException extends \RuntimeException
{
    public function __construct(string $message, public string $errorCode, public bool $retryable)
    {
        parent::__construct($message);
    }
}
