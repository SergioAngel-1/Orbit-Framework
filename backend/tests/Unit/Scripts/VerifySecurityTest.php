<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `scripts/verify-security.sh`: la comprobación de seguridad de la pila en marcha.
 *
 * Los tests unitarios simulan `fetch` y `$_SERVER`: no pueden decir si la pila REAL está
 * cerrada. Este script lo comprueba con tráfico HTTP de verdad; aquí se prueba a su vez
 * contra una pila de pega (tests/Fixtures/verify-security-stack.php, servida con el
 * servidor integrado de PHP) que se comporta como una pila segura o con UNA fuga concreta,
 * y se exige que el script la detecte: un verificador que nunca falla no verifica nada.
 */
final class VerifySecurityTest extends ShellScriptTestCase
{
    private const SECRET = 'secreto-interno-de-prueba-0123456789';
    private const WC_SECRET = 'secreto-webhooks-woo-de-prueba-0123';

    /** @var resource|null */
    private $server = null;
    /** @var array<int, resource> */
    private array $serverPipes = [];
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->serverPipes as $pipe) {
                is_resource($pipe) && fclose($pipe);
            }
            proc_close($this->server);
            $this->server = null;
        }
        parent::tearDown();
    }

    /** Arranca la pila de pega en un puerto libre y devuelve su URL base. */
    private function startStack(string $leaks = '', bool $broken = false): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $this->server = proc_open(
            [PHP_BINARY, '-d', 'expose_php=0', '-S', "127.0.0.1:{$this->port}", HWE_BACKEND_DIR . '/tests/Fixtures/verify-security-stack.php'],
            // Con pipes y no con `file`: Patchwork (cargado en el bootstrap) envuelve file://, y
            // los descriptores `file` de proc_open fallan bajo PHPUnit con «Bad file descriptor».
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->serverPipes,
            HWE_BACKEND_DIR,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'STACK_SECRET' => self::SECRET,
                'STACK_WC_SECRET' => self::WC_SECRET,
                'STACK_LEAK' => $leaks,
                'STACK_BROKEN' => $broken ? '1' : '0',
            ]
        );
        $this->assertIsResource($this->server);
        // El servidor escribe un registro por petición en stderr: sin vaciarlo (no bloqueante)
        // una ejecución larga podría llenar el pipe y colgarse.
        stream_set_blocking($this->serverPipes[1], false);
        stream_set_blocking($this->serverPipes[2], false);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return "http://127.0.0.1:{$this->port}";
            }
            usleep(100_000);
        }
        $this->fail('La pila de pega no arrancó.');
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function verify(string $base, array $env = [], array $args = []): array
    {
        return $this->sh($this->script('verify-security.sh'), $args, $env + [
            'WP_URL' => $base,
            'BFF_URL' => $base,
            'PUBLIC_URL' => $base,
            'HWE_REVALIDATION_SECRET' => self::SECRET,
            'WC_WEBHOOK_SECRET' => self::WC_SECRET,
            'VERIFY_2FA_USERNAME' => 'con-2fa',
            'VERIFY_2FA_PASSWORD' => 'clave-correcta',
        ]);
    }

    // ── Una pila segura pasa, entera y sin omitir nada ────────────────────────

    public function testASecureStackPassesEveryCheck(): void
    {
        $base = $this->startStack();

        $result = $this->verify($base, [], ['--strict']);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertSame(0, $result['code'], $out);
        $this->assertStringNotContainsString('✗', $out);
        $this->assertStringNotContainsString('(omitida)', $out, 'Con todos los secretos no debe omitirse nada.');
        $this->assertMatchesRegularExpression('/RESULTADO: \d+ correctas, 0 fallos, 0 omitidas/', $out);
    }

    public function testItIsIdempotent(): void
    {
        $base = $this->startStack();

        $first = $this->verify($base);
        $second = $this->verify($base);

        $this->assertSame(0, $first['code']);
        $this->assertSame($first['stdout'], $second['stdout']);
    }

    // ── Cada fuga se detecta ──────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function leaks(): iterable
    {
        yield 'GraphQL abierto sin el secreto' => ['graphql', 'GraphQL'];
        yield 'Store API abierta sin el secreto' => ['store', 'Store API'];
        yield 'auth propia abierta sin el secreto' => ['auth', 'hwe/v1/auth'];
        yield 'webhook sin firma aceptado' => ['webhook', 'sin firma'];
        yield 'escritura sin Origin aceptada' => ['origin', 'Origin'];
        yield 'login con 2FA que entrega tokens' => ['login', '2FA'];
        yield 'frontend sin CSP ni nosniff' => ['headers', 'cabecera'];
        yield 'CSP que vuelve a permitir qrserver' => ['qr', 'qrserver'];
        yield 'X-Powered-By expuesto' => ['powered', 'X-Powered-By'];
        yield 'WordPress sin cabeceras de seguridad' => ['wp-headers', 'WordPress'];
    }

    #[DataProvider('leaks')]
    public function testEachLeakIsDetectedAndNamed(string $leak, string $expected): void
    {
        $base = $this->startStack($leak);

        $result = $this->verify($base);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertSame(1, $result['code'], "La fuga «{$leak}» no se detectó:\n{$out}");
        $failed = array_values(array_filter(explode("\n", $out), static fn (string $l) => str_contains($l, '✗')));
        $this->assertNotEmpty($failed);
        $this->assertStringContainsStringIgnoringCase($expected, implode("\n", $failed), "El fallo no nombra «{$expected}»:\n{$out}");
    }

    // ── Un 404 de una pila rota no es un falso verde ──────────────────────────

    public function testABrokenStackThatRejectsEvenTheSecretIsNotReportedAsSecure(): void
    {
        $base = $this->startStack('', true);

        $result = $this->verify($base);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertNotSame(0, $result['code'], 'Los 404 de WordPress no son prueba de nada si con el secreto también falla.' . "\n" . $out);
        $this->assertStringContainsString('✗', $out);
    }

    public function testADeadStackIsReportedAsUnreachable(): void
    {
        $result = $this->verify('http://127.0.0.1:9');

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('no responde', $result['stdout'] . $result['stderr']);
    }

    // ── Lo que no se puede comprobar se dice, no se calla ─────────────────────

    public function testWithoutSecretsTheDependentChecksAreSkippedLoudly(): void
    {
        $base = $this->startStack();

        $result = $this->verify($base, [
            'HWE_REVALIDATION_SECRET' => '',
            'WC_WEBHOOK_SECRET' => '',
            'VERIFY_2FA_USERNAME' => '',
            'VERIFY_2FA_PASSWORD' => '',
        ]);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertSame(0, $result['code'], $out);
        $this->assertStringContainsString('(omitida)', $out);
        $this->assertMatchesRegularExpression('/RESULTADO: \d+ correctas, 0 fallos, [1-9]\d* omitidas/', $out);
    }

    public function testStrictModeTurnsSkippedChecksIntoFailures(): void
    {
        $base = $this->startStack();

        $result = $this->verify($base, ['HWE_REVALIDATION_SECRET' => ''], ['--strict']);

        $this->assertSame(1, $result['code']);
    }

    public function testItNeverPrintsTheSecrets(): void
    {
        $base = $this->startStack();

        $result = $this->verify($base);
        $out = $result['stdout'] . $result['stderr'];

        foreach ([self::SECRET, self::WC_SECRET, 'clave-correcta'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
    }
}
