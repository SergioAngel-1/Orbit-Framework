<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping\Support;

use Brain\Monkey\Functions;

/**
 * Doble de las funciones de WordPress que usan los proveedores de distancia y el servicio:
 * HTTP (`wp_remote_*`), transients, opciones, `error_log` y el filtro de proveedores.
 *
 * Registra cada petición que sale y cada aviso que se escribe, para que los tests comprueben lo
 * que va a la red y lo que queda en el log, no detalles internos. Las respuestas HTTP se encolan
 * en orden; una petición sin respuesta encolada hace fallar el test (no hay tráfico inesperado).
 */
final class WpShippingFake
{
    /** @var list<array{method:string,url:string,args:array<string,mixed>}> */
    public array $requests = [];

    /** @var list<string> */
    public array $logged = [];

    /** @var array<string,mixed> Opciones de WordPress (`hwe_config`, `hwe_config_secrets`…). */
    public array $options = [];

    /** @var array<string,mixed> */
    public array $transients = [];

    /** @var list<array{key:string,value:mixed,ttl:int}> */
    public array $transientWrites = [];

    /** @var list<array{status:int,body:string}|\WP_Error> */
    private array $queue = [];

    /** @var (callable(array<string,mixed>):array<string,mixed>)|null */
    private $providersFilter = null;

    /** Encola una respuesta HTTP. Un cuerpo array se envía como JSON. */
    public function respond(int $status, mixed $body = ''): void
    {
        $this->queue[] = [
            'status' => $status,
            'body'   => is_array($body) ? (string) json_encode($body) : (string) $body,
        ];
    }

    /** Encola un fallo de transporte (timeout, DNS…). */
    public function failTransport(): void
    {
        $this->queue[] = new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $filter */
    public function filterProviders(callable $filter): void
    {
        $this->providersFilter = $filter;
    }

    /** Peticiones HTTP enviadas con ese método. */
    public function sent(string $method): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method));
    }

    public function install(): void
    {
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('is_wp_error')->alias(static fn (mixed $value): bool => $value instanceof \WP_Error);
        Functions\when('wp_remote_get')->alias(fn (string $url, array $args = []): array|\WP_Error => $this->send('GET', $url, $args));
        Functions\when('wp_remote_post')->alias(fn (string $url, array $args = []): array|\WP_Error => $this->send('POST', $url, $args));
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => (int) ($response['response']['code'] ?? 0));
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $response): string => (string) ($response['body'] ?? ''));

        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl = 0): bool {
            $this->transients[$key]   = $value;
            $this->transientWrites[]  = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });

        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;
            return true;
        });

        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_shipping_distance_providers' === $hook && null !== $this->providersFilter) {
                return ($this->providersFilter)($value);
            }
            return $value;
        });

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://sitio.test' . $path);
        Functions\when('add_query_arg')->alias(static function (array|string $args, ?string $url = null): string {
            $query = http_build_query((array) $args);
            return $url . ( str_contains((string) $url, '?') ? '&' : '?' ) . $query;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('get_locale')->alias(static fn (): string => 'es_CO');
    }

    /** @return array{status:int,body:string}|\WP_Error */
    private function send(string $method, string $url, array $args): array|\WP_Error
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'args' => $args];

        if ([] === $this->queue) {
            throw new \LogicException(sprintf('Petición HTTP inesperada: %s %s', $method, $url));
        }

        $next = array_shift($this->queue);
        if ($next instanceof \WP_Error) {
            return $next;
        }

        return ['response' => ['code' => $next['status']], 'body' => $next['body']];
    }
}
