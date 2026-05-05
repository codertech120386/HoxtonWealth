<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
