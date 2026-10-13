<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\FreeShippingRule;
use HWE\ControlCenter\WooCashGatewaySync;
use HWE\ControlCenter\WooCurrencySync;
use HWE\ControlCenter\WooGuestCheckoutSync;
use HWE\Tests\TestCase;

/**
 * Las sincronizaciones con WooCommerce (`WooCurrencySync`, `WooGuestCheckoutSync`,
 * `WooCashGatewaySync`) y la regla de envío gratis (`FreeShippingRule`).
 *
 * El panel es la fuente de verdad de moneda, checkout de invitados y efectivo: al guardar
 * se empujan a los ajustes nativos de WooCommerce, y si un admin los cambia directo en
 * WooCommerce se avisa en wp-admin. La regla de envío gratis la consume el módulo
 * `shipping` por el filtro `hwe_shipping_free_rule`.
 */
final class WooSyncTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<array{string,mixed}> */
    private array $updates = [];
    private bool $failUpdates = false;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->updates[] = [$key, $value];
            if ($this->failUpdates) {
                return false;
            }
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->alias(static fn ($v) => (string) $v);
        Functions\when('taxonomy_exists')->justReturn(false);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    private function storedConfig(array $config): void {
        $this->options['hwe_config'] = $config;
    }

    private function notices(callable $fn): string {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    // ── moneda ──────────────────────────────────────────────────────────────

    public function testCurrencySyncPushesTheConfiguredValue(): void {
        $this->storedConfig(['ecommerce' => ['currency' => 'USD']]);
        $this->options['woocommerce_currency'] = 'EUR';

        WooCurrencySync::sync('USD');

        $this->assertSame([['woocommerce_currency', 'USD']], $this->updates);
    }

    public function testCurrencySyncSkipsEmptyAndAlignedValues(): void {
        WooCurrencySync::sync('');

        $this->storedConfig(['ecommerce' => ['currency' => 'EUR']]);
        $this->options['woocommerce_currency'] = 'EUR';
        WooCurrencySync::sync('EUR');

        $this->assertSame([], $this->updates);
    }

    public function testCurrencySyncFailureIsLogged(): void {
        $this->failUpdates = true;

        WooCurrencySync::sync('USD');

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('woocommerce_currency', $this->logged[0]);
    }

    public function testCurrencyDivergenceWarnsInWpAdmin(): void {
        $this->storedConfig(['ecommerce' => ['currency' => 'USD']]);
        $this->options['woocommerce_currency'] = 'EUR';

        $out = $this->notices(fn () => WooCurrencySync::maybeWarnDivergence());

        $this->assertStringContainsString('notice-warning', $out);
        $this->assertStringContainsString('USD', $out);
    }

    public function testCurrencyWithoutDivergenceStaysSilent(): void {
        $this->storedConfig(['ecommerce' => ['currency' => 'EUR']]);
        $this->options['woocommerce_currency'] = 'EUR';

        $this->assertSame('', $this->notices(fn () => WooCurrencySync::maybeWarnDivergence()));
    }

    // ── checkout de invitados ───────────────────────────────────────────────

    public function testGuestCheckoutSyncDisablesTheNativeSetting(): void {
        $this->storedConfig(['ecommerce' => ['checkout_login_required' => true]]);
        $this->options['woocommerce_enable_guest_checkout'] = 'yes';

        WooGuestCheckoutSync::sync();

        $this->assertSame([['woocommerce_enable_guest_checkout', 'no']], $this->updates);
    }

    public function testGuestCheckoutSyncEnablesItWhenLoginIsNotRequired(): void {
        $this->storedConfig(['ecommerce' => ['checkout_login_required' => false]]);
        $this->options['woocommerce_enable_guest_checkout'] = 'no';

        WooGuestCheckoutSync::sync();

        $this->assertSame([['woocommerce_enable_guest_checkout', 'yes']], $this->updates);
    }

    public function testGuestCheckoutDivergenceWarns(): void {
        $this->storedConfig(['ecommerce' => ['checkout_login_required' => true]]);
        $this->options['woocommerce_enable_guest_checkout'] = 'yes';

        $out = $this->notices(fn () => WooGuestCheckoutSync::maybeWarnDivergence());

        $this->assertStringContainsString('notice-warning', $out);
    }

    // ── efectivo (COD) ──────────────────────────────────────────────────────

    public function testCashSyncEnablesCodWithHeadlessProofSettings(): void {
        $this->storedConfig(['payments' => ['cash_enabled' => true]]);

        WooCashGatewaySync::sync();

        $this->assertCount(1, $this->updates);
        [$key, $settings] = $this->updates[0];
        $this->assertSame('woocommerce_cod_settings', $key);
        $this->assertSame('yes', $settings['enabled']);
        $this->assertSame([], $settings['enable_for_methods']);
        $this->assertSame('yes', $settings['enable_for_virtual']);
        $this->assertNotSame('', $settings['title']);
        $this->assertNotSame('', $settings['description']);
    }

    public function testCashSyncKeepsTheOperatorsCustomTexts(): void {
        $this->storedConfig(['payments' => ['cash_enabled' => true]]);
        $this->options['woocommerce_cod_settings'] = [
            'enabled' => 'no', 'title' => 'Efectivo en tienda', 'description' => 'Paga al recoger.',
        ];

        WooCashGatewaySync::sync();

        [, $settings] = $this->updates[0];
        $this->assertSame('yes', $settings['enabled']);
        $this->assertSame('Efectivo en tienda', $settings['title']);
        $this->assertSame('Paga al recoger.', $settings['description']);
    }

    public function testCashSyncDisablesCod(): void {
        $this->storedConfig(['payments' => ['cash_enabled' => false]]);
        $this->options['woocommerce_cod_settings'] = ['enabled' => 'yes'];

        WooCashGatewaySync::sync();

        [, $settings] = $this->updates[0];
        $this->assertSame('no', $settings['enabled']);
    }

    public function testCashWithoutDivergenceDoesNothing(): void {
        $this->storedConfig(['payments' => ['cash_enabled' => false]]);
        $this->options['woocommerce_cod_settings'] = ['enabled' => 'no'];

        WooCashGatewaySync::sync();

        $this->assertSame([], $this->updates);
        $this->assertSame('', $this->notices(fn () => WooCashGatewaySync::maybeWarnDivergence()));
    }

    // ── regla de envío gratis ───────────────────────────────────────────────

    public function testFreeShippingRuleShape(): void {
        $this->storedConfig(['ecommerce' => ['free_shipping' => [
            'enabled' => true, 'min_amount' => '150000',
            'categories' => 'ropa |  calzado', 'products' => 'camiseta',
        ]]]);

        $this->assertSame(
            ['enabled' => true, 'min_amount' => 150000.0, 'categories' => ['ropa', 'calzado'], 'products' => ['camiseta']],
            FreeShippingRule::rule()
        );
    }

    public function testFreeShippingRuleDefaultsToDisabled(): void {
        $this->assertSame(
            ['enabled' => false, 'min_amount' => 0.0, 'categories' => [], 'products' => []],
            FreeShippingRule::rule()
        );
    }

    public function testFreeShippingRuleClampsNegativeAmounts(): void {
        $this->storedConfig(['ecommerce' => ['free_shipping' => ['enabled' => true, 'min_amount' => '-5']]]);

        $this->assertSame(0.0, FreeShippingRule::rule()['min_amount']);
    }

    public function testFlushRatesWithoutWooDoesNothing(): void {
        FreeShippingRule::flushRates();

        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->logged);
    }

    public function testCategoryOptionsKeepStoredSlugs(): void {
        $this->storedConfig(['ecommerce' => ['free_shipping' => ['categories' => 'nueva | vieja']]]);

        // Sin taxonomía (ni Woo) no hay opciones reales: las guardadas se conservan para que
        // el primer guardado del panel no las borre.
        $this->assertSame(
            ['nueva' => 'nueva (no encontrado)', 'vieja' => 'vieja (no encontrado)'],
            FreeShippingRule::categoryOptions()
        );
    }
}
