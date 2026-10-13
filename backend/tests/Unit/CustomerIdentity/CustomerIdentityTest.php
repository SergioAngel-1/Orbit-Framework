<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\CustomerIdentity;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 2) . '/Stubs/wc-order.php';

/**
 * `hwe-customer-identity` (tarea 53): documento único y de escritura única, aceptación de términos,
 * datos de extensión del checkout y enmascarado en los correos del cliente.
 *
 * Los tipos de documento salen del paquete de país (`hwe-geo`), así que cada test fija el país de
 * la tienda. El archivo declara una clase final con estado estático: cada test corre en su proceso.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CustomerIdentityTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = ['ecommerce' => ['country' => 'CO']];
    /** @var array<int,array<string,string>> user_id => meta */
    private array $userMeta = [];
    /** @var list<int> ids de usuarios que existen */
    private array $users = [7, 8];
    /** @var array<string,int> "TIPO|NUMERO" => id del dueño */
    private array $owners = [];
    /** @var array<int,array{string,string,mixed}> */
    private array $metaWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'wp_json_encode' => static fn ($v) => json_encode($v),
            'sanitize_text_field' => static fn ($v) => trim((string) $v),
            'wp_unslash' => static fn ($v) => $v,
            'esc_html' => static fn ($v) => (string) $v,
            'wp_date' => static fn (string $f, int $t) => gmdate($f, $t),
        ]);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => 'hwe_config' === $key ? $this->config : $default);
        Functions\when('get_userdata')->alias(fn (int $id) => in_array($id, $this->users, true) ? (object) ['ID' => $id] : false);
        Functions\when('get_user_meta')->alias(fn (int $id, string $key) => $this->userMeta[$id][$key] ?? '');
        Functions\when('update_user_meta')->alias(function (int $id, string $key, $value): bool {
            $this->userMeta[$id][$key] = (string) $value;
            $this->metaWrites[] = [(string) $id, $key, $value];
            return true;
        });
        Functions\when('get_users')->alias(function (array $args): array {
            $type = $number = '';
            foreach ($args['meta_query'] as $clause) {
                if (is_array($clause) && 'billing_document_type' === ($clause['key'] ?? '')) {
                    $type = $clause['value'];
                }
                if (is_array($clause) && 'billing_document' === ($clause['key'] ?? '')) {
                    $number = $clause['value'];
                }
            }
            $owner = $this->owners["$type|$number"] ?? 0;
            return $owner > 0 && !in_array($owner, $args['exclude'], true) ? [$owner] : [];
        });
        Functions\when('hwe_has_internal_secret')->justReturn(true);

        $this->loadOwnFile('mu-plugins/hwe-geo/packs.php');
        $this->loadOwnFile('plugins/hwe-customer-identity/hwe-customer-identity.php');
    }

    private function request(array $params): \WP_REST_Request
    {
        return new \WP_REST_Request($params);
    }

    // --- tipos de documento: los del paquete de país ---------------------------------------------

    public function testLosTiposSalenDelPaqueteDeLaTienda(): void
    {
        $this->assertSame(['CC', 'CE', 'PP', 'NIT'], array_keys(\HWE_Customer_Identity::types()));
    }

    public function testUnPaisSinDocumentosNoAdmiteNingunTipo(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES']];

        $this->assertSame([], \HWE_Customer_Identity::types());
        $this->assertFalse(\HWE_Customer_Identity::isValid('CC', '1012345678'));
    }

    public function testNormalizaSinEspaciosPuntosNiGuionesYEnMayusculas(): void
    {
        $this->assertSame('900123456', \HWE_Customer_Identity::normalizeNumber(' 900.123.456 '));
        $this->assertSame('900123456', \HWE_Customer_Identity::normalizeNumber('900-123-456'));
        $this->assertSame('AB12345', \HWE_Customer_Identity::normalizeNumber('ab 12345'));
    }

    public function testValidaElNumeroContraElPatronDeSuTipo(): void
    {
        $this->assertTrue(\HWE_Customer_Identity::isValid('CC', '1012345678'));
        $this->assertFalse(\HWE_Customer_Identity::isValid('CC', '0123456789'));
        $this->assertTrue(\HWE_Customer_Identity::isValid('NIT', '9001234567'));
        $this->assertFalse(\HWE_Customer_Identity::isValid('NIT', '123'));
        $this->assertFalse(\HWE_Customer_Identity::isValid('XX', '1012345678'), 'un tipo que el paquete no tiene');
    }

    // --- document-available ----------------------------------------------------------------------

    public function testDocumentoLibreYOcupado(): void
    {
        $this->owners['CC|1012345678'] = 8;

        $taken = \HWE_Customer_Identity::handleDocumentAvailable($this->request(['document_type' => 'cc', 'document_number' => '1.012.345.678']));
        $free  = \HWE_Customer_Identity::handleDocumentAvailable($this->request(['document_type' => 'CC', 'document_number' => '1098765432']));

        $this->assertSame(['available' => false], $taken->get_data());
        $this->assertSame(['available' => true], $free->get_data());
    }

    public function testUnDocumentoInvalidoEsUn400(): void
    {
        $r = \HWE_Customer_Identity::handleDocumentAvailable($this->request(['document_type' => 'CC', 'document_number' => 'abc']));

        $this->assertSame(400, $r->get_status());
        $this->assertSame('invalid_document', $r->get_data()['error']);
    }

    // --- attach-identity -------------------------------------------------------------------------

    public function testUnUsuarioInexistenteEsUn404(): void
    {
        $r = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 99, 'document_type' => 'CC', 'document_number' => '1012345678']));

        $this->assertSame(404, $r->get_status());
    }

    public function testGuardaElDocumentoNormalizadoDeUnaCuentaSinDocumento(): void
    {
        $r = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'document_type' => 'cc', 'document_number' => '1.012.345.678']));

        $this->assertSame(200, $r->get_status());
        $this->assertSame('CC', $this->userMeta[7]['billing_document_type']);
        $this->assertSame('1012345678', $this->userMeta[7]['billing_document']);
        $this->assertSame('1012345678', $r->get_data()['document_number']);
    }

    public function testElDocumentoEsDeEscrituraUnica(): void
    {
        $this->userMeta[7] = ['billing_document_type' => 'CC', 'billing_document' => '1012345678'];

        $other = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'document_type' => 'CC', 'document_number' => '1098765432']));
        $same  = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'document_type' => 'CC', 'document_number' => '1.012.345.678']));

        $this->assertSame(409, $other->get_status());
        $this->assertSame('document_locked', $other->get_data()['error']);
        $this->assertSame(200, $same->get_status(), 'repetir el mismo es idempotente');
        $this->assertSame('1012345678', $this->userMeta[7]['billing_document']);
    }

    public function testElDocumentoEsUnicoEntreCuentas(): void
    {
        $this->owners['CC|1012345678'] = 8;

        $r = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'document_type' => 'CC', 'document_number' => '1012345678']));

        $this->assertSame(409, $r->get_status());
        $this->assertSame('document_taken', $r->get_data()['error']);
        $this->assertArrayNotHasKey(7, $this->userMeta, 'no se escribió nada');
    }

    public function testUnDocumentoInvalidoNoSeGuarda(): void
    {
        $r = \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'document_type' => 'CC', 'document_number' => '12']));

        $this->assertSame(400, $r->get_status());
        $this->assertSame([], $this->metaWrites);
    }

    public function testLaAceptacionDeTerminosSeRegistraUnaSolaVez(): void
    {
        \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'terms_accepted' => true]));
        $first = $this->userMeta[7]['hwe_terms_accepted_at'];
        $this->assertNotFalse(strtotime($first));

        $this->userMeta[7]['hwe_terms_accepted_at'] = '2020-01-01T00:00:00+00:00';
        \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'terms_accepted' => true]));

        $this->assertSame('2020-01-01T00:00:00+00:00', $this->userMeta[7]['hwe_terms_accepted_at']);
    }

    public function testSoloSeRegistraConUnTrueExplicito(): void
    {
        \HWE_Customer_Identity::handleAttachIdentity($this->request(['user_id' => 7, 'terms_accepted' => false]));

        $this->assertArrayNotHasKey(7, $this->userMeta);
    }

    public function testLasRutasExigenElSecretoInternoDelBff(): void
    {
        $permissions = [];
        Functions\when('register_rest_route')->alias(function (string $ns, string $route, array $args) use (&$permissions): bool {
            $permissions[$ns . $route] = $args['permission_callback'];
            return true;
        });

        \HWE_Customer_Identity::registerRoutes();

        $this->assertSame(['hwe/v1/auth/document-available', 'hwe/v1/auth/attach-identity'], array_keys($permissions));
        foreach ($permissions as $callback) {
            $this->assertTrue($callback($this->request([])));
        }
        Functions\when('hwe_has_internal_secret')->justReturn(false);
        foreach ($permissions as $callback) {
            $this->assertFalse($callback($this->request([])), 'sin el secreto interno no hay acceso');
        }
    }

    // --- Store API: el pedido nace con su documento ----------------------------------------------

    private function checkoutRequest(array $data): \WP_REST_Request
    {
        return new \WP_REST_Request(['extensions' => ['hwe-identity' => $data]]);
    }

    public function testElPedidoNaceConSuDocumentoYSuAceptacion(): void
    {
        $order = new \WC_Order();

        \HWE_Customer_Identity::applyCheckoutData($order, $this->checkoutRequest([
            'document_type' => 'cc',
            'document_number' => '1.012.345.678',
            'terms_accepted_at' => '2026-10-13T10:00:00+00:00',
        ]));

        $this->assertSame('CC', $order->meta['_hwe_document_type']);
        $this->assertSame('1012345678', $order->meta['_hwe_document_number']);
        $this->assertSame('2026-10-13T10:00:00+00:00', $order->meta['_hwe_terms_accepted_at']);
        $this->assertSame(1, $order->saved);
    }

    public function testUnDocumentoOUnaFechaInvalidosSeDescartanSinRomperLaCompra(): void
    {
        $order = new \WC_Order();

        \HWE_Customer_Identity::applyCheckoutData($order, $this->checkoutRequest([
            'document_type' => 'CC',
            'document_number' => 'no-es-un-numero',
            'terms_accepted_at' => 'ayer por la tarde',
        ]));

        $this->assertSame([], $order->meta);
    }

    public function testSinDatosDeExtensionNoHaceNada(): void
    {
        $order = new \WC_Order();

        \HWE_Customer_Identity::applyCheckoutData($order, new \WP_REST_Request(['extensions' => []]));
        \HWE_Customer_Identity::applyCheckoutData(new \stdClass(), new \WP_REST_Request([]));

        $this->assertSame([], $order->meta);
        $this->assertSame(0, $order->saved);
    }

    // --- wp-admin: perfil ------------------------------------------------------------------------

    public function testElPerfilOfreceLosTiposDelPaquete(): void
    {
        $fields = \HWE_Customer_Identity::profileFields(['billing' => ['fields' => []]]);

        $this->assertSame(['' => '—', 'CC' => 'Cédula de ciudadanía', 'CE' => 'Cédula de extranjería', 'PP' => 'Pasaporte', 'NIT' => 'NIT'], $fields['billing']['fields']['billing_document_type']['options']);
        $this->assertArrayHasKey('billing_document', $fields['billing']['fields']);
    }

    public function testSinTiposEnElPaisElPerfilNoAnadeCampos(): void
    {
        $this->config = ['ecommerce' => ['country' => 'ES']];

        $this->assertSame(['billing' => ['fields' => []]], \HWE_Customer_Identity::profileFields(['billing' => ['fields' => []]]));
    }

    public function testElAdminNormalizaElDocumentoAntesDeQueWooLoGuarde(): void
    {
        $_POST = ['billing_document_type' => 'cc', 'billing_document' => '1.012.345.678'];

        \HWE_Customer_Identity::guardProfileSave(7);

        $this->assertSame('CC', $_POST['billing_document_type']);
        $this->assertSame('1012345678', $_POST['billing_document']);
    }

    public function testElAdminNoPuedeGuardarUnDocumentoInvalidoNiAjeno(): void
    {
        $this->userMeta[7] = ['billing_document_type' => 'CC', 'billing_document' => '1012345678'];
        $this->owners['CC|1098765432'] = 8;

        foreach ([['CC', '12'], ['CC', '1098765432']] as [$type, $number]) {
            $_POST = ['billing_document_type' => $type, 'billing_document' => $number];
            \HWE_Customer_Identity::guardProfileSave(7);
            $this->assertSame('1012345678', $_POST['billing_document'], 'se repone el valor guardado');
        }

        $errors = new class () extends \WP_Error {
            public array $added = [];
            public function add($code, $message = '', $data = ''): void
            {
                $this->added[$code] = $message;
            }
        };
        \HWE_Customer_Identity::validateProfileUpdate($errors);
        $this->assertArrayHasKey('hwe_document', $errors->added);
    }

    public function testElAdminPuedeRetirarElDocumento(): void
    {
        $this->userMeta[7] = ['billing_document_type' => 'CC', 'billing_document' => '1012345678'];
        $_POST = ['billing_document_type' => '', 'billing_document' => ''];

        \HWE_Customer_Identity::guardProfileSave(7);

        $this->assertSame('', $_POST['billing_document_type']);
        $this->assertSame('', $_POST['billing_document']);
    }

    // --- correos ---------------------------------------------------------------------------------

    private function orderWithDocument(): \WC_Order
    {
        $order = new \WC_Order();
        $order->meta = ['_hwe_document_type' => 'CC', '_hwe_document_number' => '1012345050'];
        return $order;
    }

    private function email(string $type, \WC_Order $order, bool $toAdmin, bool $plain = false): string
    {
        ob_start();
        \HWE_Customer_Identity::renderEmailIdentity($type, $order, $toAdmin, $plain);
        return (string) ob_get_clean();
    }

    public function testElCorreoDelClienteLlevaElDocumentoEnmascarado(): void
    {
        $out = $this->email('billing', $this->orderWithDocument(), false);

        $this->assertSame('<br/>Cédula de ciudadanía: ***050', $out);
        $this->assertStringNotContainsString('1012345050', $out);
    }

    public function testElCorreoDeLaTiendaLlevaElDocumentoCompleto(): void
    {
        $this->assertSame('<br/>Cédula de ciudadanía: 1012345050', $this->email('billing', $this->orderWithDocument(), true));
    }

    public function testEnTextoPlanoNoLlevaHtml(): void
    {
        $this->assertSame("Cédula de ciudadanía: ***050\n", $this->email('billing', $this->orderWithDocument(), false, true));
    }

    public function testSoloLaDireccionDeFacturacionYSoloSiHayDocumento(): void
    {
        $this->assertSame('', $this->email('shipping', $this->orderWithDocument(), false));
        $this->assertSame('', $this->email('billing', new \WC_Order(), false));
    }

    public function testUnDocumentoCortoNoDejaVerNada(): void
    {
        $this->assertSame('***', \HWE_Customer_Identity::maskNumber('123'));
        $this->assertSame('***567', \HWE_Customer_Identity::maskNumber('1234567'));
    }

    public function testElTipoQueElPaqueteYaNoTieneSeMuestraTalCual(): void
    {
        $order = new \WC_Order();
        $order->meta = ['_hwe_document_type' => 'DNI', '_hwe_document_number' => '12345678'];

        $this->assertSame('<br/>DNI: ***678', $this->email('billing', $order, false));
    }
}
