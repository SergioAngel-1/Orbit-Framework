<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ControlCenter;

use Brain\Monkey\Functions;
use HWE\ControlCenter\RestApi;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * `GET /wp-json/hwe/v1/config`: la config pública con los valores calculados al vuelo
 * (`modules` y `ecommerce.price_decimals`) y nunca un secreto.
 */
final class RestApiTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('error_log')->justReturn(true);
    }

    /** @return array<string,mixed> */
    private function publicConfig(): array
    {
        $response = RestApi::handleGet(new \WP_REST_Request());
        return $response->get_data();
    }

    public function testItExposesTheModulesMap(): void {
        $this->options['active_plugins'] = ['hwe-banners/hwe-banners.php'];

        $config = $this->publicConfig();

        $this->assertTrue($config['modules']['banners']);
        $this->assertFalse($config['modules']['shipping']);
    }

    public function testWithoutWooCommerceThePriceDecimalsAreNull(): void {
        $this->assertNull($this->publicConfig()['ecommerce']['price_decimals']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItExposesWooCommercePriceDecimals(): void {
        Functions\when('wc_get_price_decimals')->justReturn(0);

        $this->assertSame(0, $this->publicConfig()['ecommerce']['price_decimals']);
    }

    public function testItNeverExposesSecrets(): void {
        $this->options['hwe_config_secrets'] = ['payments' => ['wompi' => ['events_secret' => 'v2:cifrado']]];

        $json = json_encode($this->publicConfig(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('events_secret', $json);
        $this->assertStringNotContainsString('v2:cifrado', $json);
    }
}
