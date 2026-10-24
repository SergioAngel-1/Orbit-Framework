<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Capacidades de administración del módulo `memberships` (spec §11.4).
 *
 * `hwe_memberships_view` lee (menú, niveles, beneficios y comparativa) y
 * `hwe_memberships_manage` escribe (guardar beneficios). Se conceden solo a `administrator` y
 * `shop_manager`: un rol creado por una instancia nunca las recibe por su cuenta. La concesión
 * llega en `activated_plugin` y se autorrepara en `admin_menu` (antes de que WordPress compruebe
 * el acceso a las páginas) y en `admin_init`; ambas pasadas son idempotentes y no escriben si
 * las capacidades ya están.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CapabilitiesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = array();

    /** @var list<string> Capacidades del usuario simulado. */
    private array $caps = array();

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = array();
        $this->caps   = array();

        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed =>
                'active_plugins' === $name ? array('hwe-memberships/hwe-memberships.php') : $default
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            '__' => static fn ($value): string => (string) $value,
        ]);

        $this->loadOwnFile('plugins/hwe-memberships/hwe-memberships.php');
    }

    public function testActivationGrantsBothCapabilitiesOnlyToTheTwoCoreRoles(): void
    {
        $roles = array();
        Functions\when('get_role')->alias(function (string $slug) use (&$roles) {
            if (!in_array($slug, array('administrator', 'shop_manager'), true)) {
                return false;
            }
            return $roles[$slug] ??= new TestMembershipsCapabilitiesRole($slug);
        });

        \HWE_Memberships_Admin_Capabilities::onActivated('otro-plugin/otro.php');
        $this->assertSame(array(), $roles, 'Activar otro plugin no concede capacidades de este módulo.');

        \HWE_Memberships_Admin_Capabilities::onActivated('hwe-memberships/hwe-memberships.php');
        \HWE_Memberships_Admin_Capabilities::grant();

        $this->assertSame(
            array('hwe_memberships_view' => true, 'hwe_memberships_manage' => true),
            $roles['administrator']->caps,
            'La concesión es idempotente: dos pasadas no duplican ni cambian nada.'
        );
        $this->assertSame(
            array('hwe_memberships_view' => true, 'hwe_memberships_manage' => true),
            $roles['shop_manager']->caps
        );
        $this->assertSame(
            array('administrator', 'shop_manager'),
            array_keys($roles),
            'Ningún rol de una instancia recibe las capacidades.'
        );
    }

    public function testTheRepairOnlyTouchesTheTwoCoreRolesAndDoesNotRewriteWhatIsAlreadyGranted(): void
    {
        $administrator = new TestMembershipsCapabilitiesRole('administrator');
        $shopManager   = new TestMembershipsCapabilitiesRole('shop_manager');
        $instanceRole  = new TestMembershipsCapabilitiesRole('hwe_vendor');
        $asked         = array();
        Functions\when('get_role')->alias(
            static function (string $slug) use ($administrator, $shopManager, $instanceRole, &$asked) {
                $asked[] = $slug;
                return match ($slug) {
                    'administrator' => $administrator,
                    'shop_manager'  => $shopManager,
                    'hwe_vendor'    => $instanceRole,
                    default         => false,
                };
            }
        );

        \HWE_Memberships_Admin_Capabilities::maybeRepair();

        $this->assertTrue($administrator->has_cap('hwe_memberships_view'));
        $this->assertTrue($administrator->has_cap('hwe_memberships_manage'));
        $this->assertTrue($shopManager->has_cap('hwe_memberships_manage'));
        $this->assertSame(array(), $instanceRole->caps, 'Un rol de la instancia nunca recibe las capacidades.');
        $this->assertNotContains('hwe_vendor', $asked, 'La reparación ni siquiera consulta roles de la instancia.');
        $this->assertNotEmpty($this->logged, 'La autorreparación se registra.');
        $this->assertSame(4, $administrator->writes + $shopManager->writes, 'Dos capacidades por rol.');

        \HWE_Memberships_Admin_Capabilities::maybeRepair();

        $this->assertSame(
            4,
            $administrator->writes + $shopManager->writes,
            'Con las capacidades ya puestas no se vuelve a escribir.'
        );
    }

    public function testTheRepairRunsBeforeTheMenuAndAlsoOnAdminInit(): void
    {
        \HWE_Memberships_Admin_Capabilities::register();

        $priority = has_action(
            'admin_menu',
            array(\HWE_Memberships_Admin_Capabilities::class, 'maybeRepair')
        );
        $this->assertNotFalse($priority, 'La reparación se engancha a admin_menu.');
        $this->assertLessThan(
            10,
            $priority,
            'Debe correr antes de que WordPress compruebe el acceso a las páginas del módulo.'
        );
        $this->assertNotFalse(
            has_action('admin_init', array(\HWE_Memberships_Admin_Capabilities::class, 'maybeRepair')),
            'admin_init cubre admin-post.php y AJAX.'
        );
    }

    public function testARepairRefreshesTheCapabilitiesOfTheCurrentUser(): void
    {
        $administrator = new TestMembershipsCapabilitiesRole('administrator');
        $shopManager   = new TestMembershipsCapabilitiesRole('shop_manager');
        Functions\when('get_role')->alias(
            static fn (string $slug) => match ($slug) {
                'administrator' => $administrator,
                'shop_manager'  => $shopManager,
                default         => false,
            }
        );

        $current = new class () {
            public int $refreshed = 0;

            public function get_role_caps(): array
            {
                $this->refreshed++;
                return array();
            }
        };
        Functions\when('wp_get_current_user')->justReturn($current);

        \HWE_Memberships_Admin_Capabilities::maybeRepair();

        $this->assertSame(1, $current->refreshed, 'El usuario de esta petición debe ver ya las capacidades.');
    }

    public function testThePluginHooksTheActivationGrantForItsOwnFile(): void
    {
        $this->assertNotFalse(
            has_action(
                'activated_plugin',
                array(\HWE_Memberships_Admin_Capabilities::class, 'onActivated')
            ),
            'El arranque del plugin engancha la concesión de capacidades.'
        );
    }

    public function testTheCapabilitiesMatchTheOnesTheLevelPostTypeDeclares(): void
    {
        $this->assertSame(
            \HWE_Memberships_Levels::CAP_VIEW,
            \HWE_Memberships_Admin_Capabilities::CAP_VIEW
        );
        $this->assertSame(
            \HWE_Memberships_Levels::CAP_MANAGE,
            \HWE_Memberships_Admin_Capabilities::CAP_MANAGE
        );
    }

    public function testCanViewIsImpliedByManage(): void
    {
        $this->caps = array('hwe_memberships_manage');
        Functions\when('current_user_can')->alias(
            fn (string $cap): bool => in_array($cap, $this->caps, true)
        );

        $this->assertTrue(\HWE_Memberships_Admin_Capabilities::canView());
        $this->assertTrue(\HWE_Memberships_Admin_Capabilities::canManage());

        $this->caps = array('hwe_memberships_view');
        $this->assertTrue(\HWE_Memberships_Admin_Capabilities::canView());
        $this->assertFalse(\HWE_Memberships_Admin_Capabilities::canManage());

        $this->caps = array();
        $this->assertFalse(\HWE_Memberships_Admin_Capabilities::canView());
        $this->assertFalse(\HWE_Memberships_Admin_Capabilities::canManage());
    }
}

/** Rol mínimo de WordPress para probar la concesión de capacidades del módulo. */
final class TestMembershipsCapabilitiesRole
{
    public string $slug;
    /** @var array<string,bool> */
    public array $caps = array();
    public int $writes = 0;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    public function add_cap(string $cap, bool $grant = true): void
    {
        $this->writes++;
        $this->caps[$cap] = $grant;
    }

    public function has_cap(string $cap): bool
    {
        return !empty($this->caps[$cap]);
    }
}
