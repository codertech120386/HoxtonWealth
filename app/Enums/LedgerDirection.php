<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerDirection: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
}
