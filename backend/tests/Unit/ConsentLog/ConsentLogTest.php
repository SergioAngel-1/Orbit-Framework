<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\ConsentLog;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-consent-log` (módulo `consent-log`): el registro de decisiones del banner de
 * cookies. Solo lo escribe el BFF; aquí se prueba lo que decide el plugin y no el BFF: qué
 * se acepta, cuándo una decisión es una retirada, a quién se vincula un comprobante y quién
 * puede llamar.
 *
 * `$wpdb` se simula con un doble que anota lo que se le pide (no interpreta SQL): lo que
 * importa es QUÉ fila se inserta y si se inserta.
 *
 * Cada test en su proceso: el archivo define una clase global y se registra al cargarse.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConsentLogTest extends TestCase
{
    private const RECEIPT = '0a1b2c3d-1111-4222-8333-444455556666';
    private const TEXT = 'Usamos cookies de analítica y publicidad si las aceptas.';

    /** @var array<string,mixed> Opción `hwe_config` de Control Center. */
    private array $config = [];

    protected function setUp(): void
    {
        parent::setUp();

        eval('class HWE_Test_Wpdb {
            public string $prefix = "wp_";
            public array $inserts = [];
            public array $queries = [];
            /** Última fila del comprobante (lo que devuelve get_row). */
            public $last = null;
            /** Resultado de get_var según un fragmento de la consulta. */
            public array $vars = [];
            public function get_charset_collate() { return ""; }
            public function prepare($sql, ...$args) {
                if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
                return $sql . " /* " . json_encode($args) . " */";
            }
            public function get_var($sql) {
                foreach ($this->vars as $needle => $value) {
                    if (str_contains($sql, $needle)) { return $value; }
                }
                return null;
            }
            public function get_row($sql) { return $this->last; }
            public function get_col($sql) { return []; }
            public function get_results($sql) { return []; }
            public function query($sql) { $this->queries[] = $sql; return 1; }
            public function insert($table, $data) { $this->inserts[] = [$table, $data]; return 1; }
        }');

        $GLOBALS['wpdb'] = new \HWE_Test_Wpdb();
        if (!defined('OBJECT')) {
            define('OBJECT', 'OBJECT');
        }
        $this->config = [];
        Functions\when('get_option')->alias(fn (string $name, $default = false) => $name === 'hwe_config' ? $this->config : $default);

        Functions\stubs([
            'wp_check_invalid_utf8' => static fn ($v) => $v,
            'get_page_by_path' => null,
            'get_userdata' => static fn ($id) => (int) $id === 7 ? (object) ['ID' => 7] : false,
        ]);
    }

    private function load(): void
    {
        $this->loadOwnFile('plugins/hwe-consent-log/hwe-consent-log.php');
    }

    private function wpdb(): \HWE_Test_Wpdb
    {
        return $GLOBALS['wpdb'];
    }

    /** @param array<string,mixed> $override */
    private function record(array $override = []): \WP_REST_Request
    {
        return new \WP_REST_Request($override + [
            'receipt' => self::RECEIPT,
            'decision' => 'accepted',
            'action' => 'first',
            'variant' => 'bar',
            'text' => self::TEXT,
            'text_hash' => hash('sha256', self::TEXT),
            'services' => ['ga4', 'meta'],
            'analytics' => true,
            'advertising' => true,
            'locale' => 'es',
            'generation' => 2,
            'client_at' => '2026-10-13T10:00:00Z',
            'path' => '/tienda',
            'ip' => '203.0.113.0',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    private function lastRow(string $decision = 'accepted', ?int $userId = null): object
    {
        return (object) [
            'receipt' => self::RECEIPT,
            'user_id' => $userId,
            'decision' => $decision,
            'analytics' => $decision === 'accepted' ? 1 : 0,
            'advertising' => $decision === 'accepted' ? 1 : 0,
            'services' => 'ga4,meta',
            'text_hash' => hash('sha256', self::TEXT),
            'policy_version' => null,
            'generation' => 2,
            'variant' => 'bar',
            'locale' => 'es',
        ];
    }

    /** @return array<string,mixed> La fila insertada en el registro (no en la tabla de textos). */
    private function insertedRow(): array
    {
        $rows = array_values(array_filter(
            $this->wpdb()->inserts,
            static fn (array $insert): bool => str_ends_with($insert[0], 'hwe_consent_log'),
        ));
        $this->assertCount(1, $rows, 'Se esperaba exactamente una fila en el registro.');
        return $rows[0][1];
    }

    // ── quién puede llamar ───────────────────────────────────────────────────

    public function testEveryRouteDemandsTheInternalSecretAndFailsClosedWithoutIt(): void
    {
        $registered = [];
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$registered) {
            $registered[$route] = $args;
            return true;
        });
        $this->load();

        \HWE_Consent_Log::registerRoutes();

        $this->assertEqualsCanonicalizing(['/consent/record', '/consent/latest', '/consent/link'], array_keys($registered));
        foreach ($registered as $route => $args) {
            $this->assertFalse(
                $args['permission_callback'](new \WP_REST_Request()),
                "$route debe fallar cerrado si el mu-plugin que valida el secreto no está.",
            );
        }
    }

    public function testTheRoutesAcceptTheRequestOnlyWhenTheSecretIsValid(): void
    {
        $registered = [];
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$registered) {
            $registered[$route] = $args;
            return true;
        });
        $valid = false;
        Functions\when('hwe_has_internal_secret')->alias(function () use (&$valid): bool {
            return $valid;
        });
        $this->load();
        \HWE_Consent_Log::registerRoutes();
        $request = new \WP_REST_Request();

        $this->assertFalse($registered['/consent/record']['permission_callback']($request));
        $valid = true;
        $this->assertTrue($registered['/consent/record']['permission_callback']($request));
    }

    // ── registrar una decisión ───────────────────────────────────────────────

    public function testRejectsARecordWhoseTextDoesNotMatchItsHash(): void
    {
        $this->load();

        $response = \HWE_Consent_Log::handleRecord($this->record(['text_hash' => hash('sha256', 'otro texto')]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->wpdb()->inserts);
    }

    public function testRejectsAMalformedReceiptDecisionOrAction(): void
    {
        $this->load();

        foreach ([['receipt' => 'no-es-un-uuid'], ['decision' => 'maybe'], ['action' => 'revoke'], ['variant' => 'popup']] as $bad) {
            $response = \HWE_Consent_Log::handleRecord($this->record($bad));
            $this->assertSame(422, $response->get_status(), json_encode($bad));
        }
        $this->assertSame([], $this->wpdb()->inserts);
    }

    public function testStoresTheTextOnceAndTheDecisionWithItsServices(): void
    {
        $this->load();

        $response = \HWE_Consent_Log::handleRecord($this->record(['services' => ['ga4', 'plausible', 'gtm']]));

        $this->assertSame(201, $response->get_status());
        $row = $this->insertedRow();
        $this->assertSame('first', $row['action']);
        $this->assertSame('accepted', $row['decision']);
        $this->assertSame('ga4,plausible,gtm', $row['services'], 'plausible es un servicio más del banner.');
        $this->assertSame(hash('sha256', self::TEXT), $row['text_hash']);
        $this->assertSame(2, $row['generation']);
        $this->assertNotEmpty(array_filter(
            $this->wpdb()->queries,
            static fn (string $q): bool => str_contains($q, 'INSERT IGNORE INTO wp_hwe_consent_texts'),
        ));
    }

    public function testIgnoresServicesTheFrameworkDoesNotKnow(): void
    {
        $this->load();

        \HWE_Consent_Log::handleRecord($this->record(['services' => ['ga4', 'evil,injected', 'meta']]));

        $this->assertSame('ga4,meta', $this->insertedRow()['services']);
    }

    public function testADeclinedDecisionNeverStoresGrantedCategories(): void
    {
        $this->load();

        \HWE_Consent_Log::handleRecord($this->record(['decision' => 'declined', 'analytics' => true, 'advertising' => true]));

        $row = $this->insertedRow();
        $this->assertSame(0, $row['analytics']);
        $this->assertSame(0, $row['advertising']);
    }

    public function testAChangeThatWithdrawsAnAcceptanceIsStoredAsARevocation(): void
    {
        $this->wpdb()->last = $this->lastRow('accepted');
        $this->load();

        \HWE_Consent_Log::handleRecord($this->record(['action' => 'change', 'decision' => 'declined']));

        $this->assertSame('revoke', $this->insertedRow()['action']);
    }

    public function testAChangeThatAcceptsIsNotARevocation(): void
    {
        $this->wpdb()->last = $this->lastRow('declined');
        $this->load();

        \HWE_Consent_Log::handleRecord($this->record(['action' => 'change', 'decision' => 'accepted']));

        $this->assertSame('change', $this->insertedRow()['action']);
    }

    public function testAResentRecordIsNotDuplicated(): void
    {
        $this->wpdb()->vars = ['FROM wp_hwe_consent_log WHERE receipt' => '41'];
        $this->load();

        $response = \HWE_Consent_Log::handleRecord($this->record());

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['duplicate']);
        $this->assertSame([], $this->wpdb()->inserts);
    }

    public function testTheUserIdOfTheRecordMustBeAnExistingAccount(): void
    {
        $this->load();

        \HWE_Consent_Log::handleRecord($this->record(['user_id' => 999]));

        $this->assertNull($this->insertedRow()['user_id']);
    }

    // ── vincular el comprobante a la cuenta ─────────────────────────────────

    public function testLinkingCopiesTheCurrentDecisionOntoTheAccount(): void
    {
        $this->wpdb()->last = $this->lastRow('accepted');
        $this->wpdb()->vars = ['COUNT(*)' => '0'];
        $this->load();

        $response = \HWE_Consent_Log::handleLink(new \WP_REST_Request(['receipt' => self::RECEIPT, 'user_id' => 7, 'ip' => '203.0.113.0']));

        $this->assertSame(201, $response->get_status());
        $row = $this->insertedRow();
        $this->assertSame('linked', $row['action']);
        $this->assertSame(7, $row['user_id']);
        $this->assertSame('accepted', $row['decision']);
        $this->assertSame('ga4,meta', $row['services']);
    }

    public function testAReceiptAlreadyLinkedToAnotherAccountIsNotLinked(): void
    {
        $this->wpdb()->last = $this->lastRow('accepted', 5);
        $this->wpdb()->vars = ['COUNT(*)' => '1'];
        $this->load();

        $response = \HWE_Consent_Log::handleLink(new \WP_REST_Request(['receipt' => self::RECEIPT, 'user_id' => 7]));

        $this->assertSame('other_account', $response->get_data()['reason']);
        $this->assertSame([], $this->wpdb()->inserts, 'Las decisiones de una persona no se atribuyen a otra.');
    }

    public function testLinkingAnUnknownReceiptOrAMissingUserDoesNothing(): void
    {
        $this->load();

        $unknown = \HWE_Consent_Log::handleLink(new \WP_REST_Request(['receipt' => self::RECEIPT, 'user_id' => 7]));
        $noUser = \HWE_Consent_Log::handleLink(new \WP_REST_Request(['receipt' => self::RECEIPT, 'user_id' => 999]));

        $this->assertSame('unknown_receipt', $unknown->get_data()['reason']);
        $this->assertSame(422, $noUser->get_status());
        $this->assertSame([], $this->wpdb()->inserts);
    }

    public function testRelinkingTheSameAccountIsANoOp(): void
    {
        $this->wpdb()->last = $this->lastRow('accepted', 7);
        $this->load();

        $response = \HWE_Consent_Log::handleLink(new \WP_REST_Request(['receipt' => self::RECEIPT, 'user_id' => 7]));

        $this->assertSame('already_linked', $response->get_data()['reason']);
        $this->assertSame([], $this->wpdb()->inserts);
    }

    // ── decisión vigente ─────────────────────────────────────────────────────

    public function testLatestReportsTheDecisionOfTheLastRow(): void
    {
        $row = $this->lastRow('declined');
        $row->created_at = '2026-10-13 10:00:00';
        $this->wpdb()->last = $row;
        $this->load();

        $data = \HWE_Consent_Log::handleLatest(new \WP_REST_Request(['receipt' => self::RECEIPT]))->get_data();

        $this->assertSame('declined', $data['decision']);
        $this->assertSame(2, $data['generation']);
        $this->assertSame('2026-10-13T10:00:00Z', $data['created_at']);
    }

    public function testLatestOfAnUnknownReceiptHasNoDecision(): void
    {
        $this->load();

        $data = \HWE_Consent_Log::handleLatest(new \WP_REST_Request(['receipt' => self::RECEIPT]))->get_data();

        $this->assertNull($data['decision']);
    }

    public function testLatestRejectsAMalformedReceipt(): void
    {
        $this->load();

        $this->assertSame(422, \HWE_Consent_Log::handleLatest(new \WP_REST_Request(['receipt' => "x' OR 1=1"]))->get_status());
    }

    // ── datos personales y retención ─────────────────────────────────────────

    public function testDeletingAnAccountDetachesItsRowsInsteadOfDeletingThem(): void
    {
        $this->load();

        \HWE_Consent_Log::detachUser(7);

        $this->assertCount(1, $this->wpdb()->queries);
        $this->assertStringContainsString('UPDATE wp_hwe_consent_log SET user_id = NULL', $this->wpdb()->queries[0]);
        $this->assertStringNotContainsString('DELETE', $this->wpdb()->queries[0]);
    }

    public function testRetentionDefaultsToFiveYearsWhenControlCenterSaysNothing(): void
    {
        $this->load();

        $this->assertSame(5, $this->callPrivate('retentionYears'));
        $this->config = ['integrations' => ['consent_retention_years' => 'abc']];
        $this->assertSame(5, $this->callPrivate('retentionYears'), 'Un valor que no es número cae al defecto.');
    }

    public function testRetentionReadsTheYearsFromControlCenterAndZeroKeepsEverything(): void
    {
        $this->config = ['integrations' => ['consent_retention_years' => 2]];
        $this->load();
        $this->assertSame(2, $this->callPrivate('retentionYears'));

        $this->config = ['integrations' => ['consent_retention_years' => 0]];
        $this->assertSame(0, $this->callPrivate('retentionYears'));
        \HWE_Consent_Log::purgeExpired();
        $this->assertSame([], $this->wpdb()->queries, 'Con 0 no se borra nada.');
    }

    public function testCsvCellsThatLookLikeFormulasAreMadeInert(): void
    {
        $this->load();

        $this->assertSame("'=HYPERLINK(\"http://x\")", $this->callPrivate('csvCell', '=HYPERLINK("http://x")'));
        $this->assertSame("'@SUM(A1)", $this->callPrivate('csvCell', '@SUM(A1)'));
        $this->assertSame('Mozilla/5.0', $this->callPrivate('csvCell', 'Mozilla/5.0'));
        $this->assertSame('', $this->callPrivate('csvCell', ''));
    }

    /** @return mixed */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(\HWE_Consent_Log::class, $method);
        return $reflection->invoke(null, ...$args);
    }
}
