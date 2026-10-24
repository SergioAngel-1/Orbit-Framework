<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Niveles del módulo `memberships` (`HWE_Memberships_Levels`): CPT no público con UI, rango
 * entero único entre publicados, lectura ordenada para el resto del módulo, contrato de campos
 * (incluidas las traducciones) y protección de los niveles que ya tienen socios.
 *
 * Se prueba la lógica, no la pantalla: qué devuelve la lectura, qué se rechaza al guardar con su
 * aviso, y que la comprobación de socios consulta `grants` (activas o programadas) sin escribir.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LevelsTest extends TestCase
{
    /** @var array<int,\WP_Post> */
    private array $posts = [];
    /** @var array<int,array<string,mixed>> */
    private array $meta = [];
    /** @var array<string,mixed> */
    private array $transients = [];
    /** @var list<array{0:int,1:string,2:mixed}> */
    private array $metaWrites = [];
    /** @var list<array<string,mixed>> */
    private array $postQueries = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array{0:string,1:array<string,mixed>}|null */
    private ?array $registeredType = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posts          = [];
        $this->meta           = [];
        $this->transients     = [];
        $this->metaWrites     = [];
        $this->postQueries    = [];
        $this->logged         = [];
        $this->registeredType = null;
        $_POST                = [];

        $GLOBALS['wpdb'] = new TestMembershipsLevelsWpdb();

        Functions\stubs([
            '__' => static fn ($value) => $value,
            'esc_html__' => static fn ($value) => $value,
            'esc_html' => static fn ($value) => (string) $value,
            'esc_attr' => static fn ($value) => (string) $value,
            'esc_url_raw' => static fn ($value) => (string) $value,
            'sanitize_text_field' => static fn ($value) => trim(strip_tags((string) $value)),
            'sanitize_hex_color' => static function ($value) {
                $value = strtolower(trim((string) $value));
                return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';
            },
            'wp_unslash' => static fn ($value) => $value,
            'wp_verify_nonce' => static fn () => 1,
            'current_user_can' => static fn () => true,
            'get_current_user_id' => static fn () => 7,
            'wp_is_post_revision' => static fn () => false,
            'wp_is_post_autosave' => static fn () => false,
            'register_post_type' => function (string $type, array $args) {
                $this->registeredType = [$type, $args];
                return (object) ['name' => $type];
            },
        ]);

        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            $this->postQueries[] = $args;

            $statuses = (array) ($args['post_status'] ?? ['publish']);
            $excluded = array_map('intval', (array) ($args['exclude'] ?? []));
            $out      = [];

            foreach ($this->posts as $post) {
                if (!in_array($post->post_status, $statuses, true)) {
                    continue;
                }
                if ([] !== $excluded && in_array($post->ID, $excluded, true)) {
                    continue;
                }
                if (isset($args['meta_key'], $args['meta_value'])) {
                    $value = $this->meta[$post->ID][$args['meta_key']] ?? '';
                    if (is_array($args['meta_value'])) {
                        if (!in_array($value, $args['meta_value'], true)) {
                            continue;
                        }
                    } elseif ((string) $value !== (string) $args['meta_value']) {
                        continue;
                    }
                }
                $out[] = $post;
            }

            if ('ids' === ($args['fields'] ?? '')) {
                return array_map(static fn (\WP_Post $post): int => $post->ID, $out);
            }
            return $out;
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id][$key] = $value;
            $this->metaWrites[]   = [$id, $key, $value];
            return true;
        });
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /** Crea un nivel en el doble de WordPress. */
    private function level(
        int $id,
        int $rank,
        string $status = 'publish',
        array $meta = []
    ): void {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = 'Descripción ' . $id;

        $this->posts[$id] = $post;
        $this->meta[$id]  = array_merge(
            [\HWE_Memberships_Levels::RANK_META => (string) $rank],
            $meta
        );
    }

    private function fillPost(array $fields): void
    {
        $_POST = array_merge(
            ['hwe_membership_level_nonce' => 'nonce'],
            $fields
        );
    }

    private function notices(): string
    {
        return implode(' | ', array_map(
            static fn ($value): string => is_array($value) ? implode(' ', $value) : (string) $value,
            array_values($this->transients)
        ));
    }

    // --- lectura -------------------------------------------------------------------------------

    public function testAllReturnsPublishedLevelsOrderedByRank(): void
    {
        $this->level(11, 5);
        $this->level(10, 20);
        $this->level(12, 10, 'draft');

        $published = \HWE_Memberships_Levels::all();
        $this->assertSame([11, 10], array_column($published, 'id'));
        $this->assertSame([5, 20], array_column($published, 'rank'));

        $every = \HWE_Memberships_Levels::all(true);
        $this->assertSame([11, 12, 10], array_column($every, 'id'));
        $this->assertSame([true, false, true], array_column($every, 'published'));
    }

    public function testALevelExposesTheFullContract(): void
    {
        $this->level(10, 3, 'publish', [
            \HWE_Memberships_Levels::ICON_META        => 'https://example.test/icon.svg',
            \HWE_Memberships_Levels::COLOR_META       => '#112233',
            \HWE_Memberships_Levels::PURCHASABLE_META => '1',
            \HWE_Memberships_Levels::MIN_AGE_META     => '30',
            \HWE_Memberships_Levels::BENEFITS_META    => [
                'discount' => ['enabled' => true, 'percent' => 10],
            ],
        ]);

        $level = \HWE_Memberships_Levels::all()[0];

        foreach ([
            'id',
            'name',
            'description',
            'slug',
            'rank',
            'icon',
            'color',
            'purchasable',
            'min_account_age_days',
            'published',
            'benefits',
            'translations',
            'slugs',
        ] as $key) {
            $this->assertArrayHasKey($key, $level, 'Falta la clave ' . $key . '.');
        }

        $this->assertSame(10, $level['id']);
        $this->assertSame('Nivel 10', $level['name']);
        $this->assertSame('Descripción 10', $level['description']);
        $this->assertSame('nivel-10', $level['slug']);
        $this->assertSame(3, $level['rank']);
        $this->assertSame('https://example.test/icon.svg', $level['icon']);
        $this->assertSame('#112233', $level['color']);
        $this->assertTrue($level['purchasable']);
        $this->assertSame(30, $level['min_account_age_days']);
        $this->assertTrue($level['published']);
        $this->assertSame(['discount' => ['enabled' => true, 'percent' => 10]], $level['benefits']);
        $this->assertSame([], $level['translations']);
        $this->assertSame([], $level['slugs']);
    }

    public function testGetFindsPublishedAndUnpublishedLevelsAndNullForAMissingOne(): void
    {
        $this->level(10, 5);
        $this->level(12, 8, 'draft');

        $this->assertSame(10, \HWE_Memberships_Levels::get(10)['id']);
        $this->assertSame(8, \HWE_Memberships_Levels::get(12)['rank']);
        $this->assertNull(\HWE_Memberships_Levels::get(999));

        $this->assertSame(5, \HWE_Memberships_Levels::rankOf(10));
        $this->assertSame(8, \HWE_Memberships_Levels::rankOf(12));
        $this->assertSame(0, \HWE_Memberships_Levels::rankOf(999));
    }

    public function testByRankOnlyReturnsPublishedLevels(): void
    {
        $this->level(10, 10);
        $this->level(12, 10, 'draft');

        $found = \HWE_Memberships_Levels::byRank(10);
        $this->assertNotNull($found);
        $this->assertSame(10, $found['id']);
        $this->assertNull(\HWE_Memberships_Levels::byRank(99));
    }

    public function testAPublishedLevelWithoutAValidRankIsNotOffered(): void
    {
        $this->level(10, 0);

        $this->assertSame([], \HWE_Memberships_Levels::all());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testPurchasableRequiresTheFlagAndAPublishedLevel(): void
    {
        $this->level(10, 5, 'publish', [\HWE_Memberships_Levels::PURCHASABLE_META => '1']);
        $this->level(11, 6);
        $this->level(12, 7, 'draft', [\HWE_Memberships_Levels::PURCHASABLE_META => '1']);

        $this->assertTrue(\HWE_Memberships_Levels::isPurchasable(10));
        $this->assertFalse(\HWE_Memberships_Levels::isPurchasable(11), 'Sin la marca no se vende.');
        $this->assertFalse(\HWE_Memberships_Levels::isPurchasable(12), 'Un nivel sin publicar no se vende.');
        $this->assertFalse(\HWE_Memberships_Levels::isPurchasable(999));
    }

    public function testMinAccountAgeIsANonNegativeInteger(): void
    {
        $this->level(10, 5, 'publish', [\HWE_Memberships_Levels::MIN_AGE_META => '30']);
        $this->level(11, 6, 'publish', [\HWE_Memberships_Levels::MIN_AGE_META => '-5']);
        $this->level(12, 7);

        $this->assertSame(30, \HWE_Memberships_Levels::minAccountAgeDays(10));
        $this->assertSame(0, \HWE_Memberships_Levels::minAccountAgeDays(11));
        $this->assertSame(0, \HWE_Memberships_Levels::minAccountAgeDays(12));
        $this->assertSame(0, \HWE_Memberships_Levels::minAccountAgeDays(999));
    }

    public function testBenefitsConfigReturnsTheStoredShapeWithoutSanitizing(): void
    {
        $benefits = [
            'free_delivery' => ['enabled' => true, 'per_period' => 2],
            'early_access'  => ['enabled' => false, 'hours' => 24],
        ];
        $this->level(10, 5, 'publish', [\HWE_Memberships_Levels::BENEFITS_META => $benefits]);

        $this->assertSame($benefits, \HWE_Memberships_Levels::benefitsConfig(10));
        $this->assertSame($benefits, \HWE_Memberships_Levels::get(10)['benefits']);
        $this->assertSame([], \HWE_Memberships_Levels::benefitsConfig(999));
    }

    public function testTranslationsAndSlugsAreReadFromTheContentRegistry(): void
    {
        $this->level(10, 5, 'publish', [
            '_hwe_title_en'   => 'Level ten',
            '_hwe_content_en' => 'English description',
            '_hwe_slug_en'    => 'level-ten',
        ]);
        $this->level(11, 6, 'publish', [
            '_hwe_title_en' => 'No slug',
        ]);

        Functions\when('hwe_i18n_locales')->justReturn(['en', 'pt_br']);
        Functions\when('hwe_i18n_get')->alias(
            fn (string $kind, int $id, string $field, string $suffix): string =>
                (string) ($this->meta[$id]['_hwe_' . $field . '_' . $suffix] ?? '')
        );

        $level = \HWE_Memberships_Levels::get(10);
        $this->assertSame(
            ['en' => ['name' => 'Level ten', 'description' => 'English description', 'slug' => 'level-ten']],
            $level['translations']
        );
        $this->assertSame(['en' => 'level-ten', 'pt_br' => 'nivel-10'], $level['slugs']);

        $withoutSlug = \HWE_Memberships_Levels::get(11);
        $this->assertSame('nivel-11', $withoutSlug['slugs']['en'], 'Sin slug traducido se usa el nativo.');
    }

    // --- guardado ------------------------------------------------------------------------------

    public function testADuplicateRankIsRejectedWithANotice(): void
    {
        $this->level(10, 1);
        $this->level(11, 5);

        $this->fillPost(['hwe_membership_rank' => '1']);
        \HWE_Memberships_Levels::save(11, $this->posts[11]);

        $this->assertSame('5', $this->meta[11][\HWE_Memberships_Levels::RANK_META], 'Se conserva el rango anterior.');
        $this->assertStringContainsString('rango', $this->notices());
    }

    public function testANewLevelThatRepeatsARankGetsTheNextFreeOneWithANotice(): void
    {
        $this->level(10, 1);
        $this->level(11, 5);
        $this->level(12, 0, 'draft');

        $this->fillPost(['hwe_membership_rank' => '1']);
        \HWE_Memberships_Levels::save(12, $this->posts[12]);

        $this->assertSame('6', (string) $this->meta[12][\HWE_Memberships_Levels::RANK_META]);
        $this->assertStringContainsString('rango', $this->notices());
    }

    public function testAnInvalidRankKeepsThePreviousOneWithANotice(): void
    {
        $this->level(10, 5);

        $this->fillPost(['hwe_membership_rank' => '0']);
        \HWE_Memberships_Levels::save(10, $this->posts[10]);

        $this->assertSame('5', $this->meta[10][\HWE_Memberships_Levels::RANK_META]);
        $this->assertStringContainsString('rango', $this->notices());
    }

    public function testPublishingADraftWhoseRankWasTakenMovesItToTheNextFreeOne(): void
    {
        $this->level(10, 5);
        $this->level(11, 6);
        $this->level(12, 6, 'draft');

        $this->fillPost(['hwe_membership_rank' => '6']);
        \HWE_Memberships_Levels::save(12, $this->posts[12]);

        $this->assertSame(
            7,
            (int) $this->meta[12][\HWE_Memberships_Levels::RANK_META],
            'El rango anterior también estaba ocupado: no vale con conservarlo.'
        );
        $this->assertStringContainsString('rango', $this->notices());
    }

    public function testAValidRankIsSaved(): void
    {
        $this->level(10, 5);
        $this->level(11, 2);

        $this->fillPost(['hwe_membership_rank' => '9']);
        \HWE_Memberships_Levels::save(10, $this->posts[10]);

        $this->assertSame(9, (int) $this->meta[10][\HWE_Memberships_Levels::RANK_META]);
        $this->assertSame('', $this->notices());
    }

    public function testTheRankIsOnlyUniqueAmongPublishedLevels(): void
    {
        $this->level(10, 7, 'draft');

        $this->assertFalse(
            \HWE_Memberships_Levels::isRankTaken(7),
            'Un borrador no reserva el rango: la unicidad se exige entre publicados.'
        );

        $this->posts[10]->post_status = 'publish';
        $this->assertTrue(\HWE_Memberships_Levels::isRankTaken(7));
        $this->assertFalse(\HWE_Memberships_Levels::isRankTaken(7, 10), 'El propio nivel no se compara consigo mismo.');
        $this->assertFalse(\HWE_Memberships_Levels::isRankTaken(8));
    }

    public function testMetaBoxAlsoSavesTheOtherLevelFields(): void
    {
        $this->level(10, 5);

        $this->fillPost([
            'hwe_membership_rank'          => '5',
            'hwe_membership_icon'          => 'https://example.test/icon.svg',
            'hwe_membership_color'         => '#AABBCC',
            'hwe_membership_purchasable'   => '1',
            'hwe_membership_min_age_days'  => '12',
        ]);
        \HWE_Memberships_Levels::save(10, $this->posts[10]);

        $this->assertSame('https://example.test/icon.svg', $this->meta[10][\HWE_Memberships_Levels::ICON_META]);
        $this->assertSame('#aabbcc', $this->meta[10][\HWE_Memberships_Levels::COLOR_META]);
        $this->assertSame('1', $this->meta[10][\HWE_Memberships_Levels::PURCHASABLE_META]);
        $this->assertSame(12, $this->meta[10][\HWE_Memberships_Levels::MIN_AGE_META]);
    }

    // --- protección de niveles con socios -------------------------------------------------------

    public function testALevelWithActiveMembersCannotBeDeletedOrUnpublished(): void
    {
        $this->level(10, 5);
        $wpdb = $GLOBALS['wpdb'];
        $this->assertInstanceOf(TestMembershipsLevelsWpdb::class, $wpdb);
        $wpdb->activeMembers = '1';

        $this->assertTrue(\HWE_Memberships_Levels::hasActiveMembers(10));
        $this->assertFalse(\HWE_Memberships_Levels::canRemove(10));

        $this->assertFalse(\HWE_Memberships_Levels::preventDelete(null, $this->posts[10]));
        $this->assertStringContainsString('socios', $this->notices());

        $data = \HWE_Memberships_Levels::guardUnpublish(
            ['post_type' => \HWE_Memberships_Levels::POST_TYPE, 'post_status' => 'draft'],
            ['ID' => 10]
        );
        $this->assertSame('publish', $data['post_status'], 'El nivel sigue publicado.');

        $this->assertNotEmpty($wpdb->queries);
        $this->assertStringContainsString('active', $wpdb->queries[0]);
        $this->assertStringContainsString('scheduled', $wpdb->queries[0]);
        foreach ($wpdb->queries as $query) {
            $this->assertStringNotContainsString('DELETE', strtoupper($query), 'El historial no se borra.');
            $this->assertStringNotContainsString('UPDATE', strtoupper($query), 'La comprobación es de solo lectura.');
        }
    }

    public function testWithoutActiveMembersTheLevelCanBeRemoved(): void
    {
        $this->level(10, 5);
        $wpdb = $GLOBALS['wpdb'];
        $this->assertInstanceOf(TestMembershipsLevelsWpdb::class, $wpdb);
        $wpdb->activeMembers = null;

        $this->assertFalse(\HWE_Memberships_Levels::hasActiveMembers(10));
        $this->assertTrue(\HWE_Memberships_Levels::canRemove(10));
        $this->assertNull(\HWE_Memberships_Levels::preventDelete(null, $this->posts[10]));

        $data = \HWE_Memberships_Levels::guardUnpublish(
            ['post_type' => \HWE_Memberships_Levels::POST_TYPE, 'post_status' => 'draft'],
            ['ID' => 10]
        );
        $this->assertSame('draft', $data['post_status']);
    }

    public function testGuardsOnlyTouchMembershipLevels(): void
    {
        $post               = new \WP_Post();
        $post->ID           = 10;
        $post->post_type    = 'post';
        $post->post_status  = 'publish';

        $this->assertNull(\HWE_Memberships_Levels::preventDelete(null, $post));
        $data = \HWE_Memberships_Levels::guardUnpublish(
            ['post_type' => 'post', 'post_status' => 'draft'],
            ['ID' => 10]
        );
        $this->assertSame('draft', $data['post_status']);
    }

    // --- registro ------------------------------------------------------------------------------

    public function testTranslatableFieldsAreDeclaredForTheContentRegistry(): void
    {
        $registry = \HWE_Memberships_Levels::translatableFields([
            'post' => ['kind' => 'post', 'fields' => ['title' => ['input' => 'text']]],
        ]);

        $this->assertArrayHasKey('post', $registry, 'No pisa lo que ya declara el núcleo.');
        $this->assertArrayHasKey(\HWE_Memberships_Levels::POST_TYPE, $registry);

        $definition = $registry[\HWE_Memberships_Levels::POST_TYPE];
        $this->assertSame('post', $definition['kind']);
        $this->assertArrayHasKey('title', $definition['fields']);
        $this->assertArrayHasKey('content', $definition['fields']);
        $this->assertSame('slug', $definition['fields']['slug']['input']);
    }

    public function testRegisterHooksTheTranslatableRegistry(): void
    {
        Filters\expectAdded('hwe_translatable_fields')
            ->once()
            ->with([\HWE_Memberships_Levels::class, 'translatableFields']);

        \HWE_Memberships_Levels::register();
    }

    public function testThePostTypeIsPrivateAndUsesTheModuleCapabilities(): void
    {
        \HWE_Memberships_Levels::registerPostType();

        $this->assertNotNull($this->registeredType);
        [$type, $args] = $this->registeredType;

        $this->assertSame(\HWE_Memberships_Levels::POST_TYPE, $type);
        $this->assertFalse($args['public']);
        $this->assertFalse($args['publicly_queryable']);
        $this->assertFalse($args['show_in_rest']);
        $this->assertTrue($args['show_ui']);
        $this->assertSame('hwe_memberships_manage', $args['capabilities']['edit_posts']);
        $this->assertSame('hwe_memberships_view', $args['capabilities']['read']);
    }
}

/** Doble mínimo de `$wpdb` para la comprobación de socios. */
final class TestMembershipsLevelsWpdb
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $queries = [];
    public ?string $activeMembers = null;

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . (string) $arg . "'", $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): ?string
    {
        $this->queries[] = $query;
        return $this->activeMembers;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
