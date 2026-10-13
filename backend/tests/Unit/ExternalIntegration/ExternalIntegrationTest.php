<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ExternalIntegration;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GraphQL\Error\UserError;
use HWE\ExternalIntegration\Access;
use HWE\ExternalIntegration\GraphqlGuard;
use HWE\ExternalIntegration\Policy;
use HWE\ExternalIntegration\Role;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Módulo `hwe-external-integration` (tarea 59): rol mínimo, Application Password acotada,
 * puerta de red por IP (en `hwe-bff-only.php`), política cerrada de operaciones, `updateOrder`
 * limitado al estado, `metaData` por lista cerrada y campos vetados.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExternalIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    /** Lo que `hwe_integration_policy` devuelve en este test (por defecto, sin tocar). */
    private mixed $policyOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        $this->policyOverride = null;
        $_SERVER = [];

        Functions\stubs([
            'sanitize_text_field' => static fn ($v) => is_string($v) ? trim($v) : '',
            'wp_unslash' => static fn ($v) => $v,
            'absint' => static fn ($v) => abs((int) $v),
            'plugin_dir_url' => static fn () => '',
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
        // El filtro de la política: por defecto devuelve lo que recibe.
        Filters\expectApplied('hwe_integration_policy')->andReturnUsing(
            fn ($defaults) => $this->policyOverride ?? $defaults
        );

        $this->loadOwnFile('plugins/hwe-external-integration/hwe-external-integration.php');
        Policy::reset();
    }

    private function info(string $parent, string $field): object
    {
        return (object) ['parentType' => (object) ['name' => $parent], 'fieldName' => $field];
    }

    /** Hace que la petición sea (o no) de la integración, por IP. */
    private function asIntegration(bool $restricted = true): void
    {
        Functions\when('hwe_bff_only_is_trusted_call')->justReturn(false);
        Functions\when('hwe_bff_only_is_integration_call')->justReturn($restricted);
        Functions\when('wp_get_current_user')->justReturn(new \WP_User());
    }

    private function asBff(): void
    {
        Functions\when('hwe_bff_only_is_trusted_call')->justReturn(true);
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(false);
        Functions\when('wp_get_current_user')->justReturn(new \WP_User());
    }

    private function integrationUser(): \WP_User
    {
        $user = new \WP_User();
        $user->roles = [Role::SLUG];
        return $user;
    }

    // ── Rol y capacidades ───────────────────────────────────────────────────────

    public function testElRolSoloPuedeLeerYEditarPedidos(): void
    {
        $caps = Role::capabilities();

        $this->assertSame(
            ['read', 'read_private_shop_orders', 'edit_shop_orders', 'edit_others_shop_orders', 'edit_private_shop_orders', 'edit_published_shop_orders'],
            array_keys($caps)
        );
        foreach (['manage_woocommerce', 'list_users', 'edit_products', 'edit_posts', 'edit_others_posts', 'manage_options'] as $cap) {
            $this->assertArrayNotHasKey($cap, $caps, $cap);
        }
    }

    public function testElRolSeCreaUnaSolaVezPorVersion(): void
    {
        $created = 0;
        Functions\when('get_option')->justReturn(Role::VERSION);
        Functions\when('get_role')->justReturn(new \stdClass());
        Functions\when('add_role')->alias(function () use (&$created): void {
            ++$created;
        });
        Role::ensure();
        $this->assertSame(0, $created, 'Con la versión al día no hace nada.');

        Functions\when('get_option')->justReturn(Role::VERSION - 1);
        Functions\when('remove_role')->justReturn(null);
        Functions\when('update_option')->justReturn(true);
        Role::ensure();
        $this->assertSame(1, $created);
    }

    public function testElPedidoConHposSeAutorizaSoloParaElRolYSoloParaEseTipo(): void
    {
        Functions\when('get_userdata')->alias(fn ($id) => 7 === $id ? $this->integrationUser() : new \WP_User());
        Functions\when('get_post_type')->alias(fn ($id) => 100 === $id ? 'shop_order_placehold' : 'post');

        // El rol: edit_post de un pedido → capacidad de pedidos (no `edit_others_posts`).
        $this->assertSame(['edit_shop_orders'], Role::mapMetaCap(['edit_others_posts'], 'edit_post', 7, [100]));
        $this->assertSame(['read_private_shop_orders'], Role::mapMetaCap(['read'], 'read_post', 7, [100]));
        // El rol sobre una entrada del blog: no se toca.
        $this->assertSame(['edit_others_posts'], Role::mapMetaCap(['edit_others_posts'], 'edit_post', 7, [200]));
        // Otro usuario sobre un pedido: no se toca.
        $this->assertSame(['edit_others_posts'], Role::mapMetaCap(['edit_others_posts'], 'edit_post', 9, [100]));
        // Otra meta-capacidad: no se toca.
        $this->assertSame(['x'], Role::mapMetaCap(['x'], 'delete_post', 7, [100]));
    }

    // ── Acceso ──────────────────────────────────────────────────────────────────

    public function testSoloElUsuarioDeServicioPuedeTenerApplicationPasswords(): void
    {
        $this->assertTrue(Access::availableForUser(true, $this->integrationUser()));
        $this->assertFalse(Access::availableForUser(true, new \WP_User()));
        $this->assertFalse(Access::availableForUser(false, $this->integrationUser()));
    }

    public function testLaContrasenaSoloSirveDesdeLaIpAutorizada(): void
    {
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(false);
        Functions\when('is_graphql_http_request')->justReturn(true);
        $error = new \WP_Error();

        Access::locks($error, $this->integrationUser());

        $this->assertSame(['hwe_integration_ip'], $error->get_error_codes());
    }

    public function testLaContrasenaSoloSirveEnGraphql(): void
    {
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(true);
        Functions\when('is_graphql_http_request')->justReturn(false);
        $error = new \WP_Error();

        Access::locks($error, $this->integrationUser());

        $this->assertSame(['hwe_integration_graphql_only'], $error->get_error_codes());
    }

    public function testDesdeLaIpYEnGraphqlLaContrasenaPasa(): void
    {
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(true);
        Functions\when('is_graphql_http_request')->justReturn(true);
        $error = new \WP_Error();

        Access::locks($error, $this->integrationUser());

        $this->assertSame([], $error->get_error_codes());
    }

    public function testLosCandadosNoTocanAOtrosUsuarios(): void
    {
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(false);
        $error = new \WP_Error();

        Access::locks($error, new \WP_User());

        $this->assertSame([], $error->get_error_codes());
    }

    public function testLaCuentaDeServicioNoPuedeIniciarSesionInteractiva(): void
    {
        $result = Access::noInteractiveLogin($this->integrationUser());
        $other = new \WP_User();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('hwe_integration_no_login', $result->get_error_code());
        $this->assertSame($other, Access::noInteractiveLogin($other));
    }

    public function testElBffYWpCliNoQuedanRestringidos(): void
    {
        $this->asBff();
        $this->assertFalse(Access::isRestrictedRequest());
    }

    public function testUnaLlamadaDelBffDesdeUnaIpDeLaListaNoCuentaComoIntegracion(): void
    {
        // Mismo origen que la integración, pero con el secreto interno: es el BFF.
        Functions\when('hwe_bff_only_is_trusted_call')->justReturn(true);
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn($this->integrationUser());

        $this->assertFalse(Access::isRestrictedRequest());
    }

    public function testLaIpAutorizadaOElRolHacenLaPeticionRestringida(): void
    {
        $this->asIntegration(true);
        $this->assertTrue(Access::isRestrictedRequest());

        Functions\when('hwe_bff_only_is_trusted_call')->justReturn(false);
        Functions\when('hwe_bff_only_is_integration_call')->justReturn(false);
        Functions\when('wp_get_current_user')->justReturn($this->integrationUser());
        $this->assertTrue(Access::isRestrictedRequest());

        Functions\when('wp_get_current_user')->justReturn(new \WP_User());
        $this->assertFalse(Access::isRestrictedRequest());
    }

    // ── Política ────────────────────────────────────────────────────────────────

    public function testElDefectoPermiteLeerPedidosYCambiarSuEstado(): void
    {
        $policy = Policy::get();

        $this->assertSame(['order', 'orders'], $policy['queries']);
        $this->assertSame(['updateOrder'], $policy['mutations']);
        $this->assertSame(['id', 'orderId', 'status', 'clientMutationId'], $policy['update_order_fields']);
        $this->assertSame(['_carrier', '_tracking_number', '_tracking_url'], $policy['order_meta_keys']);
    }

    public function testLaInstanciaPuedeAbrirOperacionesPeroNuncaLasDeAutenticacion(): void
    {
        $this->policyOverride = ['queries' => ['order', 'orders', 'products', 'users', 'viewer', 'customers'], 'mutations' => ['updateOrder', 'login', 'registerUser']];

        $policy = Policy::get();

        $this->assertSame(['order', 'orders', 'products'], $policy['queries']);
        $this->assertSame(['updateOrder'], $policy['mutations']);
    }

    public function testLosCamposVetadosDelDefectoNoSePuedenQuitar(): void
    {
        $this->policyOverride = [
            'blocked_fields' => ['Order' => ['campoNuevo'], 'Producto' => ['costo']],
            'unpaid_statuses' => ['on-hold'],
        ];

        $policy = Policy::get();

        $this->assertContains('orderKey', $policy['blocked_fields']['Order']);
        $this->assertContains('campoNuevo', $policy['blocked_fields']['Order']);
        $this->assertSame(['costo'], $policy['blocked_fields']['Producto']);
        $this->assertContains('metaData', $policy['blocked_fields']['Customer']);
        // «sin pagar» solo se amplía: pending y failed siguen ahí.
        $this->assertEqualsCanonicalizing(['pending', 'failed', 'on-hold'], $policy['unpaid_statuses']);
    }

    public function testUnaPoliticaMalFormadaSeDescartaYQuedaEnElLog(): void
    {
        $this->policyOverride = ['queries' => 'order', 'order_meta_keys' => [5, '', '_carrier']];

        $policy = Policy::get();

        $this->assertSame([], $policy['queries']);
        $this->assertSame(['_carrier'], $policy['order_meta_keys']);
        $this->assertNotEmpty($this->logged);
    }

    public function testUnFiltroQueNoDevuelveUnaListaVuelveAlDefecto(): void
    {
        $this->policyOverride = 'roto';

        $this->assertSame(['order', 'orders'], Policy::get()['queries']);
        $this->assertNotEmpty($this->logged);
    }

    // ── Guarda de GraphQL ───────────────────────────────────────────────────────

    #[DataProvider('operacionesRechazadas')]
    public function testUnaOperacionFueraDeLaListaSeRechaza(string $parent, string $field): void
    {
        $this->asIntegration();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Operación no permitida');
        GraphqlGuard::preResolveField(null, null, [], null, $this->info($parent, $field));
    }

    /** @return iterable<string,array{string,string}> */
    public static function operacionesRechazadas(): iterable
    {
        yield 'login' => ['RootMutation', 'login'];
        yield 'registro' => ['RootMutation', 'registerUser'];
        yield 'entradas' => ['RootQuery', 'posts'];
        yield 'usuarios' => ['RootQuery', 'users'];
        yield 'clientes' => ['RootQuery', 'customers'];
        yield 'cupones' => ['RootQuery', 'coupons'];
    }

    public function testLasOperacionesPermitidasYLaIntrospeccionPasan(): void
    {
        $this->asIntegration();

        foreach ([['RootQuery', 'order'], ['RootQuery', 'orders'], ['RootMutation', 'updateOrder'], ['RootQuery', '__schema'], ['RootQuery', '__typename'], ['Order', 'status']] as [$parent, $field]) {
            $this->assertNull(
                GraphqlGuard::preResolveField(null, null, [], null, $this->info($parent, $field)),
                "$parent.$field"
            );
        }
    }

    #[DataProvider('camposVetados')]
    public function testUnCampoVetadoNoSeLee(string $parent, string $field): void
    {
        $this->asIntegration();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Campo no permitido');
        GraphqlGuard::preResolveField(null, null, [], null, $this->info($parent, $field));
    }

    /** @return iterable<string,array{string,string}> */
    public static function camposVetados(): iterable
    {
        yield 'orderKey' => ['Order', 'orderKey'];
        yield 'IP del comprador' => ['Order', 'customerIpAddress'];
        yield 'user agent' => ['Order', 'customerUserAgent'];
        yield 'notas internas' => ['Order', 'orderNotes'];
        yield 'token JWT' => ['Customer', 'jwtAuthToken'];
        yield 'secreto JWT' => ['Customer', 'jwtUserSecret'];
        yield 'sesión' => ['Customer', 'session'];
        yield 'meta del cliente' => ['Customer', 'metaData'];
    }

    public function testElBffNoQuedaRestringido(): void
    {
        $this->asBff();

        $this->assertNull(GraphqlGuard::preResolveField(null, null, [], null, $this->info('RootMutation', 'login')));
        $this->assertNull(GraphqlGuard::preResolveField(null, null, [], null, $this->info('Order', 'orderKey')));
        $this->assertSame(['billing' => 'x'], GraphqlGuard::mutationInput(['billing' => 'x'], null, null, 'updateOrder'));
        $this->assertSame('list_users', GraphqlGuard::customerCap('list_users'));
    }

    public function testUpdateOrderSoloAdmiteElEstado(): void
    {
        $this->asIntegration();

        $this->assertSame(
            ['orderId' => 5, 'status' => 'COMPLETED'],
            GraphqlGuard::mutationInput(['orderId' => 5, 'status' => 'COMPLETED'], null, null, 'updateOrder')
        );

        try {
            GraphqlGuard::mutationInput(['orderId' => 5, 'status' => 'COMPLETED', 'billing' => ['firstName' => 'X']], null, null, 'updateOrder');
            $this->fail('Debía rechazarse.');
        } catch (UserError $e) {
            $this->assertStringContainsString('solo admite cambiar el estado', $e->getMessage());
            $this->assertStringContainsString('billing', $e->getMessage());
        }

        $this->expectExceptionMessage('exige «status»');
        GraphqlGuard::mutationInput(['orderId' => 5], null, null, 'updateOrder');
    }

    public function testOtraMutacionNoPasaPorLaValidacionDeUpdateOrder(): void
    {
        $this->asIntegration();

        $this->assertSame(['x' => 1], GraphqlGuard::mutationInput(['x' => 1], null, null, 'otraMutacion'));
    }

    #[DataProvider('transicionesProhibidas')]
    public function testUnPedidoSinPagarSoloSePuedeCancelar(string $current, string $requested): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('sin pagar');
        GraphqlGuard::assertCanTransition($current, $requested, 5);
    }

    /** @return iterable<string,array{string,string}> */
    public static function transicionesProhibidas(): iterable
    {
        yield 'pendiente → procesando' => ['pending', 'PROCESSING'];
        yield 'pendiente → completado' => ['pending', 'wc-completed'];
        yield 'fallido → procesando' => ['failed', 'processing'];
        yield 'borrador de checkout → lo que sea' => ['checkout-draft', 'cancelled'];
    }

    public function testUnPedidoSinPagarSiSePuedeCancelarYUnoPagadoCambiaLibremente(): void
    {
        GraphqlGuard::assertCanTransition('pending', 'CANCELLED', 5);
        GraphqlGuard::assertCanTransition('failed', 'wc-cancelled', 5);
        GraphqlGuard::assertCanTransition('processing', 'COMPLETED', 5);
        GraphqlGuard::assertCanTransition('on-hold', 'processing', 5);

        $this->addToAssertionCount(1);
        $this->assertSame('processing', GraphqlGuard::target('wc-PROCESSING'));
    }

    public function testElSueloDeSinPagarNoSeRebajaDesdeLaPolitica(): void
    {
        $this->policyOverride = ['unpaid_statuses' => []];
        Policy::reset();

        $this->expectExceptionMessage('sin pagar');
        GraphqlGuard::assertCanTransition('pending', 'processing', 5);
    }

    // ── metaData y cliente ──────────────────────────────────────────────────────

    private function meta(string $key, string $value = 'v'): object
    {
        return new class ($key, $value) {
            public function __construct(private string $key, private string $value)
            {
            }

            /** @return array{key:string,value:string} */
            public function get_data(): array
            {
                return ['key' => $this->key, 'value' => $this->value];
            }
        };
    }

    private function keys(?array $result): array
    {
        return array_map(static fn ($m) => $m->get_data()['key'], $result ?? []);
    }

    public function testElMetaDelPedidoSoloDejaPasarLasClavesDeSeguimiento(): void
    {
        $this->asIntegration();
        $result = [
            $this->meta('_carrier'),
            $this->meta('_hwe_document_number'),
            $this->meta('placetopay_response'),
            $this->meta('_tracking_url'),
        ];

        $kept = GraphqlGuard::metaData($result, null, [], null, null, 'Order', 'metaData');

        $this->assertSame(['_carrier', '_tracking_url'], $this->keys($kept));
        $this->assertSame(['_carrier', '_tracking_url'], $this->keys(GraphqlGuard::metaData($result, null, [], null, null, 'Refund', 'metaData')));
    }

    public function testElMetaDeUnaLineaDejaElVisibleYOcultaElPrivado(): void
    {
        $this->asIntegration();
        $result = [$this->meta('modalidad'), $this->meta('_reduced_stock'), $this->meta('mezcla')];

        $kept = GraphqlGuard::metaData($result, null, [], null, null, 'LineItem', 'metaData');

        $this->assertSame(['modalidad', 'mezcla'], $this->keys($kept));
    }

    public function testSinNadaVisibleElMetaEsNulo(): void
    {
        $this->asIntegration();

        $this->assertNull(GraphqlGuard::metaData([$this->meta('_privado')], null, [], null, null, 'Order', 'metaData'));
    }

    public function testElMetaNoSeFiltraParaElBffNiEnOtrosCampos(): void
    {
        $result = [$this->meta('_privado')];

        $this->asBff();
        $this->assertSame($result, GraphqlGuard::metaData($result, null, [], null, null, 'Order', 'metaData'));

        $this->asIntegration();
        $this->assertSame($result, GraphqlGuard::metaData($result, null, [], null, null, 'Order', 'status'));
        $this->assertSame('x', GraphqlGuard::metaData('x', null, [], null, null, 'Order', 'metaData'));
    }

    public function testLaIntegracionPuedeLeerElClienteDeUnPedidoYNadaMas(): void
    {
        $this->asIntegration();

        $this->assertSame('edit_shop_orders', GraphqlGuard::customerCap('list_users'));
        $this->assertSame('manage_options', GraphqlGuard::customerCap('manage_options'));
    }

    public function testLaPoliticaPuedeAnadirClavesDeMetaVisibles(): void
    {
        $this->policyOverride = ['order_meta_keys' => ['_carrier', '_hwe_document_number']];
        $this->asIntegration();

        $kept = GraphqlGuard::metaData(
            [$this->meta('_hwe_document_number'), $this->meta('_otro')],
            null, [], null, null, 'Order', 'metaData'
        );

        $this->assertSame(['_hwe_document_number'], $this->keys($kept));
    }
}
