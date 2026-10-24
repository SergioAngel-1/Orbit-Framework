<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\ControlCenter\Revalidation;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use function Patchwork\redefine;

require_once HWE_WP_CONTENT_DIR . '/plugins/hwe-memberships/includes/Benefits/Benefit.php';

/**
 * Administración del módulo `memberships` (spec §11): menú «Membresías», niveles y beneficios por
 * nivel.
 *
 * Se prueba lo que decide el panel y no cómo lo pinta WordPress: con el módulo apagado no hay
 * menú; el menú es neutro y cuelga de él el CPT de niveles; la pantalla de beneficios pinta los
 * campos de cada tipo registrado (también los de instancia y los traducibles) y muestra en gris,
 * con el motivo, los de una dependencia apagada; guardar exige capacidad y nonce, pasa por
 * `sanitizeLevelConfig`, conserva sin tocar la configuración de los tipos no disponibles o
 * desconocidos, avisa de los valores ajustados y revalida `memberships` una sola vez. La
 * comparativa es de solo lectura.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminLevelsTest extends TestCase
{
    private const LEVEL  = 10;
    private const SECOND = 11;

    /** @var array<int,\WP_Post> */
    private array $posts = array();

    /** @var array<int,array<string,mixed>> */
    private array $meta = array();

    /** @var list<array{0:int,1:string,2:mixed}> */
    private array $metaWrites = array();

    /** @var array<string,mixed> */
    private array $transients = array();

    /** @var list<string> */
    private array $logged = array();

    /** @var list<string> */
    private array $revalidated = array();

    /** @var list<string> */
    private array $died = array();

    /** @var list<string> */
    private array $redirects = array();

    /** @var list<string> Capacidades del usuario simulado. */
    private array $caps = array('hwe_memberships_view', 'hwe_memberships_manage');

    /** @var list<string> */
    private array $activePlugins = array('hwe-memberships/hwe-memberships.php');

    /** @var array<string,mixed> Opción `hwe_config` del Control Center. */
    private array $config = array();

    private bool $nonceValid = true;

    /** @var array{0:string,1:string}|null */
    private ?array $nonceField = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = array();
        $_GET  = array();

        $this->posts         = array();
        $this->meta          = array();
        $this->metaWrites    = array();
        $this->transients    = array();
        $this->logged        = array();
        $this->revalidated   = array();
        $this->died          = array();
        $this->redirects     = array();
        $this->caps          = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->activePlugins = array('hwe-memberships/hwe-memberships.php');
        $this->config        = array(
            'brand' => array('locale' => 'es'),
            'i18n'  => array('locales' => 'en'),
        );
        $this->nonceValid = true;
        $this->nonceField = null;

        $GLOBALS['wpdb'] = new TestMembershipsAdminWpdb();

        redefine(Revalidation::class . '::trigger', function (string $tag = 'site-config'): void {
            $this->revalidated[] = $tag;
        });

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => $this->config,
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_memberships_benefit_types' === $hook) {
                $value['probe_type']      = new AdminProbeBenefit();
                $value['unavailable_type'] = new AdminUnavailableBenefit();
            }
            return $value;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            $statuses = (array) ($args['post_status'] ?? array('publish'));
            $excluded = array_map('intval', (array) ($args['exclude'] ?? array()));
            $out      = array();
            foreach ($this->posts as $post) {
                if (!in_array($post->post_status, $statuses, true)) {
                    continue;
                }
                if (array() !== $excluded && in_array($post->ID, $excluded, true)) {
                    continue;
                }
                if (isset($args['meta_key'], $args['meta_value'])) {
                    $value = $this->meta[$post->ID][$args['meta_key']] ?? '';
                    if ((string) $value !== (string) $args['meta_value']) {
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
        Functions\when('update_post_meta')->alias(function (int $id, string $key, mixed $value): bool {
            $this->meta[$id][$key] = $value;
            $this->metaWrites[]    = array($id, $key, $value);
            return true;
        });
        Functions\when('current_user_can')->alias(
            fn (string $cap): bool => in_array($cap, $this->caps, true)
        );
        Functions\when('wp_verify_nonce')->alias(fn (mixed $nonce, mixed $action): bool => $this->nonceValid);
        Functions\when('wp_die')->alias(function (string $message): void {
            $this->died[] = $message;
        });
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirects[] = $url;
        });
        Functions\when('wp_nonce_field')->alias(function (string $action, string $name): void {
            $this->nonceField = array($action, $name);
            echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="nonce">';
        });
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            '__'                  => static fn ($value): string => (string) $value,
            'esc_html__'          => static fn ($value): string => (string) $value,
            'esc_attr__'          => static fn ($value): string => (string) $value,
            'esc_html'            => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_attr'            => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'esc_url'             => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'sanitize_text_field' => static fn ($value): string => trim(strip_tags((string) $value)),
            'sanitize_key'        => static fn ($value): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)),
            'wp_unslash'          => static fn ($value) => $value,
            'absint'              => static fn ($value): int => abs((int) $value),
            'admin_url'           => static fn (string $path = ''): string => 'https://example.test/wp-admin/' . ltrim($path, '/'),
            'add_query_arg'       => static function (mixed $args, string $url = ''): string {
                $args  = is_array($args) ? $args : array();
                $query = http_build_query(array_filter($args, static fn ($value): bool => null !== $value));
                return '' === $query ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
            },
            'number_format_i18n'  => static fn ($value, int $decimals = 0): string => number_format((float) $value, $decimals),
        ]);

        $this->loadOwnFile('plugins/hwe-memberships/hwe-memberships.php');
    }

    protected function tearDown(): void
    {
        $_POST = array();
        $_GET  = array();
        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function level(
        int $id,
        int $rank,
        string $status = 'publish',
        array $meta = array()
    ): void {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = '';

        $this->posts[$id] = $post;
        $this->meta[$id]  = array_merge(
            array(\HWE_Memberships_Levels::RANK_META => (string) $rank),
            $meta
        );
    }

    private function postBenefits(int $levelId, array $types, array $benefits): void
    {
        $_POST = array(
            \HWE_Memberships_Admin_Benefits_Screen::NONCE_FIELD => 'nonce',
            'level_id'                                          => (string) $levelId,
            'hwe_memberships_benefit_types'                     => $types,
            'hwe_memberships_benefits'                          => $benefits,
        );
    }

    private function render(callable $view): string
    {
        ob_start();
        $view();
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function storedBenefits(int $levelId): array
    {
        $value = $this->meta[$levelId][\HWE_Memberships_Levels::BENEFITS_META] ?? array();
        return is_array($value) ? $value : array();
    }

    // ── Menú y capacidades ──────────────────────────────────────────────────

    public function testWithoutTheModuleThereIsNoMenuOrRepair(): void
    {
        $this->activePlugins = array();

        Actions\expectAdded('admin_menu')->never();
        Actions\expectAdded('admin_init')->never();

        \HWE_Memberships_Admin::register();
    }

    public function testWithTheModuleTheMenuTheRepairAndTheScreensAreRegistered(): void
    {
        \HWE_Memberships_Admin::register();

        $this->assertNotFalse(
            has_action('admin_menu', array(\HWE_Memberships_Admin_Capabilities::class, 'maybeRepair'))
        );
        $this->assertNotFalse(
            has_action('admin_menu', array(\HWE_Memberships_Admin_Menu::class, 'addMenu'))
        );
        $this->assertNotFalse(
            has_action(
                'admin_post_' . \HWE_Memberships_Admin_Benefits_Screen::ACTION,
                array(\HWE_Memberships_Admin_Benefits_Screen::class, 'handleSave')
            )
        );
        $this->assertNotFalse(
            has_action(
                'save_post_' . \HWE_Memberships_Levels::POST_TYPE,
                array(\HWE_Memberships_Admin::class, 'onLevelSaved')
            )
        );
    }

    public function testTheMenuIsNeutralAndHoldsTheLevelsAndBenefitsScreens(): void
    {
        $menu     = null;
        $submenus = array();
        Functions\when('add_menu_page')->alias(function (...$args) use (&$menu) {
            $menu = $args;
            return 'toplevel_page_hwe-memberships';
        });
        Functions\when('add_submenu_page')->alias(function (...$args) use (&$submenus) {
            $submenus[] = $args;
            return 'hook';
        });

        \HWE_Memberships_Admin_Menu::addMenu();

        $this->assertNotNull($menu);
        $this->assertSame('Membresías', $menu[0], 'El título del menú es neutro.');
        $this->assertSame('Membresías', $menu[1]);
        $this->assertSame(\HWE_Memberships_Admin_Capabilities::CAP_VIEW, $menu[2]);
        $this->assertSame(\HWE_Memberships_Admin_Menu::MAIN, $menu[3]);
        $this->assertSame(
            array(\HWE_Memberships_Admin_Levels_Screen::class, 'render'),
            $menu[4]
        );

        $slugs = array_map(static fn (array $args): string => (string) $args[4], $submenus);
        $this->assertContains(\HWE_Memberships_Admin_Menu::MAIN, $slugs);
        $this->assertContains('hwe-memberships-benefits', $slugs);
        $this->assertContains('hwe-memberships-comparison', $slugs);
    }

    public function testTheLevelPostTypeLivesUnderTheModuleMenu(): void
    {
        $registered = null;
        Functions\when('register_post_type')->alias(function (string $type, array $args) use (&$registered) {
            $registered = array($type, $args);
            return (object) array('name' => $type);
        });

        \HWE_Memberships_Levels::registerPostType();

        $this->assertNotNull($registered);
        $this->assertSame(
            \HWE_Memberships_Admin_Menu::MAIN,
            $registered[1]['show_in_menu'],
            'Los niveles cuelgan del menú «Membresías».'
        );
    }

    // ── Pantalla de niveles ─────────────────────────────────────────────────

    public function testTheLevelsScreenListsEveryLevelAndEscapesItsName(): void
    {
        $this->level(self::LEVEL, 3);
        $this->posts[self::LEVEL]->post_title = '<script>alert(1)</script>';
        $this->level(self::SECOND, 7, 'draft');

        $html = $this->render(array(\HWE_Memberships_Admin_Levels_Screen::class, 'render'));

        $this->assertStringContainsString('Niveles', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Borrador', $html);
        $this->assertStringContainsString('post.php?post=10', $html);
        $this->assertStringContainsString('page=hwe-memberships-benefits', $html);
    }

    // ── Pantalla de beneficios ──────────────────────────────────────────────

    public function testTheBenefitsScreenRendersEveryFieldTypeAndTheConfiguredLocales(): void
    {
        $this->level(self::LEVEL, 1, 'publish', array(
            \HWE_Memberships_Levels::BENEFITS_META => array(
                'probe_type' => array(
                    'enabled'  => true,
                    'percent'  => 25,
                    'flag'     => true,
                    'mode'     => 'b',
                    'level'    => self::SECOND,
                    'cats'     => array(1, 3),
                    'label'    => 'Visible',
                    'title'    => array('es' => 'Hola', 'en' => 'Hello'),
                ),
            ),
        ));
        $this->level(self::SECOND, 2);
        $_GET = array('level' => (string) self::LEVEL);

        $html = $this->render(array(\HWE_Memberships_Admin_Benefits_Screen::class, 'render'));

        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][percent]"', $html);
        $this->assertStringContainsString('value="25"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][flag]"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][mode]"', $html);
        $this->assertStringContainsString('value="b" selected="selected"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][level]"', $html);
        $this->assertStringContainsString('Nivel 11', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][cats]"', $html);
        $this->assertStringContainsString('value="1, 3"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][label]"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][title][es]"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][title][en]"', $html);
        $this->assertStringContainsString('value="Hola"', $html);
        $this->assertStringContainsString('value="Hello"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits[probe_type][enabled]"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefit_types[]"', $html);
        $this->assertStringContainsString('name="level_id"', $html);
        $this->assertStringContainsString('name="hwe_memberships_benefits_nonce"', $html);
        $this->assertSame(
            array(
                \HWE_Memberships_Admin_Benefits_Screen::NONCE_ACTION . ':' . self::LEVEL,
                \HWE_Memberships_Admin_Benefits_Screen::NONCE_FIELD,
            ),
            $this->nonceField
        );
    }

    public function testAnUnavailableBenefitIsShownDisabledWithItsReason(): void
    {
        $this->level(self::LEVEL, 1, 'publish', array(
            \HWE_Memberships_Levels::BENEFITS_META => array(
                'unavailable_type' => array(
                    'enabled' => true,
                    'title'   => array('es' => 'Se conserva'),
                    'points'  => 15,
                ),
            ),
        ));
        $_GET = array('level' => (string) self::LEVEL);

        $html = $this->render(array(\HWE_Memberships_Admin_Benefits_Screen::class, 'render'));

        $this->assertMatchesRegularExpression(
            '/data-benefit="unavailable_type"[^>]*\bdisabled\b/',
            $html,
            'El bloque de un tipo cuya dependencia está apagada se pinta deshabilitado.'
        );
        $this->assertStringContainsString('loyalty', $html);
        $this->assertStringContainsString('points', $html);
        $this->assertStringContainsString('Se conserva', $html, 'El valor guardado sigue visible para el editor.');
    }

    public function testSavingBenefitsKeepsTheStoredConfigurationOfAnUnavailableType(): void
    {
        $kept = array(
            'enabled' => true,
            'title'   => array('es' => 'Conservar'),
            'points'  => 15,
        );
        $this->level(self::LEVEL, 1, 'publish', array(
            \HWE_Memberships_Levels::BENEFITS_META => array(
                'unavailable_type' => $kept,
                'legacy_type'      => array('algo' => 'intacto'),
            ),
        ));

        $this->postBenefits(
            self::LEVEL,
            array('probe_type'),
            array('probe_type' => array('enabled' => '1', 'percent' => '10'))
        );
        \HWE_Memberships_Admin_Benefits_Screen::handleSave();

        $stored = $this->storedBenefits(self::LEVEL);
        $this->assertSame($kept, $stored['unavailable_type'], 'La configuración del tipo no disponible no se toca.');
        $this->assertSame(array('algo' => 'intacto'), $stored['legacy_type'], 'Una clave que este despliegue no conoce se conserva.');
        $this->assertSame(10, $stored['probe_type']['percent']);
        $this->assertTrue($stored['probe_type']['enabled']);
    }

    public function testSavingBenefitsSanitizesEveryValueAndWarnsAboutTheAdjustments(): void
    {
        $this->level(self::LEVEL, 1);

        $this->postBenefits(
            self::LEVEL,
            array('probe_type'),
            array(
                'probe_type' => array(
                    'enabled'      => '1',
                    'percent'      => '999',
                    'flag'         => '1',
                    'mode'         => 'no-existe',
                    'level'        => '11',
                    'cats'         => '3, no-es-un-id, 1',
                    'label'        => '<b>Hola</b>',
                    'title'        => array('es' => 'Hola', 'en' => 'Hello', 'fr' => 'Bonjour'),
                    'campo-ajeno'  => 'se descarta',
                ),
            )
        );
        \HWE_Memberships_Admin_Benefits_Screen::handleSave();

        $stored = $this->storedBenefits(self::LEVEL)['probe_type'];
        $this->assertSame(100, $stored['percent'], 'Un porcentaje fuera de rango se recorta.');
        $this->assertTrue($stored['flag']);
        $this->assertSame('a', $stored['mode'], 'Una selección fuera de las opciones vuelve al default.');
        $this->assertSame(11, $stored['level']);
        $this->assertSame(array(1, 3), $stored['cats']);
        $this->assertSame('Hola', $stored['label'], 'El texto se sanea y se recorta.');
        $this->assertSame(array('es' => 'Hola', 'en' => 'Hello'), $stored['title'], 'Solo los idiomas configurados.');
        $this->assertArrayNotHasKey('campo-ajeno', $stored, 'Los campos que el tipo no declara se descartan.');
        $this->assertNotEmpty($this->transients, 'Queda un aviso para el panel.');
        $flash = array_values($this->transients)[0];
        $this->assertSame('saved_adjusted', $flash['code']);
        $this->assertContains('probe_type', $flash['adjusted']);
    }

    public function testSavingALevelRequiresManageCapabilityAndNonce(): void
    {
        $this->level(self::LEVEL, 1);

        $this->caps = array('hwe_memberships_view');
        $_POST      = array(
            \HWE_Memberships_Levels::NONCE_FIELD => 'nonce',
            'hwe_membership_rank'                => '9',
        );
        \HWE_Memberships_Levels::save(self::LEVEL, $this->posts[self::LEVEL]);

        $this->assertSame(array(), $this->metaWrites, 'Sin capacidad de gestión el nivel no se escribe.');
        $this->assertSame('1', (string) $this->meta[self::LEVEL][\HWE_Memberships_Levels::RANK_META]);

        $this->caps        = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->nonceValid  = false;
        \HWE_Memberships_Levels::save(self::LEVEL, $this->posts[self::LEVEL]);

        $this->assertSame(array(), $this->metaWrites, 'Sin nonce válido el nivel no se escribe.');
        $this->assertSame('1', (string) $this->meta[self::LEVEL][\HWE_Memberships_Levels::RANK_META]);
    }

    public function testSavingBenefitsRequiresTheManageCapability(): void
    {        $this->caps = array('hwe_memberships_view');
        $this->level(self::LEVEL, 1);

        $this->postBenefits(
            self::LEVEL,
            array('probe_type'),
            array('probe_type' => array('enabled' => '1'))
        );
        \HWE_Memberships_Admin_Benefits_Screen::handleSave();

        $this->assertNotEmpty($this->died, 'Sin capacidad la petición muere.');
        $this->assertSame(array(), $this->metaWrites, 'Sin capacidad no se escribe.');
        $this->assertSame(array(), $this->redirects);
    }

    public function testSavingBenefitsRequiresAValidNonce(): void
    {
        $this->nonceValid = false;
        $this->level(self::LEVEL, 1);

        $this->postBenefits(
            self::LEVEL,
            array('probe_type'),
            array('probe_type' => array('enabled' => '1'))
        );
        \HWE_Memberships_Admin_Benefits_Screen::handleSave();

        $this->assertNotEmpty($this->died, 'Sin nonce la petición muere.');
        $this->assertSame(array(), $this->metaWrites);
        $this->assertSame(array(), $this->redirects);
    }

    public function testSavingBenefitsRevalidatesMembershipsExactlyOnce(): void
    {
        $this->level(self::LEVEL, 1);

        $this->postBenefits(
            self::LEVEL,
            array('probe_type'),
            array('probe_type' => array('enabled' => '1'))
        );
        \HWE_Memberships_Admin_Benefits_Screen::handleSave();

        $this->assertSame(array('memberships'), $this->revalidated);
        $this->assertCount(1, $this->redirects, 'El panel vuelve a la pantalla del nivel.');
        $this->assertStringContainsString('page=hwe-memberships-benefits', $this->redirects[0]);
        $this->assertStringContainsString('level=10', $this->redirects[0]);
    }

    public function testSavingALevelAlsoRevalidatesMemberships(): void
    {
        $this->level(self::LEVEL, 1);

        \HWE_Memberships_Admin::onLevelSaved(self::LEVEL, $this->posts[self::LEVEL], true);

        $this->assertSame(array('memberships'), $this->revalidated);

        $this->caps = array('hwe_memberships_view');
        \HWE_Memberships_Admin::onLevelSaved(self::LEVEL, $this->posts[self::LEVEL], true);
        $this->assertSame(array('memberships'), $this->revalidated, 'Sin capacidad de gestión no se revalida.');
    }

    // ── Comparativa ─────────────────────────────────────────────────────────

    public function testTheComparisonShowsLevelsTimesBenefitsInReadOnlyMode(): void
    {
        $this->level(self::LEVEL, 1, 'publish', array(
            \HWE_Memberships_Levels::BENEFITS_META => array(
                'probe_type' => array('enabled' => true, 'percent' => 25),
            ),
        ));
        $this->level(self::SECOND, 2);

        $html = $this->render(array(\HWE_Memberships_Admin_Benefits_Screen::class, 'renderComparison'));

        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<input', $html, 'La comparativa es de solo lectura.');
        $this->assertStringContainsString('probe_type', $html);
        $this->assertStringContainsString('Nivel 10', $html);
        $this->assertStringContainsString('Nivel 11', $html);
        $this->assertStringContainsString('25', $html);
    }
}

/** Tipo de beneficio de prueba con todos los tipos de campo del contrato. */
final class AdminProbeBenefit extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return 'probe_type';
    }

    public function fields(): array
    {
        return array(
            'percent' => array(
                'type'    => self::FIELD_PERCENTAGE,
                'label'   => 'Porcentaje',
                'default' => 0,
            ),
            'flag'    => array(
                'type'    => self::FIELD_BOOLEAN,
                'label'   => 'Bandera',
                'default' => false,
            ),
            'mode'    => array(
                'type'    => self::FIELD_SELECT,
                'label'   => 'Modo',
                'options' => array('a' => 'A', 'b' => 'B'),
                'default' => 'a',
            ),
            'level'   => array(
                'type'    => self::FIELD_LEVEL,
                'label'   => 'Nivel',
                'default' => 0,
            ),
            'cats'    => array(
                'type'    => self::FIELD_CATEGORIES,
                'label'   => 'Categorías',
                'default' => array(),
            ),
            'label'   => array(
                'type'    => self::FIELD_TEXT,
                'label'   => 'Etiqueta',
                'default' => '',
                'max'     => 40,
            ),
            'title'   => array(
                'type'    => self::FIELD_TRANSLATABLE,
                'label'   => 'Título',
                'default' => array(),
                'max'     => 80,
            ),
        );
    }

    public function publicData(int $levelId): array
    {
        return array('key' => $this->key());
    }
}

/** Tipo de beneficio cuya dependencia está apagada en el escenario de prueba. */
final class AdminUnavailableBenefit extends \HWE_Memberships_Benefit
{
    public function key(): string
    {
        return 'unavailable_type';
    }

    public function dependsOn(): ?string
    {
        return 'loyalty:points';
    }

    public function fields(): array
    {
        return array(
            'title'  => array(
                'type'    => self::FIELD_TRANSLATABLE,
                'label'   => 'Título',
                'default' => array(),
                'max'     => 80,
            ),
            'points' => array(
                'type'    => self::FIELD_NUMBER,
                'label'   => 'Puntos',
                'default' => 0,
                'min'     => 0,
                'max'     => 1000,
            ),
        );
    }

    public function publicData(int $levelId): array
    {
        return array('key' => $this->key());
    }
}

/** Doble mínimo de `$wpdb` para las comprobaciones de niveles que consultan concesiones. */
final class TestMembershipsAdminWpdb
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $queries = array();

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[ds]/', (string) $arg, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): ?string
    {
        $this->queries[] = $query;
        return null;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
