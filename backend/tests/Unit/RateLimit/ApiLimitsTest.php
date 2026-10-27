<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Los límites de la API existentes no cambian al añadir el del login. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiLimitsTest extends RateLimitTestCase
{
    public function testGraphqlIsLimitedTo60PerWindow(): void
    {
        $this->assertSame(60, $this->allowedBeforeBlocking('POST', '/graphql', 100));
    }

    public function testRestIsLimitedTo120PerWindow(): void
    {
        $this->assertSame(120, $this->allowedBeforeBlocking('GET', '/wp-json/wp/v2/posts', 200));
    }

    public function testOtherRoutesAreNotLimitedHere(): void
    {
        $this->assertSame(300, $this->allowedBeforeBlocking('GET', '/wp-admin/index.php', 300));
        $this->assertSame(300, $this->allowedBeforeBlocking('POST', '/wp-admin/admin-ajax.php', 300));
    }

    public function testTheRestRouteQueryFormIsLimited(): void
    {
        // WordPress despacha la REST API también con enlaces permanentes planos:
        // `/?rest_route=/wp/v2/posts`. Esa forma tiene que contar en el mismo cubo.
        $_GET['rest_route'] = '/wp/v2/posts';

        $this->assertSame(120, $this->allowedBeforeBlocking('GET', '/', 200));
    }

    public function testTheGraphqlQueryFormIsLimited(): void
    {
        $_GET['graphql'] = '1';

        $this->assertSame(60, $this->allowedBeforeBlocking('POST', '/', 100));
    }

    public function testAPathThatMerelyContainsTheApiNameIsNotLimited(): void
    {
        // Clasificación por segmento de ruta, no por subcadena del URI completo:
        // una entrada cuyo slug empieza por "graphql-" no es el endpoint.
        $this->assertSame(300, $this->allowedBeforeBlocking('GET', '/blog/graphql-vs-rest', 300));
    }
}
