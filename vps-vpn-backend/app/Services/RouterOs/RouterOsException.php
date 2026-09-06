<?php

namespace App\Services\RouterOs;

use RuntimeException;

class RouterOsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusHttp = null,
        public readonly ?array $balasan = null,
    ) {
        parent::__construct($message);
    }
}
