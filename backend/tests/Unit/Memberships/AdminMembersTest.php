<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TestMembershipsServiceWpdb;

require_once HWE_BACKEND_DIR . '/tests/Stubs/wp-memberships-service-wpdb.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-order.php';
require_once HWE_BACKEND_DIR . '/tests/Stubs/wc-cart.php';

/**
 * Administración de socios del módulo `memberships` (spec §11.1, §11.2 y §11.4): listado,
 * resumen, historial, CSV, perfil de usuario, columna del listado de usuarios y caja del pedido.
 *
 * Se prueba la DECISIÓN del panel y no cómo lo pinta WordPress: que los filtros y la ordenación
 * se resuelvan en SQL (sin cargar todos los usuarios ni todas las filas), que el CSV no lleve
 * email ni login y neutralice las celdas que una hoja de cálculo ejecutaría, que cada acción del
 * perfil delegue en `HWE_Memberships_Grants` y deje historial con el administrador, y que la caja
 * del pedido lea las metas por sus constantes y viva en las dos pantallas de HPOS.
 *
 * El doble de `$wpdb` envuelve al de los servicios (`TestMembershipsServiceWpdb`) y añade las
 * consultas de administración: listados paginados, agregados del resumen y búsquedas puntuales.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminMembersTest extends TestCase
{
    private const USER   = 7;
    private const OTHER  = 8;
    private const LEVEL  = 101; // rango 5
    private const LEVEL2 = 102; // rango 10
    private const ACTOR  = 1;

    private int $now = 1_800_000_000;

    /** @var array<int,\WP_Post> */
    private array $posts = array();

    /** @var array<int,array<string,mixed>> */
    private array $meta = array();

    /** @var array<int,object> */
    private array $users = array();

    /** @var list<string> */
    private array $caps = array('hwe_memberships_view', 'hwe_memberships_manage');

    /** @var list<string> */
    private array $activePlugins = array('hwe-memberships/hwe-memberships.php');

    /** @var list<string> */
    private array $logged = array();

    /** @var list<string> */
    private array $died = array();

    /** @var list<string> */
    private array $redirects = array();

    /** @var array<string,mixed> */
    private array $transients = array();

    private bool $nonceValid = true;

    /** @var list<array{0:string,1:string}> */
    private array $nonceFields = array();

    /** @var list<array<string,mixed>> Filas de la caja del pedido añadidas por el filtro. */
    private array $extraOrderRows = array();

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = array();
        $_GET  = array();

        $this->now           = 1_800_000_000;
        $this->posts         = array();
        $this->meta          = array();
        $this->users         = array();
        $this->caps          = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->activePlugins = array('hwe-memberships/hwe-memberships.php');
        $this->logged        = array();
        $this->died          = array();
        $this->redirects     = array();
        $this->transients    = array();
        $this->nonceValid    = true;
        $this->nonceFields   = array();
        $this->extraOrderRows = array();

        $GLOBALS['wpdb'] = new TestAdminMembersWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Admin/Admin.php');
        \HWE_Memberships_Events::clear();

        $this->level(self::LEVEL, 5);
        $this->level(self::LEVEL2, 10);

        $this->users[self::USER] = (object) array(
            'ID'           => self::USER,
            'display_name' => 'Cliente Siete',
            'user_email'   => 'siete@example.test',
            'user_login'   => 'cliente-siete',
        );
        $this->users[self::OTHER] = (object) array(
            'ID'           => self::OTHER,
            'display_name' => 'Cliente Ocho',
            'user_email'   => 'ocho@example.test',
            'user_login'   => 'cliente-ocho',
        );

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'active_plugins' => $this->activePlugins,
                default          => $default,
            };
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_posts')->alias(function (array $args): array {
            $statuses = (array) ($args['post_status'] ?? array('publish'));
            $out      = array();
            foreach ($this->posts as $post) {
                if (in_array($post->post_status, $statuses, true)) {
                    $out[] = $post;
                }
            }
            return $out;
        });
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->meta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_user_by')->alias(function (string $field, string $value) {
            foreach ($this->users as $user) {
                $haystack = match ($field) {
                    'email' => $user->user_email,
                    'login' => $user->user_login,
                    'slug'  => $user->user_login,
                    default => '',
                };
                if ($haystack === $value) {
                    return $user;
                }
            }
            return false;
        });
        Functions\when('current_user_can')->alias(function (string $cap, mixed ...$args): bool {
            if ('edit_user' === $cap) {
                return true;
            }
            return in_array($cap, $this->caps, true);
        });
        Functions\when('wp_verify_nonce')->alias(fn (mixed $nonce, mixed $action): bool => $this->nonceValid);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_die')->alias(function (string $message): void {
            $this->died[] = $message;
        });
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirects[] = $url;
        });
        Functions\when('wp_nonce_field')->alias(function (string $action, string $name): void {
            $this->nonceFields[] = array($action, $name);
            echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="nonce">';
        });
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('get_current_user_id')->justReturn(self::ACTOR);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed ...$args): mixed {
            if ('hwe_memberships_order_box_rows' === $hook && array() !== $this->extraOrderRows) {
                $value = is_array($value) ? $value : array();
                foreach ($this->extraOrderRows as $row) {
                    $value[] = $row;
                }
            }
            return $value;
        });
        Functions\when('wc_get_page_screen_id')->alias(
            static fn (string $id): string => 'shop-order' === $id ? 'woocommerce_page_wc-orders' : ''
        );
        Functions\stubs(array(
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
                $query = http_build_query(array_filter($args, static fn ($value): bool => '' !== $value && null !== $value));
                return '' === $query ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
            },
            'number_format_i18n'  => static fn ($value, int $decimals = 0): string => number_format((float) $value, $decimals),
            'wp_date'             => static fn (string $format, int $timestamp): string => gmdate($format, $timestamp),
            'wp_kses_post'        => static fn ($value): string => (string) $value,
            'sanitize_html_class' => static fn ($value): string => (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value),
            'wc_price'            => static fn ($amount): string => '<span class="woocommerce-Price-amount">'
                . number_format((float) $amount, 2, ',', '.') . '</span>',
            '_n'                  => static fn (string $single, string $plural, int $number): string => 1 === $number ? $single : $plural,
        ));
    }

    protected function tearDown(): void
    {
        $_POST = array();
        $_GET  = array();
        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function wpdb(): TestAdminMembersWpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function service(): TestMembershipsServiceWpdb
    {
        return $this->wpdb()->service;
    }

    private function level(int $id, int $rank, string $status = 'publish'): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = 'Nivel ' . $id;
        $post->post_name    = 'nivel-' . $id;
        $post->post_content = '';

        $this->posts[$id] = $post;
        $this->meta[$id]  = array(\HWE_Memberships_Levels::RANK_META => (string) $rank);
    }

    private function day(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now + $days * DAY_IN_SECONDS);
    }

    /** @param array<string,mixed> $overrides */
    private function activeGrant(int $userId = self::USER, int $levelId = self::LEVEL, int $days = 30, array $overrides = array()): int
    {
        $id = $this->service()->seedGrant(array_merge(array(
            'user_id'         => $userId,
            'level_id'        => $levelId,
            'origin'          => 'order',
            'status'          => 'active',
            'starts_at'       => $this->day(-1),
            'ends_at'         => $this->day($days),
            'idempotency_key' => 'order:' . $userId . ':' . $levelId . ':1',
        ), $overrides));

        $this->service()->seedMember($userId, array(
            'level_id'      => $levelId,
            'covers_until'  => $this->day($days),
            'member_number' => 'M-' . $userId,
            'member_since'  => $this->day(-1),
        ));

        return $id;
    }

    private function render(callable $view): string
    {
        ob_start();
        $view();
        return (string) ob_get_clean();
    }

    // ── Menú y registro ─────────────────────────────────────────────────────

    public function testWithoutTheModuleTheMemberScreensAreNotRegistered(): void
    {
        $this->activePlugins = array();

        Actions\expectAdded('admin_post_' . \HWE_Memberships_Admin_Members_Screen::ACTION)->never();
        Actions\expectAdded('admin_post_' . \HWE_Memberships_Admin_Csv::ACTION)->never();
        Actions\expectAdded('admin_post_' . \HWE_Memberships_Admin_User_Profile::ACTION)->never();

        \HWE_Memberships_Admin::register();
    }

    public function testTheMemberScreensAreRegisteredWithTheModule(): void
    {
        \HWE_Memberships_Admin::register();

        $this->assertNotFalse(has_action(
            'admin_post_' . \HWE_Memberships_Admin_Members_Screen::ACTION,
            array(\HWE_Memberships_Admin_Members_Screen::class, 'handleAction')
        ));
        $this->assertNotFalse(has_action(
            'admin_post_' . \HWE_Memberships_Admin_Csv::ACTION,
            array(\HWE_Memberships_Admin_Csv::class, 'export')
        ));
        $this->assertNotFalse(has_action(
            'admin_post_' . \HWE_Memberships_Admin_User_Profile::ACTION,
            array(\HWE_Memberships_Admin_User_Profile::class, 'handleAction')
        ));
        $this->assertNotFalse(has_filter(
            'manage_users_custom_column',
            array(\HWE_Memberships_Admin_User_Columns::class, 'render')
        ));
        $this->assertNotFalse(has_action(
            'add_meta_boxes',
            array(\HWE_Memberships_Admin_Order_Box::class, 'add')
        ));
    }

    public function testTheMenuAddsSummaryMembersAndHistoryInSpecOrder(): void
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
        $this->assertSame('Membresías', $menu[0], 'El título del menú sigue siendo neutro.');

        $slugs = array_map(static fn (array $args): string => (string) $args[4], $submenus);
        $this->assertSame(
            array(
                'hwe-memberships-summary',
                'hwe-memberships',
                'hwe-memberships-benefits',
                'hwe-memberships-comparison',
                'hwe-memberships-members',
                'hwe-memberships-bulk',
                'hwe-memberships-history',
            ),
            $slugs,
            'Resumen, niveles, socios, operaciones en masa e historial, en el orden del spec.'
        );
    }

    // ── Listado de socios ───────────────────────────────────────────────────

    public function testTheMembersListFiltersAndSortsInSqlWithLimitAndOffset(): void
    {
        Functions\expect('get_users')->never();

        $filters = \HWE_Memberships_Admin_Members_Screen::filters(array(
            'hwe_memberships_level'  => (string) self::LEVEL,
            'hwe_memberships_status' => 'frozen',
            'hwe_memberships_origin' => 'order',
            'hwe_memberships_orderby' => 'member',
            'hwe_memberships_order'   => 'asc',
            'paged'                   => '2',
        ));

        $this->assertSame(self::LEVEL, $filters['level']);
        $this->assertSame('frozen', $filters['status']);
        $this->assertSame('order', $filters['origin']);
        $this->assertSame(2, $filters['paged']);

        $result = \HWE_Memberships_Admin_Members_Screen::query($filters);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);

        $list = $this->wpdb()->lastCallContaining('SELECT m.user_id');
        $this->assertNotNull($list, 'El listado debe consultarse en SQL.');
        $this->assertStringContainsString('FROM wp_hwe_memberships_members m', $list['sql']);
        $this->assertStringContainsString('LEFT JOIN wp_users u ON u.ID = m.user_id', $list['sql']);
        $this->assertStringContainsString('m.level_id = 101', $list['sql'], 'El nivel se filtra en SQL.');
        $this->assertStringContainsString('m.frozen_at IS NOT NULL', $list['sql'], 'El estado se filtra en SQL.');
        $this->assertStringContainsString(
            "EXISTS (SELECT 1 FROM wp_hwe_memberships_grants g WHERE g.user_id = m.user_id AND g.status IN ('active','scheduled') AND g.origin = 'order')",
            $list['sql'],
            'El origen se filtra en SQL, contra las concesiones vivas.'
        );
        $this->assertStringContainsString('ORDER BY m.member_number ASC', $list['sql']);
        $this->assertStringContainsString('LIMIT %d OFFSET %d', $list['sql']);
        $this->assertSame(
            array(\HWE_Memberships_Admin_Members_Screen::PER_PAGE, \HWE_Memberships_Admin_Members_Screen::PER_PAGE),
            $list['args'],
            'La página 2 pide 20 filas desde la 20: no se carga el listado entero.'
        );

        $count = $this->wpdb()->lastRawCallContaining('SELECT COUNT(*) FROM wp_hwe_memberships_members m');
        $this->assertNotNull($count, 'El total se cuenta en SQL, sin traer las filas.');
        $this->assertStringContainsString('m.level_id = 101', $count['sql']);
    }

    public function testAnUnknownSortOrStatusFallsBackToTheDefault(): void
    {
        $filters = \HWE_Memberships_Admin_Members_Screen::filters(array(
            'hwe_memberships_status'  => 'no-existe',
            'hwe_memberships_origin'  => 'inventado',
            'hwe_memberships_orderby' => 'evil; DROP TABLE wp_users',
            'hwe_memberships_order'   => 'random',
            'paged'                   => '-4',
        ));

        $this->assertSame('', $filters['status']);
        $this->assertSame('', $filters['origin']);
        $this->assertSame('recent', $filters['orderby']);
        $this->assertSame('asc', $filters['order']);
        $this->assertSame(1, $filters['paged']);

        \HWE_Memberships_Admin_Members_Screen::query($filters);
        $list = $this->wpdb()->lastCallContaining('SELECT m.user_id');
        $this->assertNotNull($list);
        $this->assertStringContainsString('ORDER BY m.member_since DESC, m.user_id DESC', $list['sql']);
        $this->assertStringNotContainsString('evil', $list['sql']);
        $this->assertStringNotContainsString('DROP', $list['sql']);
    }

    public function testTheMembersRowsAreEscapedAndCarryPerRowActions(): void
    {
        $rows = array(
            (object) array(
                'user_id'       => self::USER,
                'display_name'  => '<script>alert(1)</script>',
                'level_id'      => self::LEVEL,
                'covers_until'  => $this->day(30),
                'frozen_at'     => null,
                'member_number' => 'M-7',
                'member_since'  => $this->day(-10),
            ),
        );

        $method = new \ReflectionMethod(\HWE_Memberships_Admin_Members_Screen::class, 'renderRows');
        ob_start();
        $method->invoke(null, $rows, true);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringContainsString('M-7', $html);
        $this->assertStringContainsString('user-edit.php?user_id=7', $html);
        $this->assertStringContainsString('name="action" value="hwe_memberships_member_action"', $html);
        $this->assertStringContainsString('value="freeze"', $html);
        $this->assertStringContainsString('value="remove"', $html);
        $this->assertStringContainsString('hwe_memberships_member_nonce', $html);
    }

    public function testARowActionRequiresTheManageCapabilityAndNonce(): void
    {
        $this->activeGrant();

        $this->caps = array('hwe_memberships_view');
        $_POST = array(
            'hwe_memberships_member_nonce' => 'nonce',
            'user_id'                      => (string) self::USER,
            'hwe_memberships_action'       => 'freeze',
        );
        \HWE_Memberships_Admin_Members_Screen::handleAction();

        $this->assertNotEmpty($this->died, 'Sin capacidad la petición muere.');
        $this->assertNull($this->service()->members[self::USER]['frozen_at']);

        $this->caps       = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->nonceValid = false;
        $this->died       = array();
        \HWE_Memberships_Admin_Members_Screen::handleAction();

        $this->assertNotEmpty($this->died, 'Sin nonce la petición muere.');
        $this->assertNull($this->service()->members[self::USER]['frozen_at']);
        $this->assertSame(array(), $this->redirects);
    }

    public function testFreezingAndUnfreezingFromTheListRecordsTheAdmin(): void
    {
        $this->activeGrant();

        $this->postRowAction('freeze');
        \HWE_Memberships_Admin_Members_Screen::handleAction();

        $this->assertNotNull($this->service()->members[self::USER]['frozen_at']);
        $this->assertContains('frozen', $this->service()->eventTypes());
        $frozen = $this->lastEventOfType('frozen');
        $this->assertSame('admin', $frozen['actor']);
        $this->assertSame(self::ACTOR, (int) $frozen['actor_id']);
        $this->assertCount(1, $this->redirects);

        $this->postRowAction('unfreeze');
        \HWE_Memberships_Admin_Members_Screen::handleAction();

        $this->assertNull($this->service()->members[self::USER]['frozen_at']);
        $this->assertContains('unfrozen', $this->service()->eventTypes());
    }

    public function testRemovingFromTheListEndsTheLiveGrants(): void
    {
        $this->activeGrant();

        $this->postRowAction('remove', 'Baja solicitada por el cliente');
        \HWE_Memberships_Admin_Members_Screen::handleAction();

        foreach ($this->service()->grantsOf(self::USER) as $grant) {
            $this->assertSame('ended', (string) $grant['status'], 'Quitar termina las concesiones activas.');
        }

        $removed = $this->lastEventOfType('removed_by_admin');
        $this->assertSame('admin', $removed['actor']);
        $this->assertSame(self::ACTOR, (int) $removed['actor_id']);
        $this->assertStringContainsString('Baja solicitada', (string) $removed['data']);
    }

    // ── CSV ─────────────────────────────────────────────────────────────────

    public function testCsvCellsThatExcelWouldExecuteAreMadeInert(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", \HWE_Memberships_Admin_Csv::csvCell('=HYPERLINK("http://x")'));
        $this->assertSame("'+1", \HWE_Memberships_Admin_Csv::csvCell('+1'));
        $this->assertSame("'-1+cmd|' /C calc'!A0", \HWE_Memberships_Admin_Csv::csvCell("-1+cmd|' /C calc'!A0"));
        $this->assertSame('-1', \HWE_Memberships_Admin_Csv::csvCell('-1'), 'Un número negativo es un dato, no una fórmula.');
        $this->assertSame("'@SUM(A1)", \HWE_Memberships_Admin_Csv::csvCell('@SUM(A1)'));
        $this->assertSame("'\t=1", \HWE_Memberships_Admin_Csv::csvCell("\t=1"));
        $this->assertSame("'\r=1", \HWE_Memberships_Admin_Csv::csvCell("\r=1"));
        $this->assertSame('Cliente Siete', \HWE_Memberships_Admin_Csv::csvCell('Cliente Siete'));
        $this->assertSame('', \HWE_Memberships_Admin_Csv::csvCell(''));
    }

    public function testTheCsvStreamsInBatchesWithoutEmailOrLogin(): void
    {
        $this->wpdb()->memberRows = array();
        for ($i = 0; $i < \HWE_Memberships_Admin_Csv::BATCH + 1; $i++) {
            $this->wpdb()->memberRows[] = (object) array(
                'user_id'       => self::USER,
                'display_name'  => '=2+2',
                'level_id'      => self::LEVEL,
                'covers_until'  => $this->day(30),
                'frozen_at'     => null,
                'member_number' => 'M-7',
                'member_since'  => $this->day(-10),
                'user_email'    => 'siete@example.test',
                'user_login'    => 'cliente-siete',
            );
        }

        $filters = \HWE_Memberships_Admin_Members_Screen::filters(array('hwe_memberships_level' => (string) self::LEVEL));
        $handle  = fopen('php://memory', 'w+');
        $this->assertIsResource($handle);

        fputcsv($handle, \HWE_Memberships_Admin_Csv::columns(), ',', '"', '\\');
        $written = \HWE_Memberships_Admin_Csv::writeRows($handle, $filters);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $this->assertSame(\HWE_Memberships_Admin_Csv::BATCH + 1, $written);
        $this->assertStringContainsString("'=2+2", $csv, 'El nombre visible peligroso queda inerte.');
        $this->assertStringContainsString('Nivel 101', $csv);
        $this->assertStringNotContainsString('siete@example.test', $csv, 'El CSV no lleva el email.');
        $this->assertStringNotContainsString('cliente-siete', $csv, 'El CSV no lleva el login.');

        $first  = $this->wpdb()->callsContaining('SELECT m.user_id');
        $this->assertGreaterThanOrEqual(2, count($first), 'El volcado va por lotes, no de una sola vez.');
        $this->assertSame(array(\HWE_Memberships_Admin_Csv::BATCH, 0), $first[0]['args']);
        $this->assertSame(array(\HWE_Memberships_Admin_Csv::BATCH, \HWE_Memberships_Admin_Csv::BATCH), $first[1]['args']);
    }

    public function testOnlyManagersCanExportTheMembersCsv(): void
    {
        $this->caps = array('hwe_memberships_view');

        $this->assertFalse(
            \HWE_Memberships_Admin_Csv::canExport(),
            'Ver el listado no basta para una exportación masiva.'
        );
        \HWE_Memberships_Admin_Csv::export();
        $this->assertSame(array('No autorizado.'), $this->died);

        $viewer = $this->render(array(\HWE_Memberships_Admin_Members_Screen::class, 'render'));
        $this->assertStringNotContainsString('Exportar CSV', $viewer, 'La acción no se ofrece a quien solo puede ver.');

        $this->caps[] = 'hwe_memberships_manage';
        $this->assertTrue(\HWE_Memberships_Admin_Csv::canExport());

        $manager = $this->render(array(\HWE_Memberships_Admin_Members_Screen::class, 'render'));
        $this->assertStringContainsString('Exportar CSV', $manager);
    }

    // ── Columna del listado de usuarios ─────────────────────────────────────

    public function testTheUserListColumnSortsAndFiltersInSql(): void
    {
        $query = $this->userQuery('hwe_memberships');
        \HWE_Memberships_Admin_User_Columns::applySort($query);

        $this->assertStringContainsString('LEFT JOIN wp_hwe_memberships_members hwe_mm', $query->query_from);
        $this->assertStringContainsString('COALESCE(hwe_mm.level_id, 0) DESC', $query->query_orderby);

        $login = $this->userQuery('login');
        \HWE_Memberships_Admin_User_Columns::applySort($login);
        $this->assertSame('', $login->query_from, 'Una ordenación ajena no toca la consulta.');
        $this->assertSame('', $login->query_orderby);

        $_GET = array(
            \HWE_Memberships_Admin_User_Columns::LEVEL_QUERY  => (string) self::LEVEL,
            \HWE_Memberships_Admin_User_Columns::STATUS_QUERY => 'frozen',
        );
        $filtered = $this->userQuery('login');
        \HWE_Memberships_Admin_User_Columns::applyFilter($filtered);

        $this->assertStringContainsString('LEFT JOIN wp_hwe_memberships_members hwe_mm', $filtered->query_from);
        $this->assertStringContainsString('hwe_mm.level_id = 101', $filtered->query_where);
        $this->assertStringContainsString('hwe_mm.frozen_at IS NOT NULL', $filtered->query_where);

        $_GET = array(\HWE_Memberships_Admin_User_Columns::LEVEL_QUERY => 'inventado');
        $untouched = $this->userQuery('login');
        \HWE_Memberships_Admin_User_Columns::applyFilter($untouched);
        $this->assertSame('', $untouched->query_from, 'Un filtro inválido no se aplica en silencio.');
        $this->assertSame('', $untouched->query_where);
    }

    public function testTheUserListCellShowsLevelEndAndFrozenState(): void
    {
        $this->activeGrant();

        $cell = \HWE_Memberships_Admin_User_Columns::render('', \HWE_Memberships_Admin_User_Columns::COLUMN, (string) self::USER);
        $this->assertStringContainsString('Nivel 101', $cell);
        $this->assertStringContainsString('user-edit.php?user_id=7', $cell);
        $this->assertStringContainsString('hasta', $cell);

        \HWE_Memberships_Admin_User_Columns::flushCache();
        $this->service()->members[self::USER]['frozen_at'] = $this->day(0);
        $frozen = \HWE_Memberships_Admin_User_Columns::render('', \HWE_Memberships_Admin_User_Columns::COLUMN, (string) self::USER);
        $this->assertStringContainsString('congelado', $frozen);

        $this->assertSame('—', \HWE_Memberships_Admin_User_Columns::render('', \HWE_Memberships_Admin_User_Columns::COLUMN, (string) self::OTHER));
        $this->assertSame('otra', \HWE_Memberships_Admin_User_Columns::render('otra', 'email', (string) self::USER));
    }

    // ── Perfil de usuario ───────────────────────────────────────────────────

    public function testGrantingFromTheProfileDelegatesToGrantsAndLeavesHistory(): void
    {
        Functions\expect('get_users')->never();

        $this->postProfileAction('grant', array(
            'hwe_memberships_grant_level' => (string) self::LEVEL,
            'hwe_memberships_grant_days'  => '30',
            'hwe_memberships_note'        => 'Cortesía',
            'hwe_memberships_key'         => 'a1b2c3d4',
        ));
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $grants = $this->service()->grantsOf(self::USER);
        $this->assertCount(1, $grants, 'Conceder crea una concesión de administración.');
        $this->assertSame('admin', $grants[0]['origin']);
        $this->assertSame('Cortesía', $grants[0]['note']);
        $this->assertSame(self::LEVEL, (int) $grants[0]['level_id']);

        $event = $this->lastEventOfType('granted_by_admin');
        $this->assertSame('admin', $event['actor']);
        $this->assertSame(self::ACTOR, (int) $event['actor_id']);
        $this->assertCount(1, $this->redirects);
        $this->assertNotEmpty($this->transients, 'Queda un aviso para el panel.');

        // Un doble envío del mismo formulario no concede dos veces.
        \HWE_Memberships_Admin_User_Profile::handleAction();
        $this->assertCount(1, $this->service()->grantsOf(self::USER));
    }

    public function testAnIndefiniteGrantStoresNoEndDate(): void
    {
        $this->postProfileAction('grant', array(
            'hwe_memberships_grant_level' => (string) self::LEVEL,
            'hwe_memberships_grant_days'  => '0',
            'hwe_memberships_note'        => '',
            'hwe_memberships_key'         => 'ffffeeee',
        ));
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $grants = $this->service()->grantsOf(self::USER);
        $this->assertCount(1, $grants);
        $this->assertNull($grants[0]['ends_at'], 'Cero días = indefinida.');
    }

    public function testExtendingChainsANewAdminGrantOfTheSameLevel(): void
    {
        $first = $this->activeGrant(self::USER, self::LEVEL2, 10);
        $endsAt = (string) $this->service()->grants[$first]['ends_at'];

        $this->postProfileAction('extend', array(
            'hwe_memberships_extend_days' => '30',
            'hwe_memberships_note'        => 'Ampliación',
            'hwe_memberships_key'         => 'extendkey1',
        ));
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $grants = $this->service()->grantsOf(self::USER);
        $this->assertCount(2, $grants, 'Extender es una concesión nueva del mismo nivel.');
        $fresh = $grants[array_key_last($grants)];
        $this->assertSame(self::LEVEL2, (int) $fresh['level_id']);
        $this->assertSame('admin', $fresh['origin']);
        $this->assertSame($endsAt, $fresh['starts_at'], 'La línea de tiempo la encadena detrás de la cobertura.');
        $this->assertSame('Ampliación', $fresh['note']);
    }

    public function testExtendingWithoutALiveMembershipIsRejected(): void
    {
        $this->postProfileAction('extend', array(
            'hwe_memberships_extend_days' => '30',
            'hwe_memberships_note'        => '',
            'hwe_memberships_key'         => 'extendkey2',
        ));
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $this->assertSame(array(), $this->service()->grantsOf(self::USER));
        $this->assertSame('no_membership', (string) ($this->latestTransient()['code'] ?? ''));
    }

    public function testRemovingFreezingAndUnfreezingFromTheProfileDelegateToGrants(): void
    {
        $this->activeGrant();

        $this->postProfileAction('freeze');
        \HWE_Memberships_Admin_User_Profile::handleAction();
        $this->assertNotNull($this->service()->members[self::USER]['frozen_at']);
        $this->assertContains('frozen', $this->service()->eventTypes());
        $this->assertSame('admin', $this->lastEventOfType('frozen')['actor']);

        $this->postProfileAction('unfreeze');
        \HWE_Memberships_Admin_User_Profile::handleAction();
        $this->assertNull($this->service()->members[self::USER]['frozen_at']);
        $this->assertContains('unfrozen', $this->service()->eventTypes());

        $this->postProfileAction('remove', array('hwe_memberships_note' => 'Baja'));
        \HWE_Memberships_Admin_User_Profile::handleAction();
        foreach ($this->service()->grantsOf(self::USER) as $grant) {
            $this->assertSame('ended', (string) $grant['status']);
        }
        $removed = $this->lastEventOfType('removed_by_admin');
        $this->assertSame('admin', $removed['actor']);
        $this->assertSame(self::ACTOR, (int) $removed['actor_id']);
    }

    public function testTheProfileActionsRequireManageCapabilityAndNonce(): void
    {
        $this->caps = array('hwe_memberships_view');

        $this->postProfileAction('grant', array(
            'hwe_memberships_grant_level' => (string) self::LEVEL,
            'hwe_memberships_grant_days'  => '30',
            'hwe_memberships_key'         => 'aabbccdd',
        ));
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $this->assertNotEmpty($this->died);
        $this->assertSame(array(), $this->service()->grantsOf(self::USER));

        $this->caps       = array('hwe_memberships_view', 'hwe_memberships_manage');
        $this->nonceValid = false;
        $this->died       = array();
        \HWE_Memberships_Admin_User_Profile::handleAction();

        $this->assertNotEmpty($this->died);
        $this->assertSame(array(), $this->service()->grantsOf(self::USER));
        $this->assertSame(array(), $this->redirects);
    }

    public function testTheProfileShowsTheStateAndOnlyExplicitActions(): void
    {
        $this->activeGrant(self::USER, self::LEVEL, 30);
        $this->service()->seedGrant(array(
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL2,
            'origin'          => 'admin',
            'status'          => 'scheduled',
            'starts_at'       => $this->day(30),
            'ends_at'         => $this->day(60),
            'idempotency_key' => 'admin:queued:1',
        ));

        $html = $this->render(fn () => \HWE_Memberships_Admin_User_Profile::render($this->users[self::USER]));

        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringContainsString('Nivel 102', $html, 'La cola programada se muestra por nivel.');
        $this->assertStringContainsString('hwe_memberships_profile_action', $html);
        $this->assertStringContainsString('value="grant"', $html);
        $this->assertStringContainsString('value="extend"', $html);
        $this->assertStringContainsString('value="freeze"', $html);
        $this->assertStringContainsString('value="remove"', $html);
        $this->assertStringContainsString('hwe_memberships_profile_nonce', $html);
        $this->assertStringContainsString('M-7', $html);

        // No hay un campo «nivel» que sobrescriba el estado: solo una concesión explícita.
        $this->assertStringNotContainsString('name="hwe_memberships_level"', $html);
        $this->assertStringContainsString('name="hwe_memberships_grant_level"', $html);
    }

    public function testTheProfileWithoutManageOnlyShowsReadOnlyData(): void
    {
        $this->activeGrant();
        $this->caps = array('hwe_memberships_view');

        $html = $this->render(fn () => \HWE_Memberships_Admin_User_Profile::render($this->users[self::USER]));

        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringNotContainsString('hwe_memberships_profile_action', $html);
        $this->assertStringNotContainsString('value="grant"', $html);
    }

    // ── Historial ───────────────────────────────────────────────────────────

    public function testTheHistoryFiltersResolveInSqlWithPagination(): void
    {
        $filters = \HWE_Memberships_Admin_History_Screen::filters(array(
            'hwe_user'  => 'siete@example.test',
            'hwe_type'  => 'activated',
            'hwe_level' => (string) self::LEVEL,
            'hwe_from'  => '2024-01-01',
            'hwe_to'    => '2024-12-31',
        ));

        $this->assertSame(self::USER, $filters['user_id']);
        $this->assertSame('activated', $filters['type']);
        $this->assertSame(self::LEVEL, $filters['level']);

        \HWE_Memberships_Admin_History_Screen::query($filters, 30, 60);
        $call = $this->wpdb()->lastCallContaining('FROM wp_hwe_memberships_events e');
        $this->assertNotNull($call);
        $this->assertStringContainsString('e.user_id = 7', $call['sql']);
        $this->assertStringContainsString("e.type = 'activated'", $call['sql']);
        $this->assertStringContainsString('(e.level_from = 101 OR e.level_to = 101)', $call['sql']);
        $this->assertStringContainsString("e.created_at >= '2024-01-01 00:00:00'", $call['sql']);
        $this->assertStringContainsString("e.created_at <= '2024-12-31 23:59:59'", $call['sql']);
        $this->assertStringContainsString('ORDER BY e.created_at DESC, e.id DESC', $call['sql']);
        $this->assertSame(array(30, 60), $call['args']);

        $unknown = \HWE_Memberships_Admin_History_Screen::filters(array('hwe_user' => 'no-existe'));
        $this->assertSame(-1, $unknown['user_id'], 'Un usuario inexistente no ignora el filtro en silencio.');
    }

    public function testTheHistoryRowsAreEscaped(): void
    {
        $rows = array(
            (object) array(
                'id'           => 1,
                'user_id'      => self::USER,
                'display_name' => '<script>alert(1)</script>',
                'type'         => 'granted_by_admin',
                'level_from'   => 0,
                'level_to'     => self::LEVEL,
                'actor'        => 'admin',
                'actor_id'     => self::ACTOR,
                'data'         => array('note' => '<b>nota</b>'),
                'created_at'   => $this->day(0),
            ),
        );

        $method = new \ReflectionMethod(\HWE_Memberships_Admin_History_Screen::class, 'renderRows');
        ob_start();
        $method->invoke(null, $rows);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringContainsString('Concedida por administración', $html);
    }

    // ── Resumen ─────────────────────────────────────────────────────────────

    public function testTheSummaryAggregatesInSql(): void
    {
        Functions\expect('get_users')->never();

        $this->wpdb()->activeByLevel = array(
            (object) array('level_id' => self::LEVEL, 'total' => 4),
            (object) array('level_id' => self::LEVEL2, 'total' => 2),
        );
        $this->wpdb()->frozen       = 3;
        $this->wpdb()->expiring     = array('next7' => 1, 'next30' => 5);
        $this->wpdb()->expired      = 6;
        $this->wpdb()->activations  = 7;
        $this->wpdb()->renewals     = 8;

        $summary = \HWE_Memberships_Admin_Stats::summary(30);

        $this->assertSame(6, $summary['active']);
        $this->assertSame(3, $summary['frozen']);
        $this->assertSame(1, $summary['expiring_7']);
        $this->assertSame(5, $summary['expiring_30']);
        $this->assertSame(6, $summary['expired']);
        $this->assertSame(7, $summary['activations']);
        $this->assertSame(8, $summary['renewals']);
        $this->assertCount(2, $summary['active_by_level']);

        $grouped = $this->wpdb()->lastRawCallContaining('GROUP BY level_id');
        $this->assertNotNull($grouped);
        $this->assertStringContainsString('COUNT(*)', $grouped['sql']);

        $expiring = $this->wpdb()->lastRawCallContaining('AS next7');
        $this->assertNotNull($expiring, 'Los avisos de 7 y 30 días salen de un solo agregado.');
    }

    public function testTheSummaryScreenRendersTheCounters(): void
    {
        $this->wpdb()->activeByLevel = array((object) array('level_id' => self::LEVEL, 'total' => 4));
        $this->wpdb()->frozen       = 3;

        $html = $this->render(array(\HWE_Memberships_Admin_Stats::class, 'render'));

        $this->assertStringContainsString('Resumen', $html);
        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringContainsString('4', $html);
        $this->assertStringContainsString('Congelados', $html);
    }

    // ── Caja del pedido ─────────────────────────────────────────────────────

    public function testTheOrderBoxReadsTheSharedMetasAndLivesInBothHposScreens(): void
    {
        $this->assertSame(
            array('shop_order', 'woocommerce_page_wc-orders'),
            \HWE_Memberships_Admin_Order_Box::screens()
        );

        $order = new \WC_Order();
        $order->id = 55;
        $order->update_meta_data(\HWE_Memberships_Purchase::META_ORDER_STATE, array(
            'version' => 1,
            'status'  => \HWE_Memberships_Purchase::STATUS_PROCESSED,
            'items'   => array(
                array('item' => 5, 'product' => 90, 'level_id' => self::LEVEL, 'days' => 30, 'grant_id' => 12, 'key' => 'k'),
            ),
        ));
        $order->update_meta_data('_hwe_membership_discount', '2.5000');

        $item = new \WC_Order_Item_Product(5, 50.0, 0.0, 90, 1);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_LEVEL, self::LEVEL);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_DAYS, 30);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_GRANT, 12);
        $order->line_items = array($item);

        $this->wpdb()->eventRows = array(
            (object) array(
                'id'         => 1,
                'grant_id'   => 12,
                'type'       => \HWE_Memberships_Events::POINTS_SKIPPED,
                'data'       => array('points' => 25),
                'created_at' => $this->day(0),
            ),
        );

        ob_start();
        \HWE_Memberships_Admin_Order_Box::display($order);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Activada', $html);
        $this->assertStringContainsString('Nivel 101', $html);
        $this->assertStringContainsString('30', $html);
        $this->assertStringContainsString('2,50', $html, 'El descuento de miembro se lee de su meta.');
        $this->assertStringContainsString('omitidos', $html, 'Los puntos no abonados se ven en la caja.');
    }

    public function testTheOrderBoxShowsARevokedLineAndAcceptsExtraRowsFromTheFilter(): void
    {
        $order = new \WC_Order();
        $order->id = 56;

        $item = new \WC_Order_Item_Product(6, 50.0, 0.0, 91, 1);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_LEVEL, self::LEVEL);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_GRANT, 13);
        $item->update_meta_data(\HWE_Memberships_Purchase::META_LINE_REVOKED, $this->day(0));
        $order->line_items = array($item);

        $this->extraOrderRows = array(
            array('label' => 'Envío gratis', 'value' => 'Usado', 'state' => 'ok'),
        );

        ob_start();
        \HWE_Memberships_Admin_Order_Box::display($order);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Revocada', $html);
        $this->assertStringContainsString('Envío gratis', $html);
        $this->assertStringContainsString('Usado', $html);
    }

    public function testAnOrderWithoutMembershipDataShowsTheEmptyState(): void
    {
        $order = new \WC_Order();
        $order->id = 57;

        ob_start();
        \HWE_Memberships_Admin_Order_Box::display($order);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Sin membresía', $html);
    }

    // ── utilidades del test ─────────────────────────────────────────────────

    /** @param array<string,mixed> $extra */
    private function postRowAction(string $action, string $note = ''): void
    {
        $_POST = array(
            'hwe_memberships_member_nonce' => 'nonce',
            'user_id'                      => (string) self::USER,
            'hwe_memberships_action'       => $action,
            'hwe_memberships_note'         => $note,
        );
    }

    /** @param array<string,mixed> $extra */
    private function postProfileAction(string $action, array $extra = array()): void
    {
        $_POST = array_merge(array(
            'hwe_memberships_profile_nonce' => 'nonce',
            'user_id'                       => (string) self::USER,
            'hwe_memberships_action'        => $action,
            'hwe_memberships_note'          => '',
        ), $extra);
    }

    /** @return array<string,mixed> */
    private function lastEventOfType(string $type): array
    {
        $found = array();
        foreach ($this->service()->event_rows as $row) {
            if ((string) ($row['type'] ?? '') === $type) {
                $found = $row;
            }
        }

        $this->assertNotSame(array(), $found, 'Debe quedar un evento «' . $type . '».');
        return $found;
    }

    /** @return array<string,mixed> */
    private function latestTransient(): array
    {
        $value = array();
        foreach ($this->transients as $item) {
            $value = is_array($item) ? $item : array();
        }
        return $value;
    }

    private function userQuery(string $orderby, string $direction = 'DESC'): object
    {
        return new class ($orderby, $direction) {
            public string $query_from = '';
            public string $query_where = '';
            public string $query_orderby = '';
            private string $orderby;
            private string $direction;
            public function __construct(string $orderby, string $direction)
            {
                $this->orderby   = $orderby;
                $this->direction = $direction;
            }
            public function get(string $key): string
            {
                return match ($key) {
                    'orderby' => $this->orderby,
                    'order'   => $this->direction,
                    default   => '',
                };
            }
        };
    }
}

/**
 * Doble de `$wpdb` para la administración de socios.
 *
 * Envuelve al de los servicios del módulo (concesiones, socios, eventos y tokens) y resuelve las
 * consultas propias del panel: listados paginados, agregados del resumen, la cuota de envíos y el
 * historial. Los filtros se comprueban sobre el SQL generado en los tests; el doble solo devuelve
 * las filas sembradas para que el pintado se pueda probar sin base de datos.
 */
final class TestAdminMembersWpdb
{
    public string $prefix = 'wp_';
    public string $users = 'wp_users';
    public string $options = 'wp_options';
    public TestMembershipsServiceWpdb $service;

    /** @var list<array{sql:string,args:list<mixed>}> */
    public array $calls = array();

    /** @var list<array{sql:string,args:list<mixed>}> Todas las consultas, preparadas o no. */
    public array $rawCalls = array();

    /** @var list<object> Filas del listado de socios. */
    public array $memberRows = array();

    /** @var list<object> Filas del historial. */
    public array $eventRows = array();

    /** @var list<object> */
    public array $activeByLevel = array();

    /** @var array{next7:int,next30:int} */
    public array $expiring = array('next7' => 0, 'next30' => 0);

    public int $frozen = 0;
    public int $expired = 0;
    public int $activations = 0;
    public int $renewals = 0;

    /** @var array{used:int,reserved:int} */
    public array $quota = array('used' => 0, 'reserved' => 0);

    public function __construct()
    {
        $this->service = new TestMembershipsServiceWpdb();
    }

    public function __get(string $name): mixed
    {
        return $this->service->{$name};
    }

    public function get_charset_collate(): string
    {
        return $this->service->get_charset_collate();
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $this->calls[] = array('sql' => $sql, 'args' => $this->flatten($args));
        return $this->service->prepare($sql, ...$args);
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        return $this->service->insert($table, $data, $format);
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        return $this->service->update($table, $data, $where, $format, $whereFormat);
    }

    public function query(string $sql): int|false
    {
        return $this->service->query($sql);
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return $this->service->suppress_errors($suppress);
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        return $this->service->get_col($sql);
    }

    public function get_var(string $sql): string|int|null
    {
        $args = $this->args($sql);
        $this->rawCalls[] = array('sql' => $sql, 'args' => $args);

        if (str_contains($sql, 'COUNT(DISTINCT user_id)')) {
            return $this->expired;
        }
        if (str_contains($sql, 'FROM wp_hwe_memberships_members m')) {
            return count($this->memberRows);
        }
        if (str_contains($sql, 'FROM wp_hwe_memberships_events')) {
            if (str_contains($sql, 'level_from = 0')) {
                return $this->activations;
            }
            if (str_contains($sql, "type = 'renewed'")) {
                return $this->renewals;
            }
            if (str_contains($sql, "type = 'expired'")) {
                return $this->expired;
            }
        }
        if (str_contains($sql, 'FROM wp_hwe_memberships_members') && str_contains($sql, 'frozen_at IS NOT NULL')) {
            return $this->frozen;
        }

        return $this->service->get_var($sql);
    }

    public function get_row(string $sql): ?object
    {
        $this->rawCalls[] = array('sql' => $sql, 'args' => $this->args($sql));

        if (str_contains($sql, 'AS next7')) {
            return (object) $this->expiring;
        }
        if (str_contains($sql, 'shipping_quota_used')) {
            return (object) $this->quota;
        }

        return $this->service->get_row($sql);
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $args = $this->args($sql);
        $this->rawCalls[] = array('sql' => $sql, 'args' => $args);

        if (str_contains($sql, 'FROM wp_hwe_memberships_members') && str_contains($sql, 'GROUP BY level_id')) {
            return $this->activeByLevel;
        }

        if (str_contains($sql, 'FROM wp_hwe_memberships_members m')) {
            [$limit, $offset] = $this->limitOffset($args);
            return array_slice($this->memberRows, $offset, $limit);
        }

        if (str_contains($sql, 'FROM wp_hwe_memberships_events e')) {
            [$limit, $offset] = $this->limitOffset($args);
            return array_slice($this->eventRows, $offset, $limit);
        }

        if (str_contains($sql, 'FROM wp_hwe_memberships_events') && str_contains($sql, 'grant_id IN')) {
            $grantIds = array_map('intval', array_slice($args, 1));
            $rows     = array();
            foreach ($this->eventRows as $row) {
                if (!in_array((int) ($row->grant_id ?? 0), $grantIds, true)) {
                    continue;
                }
                if ((string) ($row->type ?? '') !== (string) ($args[0] ?? '')) {
                    continue;
                }
                $rows[] = $row;
            }
            return $rows;
        }

        if (str_contains($sql, 'AS next7')) {
            return array();
        }

        return $this->service->get_results($sql);
    }

    /** @return list<array{sql:string,args:list<mixed>}> */
    public function callsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => str_contains($call['sql'], $needle)
        ));
    }

    /** @return array{sql:string,args:list<mixed>}|null */
    public function lastCallContaining(string $needle): ?array
    {
        $calls = $this->callsContaining($needle);
        return array() === $calls ? null : $calls[array_key_last($calls)];
    }

    /** @return array{sql:string,args:list<mixed>}|null */
    public function lastRawCallContaining(string $needle): ?array
    {
        $calls = array_values(array_filter(
            $this->rawCalls,
            static fn (array $call): bool => str_contains($call['sql'], $needle)
        ));
        return array() === $calls ? null : $calls[array_key_last($calls)];
    }

    /** @return list<mixed> */
    public function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : array();
        }
        return array();
    }

    /** @return list<mixed> */
    private function flatten(array $args): array
    {
        if (1 === count($args) && is_array($args[0])) {
            return array_values($args[0]);
        }
        return array_values($args);
    }

    /** @return array{0:int,1:int} */
    private function limitOffset(array $args): array
    {
        $count = count($args);
        if ($count < 2) {
            return array(count($this->memberRows), 0);
        }
        return array((int) $args[$count - 2], (int) $args[$count - 1]);
    }
}
