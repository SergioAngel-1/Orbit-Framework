<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Schema;
use HWE\ControlCenter\Walkers\PublicConfigWalker;
use HWE\Tests\TestCase;

/**
 * Campos traducibles del Control Center a N idiomas.
 *
 * Un nodo `'translatable' => true` genera una variante `<clave>_<sufijo>` por cada idioma
 * secundario de `i18n.locales` (sin el idioma por defecto, `brand.locale`). Sustituye a las
 * claves `_en` fijas anteriores; con el valor por defecto (`en`) las claves
 * publicadas son las mismas, así que los datos ya guardados siguen valiendo.
 */
final class TranslatableFieldsTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    public function testWithTheDefaultLocalesTheEnglishVariantsKeepTheirKeys(): void {
        $brand = Schema::get()['brand']['children'];

        $this->assertArrayHasKey('tagline_en', $brand);
        $this->assertArrayHasKey('description_en', $brand);
        $this->assertSame('text', $brand['tagline_en']['type']);
        $this->assertSame('', $brand['tagline_en']['default']);
        $this->assertTrue($brand['tagline_en']['public']);
        $this->assertArrayNotHasKey('translatable', $brand['tagline_en']);
        $this->assertArrayHasKey('faq_payments_en', Schema::get()['geo']['children']);
    }

    public function testEachConfiguredSecondaryLocaleGetsItsVariant(): void {
        $this->options['hwe_config'] = ['i18n' => ['locales' => 'en, fr, pt-BR, es']];

        $brand = Schema::get()['brand']['children'];

        $this->assertArrayHasKey('tagline_en', $brand);
        $this->assertArrayHasKey('tagline_fr', $brand);
        $this->assertArrayHasKey('tagline_pt_br', $brand);
        $this->assertArrayNotHasKey('tagline_es', $brand, 'El idioma por defecto no lleva variante.');
        $this->assertSame('Tagline (PT-BR)', $brand['tagline_pt_br']['label']);
    }

    public function testAMonolingualSiteHasNoVariants(): void {
        $this->options['hwe_config'] = ['i18n' => ['locales' => '']];

        $this->assertArrayNotHasKey('tagline_en', Schema::get()['brand']['children']);
        $this->assertSame([], Schema::secondaryLocales());
    }

    public function testInvalidLocaleCodesAreIgnoredAndLogged(): void {
        $this->options['hwe_config'] = ['i18n' => ['locales' => 'en, <script>, f']];
        $this->assertSame(['en'], Schema::secondaryLocales());
        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('<script>', $this->logged[0]);
    }

    public function testInstanceFieldsCanBeTranslatableToo(): void {
        Filters\expectApplied('hwe_control_center_schema')->andReturnUsing(static function (array $schema): array {
            $schema['instance']['children']['promo'] = [
                'type' => 'group',
                'label' => 'Promo',
                'children' => [
                    'headline' => ['type' => 'text', 'label' => 'Titular', 'public' => true, 'translatable' => true],
                    'color'    => ['type' => 'color', 'label' => 'Color', 'public' => true, 'translatable' => true],
                ],
            ];
            return $schema;
        });

        $promo = Schema::get()['instance']['children']['promo']['children'];

        $this->assertArrayHasKey('headline_en', $promo);
        $this->assertArrayNotHasKey('color_en', $promo, 'Un color no es traducible: se ignora la marca.');
    }

    public function testTheVariantsAreExposedInThePublicConfig(): void {
        $this->options['hwe_config'] = ['brand' => ['tagline' => 'Hola', 'tagline_en' => 'Hello']];

        $public = (new PublicConfigWalker())->walk(Schema::get(), $this->options['hwe_config']);

        $this->assertSame('Hello', $public['brand']['tagline_en']);
        $this->assertSame('en', $public['i18n']['locales']);
    }
}
