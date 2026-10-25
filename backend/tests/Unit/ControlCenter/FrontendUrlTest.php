<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use HWE\ControlCenter\FrontendUrl;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `FrontendUrl::internal()`: la URL del frontend para llamadas SERVIDOR A SERVIDOR.
 *
 * Dentro de un contenedor, `localhost` es el propio contenedor: la URL pública
 * (`HEADLESS_FRONTEND_URL`) sirve para el navegador, CORS y los enlaces de los correos, pero no
 * para que WordPress llame al BFF (revalidación ISR, medición de consentimiento). Esa llamada
 * usa `HEADLESS_FRONTEND_INTERNAL_URL` cuando existe y, si no, la pública.
 *
 * Las constantes son globales al proceso: cada test corre en el suyo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FrontendUrlTest extends TestCase
{
    public function testTheInternalUrlWinsAndIsNormalized(): void
    {
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example/');
        define('HEADLESS_FRONTEND_INTERNAL_URL', 'http://frontend:3000/');

        $this->assertSame(
            'http://frontend:3000',
            FrontendUrl::internal(),
            'La interna es la única que llega al servicio `frontend` desde otro contenedor.'
        );
    }

    public function testAnEmptyInternalUrlFallsBackToThePublicOne(): void
    {
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example/');
        define('HEADLESS_FRONTEND_INTERNAL_URL', '');

        $this->assertSame('https://tienda.example', FrontendUrl::internal());
    }

    public function testWithoutAnInternalUrlThePublicOneIsUsed(): void
    {
        define('HEADLESS_FRONTEND_URL', 'https://tienda.example');

        $this->assertSame('https://tienda.example', FrontendUrl::internal());
    }

    public function testWithoutAnyConstantItKeepsTheDevelopmentFallback(): void
    {
        $this->assertSame('http://localhost:3000', FrontendUrl::internal());
    }
}
