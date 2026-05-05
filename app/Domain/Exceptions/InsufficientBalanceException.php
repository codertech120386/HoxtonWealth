<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $balance,
        public readonly int $attempted,
    ) {
        parent::__construct(sprintf(
            'Account %s has balance %d, attempted to debit %d',
            $accountId,
            $balance,
            $attempted,
        ));
    }
}
