<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditEventType: string
{
    case AccountCreated = 'AccountCreated';
    case DepositMade = 'DepositMade';
    case TransferRequested = 'TransferRequested';
    case TransferProcessing = 'TransferProcessing';
    case TransferCompleted = 'TransferCompleted';
    case TransferFailed = 'TransferFailed';
}
