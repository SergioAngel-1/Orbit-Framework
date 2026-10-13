<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * La revalidación de `posts`/`menus` se dispara UNA vez por petición.
 *
 * Guardar un menú emite un hook por cada ítem (decenas) más el del menú completo: sin la
 * guarda, un guardado normal lanzaría decenas de POST a `/api/revalidate`. Aquí se
 * comprueba que N llamadas en la misma petición producen UN solo disparo.
 *
 * Las constantes del secreto son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevalidateOnceTest extends TestCase
{
    /** @var list<string> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        define('HWE_REVALIDATION_SECRET', 'secreto-de-pruebas-0123456789');
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example/');

        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->posts[] = $url;
            return [];
        });
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('mu-plugins/hwe-post-revalidate.php');
        $this->loadOwnFile('mu-plugins/hwe-menu-revalidate.php');
    }

    public function testMenuSaveTriggersOnlyOncePerRequest(): void {
        hwe_menu_revalidate_once();
        hwe_menu_revalidate_once();
        hwe_menu_revalidate_once();

        $this->assertCount(1, $this->posts);
    }

    public function testPostSaveTriggersThePostsTag(): void {
        hwe_post_revalidate_now();

        $this->assertCount(1, $this->posts);
        $this->assertStringContainsString('/api/revalidate', $this->posts[0]);
    }
}
