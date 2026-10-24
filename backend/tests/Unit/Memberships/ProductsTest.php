<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-memberships`, parte de productos (spec §5.1).
 *
 * Un producto de membresía es un producto simple y virtual de WooCommerce ligado a un nivel y a
 * una duración en días (0 = indefinida). Se prueba la DECISIÓN al guardar y al leer: qué se
 * fuerza, qué se rechaza, qué pasa cuando el nivel desaparece o deja de venderse, y que la
 * columna del listado muestre el nivel y la duración. No se prueba el HTML completo de la
 * pestaña, solo que los hooks existan y que el guardado no dependa de la UI.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductsTest extends TestCase
{
    private const LEVEL = 101;      // publicado, comprable
    private const LEVEL_OFF = 103;  // publicado, NO comprable
    private const PRODUCT = 501;

    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var list<array{0:int,1:mixed,2:string}> */
    private array $termCalls = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->levelPosts = [];
        $this->postMeta   = [];
        $this->termCalls  = [];
        $this->logged     = [];
        $_POST            = [];

        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');

        $this->makeLevel(self::LEVEL, 5, true);
        $this->makeLevel(self::LEVEL_OFF, 10, false);

        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('wp_set_object_terms')->alias(function (int $id, mixed $terms, string $taxonomy): array {
            $this->termCalls[] = [$id, $terms, $taxonomy];
            return [];
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => trim((string) $value),
            'wp_unslash'          => static fn ($value) => $value,
            '__'                  => static fn ($value) => $value,
            'esc_html'            => static fn ($value) => (string) $value,
            'esc_attr'            => static fn ($value) => (string) $value,
            'esc_html__'          => static fn ($value) => $value,
            'esc_html_e'          => static fn ($value) => print $value,
            'selected'            => static fn ($left, $right, $echo = true) => $left === $right ? ' selected' : '',
            'checked'             => static fn ($left, $right, $echo = true) => $left === $right ? ' checked' : '',
        ]);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function makeLevel(int $id, int $rank, bool $purchasable): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = 'publish';
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = 'Descripción ' . $id;
        $this->levelPosts[$id] = $post;
        $this->postMeta[$id]   = [
            \HWE_Memberships_Levels::RANK_META         => (string) $rank,
            \HWE_Memberships_Levels::PURCHASABLE_META  => $purchasable ? '1' : '0',
        ];
    }

    /** @param array<string,mixed> $meta */
    private function seedProduct(int $productId, array $meta): void
    {
        $this->postMeta[$productId] = $meta;
    }

    private function save(TestMembershipProduct $product, array $post): void
    {
        $_POST = $post;
        \HWE_Memberships_Products::saveProduct($product);
        $this->postMeta[$product->get_id()] = $product->meta;
    }

    // ── Lectura del vínculo ─────────────────────────────────────────────────

    public function testMembershipOfReturnsTheLevelAndTheDuration(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '365',
        ]);

        $membership = \HWE_Memberships_Products::membershipOf(self::PRODUCT);

        $this->assertSame(['level_id' => self::LEVEL, 'days' => 365], $membership);
        $this->assertTrue(\HWE_Memberships_Products::isMembershipProduct(self::PRODUCT));
    }

    public function testZeroDaysMeansIndefiniteAndANormalProductHasNoMembership(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '0',
        ]);

        $this->assertSame(['level_id' => self::LEVEL, 'days' => null], \HWE_Memberships_Products::membershipOf(self::PRODUCT));
        $this->assertNull(\HWE_Memberships_Products::membershipOf(999));
        $this->assertFalse(\HWE_Memberships_Products::isMembershipProduct(999));
    }

    // ── Guardado desde la pestaña del producto ──────────────────────────────

    public function testSavingALevelAndDaysForcesTheProductToBeVirtual(): void
    {
        $product = new TestMembershipProduct(self::PRODUCT, 'simple', false, 'publish');

        $this->save($product, [
            'hwe_membership_level' => (string) self::LEVEL,
            'hwe_membership_days'  => '365',
        ]);

        $this->assertTrue($product->get_virtual(), 'Un producto de membresía no se envía.');
        $this->assertSame('publish', $product->get_status());
        $this->assertSame(
            ['level_id' => self::LEVEL, 'days' => 365],
            \HWE_Memberships_Products::membershipOf(self::PRODUCT)
        );
        $this->assertSame([], $this->logged);
    }

    public function testSavingWithoutALevelClearsTheBinding(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ]);
        $product = new TestMembershipProduct(self::PRODUCT, 'simple', true, 'publish');
        $product->meta = $this->postMeta[self::PRODUCT];

        $this->save($product, [
            'hwe_membership_level' => '',
            'hwe_membership_days'  => '',
        ]);

        $this->assertNull(\HWE_Memberships_Products::membershipOf(self::PRODUCT));
        $this->assertArrayNotHasKey('_hwe_membership_level_id', $product->meta);
    }

    public function testABindingToANonexistentLevelIsRejectedAndLeavesTheProductDraft(): void
    {
        $product = new TestMembershipProduct(self::PRODUCT, 'simple', false, 'publish');

        $this->save($product, [
            'hwe_membership_level' => '999',
            'hwe_membership_days'  => '30',
        ]);

        $this->assertNull(\HWE_Memberships_Products::membershipOf(self::PRODUCT));
        $this->assertSame('draft', $product->get_status());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
        $this->assertStringContainsString('no existe', $this->logged[0]);
    }

    public function testALevelThatIsNotForSaleKeepsTheBindingButLeavesTheProductDraft(): void
    {
        $product = new TestMembershipProduct(self::PRODUCT, 'simple', false, 'publish');

        $this->save($product, [
            'hwe_membership_level' => (string) self::LEVEL_OFF,
            'hwe_membership_days'  => '0',
        ]);

        $this->assertSame(
            ['level_id' => self::LEVEL_OFF, 'days' => null],
            \HWE_Memberships_Products::membershipOf(self::PRODUCT)
        );
        $this->assertSame('draft', $product->get_status());
        $this->assertNotEmpty($this->logged);
    }

    public function testNegativeOrNonNumericDaysAreStoredAsIndefinite(): void
    {
        $product = new TestMembershipProduct(self::PRODUCT);

        $this->save($product, [
            'hwe_membership_level' => (string) self::LEVEL,
            'hwe_membership_days'  => '-10',
        ]);

        $this->assertSame(['level_id' => self::LEVEL, 'days' => null], \HWE_Memberships_Products::membershipOf(self::PRODUCT));

        $this->save($product, [
            'hwe_membership_level' => (string) self::LEVEL,
            'hwe_membership_days'  => 'no es un número',
        ]);

        $this->assertSame(['level_id' => self::LEVEL, 'days' => null], \HWE_Memberships_Products::membershipOf(self::PRODUCT));
    }

    public function testAfterSavingAMembershipProductTheStoredTypeIsSimple(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ]);

        \HWE_Memberships_Products::enforceSimpleType(self::PRODUCT);

        $this->assertSame([[self::PRODUCT, 'simple', 'product_type']], $this->termCalls);

        // Un producto normal no se toca.
        \HWE_Memberships_Products::enforceSimpleType(999);
        $this->assertCount(1, $this->termCalls);
    }

    // ── Compra y listado ────────────────────────────────────────────────────

    public function testOnlyMembershipProductsWithASaleableLevelArePurchasable(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ]);
        $this->seedProduct(502, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL_OFF,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ]);

        $this->assertTrue(\HWE_Memberships_Products::gatePurchasable(true, new TestMembershipProduct(self::PRODUCT)));
        $this->assertFalse(\HWE_Memberships_Products::gatePurchasable(true, new TestMembershipProduct(502)));
        $this->assertTrue(
            \HWE_Memberships_Products::gatePurchasable(true, new TestMembershipProduct(999)),
            'Un producto normal conserva lo que decidió WooCommerce.'
        );
        $this->assertFalse(\HWE_Memberships_Products::gatePurchasable(false, new TestMembershipProduct(self::PRODUCT)));
    }

    public function testTheListingColumnShowsTheLevelAndTheDuration(): void
    {
        $this->seedProduct(self::PRODUCT, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '30',
        ]);
        $this->seedProduct(502, [
            \HWE_Memberships_Products::LEVEL_META => (string) self::LEVEL,
            \HWE_Memberships_Products::DAYS_META  => '0',
        ]);

        ob_start();
        \HWE_Memberships_Products::renderColumn(\HWE_Memberships_Products::COLUMN, self::PRODUCT);
        $monthly = (string) ob_get_clean();

        ob_start();
        \HWE_Memberships_Products::renderColumn(\HWE_Memberships_Products::COLUMN, 502);
        $forever = (string) ob_get_clean();

        ob_start();
        \HWE_Memberships_Products::renderColumn(\HWE_Memberships_Products::COLUMN, 999);
        $normal = (string) ob_get_clean();

        $this->assertStringContainsString('Nivel ' . self::LEVEL, $monthly);
        $this->assertStringContainsString('30', $monthly);
        $this->assertStringContainsString('Indefinida', $forever);
        $this->assertStringContainsString('—', $normal);
    }

    public function testRegisterHooksTheTabTheSaveAndTheListingColumn(): void
    {
        Filters\expectAdded('woocommerce_product_data_tabs')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'addTab']);
        Actions\expectAdded('woocommerce_admin_process_product_object')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'saveProduct']);
        Actions\expectAdded('woocommerce_update_product')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'enforceSimpleType']);
        Actions\expectAdded('woocommerce_new_product')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'enforceSimpleType']);
        Filters\expectAdded('manage_edit-product_columns')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'addColumn']);
        Actions\expectAdded('manage_product_posts_custom_column')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'renderColumn'], 10, 2);
        Filters\expectAdded('woocommerce_is_purchasable')
            ->once()
            ->with([\HWE_Memberships_Products::class, 'gatePurchasable'], 10, 2);

        \HWE_Memberships_Products::register();
    }
}

/** Producto mínimo: id, tipo, virtualidad, estado y metas. */
final class TestMembershipProduct
{
    /** @var array<string,mixed> */
    public array $meta = [];

    public function __construct(
        private int $id,
        private string $type = 'simple',
        private bool $virtual = false,
        private string $status = 'publish'
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_type(): string
    {
        return $this->type;
    }

    public function get_virtual(): bool
    {
        return $this->virtual;
    }

    public function set_virtual(bool $virtual): void
    {
        $this->virtual = $virtual;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function set_status(string $status): void
    {
        $this->status = $status;
    }

    public function update_meta_data(string $key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }

    public function get_meta(string $key)
    {
        return $this->meta[$key] ?? '';
    }
}
