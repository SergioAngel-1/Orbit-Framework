<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\RateLimit;

/** Sustituye a la respuesta 429 + `exit` de `hwe_deny_rate_limit()` en los tests. */
final class RateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('rate limited');
    }
}
