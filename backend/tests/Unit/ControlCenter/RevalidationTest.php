<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Revalidation;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `Revalidation`: WordPress pide al frontend que invalide un tag ISR.
 *
 * Sin fallos silenciosos: antes, sin `HWE_REVALIDATION_SECRET` la revalidación «se omitía
 * silenciosamente» y el sitio seguía sirviendo la config vieja sin que nada lo dijera.
 *
 * Los tags aceptados son los del núcleo (`site-config`, `products`, `memberships`, `banners`,
 * `posts`, `menus`) más los que cualquier instancia o módulo registre con el filtro
 * `hwe_revalidation_tags`. El frontend los vuelve a validar (`/api/revalidate`); aquí se
 * evita enviar una petición que se sabe rechazada y se avisa de un tag mal escrito.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevalidationTest extends TestCase
{
    private const SECRET = 'secreto-de-prueba-0123456789';

    /** @var list<string> */
    private array $logged = [];
    /** @var list<array{string, array<string, mixed>}> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->posts[] = [$url, $args];
            return [];
        });
    }

    private function withSecretAndFrontend(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example/');
    }

    private function registerTags(string ...$tags): void
    {
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => [...$core, ...$tags]);
    }

    // ── allowedTags ───────────────────────────────────────────────────────────

    public function testTheCoreAcceptsItsOwnTagsByDefault(): void
    {
        $this->assertSame(
            ['site-config', 'products', 'memberships', 'banners', 'posts', 'menus'],
            Revalidation::allowedTags(),
            '`products` y `memberships` son del núcleo: hay que poder invalidarlos con su módulo apagado.'
        );
    }

    public function testTagsRegisteredWithTheFilterAreAccepted(): void
    {
        $this->registerTags('example', 'catalogo.v2');

        $this->assertSame(['site-config', 'products', 'memberships', 'banners', 'posts', 'menus', 'example', 'catalogo.v2'], Revalidation::allowedTags());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTags(): iterable
    {
        yield 'vacío' => [''];
        yield 'mayúsculas' => ['Example'];
        yield 'espacios' => ['mi tag'];
        yield 'ruta' => ['../etc/passwd'];
        yield 'empieza por guion' => ['-x'];
        yield 'demasiado largo' => [str_repeat('a', 65)];
        yield 'no es texto' => [42];
        yield 'es un array' => [['x']];
        yield 'nulo' => [null];
    }

    #[DataProvider('invalidTags')]
    public function testMalformedTagsAreDroppedAndLoggedOnce(mixed $bad): void
    {
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn (array $core): array => [...$core, $bad, 'valido']);

        $this->assertSame(['site-config', 'products', 'memberships', 'banners', 'posts', 'menus', 'valido'], Revalidation::allowedTags());
        Revalidation::allowedTags();
        Revalidation::allowedTags();
        $this->assertCount(1, $this->logged, 'Un aviso por tag defectuoso y proceso, no uno por llamada.');
        $this->assertStringContainsString('[hwe] control-center:', $this->logged[0]);
    }

    public function testDuplicatesAreCollapsed(): void
    {
        $this->registerTags('example', 'example', 'site-config');

        $this->assertSame(['site-config', 'products', 'memberships', 'banners', 'posts', 'menus', 'example'], Revalidation::allowedTags());
    }

    public function testAFilterThatDoesNotReturnAnArrayFallsBackToTheCoreTags(): void
    {
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(static fn () => 'no soy una lista');

        $this->assertSame(['site-config', 'products', 'memberships', 'banners', 'posts', 'menus'], Revalidation::allowedTags());
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('hwe_revalidation_tags', $this->logged[0]);
    }

    // ── trigger ───────────────────────────────────────────────────────────────

    public function testItRevalidatesSiteConfigByDefaultWithASignedRequest(): void
    {
        $this->withSecretAndFrontend();

        Revalidation::trigger();

        $this->assertCount(1, $this->posts);
        [$url, $args] = $this->posts[0];
        $this->assertSame('https://tienda.example/api/revalidate', $url);
        $this->assertSame('site-config', json_decode($args['body'], true)['tag']);
        $this->assertSame('sha256=' . hash_hmac('sha256', $args['body'], self::SECRET), $args['headers']['X-HWE-Signature']);
        $this->assertFalse($args['blocking'], 'Fire-and-forget: guardar la config no espera al frontend.');
    }

    public function testItRevalidatesARegisteredInstanceTag(): void
    {
        $this->withSecretAndFrontend();
        $this->registerTags('example');

        Revalidation::trigger('example');

        $this->assertCount(1, $this->posts);
        $this->assertSame('example', json_decode($this->posts[0][1]['body'], true)['tag']);
    }

    public function testAnUnregisteredTagSendsNothingAndSaysWhy(): void
    {
        $this->withSecretAndFrontend();

        Revalidation::trigger('no-registrado');

        $this->assertSame([], $this->posts, 'El frontend lo rechazaría: no se envía.');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('no-registrado', $this->logged[0]);
        $this->assertStringContainsString('hwe_revalidation_tags', $this->logged[0]);
    }

    public function testWithoutASecretNothingIsSentButItIsNotSilent(): void
    {
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example');

        Revalidation::trigger();
        Revalidation::trigger();

        $this->assertSame([], $this->posts);
        $this->assertCount(1, $this->logged, 'Se avisa, una vez.');
        $this->assertStringContainsString('HWE_REVALIDATION_SECRET', $this->logged[0]);
    }

    public function testTheFrontendUrlDefaultsToLocalhostInDevelopment(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);

        Revalidation::trigger();

        $this->assertSame('http://localhost:3000/api/revalidate', $this->posts[0][0]);
    }

    public function testTheSecretNeverAppearsInTheLogOrTheBody(): void
    {
        $this->withSecretAndFrontend();

        Revalidation::trigger();
        Revalidation::trigger('no-registrado');

        $this->assertStringNotContainsString(self::SECRET, implode("\n", $this->logged));
        $this->assertStringNotContainsString(self::SECRET, $this->posts[0][1]['body']);
    }
}
