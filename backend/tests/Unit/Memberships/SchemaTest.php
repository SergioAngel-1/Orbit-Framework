<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Esquema del módulo `memberships` (`HWE_Memberships_Schema`): tablas InnoDB para concesiones,
 * estado por usuario, historial y tokens del carné, versión en una opción y migración al
 * detectar una versión vieja (también fuera de la activación).
 *
 * `$wpdb` y `dbDelta` se simulan: lo que importa es que el SQL pida las cuatro tablas con sus
 * índices, que no se reinstale en cada petición, que un fallo al cargar `dbDelta` se registre
 * sin romper el sitio y que la activación fuerce la instalación aunque la opción ya exista.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SchemaTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var list<string> */
    private array $dbDelta = [];
    /** @var list<array{0:string,1:mixed,2:mixed}> */
    private array $optionWrites = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options      = [];
        $this->dbDelta      = [];
        $this->optionWrites = [];
        $this->logged       = [];

        $GLOBALS['wpdb'] = new TestMembershipsSchemaWpdb();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value, mixed $autoload = null): bool {
            $this->options[$name] = $value;
            $this->optionWrites[] = [$name, $value, $autoload];
            return true;
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
    }

    private function installWithDbDelta(): void
    {
        Functions\when('dbDelta')->alias(function (string $sql): array {
            $this->dbDelta[] = $sql;
            return [];
        });
    }

    public function testAnOldVersionCreatesEveryTableAndStoresTheNewVersion(): void
    {
        $this->options['hwe_memberships_db_version'] = '0';
        $this->installWithDbDelta();

        \HWE_Memberships_Schema::maybeInstall();

        $this->assertCount(1, $this->dbDelta);
        $sql = $this->dbDelta[0];

        foreach (['grants', 'members', 'events', 'card_tokens', 'batches'] as $suffix) {
            $this->assertStringContainsString(
                'CREATE TABLE wp_hwe_memberships_' . $suffix,
                $sql,
                'Falta la tabla ' . $suffix . '.'
            );
        }

        // Una concesión no puede concederse dos veces por el mismo evento.
        $this->assertStringContainsString('UNIQUE KEY idempotency_key (idempotency_key)', $sql);

        // El número de socio es único y estable; una fila por usuario.
        $this->assertStringContainsString('UNIQUE KEY member_number (member_number)', $sql);
        $this->assertStringContainsString('PRIMARY KEY  (user_id)', $sql);

        // Índices que usan la lectura por usuario y la tarea diaria.
        $this->assertStringContainsString('KEY user_status (user_id,status)', $sql);
        $this->assertStringContainsString('KEY status_ends (status,ends_at)', $sql);
        $this->assertStringContainsString('KEY status_starts (status,starts_at)', $sql);
        $this->assertStringContainsString('KEY level_status (level_id,status)', $sql);

        // Columnas que necesitarán las tareas siguientes (§7.3, §7.5, §5.5 y §5.6).
        foreach ([
            'queued_seconds',
            'shipping_quota_used',
            'shipping_quota_reserved',
            'referral_gifts',
            'expiry_notified_at',
            'frozen_seconds',
            'revoked_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, 'Falta la columna ' . $column . '.');
        }

        $this->assertSame(5, substr_count($sql, 'ENGINE=InnoDB'), 'Todas las tablas admiten transacciones.');

        $this->assertSame(\HWE_Memberships_Schema::DB_VERSION, $this->options['hwe_memberships_db_version']);
        $this->assertSame(
            [['hwe_memberships_db_version', \HWE_Memberships_Schema::DB_VERSION, true]],
            $this->optionWrites,
            'La versión se guarda con autoload para leerla en cada petición.'
        );
    }

    public function testTheCurrentVersionDoesNotRunTheSchemaAgain(): void
    {
        $this->options['hwe_memberships_db_version'] = \HWE_Memberships_Schema::DB_VERSION;
        Functions\expect('dbDelta')->never();

        \HWE_Memberships_Schema::maybeInstall();

        $this->assertSame([], $this->dbDelta);
        $this->assertSame([], $this->optionWrites);
    }

    /**
     * Migración de la versión 1 (concesiones, socios, historial y tokens) a la 2, que añade la
     * tabla de lotes. `dbDelta` es aditivo: no borra ni recrea las tablas existentes.
     */
    public function testAnInstallFromVersionOneMigratesToTheBatchTable(): void
    {
        $this->options['hwe_memberships_db_version'] = '1';
        $this->installWithDbDelta();

        \HWE_Memberships_Schema::maybeInstall();

        $this->assertCount(1, $this->dbDelta);
        $sql = $this->dbDelta[0];

        $this->assertStringContainsString('CREATE TABLE wp_hwe_memberships_batches', $sql);
        $this->assertStringContainsString('operation varchar(32) NOT NULL', $sql);
        $this->assertStringContainsString('last_user_id bigint(20) unsigned NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('processed bigint(20) unsigned NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('total bigint(20) unsigned NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('KEY status_created (status,created_at)', $sql);
        $this->assertStringContainsString('KEY actor_created (actor_id,created_at)', $sql);
        // La selección es un criterio, nunca una lista de ids de usuarios pendientes.
        $this->assertStringNotContainsString('user_ids', $sql);

        $this->assertSame('2', $this->options['hwe_memberships_db_version']);
    }

    public function testActivationForcesTheInstallEvenWhenTheVersionMatches(): void
    {
        $this->options['hwe_memberships_db_version'] = \HWE_Memberships_Schema::DB_VERSION;
        $this->installWithDbDelta();

        \HWE_Memberships_Schema::install();

        $this->assertCount(1, $this->dbDelta, 'La activación repara tablas perdidas aunque la opción ya exista.');
        $this->assertSame(\HWE_Memberships_Schema::DB_VERSION, $this->options['hwe_memberships_db_version']);
    }

    public function testWithoutDbDeltaTheInstallIsLoggedAndTheVersionIsNotAdvanced(): void
    {
        $this->options['hwe_memberships_db_version'] = '0';

        // Sin stub: `function_exists('dbDelta')` es falso y ABSPATH/wp-admin no existe en los tests.
        \HWE_Memberships_Schema::maybeInstall();

        $this->assertSame([], $this->dbDelta);
        $this->assertSame([], $this->optionWrites, 'No se marca la versión si no se crearon las tablas.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testRegisterHooksTheInstallOnInit(): void
    {
        Actions\expectAdded('init')
            ->once()
            ->with([\HWE_Memberships_Schema::class, 'maybeInstall'], 5);

        \HWE_Memberships_Schema::register();
    }

    public function testTableNamesUseTheSitePrefix(): void
    {
        $this->assertSame('wp_hwe_memberships_grants', \HWE_Memberships_Schema::grantsTable());
        $this->assertSame('wp_hwe_memberships_members', \HWE_Memberships_Schema::membersTable());
        $this->assertSame('wp_hwe_memberships_events', \HWE_Memberships_Schema::eventsTable());
        $this->assertSame('wp_hwe_memberships_card_tokens', \HWE_Memberships_Schema::cardTokensTable());
        $this->assertSame('wp_hwe_memberships_batches', \HWE_Memberships_Schema::batchesTable());
    }
}

/** Doble mínimo de `$wpdb` para la instalación del esquema. */
final class TestMembershipsSchemaWpdb
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
