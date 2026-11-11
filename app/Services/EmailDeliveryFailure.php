<?php

namespace App\Services;

use RuntimeException;

class EmailDeliveryFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $transient,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($reason);
    }
}
