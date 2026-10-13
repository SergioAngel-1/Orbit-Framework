<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ContentI18n;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Nodo de WPGraphQL de un post (o producto): lleva `databaseId`. */
class FakeNode50
{
    public int $databaseId = 0;
    public int $ID = 0;
}

/** Nodo de WPGraphQL de un término. */
class FakeTermNode50
{
    public int $term_id = 0;
    public int $databaseId = 0;
}

/**
 * `$wpdb` simulado: registra las consultas y responde por fragmento de SQL.
 */
class FakeWpdb50
{
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';

    /** @var list<string> */
    public array $queries = [];

    /** @var array<string,mixed> fragmento de SQL => valor devuelto por get_var */
    public array $vars = [];

    public function prepare(string $sql, mixed ...$args): string
    {
        $flat = [];
        foreach ($args as $arg) {
            foreach (is_array($arg) ? $arg : [$arg] as $item) {
                $flat[] = $item;
            }
        }
        $i = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use ($flat, &$i): string {
            $value = $flat[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $sql);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;
        foreach ($this->vars as $fragment => $value) {
            if (str_contains($sql, $fragment)) {
                return $value;
            }
        }
        return null;
    }
}

/**
 * `hwe-content-i18n`: traducciones de contenido a N idiomas.
 *
 * Metas `_hwe_<campo>_<idioma>` de post y de término (las `_en` de instalaciones previas valen tal cual),
 * registro `hwe_translatable_fields`, guardado desde la caja «Traducciones», GraphQL
 * (`translation(locale)` y `hweSlugFrom`), búsqueda por los textos traducidos y las metas
 * en la higiene de espacios. Con un solo idioma no registra nada.
 */
final class ContentI18nTest extends TestCase
{
    /** @var array<string,string> */
    private array $meta = [];
    /** @var list<array{string,string,array}> */
    private array $fields = [];
    /** @var list<array{string,array}> */
    private array $objectTypes = [];
    /** @var list<string> */
    private array $locales = ['en', 'fr'];
    /** @var list<array{string,string,string,string}> */
    private array $metaBoxes = [];
    private FakeWpdb50 $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = [];
        $this->fields = [];
        $this->objectTypes = [];
        $this->metaBoxes = [];
        $this->locales = ['en', 'fr'];
        $GLOBALS['hwe_i18n_logged'] = [];

        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'wp_slash' => static fn ($v) => is_string($v) ? addslashes($v) : $v,
            'wp_verify_nonce' => static fn ($nonce) => $nonce === 'valido',
            'current_user_can' => static fn () => true,
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'sanitize_textarea_field' => static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '',
            'wp_kses_post' => static fn ($v) => is_string($v) ? strip_tags($v, '<p><a><strong><em><ul><li>') : '',
            'sanitize_title' => static fn ($v) => trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $v)), '-'),
            'esc_attr' => static fn ($v) => (string) $v,
            'esc_html' => static fn ($v) => (string) $v,
            'esc_textarea' => static fn ($v) => (string) $v,
            'wp_nonce_field' => static fn ($action, $name) => '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">',
            '__' => static fn ($v) => $v,
            'wp_editor' => static fn () => null,
            'set_transient' => static fn () => true,
            'get_transient' => static fn () => false,
            'delete_transient' => static fn () => true,
            'is_admin' => static fn () => false,
            'get_current_user_id' => static fn () => 1,
            'esc_attr__' => static fn ($v) => $v,
            'esc_html__' => static fn ($v) => $v,
        ]);

        Functions\when('update_post_meta')->alias(function (int $id, string $key, mixed $value): bool {
            $this->meta["post:{$id}:{$key}"] = (string) $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta["post:{$id}:{$key}"]);
            return true;
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key): string => $this->meta["post:{$id}:{$key}"] ?? '');
        Functions\when('update_term_meta')->alias(function (int $id, string $key, mixed $value): bool {
            $this->meta["term:{$id}:{$key}"] = (string) $value;
            return true;
        });
        Functions\when('delete_term_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta["term:{$id}:{$key}"]);
            return true;
        });
        Functions\when('get_term_meta')->alias(fn (int $id, string $key): string => $this->meta["term:{$id}:{$key}"] ?? '');
        Functions\when('register_graphql_field')->alias(function (string $type, string $name, array $config): bool {
            $this->fields[] = [$type, $name, $config];
            return true;
        });
        Functions\when('register_graphql_object_type')->alias(function (string $name, array $config): void {
            $this->objectTypes[] = [$name, $config];
        });
        Functions\when('add_meta_box')->alias(function (string $id, string $title, $cb, string $screen, string $context): void {
            $this->metaBoxes[] = [$id, $title, $screen, $context];
        });

        $this->wpdb = new FakeWpdb50();
        $GLOBALS['wpdb'] = $this->wpdb;

        // Los idiomas secundarios del sitio (en producción salen de `i18n.locales`).
        Filters\expectApplied('hwe_content_i18n_locales')->andReturnUsing(fn () => $this->locales);

        $this->loadOwnFile('mu-plugins/hwe-content-i18n.php');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function post(int $id, string $type = 'post', string $name = 'mi-entrada'): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = $type;
        $post->post_name = $name;
        return $post;
    }

    private function node(int $id): FakeNode50
    {
        $node = new FakeNode50();
        $node->databaseId = $id;
        $node->ID = $id;
        return $node;
    }

    /** @return callable */
    private function resolverOf(string $type, string $field): callable
    {
        foreach ($this->fields as [$t, $name, $config]) {
            if ($t === $type && $name === $field) {
                return $config['resolve'];
            }
        }
        $this->fail("No se registró {$type}.{$field} en GraphQL.");
    }

    // ── idiomas y claves ────────────────────────────────────────────────────

    public function testLocalesAreNormalizedToTheMetaSuffix(): void
    {
        $this->locales = ['en', 'pt-BR', 'EN', ''];

        $this->assertSame(['en', 'pt_br'], hwe_i18n_locales());
    }

    public function testMetaKeyIsHweFieldLocale(): void
    {
        $this->assertSame('_hwe_title_en', hwe_i18n_meta_key('title', 'en'));
        $this->assertSame('_hwe_seo_description_pt_br', hwe_i18n_meta_key('seo_description', 'pt_br'));
    }

    public function testTheCoreDeclaresTheFieldsOfTheSpec(): void
    {
        $registry = hwe_i18n_registry();

        $this->assertSame(
            ['title', 'excerpt', 'content', 'seo_title', 'seo_description', 'slug'],
            array_keys($registry['post']['fields'])
        );
        $this->assertSame(['title', 'content', 'seo_title', 'seo_description'], array_keys($registry['page']['fields']));
        $this->assertSame(
            ['name', 'short_description', 'description', 'seo_title', 'seo_description', 'slug'],
            array_keys($registry['product']['fields'])
        );
        $this->assertSame(['name', 'description', 'seo_title', 'seo_description', 'slug'], array_keys($registry['product_cat']['fields']));
        $this->assertSame(['name', 'description'], array_keys($registry['category']['fields']));
        $this->assertSame(['name'], array_keys($registry['post_tag']['fields']));
    }

    public function testAnInstanceCanDeclareFieldsForItsOwnType(): void
    {
        Functions\when('error_log')->justReturn(true);
        Filters\expectApplied('hwe_translatable_fields')->andReturnUsing(static function (array $core): array {
            $core['course'] = [
                'kind' => 'post',
                'graphql' => 'Course',
                'fields' => [
                    'title' => ['label' => 'Título', 'input' => 'text'],
                    'syllabus' => ['label' => 'Temario', 'input' => 'html'],
                    'Bad Name' => ['label' => 'Roto', 'input' => 'text'],
                    'weird' => ['label' => 'Raro', 'input' => 'nope'],
                ],
            ];
            return $core;
        });

        $fields = hwe_i18n_fields_for('course');

        $this->assertSame(['title', 'syllabus'], array_keys($fields));
    }

    public function testDiscardedFieldsAreLoggedWithTheirType(): void
    {
        Functions\expect('error_log')->atLeast()->once()->with(\Mockery::on(
            static fn (string $m): bool => str_contains($m, '[hwe] content-i18n') && str_contains($m, 'Bad Name') && str_contains($m, 'course')
        ));
        Filters\expectApplied('hwe_translatable_fields')->andReturnUsing(static function (array $core): array {
            $core['course'] = ['kind' => 'post', 'fields' => ['Bad Name' => ['label' => 'x', 'input' => 'text']]];
            return $core;
        });

        hwe_i18n_registry();
    }

    public function testAnUnknownTypeHasNoFields(): void
    {
        $this->assertSame([], hwe_i18n_fields_for('no-existe'));
    }

    // ── compatibilidad con las claves `_en` ya guardadas ──────────────────────────

    public function testItReadsTheMetasStoredByPreviousInstallations(): void
    {
        $this->meta['post:9:_hwe_title_en'] = 'Hello';
        $this->meta['post:9:_hwe_seo_description_en'] = 'Desc';
        $this->meta['post:9:_hwe_slug_en'] = 'hello';

        $this->assertSame('Hello', hwe_i18n_get('post', 9, 'title', 'en'));
        $this->assertSame('Desc', hwe_i18n_get('post', 9, 'seo_description', 'en'));
        $this->assertSame('hello', hwe_i18n_get('post', 9, 'slug', 'en'));
        $this->assertSame('', hwe_i18n_get('post', 9, 'title', 'fr'));
    }

    // ── guardado ────────────────────────────────────────────────────────────

    public function testSaveWritesOneMetaPerLocaleAndField(): void
    {
        $_POST = [
            'hwe_i18n_nonce' => 'valido',
            'hwe_i18n' => [
                'en' => ['title' => 'Hello', 'excerpt' => 'Short', 'content' => '<p>Body</p>', 'seo_title' => 'SEO'],
                'fr' => ['title' => 'Bonjour'],
            ],
        ];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame('Hello', $this->meta['post:11:_hwe_title_en']);
        $this->assertSame('Short', $this->meta['post:11:_hwe_excerpt_en']);
        $this->assertSame('<p>Body</p>', $this->meta['post:11:_hwe_content_en']);
        $this->assertSame('SEO', $this->meta['post:11:_hwe_seo_title_en']);
        $this->assertSame('Bonjour', $this->meta['post:11:_hwe_title_fr']);
    }

    public function testEmptyValuesDeleteTheMeta(): void
    {
        $this->meta['post:11:_hwe_title_en'] = 'Viejo';
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['title' => '   ']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertArrayNotHasKey('post:11:_hwe_title_en', $this->meta);
    }

    public function testSaveWithoutValidNonceWritesNothing(): void
    {
        $_POST = ['hwe_i18n' => ['en' => ['title' => 'X']]];
        hwe_i18n_save_post(11, $this->post(11));

        $_POST = ['hwe_i18n_nonce' => 'otro', 'hwe_i18n' => ['en' => ['title' => 'X']]];
        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame([], $this->meta);
    }

    public function testSaveWithoutCapabilityWritesNothing(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['title' => 'X']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame([], $this->meta);
    }

    public function testALocaleThatIsNotConfiguredIsNeverWritten(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['de' => ['title' => 'Hallo'], '../x' => ['title' => 'Y']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame([], $this->meta);
    }

    public function testAFieldThatTheTypeDoesNotDeclareIsNeverWritten(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['slug' => 'x', 'excerpt' => 'no']]];

        // Una página no declara ni `excerpt` ni `slug`.
        hwe_i18n_save_post(12, $this->post(12, 'page', 'contacto'));

        $this->assertSame([], $this->meta);
    }

    public function testATypeWithoutRegistrationIsIgnored(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['title' => 'X']]];

        hwe_i18n_save_post(13, $this->post(13, 'attachment'));

        $this->assertSame([], $this->meta);
    }

    public function testBackslashesSurviveTheUnslashingOfUpdateMeta(): void
    {
        // `update_post_meta` hace `wp_unslash` del valor: hay que entregarle datos con barras.
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['title' => 'C:\\ruta']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame(addslashes('C:\\ruta'), $this->meta['post:11:_hwe_title_en']);
    }

    public function testTextIsSanitizedByInputKind(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => [
            'title' => '<script>alert(1)</script>Hi',
            'content' => '<p>Ok</p><script>x</script>',
            'seo_title' => str_repeat('a', 90),
        ]]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame('alert(1)Hi', $this->meta['post:11:_hwe_title_en']);
        $this->assertSame('<p>Ok</p>x', $this->meta['post:11:_hwe_content_en']);
        $this->assertSame(60, mb_strlen($this->meta['post:11:_hwe_seo_title_en']));
    }

    // ── slug ────────────────────────────────────────────────────────────────

    public function testASlugEqualToTheNativeOneIsNotATranslation(): void
    {
        $this->meta['post:11:_hwe_slug_en'] = 'anterior';
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['slug' => 'mi-entrada']]];

        hwe_i18n_save_post(11, $this->post(11, 'post', 'mi-entrada'));

        $this->assertArrayNotHasKey('post:11:_hwe_slug_en', $this->meta);
    }

    public function testASlugThatIsAnotherItemsNativeSlugIsRejectedAndTheOldOneKept(): void
    {
        $this->meta['post:11:_hwe_slug_en'] = 'publicado';
        $this->wpdb->vars['post_name = '] = '42';
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['slug' => 'ocupado']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame('publicado', $this->meta['post:11:_hwe_slug_en']);
    }

    public function testASlugThatIsAnotherItemsTranslatedSlugInAnyLocaleIsRejected(): void
    {
        $this->wpdb->vars['LIKE'] = '77';
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['fr' => ['slug' => 'pris']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertArrayNotHasKey('post:11:_hwe_slug_fr', $this->meta);
        // Mira las metas de slug de TODOS los idiomas (LIKE), no solo la del idioma que se guarda.
        $this->assertCount(2, $this->wpdb->queries);
        $this->assertStringContainsString('pm.meta_key LIKE', $this->wpdb->queries[1]);
    }

    public function testAFreeSlugIsStoredSanitized(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['slug' => 'My New Slug']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame('my-new-slug', $this->meta['post:11:_hwe_slug_en']);
    }

    public function testTheSameItemMayShareASlugAcrossLocales(): void
    {
        $_POST = ['hwe_i18n_nonce' => 'valido', 'hwe_i18n' => ['en' => ['slug' => 'yoga'], 'fr' => ['slug' => 'yoga']]];

        hwe_i18n_save_post(11, $this->post(11));

        $this->assertSame('yoga', $this->meta['post:11:_hwe_slug_en']);
        $this->assertSame('yoga', $this->meta['post:11:_hwe_slug_fr']);
    }

    // ── términos ────────────────────────────────────────────────────────────

    public function testTermSaveWritesTheMetasAndKeepsBackslashes(): void
    {
        Functions\when('error_log')->justReturn(true); // «HWE_REVALIDATION_SECRET no definido» del núcleo.
        $_POST = ['hwe_i18n_term_nonce' => 'valido', 'hwe_i18n' => ['en' => ['name' => 'Self love', 'description' => '<p>A\\B</p>', 'slug' => 'self-love']]];
        Functions\when('get_term')->alias(function (int $id, string $taxonomy) {
            $term = new \WP_Term();
            $term->term_id = $id;
            $term->taxonomy = $taxonomy;
            $term->slug = 'amor-propio';
            return $term;
        });

        hwe_i18n_save_term(5, 'product_cat');

        $this->assertSame('Self love', $this->meta['term:5:_hwe_name_en']);
        $this->assertSame(addslashes('<p>A\\B</p>'), $this->meta['term:5:_hwe_description_en']);
        $this->assertSame('self-love', $this->meta['term:5:_hwe_slug_en']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSavingAProductCategoryTranslationRevalidatesTheProductsTag(): void
    {
        define('HWE_REVALIDATION_SECRET', 'secreto-de-prueba');
        Functions\when('error_log')->justReturn(true);
        // El filtro del núcleo (Brain Monkey no ejecuta los callbacks añadidos con add_filter).
        Filters\expectApplied('hwe_revalidation_tags')->andReturnUsing(
            static fn (array $core): array => hwe_i18n_revalidation_tags($core)
        );
        Functions\when('get_term')->alias(function (int $id, string $taxonomy) {
            $term = new \WP_Term();
            $term->term_id = $id;
            $term->taxonomy = $taxonomy;
            $term->slug = 'amor-propio';
            return $term;
        });
        Functions\expect('wp_remote_post')->once()->with(
            \Mockery::type('string'),
            \Mockery::on(static fn (array $args): bool => str_contains((string) $args['body'], '"tag":"products"'))
        );
        $_POST = ['hwe_i18n_term_nonce' => 'valido', 'hwe_i18n' => ['en' => ['name' => 'Self love']]];

        hwe_i18n_save_term(5, 'product_cat');

        $this->assertSame('Self love', $this->meta['term:5:_hwe_name_en']);
    }

    public function testTermSaveNeedsItsOwnNonceAndTheCapabilityOfTheTerm(): void
    {
        $_POST = ['hwe_i18n' => ['en' => ['name' => 'X']]];
        hwe_i18n_save_term(5, 'post_tag');
        $this->assertSame([], $this->meta);

        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['hwe_i18n_term_nonce' => 'valido', 'hwe_i18n' => ['en' => ['name' => 'X']]];
        hwe_i18n_save_term(5, 'post_tag');
        $this->assertSame([], $this->meta);
    }

    public function testATagHasNoTranslatableSlug(): void
    {
        $_POST = ['hwe_i18n_term_nonce' => 'valido', 'hwe_i18n' => ['en' => ['name' => 'Anxiety', 'slug' => 'anxiety']]];

        hwe_i18n_save_term(8, 'post_tag');

        $this->assertSame('Anxiety', $this->meta['term:8:_hwe_name_en']);
        $this->assertArrayNotHasKey('term:8:_hwe_slug_en', $this->meta);
    }

    // ── GraphQL ─────────────────────────────────────────────────────────────

    public function testItRegistersTranslationOnEveryAffectedType(): void
    {
        hwe_i18n_register_graphql();

        $types = array_map(static fn (array $f): string => $f[0], array_filter($this->fields, static fn (array $f): bool => $f[1] === 'translation'));
        sort($types);

        $this->assertSame(['Category', 'Page', 'Post', 'Product', 'ProductCategory', 'Tag'], $types);
    }

    public function testTheTranslationObjectTypeListstheFieldsOfTheRegistry(): void
    {
        hwe_i18n_register_graphql();

        $byName = [];
        foreach ($this->objectTypes as [$name, $config]) {
            $byName[$name] = array_keys($config['fields']);
        }

        $this->assertSame(['title', 'excerpt', 'content', 'seoTitle', 'seoDescription', 'slug'], $byName['HwePostTranslation']);
        $this->assertSame(['name', 'shortDescription', 'description', 'seoTitle', 'seoDescription', 'slug'], $byName['HweProductTranslation']);
    }

    public function testTranslationResolvesTheValuesOfThatLocaleWithNullsForTheEmptyOnes(): void
    {
        $this->meta['post:9:_hwe_title_fr'] = 'Bonjour';
        $this->meta['post:9:_hwe_seo_title_fr'] = 'SEO fr';
        hwe_i18n_register_graphql();

        $resolve = $this->resolverOf('Post', 'translation');
        $result = $resolve($this->node(9), ['locale' => 'fr']);

        $this->assertSame('Bonjour', $result['title']);
        $this->assertSame('SEO fr', $result['seoTitle']);
        $this->assertNull($result['excerpt']);
        $this->assertNull($result['slug']);
    }

    public function testTranslationNormalizesTheLocaleArgument(): void
    {
        $this->locales = ['pt-BR'];
        $this->meta['post:9:_hwe_title_pt_br'] = 'Olá';
        hwe_i18n_register_graphql();

        $result = $this->resolverOf('Post', 'translation')($this->node(9), ['locale' => 'pt-BR']);

        $this->assertSame('Olá', $result['title']);
    }

    public function testTranslationIsNullForALocaleThatIsNotSecondary(): void
    {
        $this->meta['post:9:_hwe_title_de'] = 'Hallo';
        hwe_i18n_register_graphql();

        $resolve = $this->resolverOf('Post', 'translation');

        $this->assertNull($resolve($this->node(9), ['locale' => 'de']));
        $this->assertNull($resolve($this->node(9), ['locale' => '']));
    }

    public function testTranslationOfATermReadsTermMeta(): void
    {
        $this->meta['term:5:_hwe_name_en'] = 'Self love';
        hwe_i18n_register_graphql();

        $term = new FakeTermNode50();
        $term->term_id = 5;
        $term->databaseId = 5;
        $result = $this->resolverOf('ProductCategory', 'translation')($term, ['locale' => 'en']);

        $this->assertSame('Self love', $result['name']);
    }

    public function testSlugFromFindsThePublishedNativeSlug(): void
    {
        $this->wpdb->vars["post_status = 'publish'"] = 'mi-entrada';
        hwe_i18n_register_graphql();

        $resolve = $this->resolverOf('RootQuery', 'hweSlugFrom');
        $native = $resolve(null, ['postType' => 'post', 'locale' => 'en', 'slug' => 'my-entry']);

        $this->assertSame('mi-entrada', $native);
        $this->assertStringContainsString("'_hwe_slug_en'", $this->wpdb->queries[0]);
        $this->assertStringContainsString("'my-entry'", $this->wpdb->queries[0]);
    }

    public function testSlugFromRejectsTypesWithoutTranslatableSlugAndUnknownLocales(): void
    {
        hwe_i18n_register_graphql();
        $resolve = $this->resolverOf('RootQuery', 'hweSlugFrom');

        $this->assertNull($resolve(null, ['postType' => 'page', 'locale' => 'en', 'slug' => 'x']));
        $this->assertNull($resolve(null, ['postType' => 'post', 'locale' => 'de', 'slug' => 'x']));
        $this->assertNull($resolve(null, ['postType' => 'post', 'locale' => 'en', 'slug' => '']));
        $this->assertSame([], $this->wpdb->queries);
    }

    public function testWithASingleLanguageNothingIsRegistered(): void
    {
        $this->locales = [];

        hwe_i18n_register_graphql();
        hwe_i18n_register_boxes();

        $this->assertSame([], $this->fields);
        $this->assertSame([], $this->objectTypes);
        $this->assertSame([], $this->metaBoxes);
    }

    // ── caja de admin ───────────────────────────────────────────────────────

    public function testItAddsOneBoxPerTranslatablePostType(): void
    {
        hwe_i18n_register_boxes();

        $screens = array_map(static fn (array $b): string => $b[2], $this->metaBoxes);
        sort($screens);

        $this->assertSame(['page', 'post', 'product'], $screens);
    }

    public function testTheBoxHasOneTabAndOneInputPerLocaleAndField(): void
    {
        $this->meta['post:11:_hwe_title_fr'] = 'Bonjour';

        ob_start();
        hwe_i18n_render_post_box($this->post(11));
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(2, substr_count($html, 'role="tab"'));
        $this->assertStringContainsString('name="hwe_i18n[en][title]"', $html);
        $this->assertStringContainsString('name="hwe_i18n[fr][slug]"', $html);
        $this->assertStringContainsString('value="Bonjour"', $html);
        $this->assertStringContainsString('hwe_i18n_nonce', $html);
        // La entrada declara resumen y la página no.
        ob_start();
        hwe_i18n_render_post_box($this->post(12, 'page', 'p'));
        $page = (string) ob_get_clean();
        $this->assertStringNotContainsString('[excerpt]', $page);
    }

    // ── búsqueda ────────────────────────────────────────────────────────────

    public function testSearchAlsoMatchesTheTranslatedTexts(): void
    {
        $query = new \WP_Query(['s' => 'sleep', 'post_type' => ['post']], true);
        $search = " AND (((wp_posts.post_title LIKE '%sleep%')))";

        $result = hwe_i18n_filter_posts_search($search, $query);

        $this->assertStringStartsWith(' AND ((((wp_posts.post_title LIKE', $result);
        $this->assertStringContainsString('wp_posts.ID IN (', $result);
        foreach (['_hwe_title_en', '_hwe_excerpt_en', '_hwe_content_en', '_hwe_title_fr', '_hwe_content_fr'] as $key) {
            $this->assertStringContainsString("'{$key}'", $result);
        }
        $this->assertStringContainsString("'%sleep%'", $result);
        $this->assertStringNotContainsString('_hwe_seo_title', $result);
    }

    public function testSearchIsLeftAloneOutsideASingleTranslatableType(): void
    {
        $search = " AND (((wp_posts.post_title LIKE '%x%')))";

        $mixed = new \WP_Query(['s' => 'x', 'post_type' => ['post', 'page']], true);
        $unknown = new \WP_Query(['s' => 'x', 'post_type' => ['attachment']], true);
        $notSearch = new \WP_Query(['s' => 'x', 'post_type' => ['post']], false);

        $this->assertSame($search, hwe_i18n_filter_posts_search($search, $mixed));
        $this->assertSame($search, hwe_i18n_filter_posts_search($search, $unknown));
        $this->assertSame($search, hwe_i18n_filter_posts_search($search, $notSearch));
    }

    public function testSearchEscapesLikeWildcardsOfTheTerm(): void
    {
        $query = new \WP_Query(['s' => '100%_x', 'post_type' => ['post']], true);

        $result = hwe_i18n_filter_posts_search(" AND (((wp_posts.post_title LIKE '%100%'))) ", $query);

        $this->assertStringContainsString("'%100\\\\%\\\\_x%'", $result);
    }

    public function testSearchIsLeftAloneWithASingleLanguage(): void
    {
        $this->locales = [];
        $query = new \WP_Query(['s' => 'x', 'post_type' => ['post']], true);
        $search = " AND (((wp_posts.post_title LIKE '%x%')))";

        $this->assertSame($search, hwe_i18n_filter_posts_search($search, $query));
    }

    public function testItDeclaresTheProductsTagAndKeepsTheOthers(): void
    {
        $this->assertSame(['posts', 'products'], hwe_i18n_revalidation_tags(['posts']));
        $this->assertSame(['posts', 'products'], hwe_i18n_revalidation_tags(['posts', 'products']));
    }

    // ── higiene de espacios ─────────────────────────────────────────────────

    public function testTranslatedMetasEnterTheSpaceHygiene(): void
    {
        $keys = hwe_i18n_hygiene_meta_keys(['line' => ['_hwe_seo_title'], 'html' => []]);

        $this->assertContains('_hwe_seo_title', $keys['line']);
        foreach (['_hwe_title_en', '_hwe_name_fr', '_hwe_excerpt_en', '_hwe_seo_description_fr', '_hwe_short_description_en'] as $key) {
            $this->assertContains($key, $keys['line'], $key);
        }
        foreach (['_hwe_content_en', '_hwe_description_fr'] as $key) {
            $this->assertContains($key, $keys['html'], $key);
        }
        $this->assertNotContains('_hwe_slug_en', $keys['line']);
    }

    public function testHygieneKeysAreUntouchedWithASingleLanguage(): void
    {
        $this->locales = [];
        $base = ['line' => ['_hwe_seo_title'], 'html' => []];

        $this->assertSame($base, hwe_i18n_hygiene_meta_keys($base));
    }
}
