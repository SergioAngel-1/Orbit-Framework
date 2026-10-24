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
 * Plugin `hwe-memberships`, ficha de escaparate en las vías públicas (spec §6.2).
 *
 * La decisión de qué se recorta vive en `HWE_Memberships_Teaser`. Aquí se prueba la lista
 * BLANCA (todo lo que no está se descarta), el recorte de la Store API (producto, listado, ficha
 * de variación y carrito) y el de `wc/v3` (solo cuando la petición es del BFF con su secreto
 * interno: el panel y las integraciones con ck/cs siguen viendo la ficha completa). También que
 * con el nivel se devuelve todo y que con el módulo apagado no se toca nada.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeaserTest extends TestCase
{
    private const USER = 7;
    private const LEVEL = 105;
    private const PRODUCT = 501;

    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,array<string,mixed>> */
    private array $termMeta = [];
    /** @var array<int,list<int>> */
    private array $ancestors = [];
    /** @var array<int,list<int>> */
    private array $productTerms = [];
    private int $currentUser = 0;
    private bool $bffRequest = false;
    /** ¿Existe algún producto o categoría con requisito? (lo responde el doble de `$wpdb`). */
    private bool $postMetaRankExists = false;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->activePlugins = ['hwe-memberships/hwe-memberships.php'];
        $this->postMeta      = [];
        $this->termMeta      = [];
        $this->ancestors     = [30 => [], 40 => []];
        $this->productTerms  = [self::PRODUCT => [30], 502 => []];
        $this->currentUser   = 0;
        $this->bffRequest    = false;
        $this->postMetaRankExists = false;
        $this->logged        = [];

        $test = $this;
        $GLOBALS['wpdb'] = new class($test) {
            public string $postmeta = 'wp_postmeta';
            public string $termmeta = 'wp_termmeta';
            public string $last_error = '';

            public function __construct(private object $test)
            {
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return $query . ' /* ' . implode(',', array_map('strval', $args)) . ' */';
            }

            public function get_var(string $query): ?string
            {
                return (fn (): bool => $this->postMetaRankExists)->call($this->test) ? '1' : null;
            }
        };

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Teaser.php');

        \HWE_Memberships_Levels::$levels = [
            101 => ['id' => 101, 'name' => 'Nivel 1', 'slug' => 'nivel-1', 'rank' => 1, 'published' => true],
            self::LEVEL => ['id' => self::LEVEL, 'name' => 'Nivel 5', 'slug' => 'nivel-5', 'rank' => 5, 'published' => true],
        ];
        \HWE_Memberships_Levels::$configs = [];
        \HWE_Memberships_Levels::$throwOnAll = false;
        \HWE_Memberships_Members::$states = [
            self::USER => [
                'level_id'  => self::LEVEL,
                'rank'      => 5,
                'status'    => \HWE_Memberships_Members::STATUS_ACTIVE,
                'ends_at'   => null,
                'queue'     => [],
                'grant_key' => 'grant:1',
            ],
            8 => [
                'level_id'  => 0,
                'rank'      => 0,
                'status'    => \HWE_Memberships_Members::STATUS_NONE,
                'ends_at'   => null,
                'queue'     => [],
                'grant_key' => '',
            ],
        ];
        $this->postMeta[self::PRODUCT] = [\HWE_Memberships_Access::MIN_RANK_META => '5'];
        $this->termMeta[30]            = [\HWE_Memberships_Access::MIN_RANK_META => '5'];
        $this->termMeta[40]            = [];
        \HWE_Memberships_Access::flush();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => 'active_plugins' === $name ? $this->activePlugins : $default
        );
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_term_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->termMeta[$id][$key] ?? ''
        );
        Functions\when('get_ancestors')->alias(
            fn (int $id, string $taxonomy = '', string $context = 'taxonomy'): array => $this->ancestors[$id] ?? []
        );
        Functions\when('wp_get_post_terms')->alias(
            fn (int $id, string $taxonomy = '', array $args = []): array => $this->productTerms[$id] ?? []
        );
        Functions\when('get_current_user_id')->alias(fn (): int => $this->currentUser);
        Functions\when('hwe_bff_only_has_valid_secret')->alias(fn (): bool => $this->bffRequest);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            '__'         => static fn ($value) => (string) $value,
            'esc_html__' => static fn ($value) => (string) $value,
        ]);
    }

    protected function tearDown(): void
    {
        \HWE_Memberships_Access::flush();
        parent::tearDown();
    }

    // ── Decisión y lista blanca ─────────────────────────────────────────────

    public function testIsTeaserDecidesByTheMembershipAndTheModule(): void
    {
        $this->assertTrue(\HWE_Memberships_Teaser::isTeaser(self::PRODUCT, 0), 'Sin usuario, bloqueado.');
        $this->assertFalse(\HWE_Memberships_Teaser::isTeaser(502, 0), 'Un producto público nunca es teaser.');
        $this->assertFalse(\HWE_Memberships_Teaser::isTeaser(self::PRODUCT, self::USER), 'Con el nivel, ficha completa.');
        $this->assertTrue(\HWE_Memberships_Teaser::isTeaser(self::PRODUCT, 8), 'Con sesión pero sin nivel, bloqueado.');

        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();
        $this->assertFalse(
            \HWE_Memberships_Teaser::isTeaser(self::PRODUCT, 0),
            'Con el módulo apagado no hay requisitos ni recorte.'
        );
    }

    public function testTheTeaserFieldListIsAnAllowList(): void
    {
        $fields = \HWE_Memberships_Teaser::fields();

        foreach (['id', 'name', 'slug', 'type', 'image', 'price', 'price_range', 'categories', 'hwe_membership', 'locked', 'status', 'purchasable', 'stock_status', 'manage_stock', 'stock_quantity', 'backorders_allowed'] as $field) {
            $this->assertContains($field, $fields, $field . ' debe estar en la lista blanca.');
        }
        foreach (['description', 'short_description', 'gallery', 'attributes', 'variations', 'downloads', 'hweFields'] as $field) {
            $this->assertNotContains($field, $fields, $field . ' no puede salir en la ficha de escaparate.');
        }
    }

    // ── Store API ───────────────────────────────────────────────────────────

    public function testStoreProductResponseKeepsOnlyTheTeaserFieldsForAVisitor(): void
    {
        $trimmed = \HWE_Memberships_Teaser::trimStoreProduct($this->storeProduct(self::PRODUCT), 0);

        $this->assertSame(
            ['id', 'name', 'slug', 'type', 'images', 'prices', 'categories', 'locked', 'hwe_membership'],
            array_keys($trimmed)
        );
        $this->assertSame('Producto 501', $trimmed['name']);
        $this->assertCount(1, $trimmed['images'], 'Solo la imagen principal.');
        $this->assertSame('main.jpg', $trimmed['images'][0]['src']);
        $this->assertSame(1000, $trimmed['prices']['price']);
        $this->assertSame([['id' => 30, 'name' => 'Cat', 'slug' => 'cat']], $trimmed['categories']);
        $this->assertTrue($trimmed['locked']);
        $this->assertSame(
            ['required_level' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'], 'opens_at' => null],
            $trimmed['hwe_membership']
        );

        foreach (['description', 'short_description', 'attributes', 'variations', 'sku', 'status', 'purchasable', 'stock_status', 'stock_quantity', 'manage_stock', 'backorders_allowed'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $trimmed, $hidden . ' no sale por la Store API.');
        }

        // Un usuario con sesión pero sin el nivel ve exactamente lo mismo que un visitante.
        $userNoLevel = \HWE_Memberships_Teaser::trimStoreProduct($this->storeProduct(self::PRODUCT), 8);
        $this->assertArrayNotHasKey('description', $userNoLevel);
        $this->assertTrue($userNoLevel['locked']);
    }

    public function testAVariableTeaserKeepsThePublicPriceRangeWithoutVariations(): void
    {
        $product = $this->storeProduct(self::PRODUCT, [
            'type'   => 'variable',
            'prices' => [
                'price'       => '1000',
                'price_range' => ['min_amount' => '1000', 'max_amount' => '2000'],
            ],
        ]);

        $trimmed = \HWE_Memberships_Teaser::trimStoreProduct($product, 0);

        $this->assertSame('variable', $trimmed['type']);
        $this->assertSame(['min_amount' => '1000', 'max_amount' => '2000'], $trimmed['prices']['price_range']);
        $this->assertArrayNotHasKey('variations', $trimmed);
    }

    public function testStoreProductResponseIsUntouchedWithTheLevelOrWithTheModuleOff(): void
    {
        $product = $this->storeProduct(self::PRODUCT);

        $this->assertSame($product, \HWE_Memberships_Teaser::trimStoreProduct($product, self::USER));

        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();
        $this->assertSame($product, \HWE_Memberships_Teaser::trimStoreProduct($product, 0));
    }

    public function testStoreVariationResponseIsMinimalForATeaser(): void
    {
        $variation = [
            'id'          => 900,
            'parent'      => self::PRODUCT,
            'type'        => 'variation',
            'name'        => 'Producto 501 - Rojo',
            'sku'         => 'SKU-ROJO',
            'price'       => '1000',
            'attributes'  => [['name' => 'Color', 'value' => 'Rojo']],
            'stock_status' => 'instock',
        ];

        $trimmed = \HWE_Memberships_Teaser::trimStoreProduct($variation, 0);

        $this->assertSame(
            [
                'id'             => 900,
                'type'           => 'variation',
                'parent'         => self::PRODUCT,
                'locked'         => true,
                'hwe_membership' => [
                    'required_level' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                    'opens_at'       => null,
                ],
            ],
            $trimmed
        );
        foreach (['name', 'sku', 'price', 'attributes', 'stock_status'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $trimmed, $hidden . ' de una variación no puede salir.');
        }

        // Sin producto padre no se puede evaluar: falla cerrado y tampoco sale su contenido.
        $orphan = \HWE_Memberships_Teaser::trimStoreProduct([
            'id'         => 901,
            'type'       => 'variation',
            'name'       => 'Variación huérfana',
            'attributes' => [['name' => 'Color', 'value' => 'Rojo']],
        ], 0);
        $this->assertSame(['id' => 901, 'type' => 'variation', 'locked' => true], $orphan);
    }

    public function testStoreCartItemsAreTrimmedButKeepTheLineMechanics(): void
    {
        $item = [
            'key'               => 'abc',
            'id'                => self::PRODUCT,
            'quantity'          => 2,
            'name'              => 'Producto 501',
            'summary'           => 'Color: Rojo',
            'short_description' => '<p>Secreto corto</p>',
            'description'       => '<p>Secreto largo</p>',
            'sku'               => 'SKU-501',
            'images'            => [['src' => 'main.jpg'], ['src' => 'gallery.jpg']],
            'variation'         => [['attribute' => 'Color', 'value' => 'Rojo']],
            'item_data'         => [['name' => 'Color', 'value' => 'Rojo']],
            'prices'            => ['price' => 1000, 'currency_minor_unit' => 2],
            'totals'            => ['line_total' => 2000],
            'extensions'        => ['hwe-memberships' => ['blocked' => true]],
        ];

        $trimmed = \HWE_Memberships_Teaser::trimCartItem($item, 0);

        $this->assertSame(
            ['key', 'id', 'quantity', 'name', 'images', 'prices', 'totals', 'extensions', 'locked', 'hwe_membership'],
            array_keys($trimmed)
        );
        $this->assertSame('abc', $trimmed['key']);
        $this->assertSame(2, $trimmed['quantity']);
        $this->assertCount(1, $trimmed['images']);
        $this->assertSame(['blocked' => true], $trimmed['extensions']['hwe-memberships']);
        foreach (['summary', 'short_description', 'description', 'sku', 'variation', 'item_data'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $trimmed, $hidden . ' no puede salir del carrito.');
        }

        $this->assertSame($item, \HWE_Memberships_Teaser::trimCartItem($item, self::USER), 'Con el nivel, la línea completa.');
    }

    public function testTheRestResponseFilterOnlyTouchesStoreProductAndCartRoutes(): void
    {
        $request = new \WP_REST_Request([], [], '/wc/store/v1/products');
        $response = new \WP_REST_Response([$this->storeProduct(self::PRODUCT), $this->storeProduct(502)]);

        $filtered = \HWE_Memberships_Teaser::filterRestResponse($response, [], $request);

        $this->assertSame($response, $filtered);
        $data = $response->get_data();
        $this->assertArrayHasKey('locked', $data[0]);
        $this->assertArrayNotHasKey('description', $data[0]);
        $this->assertArrayNotHasKey('locked', $data[1], 'El producto público no se toca.');

        // Ficha individual.
        $single = new \WP_REST_Response($this->storeProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::filterRestResponse($single, [], new \WP_REST_Request([], [], '/wc/store/v1/products/501'));
        $this->assertArrayNotHasKey('description', $single->get_data());
        $this->assertArrayHasKey('hwe_membership', $single->get_data());

        // Carrito.
        $cart = new \WP_REST_Response([
            'items' => [[
                'key'         => 'abc',
                'id'          => self::PRODUCT,
                'quantity'    => 1,
                'name'        => 'Producto 501',
                'description' => 'Secreto',
                'prices'      => ['price' => 1000],
            ]],
        ]);
        \HWE_Memberships_Teaser::filterRestResponse($cart, [], new \WP_REST_Request([], [], '/wc/store/v1/cart'));
        $item = $cart->get_data()['items'][0];
        $this->assertArrayNotHasKey('description', $item);
        $this->assertTrue($item['locked']);

        // Otra ruta no se toca.
        $other = new \WP_REST_Response(['description' => 'No es la Store API']);
        $this->assertSame($other->get_data(), \HWE_Memberships_Teaser::filterRestResponse(
            $other,
            [],
            new \WP_REST_Request([], [], '/wc/v3/products')
        )->get_data());

        // La categoría es pública: nombre, slug, imagen y descripción se conservan.
        $category = new \WP_REST_Response([[
            'id'          => 30,
            'name'        => 'Cat',
            'slug'        => 'cat',
            'description' => 'Descripción que invita a hacerse socio',
        ]]);
        \HWE_Memberships_Teaser::filterRestResponse($category, [], new \WP_REST_Request([], [], '/wc/store/v1/products/categories'));
        $this->assertSame(
            'Descripción que invita a hacerse socio',
            $category->get_data()[0]['description']
        );
    }

    /**
     * La Store API también sirve el producto por slug (`/products/<slug>`, ruta de WooCommerce):
     * si el recorte solo reconociera ids, bastaría pedirlo por su slug para saltárselo. Las rutas
     * de términos y de reseñas comparten el prefijo y NO son productos (sus `id` son de término).
     */
    public function testTheRestResponseFilterAlsoTrimsAProductRequestedBySlug(): void
    {
        $bySlug = new \WP_REST_Response($this->storeProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::filterRestResponse($bySlug, [], new \WP_REST_Request([], [], '/wc/store/v1/products/producto-501'));
        $this->assertArrayNotHasKey('description', $bySlug->get_data());
        $this->assertTrue($bySlug->get_data()['locked']);

        foreach (['categories', 'attributes', 'tags', 'brands', 'reviews', 'collection-data'] as $segment) {
            $terms = new \WP_REST_Response([['id' => self::PRODUCT, 'name' => 'Término', 'slug' => 't', 'description' => 'Pública']]);
            \HWE_Memberships_Teaser::filterRestResponse($terms, [], new \WP_REST_Request([], [], '/wc/store/v1/products/' . $segment));
            $this->assertSame('Pública', $terms->get_data()[0]['description'], $segment . ' no es un producto: no se recorta.');
            $this->assertArrayNotHasKey('locked', $terms->get_data()[0]);
        }
    }

    public function testAnOpeningDateTravelsInTheRestPayload(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $this->postMeta[502][\HWE_Memberships_Access::OPENS_AT_META] = $future;
        \HWE_Memberships_Access::flush();

        $trimmed = \HWE_Memberships_Teaser::trimStoreProduct($this->storeProduct(502), 0);

        $this->assertTrue($trimmed['locked']);
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', strtotime($future . ' UTC')),
            $trimmed['hwe_membership']['opens_at']
        );
        $this->assertNull($trimmed['hwe_membership']['required_level']);
    }

    // ── WC REST v3 (BFF) ────────────────────────────────────────────────────

    public function testTheWcRestResponseIsTrimmedOnlyForTheInternalBff(): void
    {
        $this->bffRequest = false;
        $response = new \WP_REST_Response($this->wcProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWcProduct($response, null, new \WP_REST_Request());
        $this->assertArrayHasKey('short_description', $response->get_data(), 'Admin/ERP siguen viendo todo.');

        $this->bffRequest = true;
        $response = new \WP_REST_Response($this->wcProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWcProduct($response, null, new \WP_REST_Request());
        $data = $response->get_data();

        $this->assertSame(
            [
                'id',
                'status',
                'purchasable',
                'stock_status',
                'manage_stock',
                'stock_quantity',
                'backorders_allowed',
                'name',
                'slug',
                'type',
                'price',
                'regular_price',
                'sale_price',
                'on_sale',
                'categories',
                'images',
                'locked',
                'hwe_membership',
            ],
            array_keys($data)
        );
        $this->assertCount(1, $data['images']);
        $this->assertTrue($data['locked']);
        $this->assertSame('publish', $data['status']);
        $this->assertTrue($data['purchasable']);
        $this->assertSame('instock', $data['stock_status']);
        $this->assertTrue($data['manage_stock']);
        $this->assertSame(3, $data['stock_quantity']);
        $this->assertTrue($data['backorders_allowed']);
        foreach (['description', 'short_description', 'sku', 'attributes', 'variations', 'gallery', 'meta_data'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data, $hidden . ' no puede salir por el BFF.');
        }

        // El secreto manda aunque la sesión tenga nivel: la petición wc/v3 es «sin usuario».
        $this->currentUser = self::USER;
        $response = new \WP_REST_Response($this->wcProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWcProduct($response, null, new \WP_REST_Request());
        $this->assertArrayNotHasKey('description', $response->get_data());

        // Con el módulo apagado no se recorta nada, ni siquiera en la petición del BFF.
        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();
        $response = new \WP_REST_Response($this->wcProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWcProduct($response, null, new \WP_REST_Request());
        $this->assertArrayHasKey('description', $response->get_data());
    }

    public function testTheBffReorderFieldsSurviveTheTeaser(): void
    {
        $this->bffRequest = true;

        // Respuesta de wc/v3 con los `_fields` exactos que pide «volver a pedir», más dos
        // campos de contenido que deben seguir ocultos.
        $response = new \WP_REST_Response([
            'id'                   => self::PRODUCT,
            'status'               => 'publish',
            'purchasable'          => true,
            'stock_status'         => 'instock',
            'manage_stock'         => true,
            'stock_quantity'       => 3,
            'backorders_allowed'   => true,
            'description'          => '<p>Secreto largo</p>',
            'short_description'    => '<p>Secreto corto</p>',
        ]);

        \HWE_Memberships_Teaser::prepareWcProduct($response, null, new \WP_REST_Request());
        $data = $response->get_data();

        foreach (['status', 'purchasable', 'stock_status', 'manage_stock', 'stock_quantity', 'backorders_allowed'] as $operational) {
            $this->assertArrayHasKey($operational, $data, $operational . ' es operativo y debe conservarse.');
        }
        $this->assertSame('publish', $data['status']);
        $this->assertTrue($data['purchasable']);
        $this->assertSame('instock', $data['stock_status']);
        $this->assertTrue($data['manage_stock']);
        $this->assertSame(3, $data['stock_quantity']);
        $this->assertTrue($data['backorders_allowed']);
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('short_description', $data);
        $this->assertTrue($data['locked']);
        $this->assertArrayHasKey('hwe_membership', $data);
    }

    public function testTheWcRestVariationResponseIsMinimalForTheBff(): void
    {
        $this->bffRequest = true;

        $variation = [
            'id'         => 900,
            'parent_id'  => self::PRODUCT,
            'type'       => 'variation',
            'name'       => 'Producto 501 - Rojo',
            'sku'        => 'SKU-ROJO',
            'price'      => '1000',
            'attributes' => [['name' => 'Color', 'option' => 'Rojo']],
            'image'      => ['src' => 'rojo.jpg'],
        ];

        $response = new \WP_REST_Response($variation);
        \HWE_Memberships_Teaser::prepareWcVariation($response, null, new \WP_REST_Request());

        $this->assertSame(
            [
                'id'             => 900,
                'parent_id'      => self::PRODUCT,
                'type'           => 'variation',
                'locked'         => true,
                'hwe_membership' => [
                    'required_level' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                    'opens_at'       => null,
                ],
            ],
            $response->get_data()
        );

        // Sin padre no se puede evaluar: tampoco sale el contenido de la variación.
        $orphan = \HWE_Memberships_Teaser::trimWcProduct(
            ['id' => 902, 'type' => 'variation', 'name' => 'Huérfana', 'sku' => 'X'],
            0,
            true
        );
        $this->assertSame(['id' => 902, 'type' => 'variation', 'locked' => true], $orphan);
    }

    // ── REST de WordPress (wp/v2/product) ───────────────────────────────────

    public function testWpRestProductDoesNotLeakContentOfALockedProduct(): void
    {
        $data = $this->wpProduct(self::PRODUCT);

        $trimmed = \HWE_Memberships_Teaser::trimWpProduct($data, 0);

        foreach (['content', 'excerpt', 'meta', 'acf', 'yoast_head', 'class_list'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $trimmed, $hidden . ' no puede salir por wp/v2/product.');
        }
        $this->assertSame('Producto 501', $trimmed['title']['rendered']);
        $this->assertSame('producto-501', $trimmed['slug']);
        $this->assertTrue($trimmed['locked']);
        $this->assertSame('nivel-5', $trimmed['hwe_membership']['required_level']['slug']);

        // Con el nivel, o en un producto público, la respuesta no se toca.
        $this->assertSame($data, \HWE_Memberships_Teaser::trimWpProduct($data, self::USER));
        $public = $this->wpProduct(502);
        $this->assertSame($public, \HWE_Memberships_Teaser::trimWpProduct($public, 0));
    }

    public function testPrepareWpProductAppliesTheTrimForTheRequestUserOnly(): void
    {
        $response = new \WP_REST_Response($this->wpProduct(self::PRODUCT));
        $post     = (object) ['ID' => self::PRODUCT];
        $request  = new \WP_REST_Request(['context' => 'view'], [], '/wp/v2/product/501');

        \HWE_Memberships_Teaser::prepareWpProduct($response, $post, $request);
        $this->assertArrayNotHasKey('content', $response->get_data());

        $this->currentUser = self::USER;
        $withLevel = new \WP_REST_Response($this->wpProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWpProduct($withLevel, $post, $request);
        $this->assertArrayHasKey('content', $withLevel->get_data());

        // Módulo apagado: nunca se recorta.
        $this->currentUser   = 0;
        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();
        $off = new \WP_REST_Response($this->wpProduct(self::PRODUCT));
        \HWE_Memberships_Teaser::prepareWpProduct($off, $post, $request);
        $this->assertArrayHasKey('content', $off->get_data());
    }

    // ── Búsqueda: sin oráculo sobre el contenido bloqueado ──────────────────

    public function testSearchColumnsAreReducedToTheTitleWhenThereAreLockedProducts(): void
    {
        $this->postMetaRankExists = true;
        $columns = ['post_title', 'post_excerpt', 'post_content'];
        $query   = (object) ['query_vars' => ['post_type' => 'product']];

        $this->assertSame(['post_title'], \HWE_Memberships_Teaser::searchColumns($columns, 'secreto', $query));

        // Una consulta de varios tipos que incluye producto también se reduce.
        $any = (object) ['query_vars' => ['post_type' => ['post', 'product']]];
        $this->assertSame(['post_title'], \HWE_Memberships_Teaser::searchColumns($columns, 'secreto', $any));
        $all = (object) ['query_vars' => ['post_type' => 'any']];
        $this->assertSame(['post_title'], \HWE_Memberships_Teaser::searchColumns($columns, 'secreto', $all));

        // Las consultas que no son de producto conservan sus columnas.
        $post = (object) ['query_vars' => ['post_type' => 'post']];
        $this->assertSame($columns, \HWE_Memberships_Teaser::searchColumns($columns, 'secreto', $post));
    }

    public function testSearchColumnsAreUntouchedWithoutLockedProductsOrWithTheModuleOff(): void
    {
        $columns = ['post_title', 'post_excerpt', 'post_content'];
        $query   = (object) ['query_vars' => ['post_type' => 'product']];

        $this->postMetaRankExists = false;
        \HWE_Memberships_Access::flush();
        $this->assertSame($columns, \HWE_Memberships_Teaser::searchColumns($columns, 'x', $query), 'Sin requisitos, la búsqueda sigue igual.');

        $this->postMetaRankExists = true;
        $this->activePlugins      = [];
        \HWE_Memberships_Access::flush();
        $this->assertSame($columns, \HWE_Memberships_Teaser::searchColumns($columns, 'x', $query), 'Con el módulo apagado, igual.');
    }

    public function testTheGraphqlProductSearchGoesThroughTheRestrictedWpSearch(): void
    {
        $this->postMetaRankExists = true;

        $args = \HWE_Memberships_Teaser::graphqlSearchArgs(['post_type' => 'product', 'search' => 'secreto', 'orderby' => 'relevance']);

        $this->assertArrayNotHasKey('search', $args, 'WooGraphQL compararía contra la descripción.');
        $this->assertSame('secreto', $args['s']);

        $noSearch = ['post_type' => 'product'];
        $this->assertSame($noSearch, \HWE_Memberships_Teaser::graphqlSearchArgs($noSearch));

        $this->postMetaRankExists = false;
        \HWE_Memberships_Access::flush();
        $plain = ['post_type' => 'product', 'search' => 'secreto'];
        $this->assertSame($plain, \HWE_Memberships_Teaser::graphqlSearchArgs($plain));
    }

    // ── Hooks ───────────────────────────────────────────────────────────────

    public function testRegisterHooksTheRestFiltersOnlyWhenTheModuleIsActive(): void
    {
        Filters\expectAdded('rest_request_after_callbacks')
            ->once()
            ->with([\HWE_Memberships_Teaser::class, 'filterRestResponse'], 999, 3);
        Filters\expectAdded('woocommerce_rest_prepare_product_object')
            ->once()
            ->with([\HWE_Memberships_Teaser::class, 'prepareWcProduct'], 999, 3);
        Filters\expectAdded('woocommerce_rest_prepare_product_variation_object')
            ->once()
            ->with([\HWE_Memberships_Teaser::class, 'prepareWcVariation'], 999, 3);

        \HWE_Memberships_Teaser::register();

        $this->activePlugins = [];
        Filters\expectAdded('rest_request_after_callbacks')->never();
        Filters\expectAdded('woocommerce_rest_prepare_product_object')->never();
        Filters\expectAdded('woocommerce_rest_prepare_product_variation_object')->never();
        \HWE_Memberships_Teaser::register();
    }

    // ── Datos de prueba ─────────────────────────────────────────────────────

    /** @return array<string,mixed> Respuesta de `wp/v2/product`. */
    private function wpProduct(int $id): array
    {
        return [
            'id'             => $id,
            'date'           => '2026-01-01T00:00:00',
            'slug'           => 'producto-' . $id,
            'status'         => 'publish',
            'type'           => 'product',
            'link'           => 'https://tienda.test/producto-' . $id,
            'title'          => ['rendered' => 'Producto ' . $id],
            'content'        => ['rendered' => '<p>Secreto largo</p>', 'protected' => false],
            'excerpt'        => ['rendered' => '<p>Secreto corto</p>', 'protected' => false],
            'featured_media' => 11,
            'meta'           => ['_secreto' => 'x'],
            'acf'            => ['campo' => 'secreto'],
            'yoast_head'     => '<meta name="description" content="Secreto">',
            'class_list'     => ['post-' . $id],
            'product_cat'    => [30],
            '_links'         => ['self' => [['href' => 'https://tienda.test/wp-json/wp/v2/product/' . $id]]],
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function storeProduct(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'                => $id,
            'name'              => 'Producto ' . $id,
            'slug'              => 'producto-' . $id,
            'type'              => 'simple',
            'permalink'         => 'https://tienda.test/producto-' . $id,
            'status'            => 'publish',
            'purchasable'       => true,
            'manage_stock'      => true,
            'backorders_allowed' => true,
            'description'       => '<p>Secreto largo</p>',
            'short_description' => '<p>Secreto corto</p>',
            'sku'               => 'SKU-' . $id,
            'prices'            => [
                'price'               => 1000,
                'regular_price'       => 1200,
                'sale_price'          => 1000,
                'currency_minor_unit' => 2,
            ],
            'images'            => [
                ['id' => 1, 'src' => 'main.jpg', 'thumbnail' => 'main-thumb.jpg', 'alt' => 'Principal'],
                ['id' => 2, 'src' => 'gallery.jpg', 'thumbnail' => 'gallery-thumb.jpg', 'alt' => 'Galería'],
            ],
            'categories'        => [['id' => 30, 'name' => 'Cat', 'slug' => 'cat']],
            'attributes'        => [['id' => 1, 'name' => 'Color', 'terms' => ['Rojo']]],
            'variations'        => [['id' => 900, 'attributes' => [['name' => 'Color', 'value' => 'Rojo']]]],
            'stock_status'      => 'instock',
            'stock_quantity'    => 3,
            'has_options'       => true,
            'is_purchasable'    => true,
            'extensions'        => [],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function wcProduct(int $id): array
    {
        return [
            'id'                => $id,
            'name'              => 'Producto ' . $id,
            'slug'              => 'producto-' . $id,
            'type'              => 'simple',
            'status'            => 'publish',
            'purchasable'       => true,
            'manage_stock'      => true,
            'backorders_allowed' => true,
            'permalink'         => 'https://tienda.test/producto-' . $id,
            'description'       => '<p>Secreto largo</p>',
            'short_description' => '<p>Secreto corto</p>',
            'sku'               => 'SKU-' . $id,
            'price'             => '10.00',
            'regular_price'     => '12.00',
            'sale_price'        => '10.00',
            'price_html'        => '<span>10,00 €</span>',
            'on_sale'           => true,
            'categories'        => [['id' => 30, 'name' => 'Cat', 'slug' => 'cat']],
            'images'            => [
                ['id' => 1, 'src' => 'main.jpg', 'alt' => 'Principal'],
                ['id' => 2, 'src' => 'gallery.jpg', 'alt' => 'Galería'],
            ],
            'attributes'        => [['id' => 1, 'name' => 'Color', 'options' => ['Rojo']]],
            'variations'        => [900],
            'meta_data'         => [['id' => 1, 'key' => '_secreto', 'value' => 'x']],
            'stock_status'      => 'instock',
            'stock_quantity'    => 3,
        ];
    }
}
