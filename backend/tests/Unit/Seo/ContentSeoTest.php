<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Seo;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;

class FakePost28 {
    public int $databaseId = 0;
    public int $ID = 0;
}

class FakeTerm28 {
    public int $term_id = 0;
    public int $databaseId = 0;
}

class FakeFeaturedLoader28 {
    /** @var list<int> */
    public array $loaded = [];

    public function load_deferred(int $id): string {
        $this->loaded[] = $id;
        return 'deferred:' . $id;
    }
}

class FakeFeaturedContext28 extends \WPGraphQL\AppContext {
    public FakeFeaturedLoader28 $loader;

    public function __construct() {
        $this->loader = new FakeFeaturedLoader28();
    }

    public function get_loader(string $name): FakeFeaturedLoader28 {
        return $this->loader;
    }
}

/**
 * Los mu-plugins de SEO por entidad (`hwe-*-seo.php`, `hwe-featured-post.php`).
 *
 * Cada entidad guarda `_hwe_seo_title` / `_hwe_seo_description` desde su caja (con nonce y
 * capacidad; vacío = borrar la meta) y los expone por WPGraphQL (`seoTitle` /
 * `seoDescription`, null si no hay override). Las variantes `_en` NO están aquí: las
 * aporta `hwe-content-i18n` (tarea 50).
 */
final class ContentSeoTest extends TestCase
{
    /** @var array<string,string> */
    private array $meta = [];
    /** @var list<array{string,string,array}> */
    private array $registered = [];
    /** @var array<string,mixed> */
    private array $featuredOption = [];
    /** @var array<string,mixed>|null */
    private ?array $featuredPost = null;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'wp_slash' => static fn ($v) => is_string($v) ? addslashes($v) : $v,
            'wp_verify_nonce' => static fn ($nonce) => $nonce === 'valido',
            'current_user_can' => static fn () => true,
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $v)), '-'),
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            'wp_nonce_field' => static fn ($action, $name) => '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">',
            '__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => $v,
        ]);
        Functions\when('update_post_meta')->alias(function (int $id, string $key, string $value): bool {
            $this->meta["post:{$id}:{$key}"] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta["post:{$id}:{$key}"]);
            return true;
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key): string => $this->meta["post:{$id}:{$key}"] ?? '');
        Functions\when('update_term_meta')->alias(function (int $id, string $key, string $value): bool {
            $this->meta["term:{$id}:{$key}"] = $value;
            return true;
        });
        Functions\when('delete_term_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta["term:{$id}:{$key}"]);
            return true;
        });
        Functions\when('get_term_meta')->alias(fn (int $id, string $key): string => $this->meta["term:{$id}:{$key}"] ?? '');
        Functions\when('register_graphql_field')->alias(function (string $type, string $name, array $config): bool {
            $this->registered[] = [$type, $name, $config];
            return true;
        });
        Functions\when('get_post')->alias(function (int $id): ?object {
            if ($this->featuredPost === null || (int) ($this->featuredPost['ID'] ?? 0) !== $id) {
                return null;
            }
            return (object) $this->featuredPost;
        });

        foreach (['hwe-post-seo.php', 'hwe-page-seo.php', 'hwe-product-seo.php', 'hwe-category-seo.php'] as $file) {
            $this->loadOwnFile('mu-plugins/' . $file);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ── guardado ────────────────────────────────────────────────────────────

    public function testPostSaveWritesTrimmedMetas(): void {
        $_POST = [
            'hwe_post_seo_nonce' => 'valido',
            'hwe_post_seo_title' => '  Título  ',
            'hwe_post_seo_description' => 'Descripción.',
        ];

        hwe_post_seo_save(11);

        $this->assertSame('Título', $this->meta['post:11:_hwe_seo_title']);
        $this->assertSame('Descripción.', $this->meta['post:11:_hwe_seo_description']);
    }

    public function testBackslashesSurviveTheUnslashingOfUpdateMeta(): void {
        // `update_post_meta` / `update_term_meta` hacen `wp_unslash` del valor: hay que
        // entregarles datos con barras o una `\` escrita a mano se pierde en cada guardado.
        $_POST = [
            'hwe_post_seo_nonce' => 'valido',
            'hwe_post_seo_title' => 'C:\\ruta',
            'hwe_category_seo_nonce' => 'valido',
            'hwe_category_seo_title' => 'A\\B',
        ];

        hwe_post_seo_save(11);
        hwe_category_seo_save(5);

        $this->assertSame(addslashes('C:\\ruta'), $this->meta['post:11:_hwe_seo_title']);
        $this->assertSame(addslashes('A\\B'), $this->meta['term:5:_hwe_seo_title']);
    }

    public function testPostSaveDeletesEmptyMetas(): void {
        $this->meta['post:11:_hwe_seo_title'] = 'Viejo';
        $_POST = ['hwe_post_seo_nonce' => 'valido', 'hwe_post_seo_title' => '', 'hwe_post_seo_description' => ''];

        hwe_post_seo_save(11);

        $this->assertArrayNotHasKey('post:11:_hwe_seo_title', $this->meta);
    }

    public function testSaveWithoutNonceWritesNothing(): void {
        $_POST = ['hwe_post_seo_title' => 'X', 'hwe_page_seo_title' => 'X', 'hwe_product_seo_title' => 'X'];

        hwe_post_seo_save(11);
        hwe_page_seo_save(11);
        hwe_product_seo_save(11);

        $this->assertSame([], $this->meta);
    }

    public function testNoindexAndCanonicalAreSavedAndCleared(): void {
        $_POST = [
            'hwe_product_seo_nonce' => 'valido',
            'hwe_product_seo_noindex' => '1',
            'hwe_product_seo_canonical' => ' https://otra.example/principal ',
        ];
        hwe_product_seo_save(22);
        $this->assertSame('1', $this->meta['post:22:_hwe_seo_noindex']);
        $this->assertSame('https://otra.example/principal', $this->meta['post:22:_hwe_seo_canonical']);

        // Casilla sin marcar y URL vacía: se borran las dos metas.
        $_POST = ['hwe_product_seo_nonce' => 'valido'];
        hwe_product_seo_save(22);
        $this->assertArrayNotHasKey('post:22:_hwe_seo_noindex', $this->meta);
        $this->assertArrayNotHasKey('post:22:_hwe_seo_canonical', $this->meta);
    }

    public function testACanonicalThatIsNotAnAbsoluteHttpUrlIsDiscarded(): void {
        foreach (['/ruta-relativa', 'javascript:alert(1)', 'ftp://x.example/a', 'https://'] as $raw) {
            $_POST = ['hwe_category_seo_nonce' => 'valido', 'hwe_category_seo_canonical' => $raw];
            hwe_category_seo_save(5);
            $this->assertArrayNotHasKey('term:5:_hwe_seo_canonical', $this->meta, $raw);
        }
    }

    public function testCategorySaveWritesTermMetas(): void {
        $_POST = [
            'hwe_category_seo_nonce' => 'valido',
            'hwe_category_seo_title' => 'Cat',
            'hwe_category_seo_description' => 'Desc',
        ];

        hwe_category_seo_save(5);

        $this->assertSame('Cat', $this->meta['term:5:_hwe_seo_title']);
        $this->assertSame('Desc', $this->meta['term:5:_hwe_seo_description']);
    }

    public function testPageAndProductSaveUseTheirOwnFieldsAndTheSharedLimits(): void {
        $_POST = [
            'hwe_page_seo_nonce' => 'valido',
            'hwe_page_seo_title' => str_repeat('a', 80),
            'hwe_page_seo_description' => str_repeat('b', 200),
            'hwe_product_seo_nonce' => 'valido',
            'hwe_product_seo_title' => 'Producto',
            'hwe_product_seo_description' => '',
        ];

        hwe_page_seo_save(21);
        hwe_product_seo_save(22);

        $this->assertSame(HWE_SEO_TITLE_MAX, mb_strlen($this->meta['post:21:_hwe_seo_title']));
        $this->assertSame(HWE_SEO_DESC_MAX, mb_strlen($this->meta['post:21:_hwe_seo_description']));
        $this->assertSame('Producto', $this->meta['post:22:_hwe_seo_title']);
        $this->assertArrayNotHasKey('post:22:_hwe_seo_description', $this->meta);
    }

    public function testANonceOfAnotherEntityDoesNotAuthorizeTheSave(): void {
        $_POST = ['hwe_post_seo_nonce' => 'valido', 'hwe_page_seo_title' => 'X'];

        hwe_page_seo_save(21);

        $this->assertSame([], $this->meta);
    }

    public function testEveryEntityRendersTheSameFieldsAndTheCounterOnlyOnce(): void {
        $this->meta['post:11:_hwe_seo_title'] = 'Actual';
        $post = new \WP_Post();
        $post->ID = 11;

        ob_start();
        hwe_post_seo_render_box($post);
        hwe_product_seo_render_box($post);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="hwe_post_seo_title"', $html);
        $this->assertStringContainsString('name="hwe_product_seo_description"', $html);
        $this->assertStringContainsString('value="Actual"', $html);
        $this->assertSame(4, substr_count($html, 'data-hwe-seo-min="'), 'Título y descripción en las dos cajas.');
        $this->assertStringContainsString('name="hwe_post_seo_noindex"', $html);
        $this->assertStringContainsString('name="hwe_product_seo_canonical"', $html);
        $this->assertSame(1, substr_count($html, '<script>'), 'El contador se imprime una sola vez por página.');
    }

    // ── GraphQL ─────────────────────────────────────────────────────────────

    /** @return array<string,array> nombre => config, para el tipo dado */
    private function fieldsOf(string $type): array {
        $out = [];
        foreach ($this->registered as [$registeredType, $name, $config]) {
            if ($registeredType === $type) {
                $out[$name] = $config;
            }
        }
        return $out;
    }

    public function testPostPageAndProductExposeSeoFields(): void {
        hwe_post_seo_register_graphql();
        hwe_page_seo_register_graphql();
        hwe_product_seo_register_graphql();

        foreach (['Post', 'Page', 'Product'] as $type) {
            $fields = $this->fieldsOf($type);
            $this->assertArrayHasKey('seoTitle', $fields, $type);
            $this->assertArrayHasKey('seoDescription', $fields, $type);
            $this->assertSame('String', $fields['seoTitle']['type']);
            $this->assertSame('String', $fields['seoCanonical']['type'], $type);
            $this->assertSame('Boolean', $fields['seoNoindex']['type'], $type);
        }
    }

    public function testNoindexResolvesToABoolean(): void {
        hwe_category_seo_register_graphql();
        $resolve = $this->fieldsOf('ProductCategory')['seoNoindex']['resolve'];
        $term = new FakeTerm28();
        $term->term_id = 5;

        $this->assertFalse($resolve($term));
        $this->meta['term:5:_hwe_seo_noindex'] = '1';
        $this->assertTrue($resolve($term));
    }

    public function testProductExposesAValidGtinOrNull(): void {
        // Sin el método nativo (WooCommerce < 9.2): se lee la meta. Stub explícito porque
        // otro test de la suite puede dejar `wc_get_product` declarada.
        Functions\when('wc_get_product')->justReturn(null);
        hwe_product_seo_register_graphql();
        $resolve = $this->fieldsOf('Product')['hweGtin']['resolve'];
        $product = new FakePost28();
        $product->databaseId = 22;

        $this->assertNull($resolve($product), 'Sin código.');

        $this->meta['post:22:_global_unique_id'] = '84-1234567890-3';
        $this->assertSame('8412345678903', $resolve($product), 'Solo dígitos.');

        $this->meta['post:22:_global_unique_id'] = '12345';
        $this->assertNull($resolve($product), 'Un largo que el estándar no admite no se declara.');

        // Con WooCommerce 9.2+, manda el campo nativo.
        Functions\when('wc_get_product')->justReturn(new class {
            public function get_global_unique_id(): string { return '012345678905'; }
        });
        $this->assertSame('012345678905', $resolve($product));
    }

    public function testCategoryExposesSeoFieldsWithoutEnglishVariants(): void {
        hwe_category_seo_register_graphql();

        $fields = $this->fieldsOf('ProductCategory');
        $this->assertArrayHasKey('seoTitle', $fields);
        $this->assertArrayHasKey('seoDescription', $fields);
        $this->assertArrayNotHasKey('seoTitleEn', $fields, 'Las variantes _en las aporta hwe-content-i18n (tarea 50).');
        $this->assertArrayNotHasKey('seoDescriptionEn', $fields);
    }

    public function testResolversReturnNullWithoutOverride(): void {
        hwe_post_seo_register_graphql();
        hwe_category_seo_register_graphql();

        $post = new FakePost28();
        $post->databaseId = 11;
        $postFields = $this->fieldsOf('Post');
        $this->assertNull($postFields['seoTitle']['resolve']($post));

        $this->meta['post:11:_hwe_seo_title'] = 'Override';
        $this->assertSame('Override', $postFields['seoTitle']['resolve']($post));

        $term = new FakeTerm28();
        $term->term_id = 5;
        $catFields = $this->fieldsOf('ProductCategory');
        $this->assertNull($catFields['seoDescription']['resolve']($term));
    }

    public function testFeaturedPostResolverFallsBackToNull(): void {
        $this->loadOwnFile('mu-plugins/hwe-featured-post.php');
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->featuredOption[$key] ?? $default);
        hwe_featured_post_register_graphql();

        $fields = $this->fieldsOf('RootQuery');
        $this->assertArrayHasKey('hweFeaturedPost', $fields);
        $this->assertSame('Post', $fields['hweFeaturedPost']['type']);
        $resolve = $fields['hweFeaturedPost']['resolve'];

        // Sin selección: null (el frontend usa el más reciente).
        $this->featuredOption = [];
        $context = new FakeFeaturedContext28();
        $this->assertNull($resolve(null, [], $context));
        $this->assertSame([], $context->loader->loaded);

        // Selección válida y publicada: carga diferida.
        $this->featuredOption = ['hwe_featured_post_id' => 42];
        $this->featuredPost = ['ID' => 42, 'post_type' => 'post', 'post_status' => 'publish'];
        $this->assertSame('deferred:42', $resolve(null, [], $context));

        // Existe pero ya no está publicado: null.
        $this->featuredPost = ['ID' => 42, 'post_type' => 'post', 'post_status' => 'draft'];
        $this->assertNull($resolve(null, [], new FakeFeaturedContext28()));
    }
}
