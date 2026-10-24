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
 * Plugin `hwe-memberships`, GraphQL de la ficha de escaparate (spec §6.2 y §10.2).
 *
 * El campo `hweMembership` (Product) y `requiredLevel` (ProductCategory) son la parte pública
 * del requisito. El recorte se hace con una lista BLANCA sobre `graphql_resolve_field`: en un
 * producto teaser todo campo que no esté permitido devuelve null, incluidas las traducciones de
 * descripción, los campos de producto del núcleo, la landing, el SEO y las variaciones. Las
 * variaciones directas por id se anulan en la raíz. Con el nivel se devuelve la ficha completa;
 * el personal de la tienda no tiene ningún atajo.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GraphqlTest extends TestCase
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
    /** @var array<int,object> */
    private array $posts = [];
    private int $currentUser = 0;
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
        $this->posts         = [];
        $this->currentUser   = 0;
        $this->logged        = [];

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-benefits-fakes.php';
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Teaser.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Graphql.php');

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
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_post_type')->alias(
            fn (int $id): string => is_object($this->posts[$id] ?? null) ? (string) $this->posts[$id]->post_type : ''
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            '__'         => static fn ($value) => (string) $value,
            'esc_html__' => static fn ($value) => (string) $value,
        ]);
        Functions\when('register_graphql_object_type')->justReturn(null);
        Functions\when('register_graphql_field')->justReturn(null);
    }

    protected function tearDown(): void
    {
        \HWE_Memberships_Access::flush();
        parent::tearDown();
    }

    private function info(string $parentType, string $fieldName): object
    {
        return (object) [
            'parentType' => (object) ['name' => $parentType],
            'fieldName'  => $fieldName,
        ];
    }

    // ── Registro ────────────────────────────────────────────────────────────

    public function testRegisterHooksTheTypeRegistrationAndTheResolveFilter(): void
    {
        Actions\expectAdded('graphql_register_types')
            ->once()
            ->with([\HWE_Memberships_Graphql::class, 'registerTypes'], 20, 1);
        Filters\expectAdded('graphql_resolve_field')
            ->once()
            ->with([\HWE_Memberships_Graphql::class, 'resolveField'], 999, 7);

        \HWE_Memberships_Graphql::register();

        $this->activePlugins = [];
        Actions\expectAdded('graphql_register_types')->never();
        Filters\expectAdded('graphql_resolve_field')->never();
        \HWE_Memberships_Graphql::register();
    }

    public function testRegisterTypesDeclaresTheModuleFieldOnProductAndProductCategory(): void
    {
        /** @var array<string,array<string,mixed>> $types */
        $types = [];
        /** @var list<array{0:string,1:string,2:array<string,mixed>}> $fields */
        $fields = [];
        Functions\when('register_graphql_object_type')->alias(function (string $name, array $config) use (&$types): void {
            $types[$name] = $config;
        });
        Functions\when('register_graphql_field')->alias(function (string $type, string $field, array $config) use (&$fields): void {
            $fields[] = [$type, $field, $config];
        });

        \HWE_Memberships_Graphql::registerTypes();

        $this->assertArrayHasKey('HweMembershipLevel', $types);
        $this->assertSame(['slug', 'rank', 'name'], array_keys($types['HweMembershipLevel']['fields']));
        $this->assertArrayHasKey('HweMembership', $types);
        $this->assertSame(
            ['requiredLevel', 'opensAt', 'locked'],
            array_keys($types['HweMembership']['fields'])
        );

        $registered = [];
        foreach ($fields as $entry) {
            $registered[$entry[0] . '.' . $entry[1]] = $entry[2];
        }
        $this->assertArrayHasKey('Product.hweMembership', $registered);
        $this->assertArrayHasKey('ProductCategory.hweMembership', $registered);
        $this->assertIsCallable($registered['Product.hweMembership']['resolve']);
        $this->assertIsCallable($registered['ProductCategory.hweMembership']['resolve']);

        // Con el módulo apagado no se registra nada.
        $this->activePlugins = [];
        $types  = [];
        $fields = [];
        \HWE_Memberships_Graphql::registerTypes();
        $this->assertSame([], $types);
        $this->assertSame([], $fields);
    }

    // ── Recorte de campos ───────────────────────────────────────────────────

    public function testATeaserProductOnlyExposesTheAllowListedFields(): void
    {
        $product = (object) ['databaseId' => self::PRODUCT, 'ID' => self::PRODUCT];

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Secreto largo',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Secreto corto',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'shortDescription'),
            'SimpleProduct',
            'shortDescription'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'instock',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'stockStatus'),
            'SimpleProduct',
            'stockStatus'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            [['id' => 1]],
            $product,
            [],
            null,
            $this->info('VariableProduct', 'variations'),
            'VariableProduct',
            'variations'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            [['key' => 'x', 'value' => 'secreto']],
            $product,
            [],
            null,
            $this->info('Product', 'hweFields'),
            'Product',
            'hweFields'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            ['title' => 'Landing'],
            $product,
            [],
            null,
            $this->info('Product', 'landing'),
            'Product',
            'landing'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'SEO secreto',
            $product,
            [],
            null,
            $this->info('Product', 'seoDescription'),
            'Product',
            'seoDescription'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            '<span>10 €</span> - En stock',
            $product,
            [],
            null,
            $this->info('Product', 'priceHtml'),
            'Product',
            'priceHtml'
        ));

        // La lista blanca sí sale.
        $this->assertSame('Producto 501', \HWE_Memberships_Graphql::resolveField(
            'Producto 501',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'name'),
            'SimpleProduct',
            'name'
        ));
        $this->assertSame('producto-501', \HWE_Memberships_Graphql::resolveField(
            'producto-501',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'slug'),
            'SimpleProduct',
            'slug'
        ));
        $this->assertSame('10.00', \HWE_Memberships_Graphql::resolveField(
            '10.00',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'price'),
            'SimpleProduct',
            'price'
        ));
        $this->assertSame(['src' => 'main.jpg'], \HWE_Memberships_Graphql::resolveField(
            ['src' => 'main.jpg'],
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'image'),
            'SimpleProduct',
            'image'
        ));
        $this->assertSame(
            ['min' => '10.00', 'max' => '20.00'],
            \HWE_Memberships_Graphql::resolveField(
                ['min' => '10.00', 'max' => '20.00'],
                $product,
                [],
                null,
                $this->info('VariableProduct', 'priceRange'),
                'VariableProduct',
                'priceRange'
            ),
            'El rango de precio de una variable es público.'
        );
    }

    public function testAnUnknownProductSourceFailsClosed(): void
    {
        $unknown = (object) [];

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Secreto',
            $unknown,
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
        $this->assertSame('id-global', \HWE_Memberships_Graphql::resolveField(
            'id-global',
            $unknown,
            [],
            null,
            $this->info('SimpleProduct', 'id'),
            'SimpleProduct',
            'id'
        ));
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testEveryProductNodeIsClippedInListsSearchAndRelatedConnections(): void
    {
        $nodes = [
            'listado'   => (object) ['databaseId' => self::PRODUCT],
            'búsqueda'  => (object) ['databaseId' => self::PRODUCT],
            'relacionado' => (object) ['databaseId' => self::PRODUCT],
            'venta cruzada' => (object) ['databaseId' => self::PRODUCT],
        ];

        foreach ($nodes as $via => $node) {
            $this->assertNull(
                \HWE_Memberships_Graphql::resolveField(
                    'Secreto',
                    $node,
                    [],
                    null,
                    $this->info('SimpleProduct', 'description'),
                    'SimpleProduct',
                    'description'
                ),
                $via . ': la descripción no sale.'
            );
            $this->assertNull(
                \HWE_Memberships_Graphql::resolveField(
                    '10.00',
                    $node,
                    [],
                    null,
                    $this->info('SimpleProduct', 'sku'),
                    'SimpleProduct',
                    'sku'
                ),
                $via . ': el SKU no sale.'
            );
        }

        foreach (['related', 'upsell', 'crossSell'] as $connection) {
            $this->assertNull(\HWE_Memberships_Graphql::resolveField(
                ['nodes' => []],
                (object) ['databaseId' => self::PRODUCT],
                [],
                null,
                $this->info('SimpleProduct', $connection),
                'SimpleProduct',
                $connection
            ));
        }

        // El producto público de la misma conexión conserva sus campos.
        $this->assertSame('Descripción pública', \HWE_Memberships_Graphql::resolveField(
            'Descripción pública',
            (object) ['databaseId' => 502],
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
    }

    public function testAFullMemberSeesTheWholeProduct(): void
    {
        $this->currentUser = self::USER;
        $product = (object) ['databaseId' => self::PRODUCT];

        $this->assertSame('Secreto largo', \HWE_Memberships_Graphql::resolveField(
            'Secreto largo',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
        $this->assertSame([['id' => 1]], \HWE_Memberships_Graphql::resolveField(
            [['id' => 1]],
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'galleryImages'),
            'SimpleProduct',
            'galleryImages'
        ));
    }

    public function testACategoryKeepsItsPublicDescriptionAndExposesItsRequirement(): void
    {
        $category = (object) ['term_id' => 30];

        $this->assertSame('Descripción que invita a hacerse socio', \HWE_Memberships_Graphql::resolveField(
            'Descripción que invita a hacerse socio',
            $category,
            [],
            null,
            $this->info('ProductCategory', 'description'),
            'ProductCategory',
            'description'
        ));
        $this->assertSame(
            [
                'requiredLevel' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                'opensAt'       => null,
                'locked'        => false,
            ],
            \HWE_Memberships_Graphql::resolveCategoryMembership($category)
        );
    }

    public function testTheStoreStaffGetsNoBypassInGraphql(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('user_can')->justReturn(true);
        $this->currentUser = 8;
        $product = (object) ['databaseId' => self::PRODUCT];

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Secreto',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
    }

    public function testTheTranslationKeepsOnlyNameAndSlugAndHidesItsDescription(): void
    {
        $product = (object) ['databaseId' => self::PRODUCT];
        $translation = [
            'name'             => 'Product 501',
            'slug'             => 'product-501',
            'shortDescription' => 'Secret short',
            'description'      => 'Secret long',
            'seoTitle'         => 'Secret seo title',
            'seoDescription'   => 'Secret seo description',
        ];

        $trimmed = \HWE_Memberships_Graphql::resolveField(
            $translation,
            $product,
            ['locale' => 'en'],
            null,
            $this->info('Product', 'translation'),
            'Product',
            'translation'
        );

        $this->assertSame(['name' => 'Product 501', 'slug' => 'product-501'], array_intersect_key(
            $trimmed,
            array_flip(['name', 'slug'])
        ));
        $this->assertArrayNotHasKey('description', $trimmed);
        $this->assertArrayNotHasKey('shortDescription', $trimmed);

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Secret long',
            $trimmed,
            [],
            null,
            $this->info('HweProductTranslation', 'description'),
            'HweProductTranslation',
            'description'
        ));
        $this->assertSame('Product 501', \HWE_Memberships_Graphql::resolveField(
            'Product 501',
            $trimmed,
            [],
            null,
            $this->info('HweProductTranslation', 'name'),
            'HweProductTranslation',
            'name'
        ));
    }

    public function testAVariationDirectlyResolvedIsClippedToItsIdentifier(): void
    {
        $variation = (object) ['databaseId' => 900, 'parentId' => self::PRODUCT];

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Producto 501 - Rojo',
            $variation,
            [],
            null,
            $this->info('ProductVariation', 'name'),
            'ProductVariation',
            'name'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            [['name' => 'Color', 'value' => 'Rojo']],
            $variation,
            [],
            null,
            $this->info('ProductVariation', 'attributes'),
            'ProductVariation',
            'attributes'
        ));
        $this->assertSame(900, \HWE_Memberships_Graphql::resolveField(
            900,
            $variation,
            [],
            null,
            $this->info('ProductVariation', 'databaseId'),
            'ProductVariation',
            'databaseId'
        ));

        // Aunque la variación llegue etiquetada como la interfaz `Product`, no se confunde con
        // el padre: su nombre tampoco sale.
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            'Producto 501 - Rojo',
            $variation,
            [],
            null,
            $this->info('Product', 'name'),
            'Product',
            'name'
        ));

        // El requisito de una variación es el del producto padre.
        $this->assertSame(
            [
                'requiredLevel' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                'opensAt'       => null,
                'locked'        => true,
            ],
            \HWE_Memberships_Graphql::resolveProductMembership($variation)
        );
    }

    public function testTheRootVariationQueryReturnsNullWhenTheParentIsTeaser(): void
    {
        $this->posts[900] = (object) ['ID' => 900, 'post_type' => 'product_variation', 'post_parent' => self::PRODUCT];
        $this->posts[901] = (object) ['ID' => 901, 'post_type' => 'product_variation', 'post_parent' => 502];

        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            (object) ['databaseId' => 900],
            null,
            ['id' => 900],
            null,
            $this->info('RootQuery', 'productVariation'),
            'RootQuery',
            'productVariation'
        ));
        $accessible = (object) ['databaseId' => 901];
        $this->assertSame($accessible, \HWE_Memberships_Graphql::resolveField(
            $accessible,
            null,
            ['id' => 901],
            null,
            $this->info('RootQuery', 'productVariation'),
            'RootQuery',
            'productVariation'
        ));

        // `node(id:)` de una variación: el nodo entero se anula si el padre es teaser.
        $this->assertNull(\HWE_Memberships_Graphql::resolveField(
            (object) ['databaseId' => 900],
            null,
            ['id' => 900],
            null,
            $this->info('RootQuery', 'node'),
            'RootQuery',
            'node'
        ));

        // Un nodo normal no se toca (el recorte de sus campos lo hace la lista blanca).
        $this->posts[501] = (object) ['ID' => 501, 'post_type' => 'product', 'post_parent' => 0];
        $productNode = (object) ['databaseId' => 501];
        $this->assertSame($productNode, \HWE_Memberships_Graphql::resolveField(
            $productNode,
            null,
            ['id' => 501],
            null,
            $this->info('RootQuery', 'node'),
            'RootQuery',
            'node'
        ));
    }

    // ── Campo del módulo ────────────────────────────────────────────────────

    public function testResolveProductMembershipReturnsTheRequirementAndTheLock(): void
    {
        $product = (object) ['databaseId' => self::PRODUCT];

        $this->assertSame(
            [
                'requiredLevel' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                'opensAt'       => null,
                'locked'        => true,
            ],
            \HWE_Memberships_Graphql::resolveProductMembership($product)
        );

        $this->currentUser = self::USER;
        $this->assertSame(
            [
                'requiredLevel' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                'opensAt'       => null,
                'locked'        => false,
            ],
            \HWE_Memberships_Graphql::resolveProductMembership($product)
        );

        $public = (object) ['databaseId' => 502];
        $this->assertSame(
            ['requiredLevel' => null, 'opensAt' => null, 'locked' => false],
            \HWE_Memberships_Graphql::resolveProductMembership($public)
        );
    }

    public function testResolveCategoryMembershipExposesTheInheritedRequirement(): void
    {
        $this->assertSame(
            [
                'requiredLevel' => ['slug' => 'nivel-5', 'rank' => 5, 'name' => 'Nivel 5'],
                'opensAt'       => null,
                'locked'        => false,
            ],
            \HWE_Memberships_Graphql::resolveCategoryMembership((object) ['term_id' => 30])
        );
        $this->assertSame(
            ['requiredLevel' => null, 'opensAt' => null, 'locked' => false],
            \HWE_Memberships_Graphql::resolveCategoryMembership((object) ['term_id' => 40])
        );
    }

    public function testWithTheModuleOffNothingIsClippedAndTheModuleFieldIsNull(): void
    {
        $this->activePlugins = [];
        \HWE_Memberships_Access::flush();
        $product = (object) ['databaseId' => self::PRODUCT];

        $this->assertSame('Secreto', \HWE_Memberships_Graphql::resolveField(
            'Secreto',
            $product,
            [],
            null,
            $this->info('SimpleProduct', 'description'),
            'SimpleProduct',
            'description'
        ));
        $this->assertNull(\HWE_Memberships_Graphql::resolveProductMembership($product));
        $this->assertNull(\HWE_Memberships_Graphql::resolveCategoryMembership((object) ['term_id' => 30]));
    }

    public function testAnOpeningDateTravelsInTheModuleField(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $this->postMeta[502][\HWE_Memberships_Access::OPENS_AT_META] = $future;
        \HWE_Memberships_Access::flush();

        $membership = \HWE_Memberships_Graphql::resolveProductMembership((object) ['databaseId' => 502]);

        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', strtotime($future . ' UTC')), $membership['opensAt']);
        $this->assertTrue($membership['locked']);
        $this->assertNull($membership['requiredLevel']);
    }
}
