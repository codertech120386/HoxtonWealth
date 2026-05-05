<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferType: string
{
    case Transfer = 'TRANSFER';
    case Deposit = 'DEPOSIT';
}
