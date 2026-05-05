<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

class JsonFormatterTap
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter());
        }
    }
}
