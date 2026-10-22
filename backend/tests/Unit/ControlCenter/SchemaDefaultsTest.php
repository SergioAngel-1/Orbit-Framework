<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\DefaultsWalker;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Tests\TestCase;

/**
 * El esquema produce defaults válidos (tarea 33).
 *
 * `Schema`, `CONFIG_DEFAULTS` (`frontend/src/lib/config/defaults.ts`) y `SiteConfig`
 * (`types.ts`) van sincronizados: este test fija el lado PHP (cada hoja tiene su default
 * y la config pública no filtra marcadores internos); el lado TS lo fija
 * `src/lib/config/defaults.test.ts`.
 */
final class SchemaDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false): mixed => $default);
        Functions\when('error_log')->justReturn(true);
    }

    public function testEveryLeafHasADefault(): void {
        $defaults = (new DefaultsWalker())->walk(Schema::get());

        $this->assertSame('HWE-Orbit', $defaults['brand']['name']);
        $this->assertSame('', $defaults['brand']['tagline_en']);
        $this->assertSame('', $defaults['brand']['description_en']);
        $this->assertSame('EUR', $defaults['ecommerce']['currency']);
        $this->assertTrue($defaults['ecommerce']['checkout_login_required']);
        $this->assertFalse($defaults['ecommerce']['free_shipping']['enabled']);
        $this->assertSame('noop', $defaults['payments']['provider']);
        $this->assertTrue($defaults['payments']['gateway_enabled']);
        $this->assertSame('allow', $defaults['geo']['ai_crawlers']);
        $this->assertSame('', $defaults['geo']['faq_en']);
        $this->assertSame([], $defaults['instance'] ?? [], 'El grupo instance vacío no aporta defaults.');
    }

    public function testThePublicConfigNeverLeaksInternalMarkers(): void {
        $schema   = Schema::get();
        $defaults = (new DefaultsWalker())->walk($schema);
        $public   = (new PublicConfigWalker())->walk($schema, $defaults);

        $json = json_encode($public, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hwe_skip', $json);
        $this->assertArrayNotHasKey('instance', $public, 'Sin campos de instancia no hay clave instance.');
        $this->assertSame('EUR', $public['ecommerce']['currency']);
    }

    public function testCurrencyOptionsFallBackWithoutWoo(): void {
        $schema = Schema::get();

        $this->assertSame(['EUR' => 'EUR'], $schema['ecommerce']['children']['currency']['options']);
    }
}
