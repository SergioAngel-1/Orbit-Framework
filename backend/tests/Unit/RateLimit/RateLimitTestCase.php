<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\RateLimit;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;

/**
 * Base de los tests de `mu-plugins/rate-limit.php`.
 *
 * Los transients son un almacén en memoria sin caducidad (suficiente para contar
 * intentos dentro de una ventana). `hwe_deny_rate_limit()` termina la petición con
 * `exit`, que mataría PHPUnit: se sustituye por una excepción que lleva el
 * `Retry-After`.
 */
abstract class RateLimitTestCase extends TestCase
{
    /** @var array<string, mixed> */
    protected array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];
        $this->transients = [];

        Functions\stubs([
            'wp_unslash' => static fn ($value) => $value,
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
        ]);
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        // `error_log` es redefinible (backend/patchwork.json); por defecto no escribe.
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('mu-plugins/rate-limit.php');

        Functions\when('hwe_deny_rate_limit')->alias(static function (int $retryAfter): void {
            throw new RateLimitedException($retryAfter);
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        parent::tearDown();
    }

    /**
     * Presenta (o retira) el secreto interno del BFF, como lo hace el frontend.
     * Se mantiene entre peticiones de un mismo test, igual que lo haría un cliente.
     */
    protected function withSecret(?string $secret): void
    {
        if (null === $secret) {
            unset($_SERVER['HTTP_X_HWE_INTERNAL_SECRET']);
            return;
        }
        $_SERVER['HTTP_X_HWE_INTERNAL_SECRET'] = $secret;
    }

    /** Simula una petición. @return bool true si pasa; false si el rate-limit la rechaza. */
    protected function request(string $method, string $uri, string $ip = '203.0.113.9'): bool
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REMOTE_ADDR'] = $ip;
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        try {
            \hwe_rate_limit_init();
            return true;
        } catch (RateLimitedException) {
            return false;
        }
    }

    /** @return int  cuántas peticiones seguidas pasan antes de la primera rechazada (tope `$max`). */
    protected function allowedBeforeBlocking(string $method, string $uri, int $max, string $ip = '203.0.113.9'): int
    {
        for ($i = 0; $i < $max; $i++) {
            if (!$this->request($method, $uri, $ip)) {
                return $i;
            }
        }
        return $max;
    }
}
