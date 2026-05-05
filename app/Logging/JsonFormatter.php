<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;

/**
 * One-line JSON log formatter that promotes a few well-known context keys
 * (correlation_id, transfer_id, account_id) to the top level so log-aggregation
 * queries don't have to dig into the `context.*` namespace.
 */
class JsonFormatter extends BaseJsonFormatter
{
    private const PROMOTED_KEYS = ['correlation_id', 'transfer_id', 'account_id'];

    public function format(LogRecord $record): string
    {
        $normalized = $this->normalize($record);

        $payload = [
            'datetime' => $normalized['datetime'],
            'level' => $normalized['level_name'],
            'channel' => $normalized['channel'],
            'message' => $normalized['message'],
        ];

        $context = $normalized['context'] ?? [];
        foreach (self::PROMOTED_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                $payload[$key] = $context[$key];
                unset($context[$key]);
            }
        }

        if (! empty($context)) {
            $payload['context'] = $context;
        }
        if (! empty($normalized['extra'])) {
            $payload['extra'] = $normalized['extra'];
        }

        return $this->toJson($payload, true).($this->appendNewline ? "\n" : '');
    }
}
