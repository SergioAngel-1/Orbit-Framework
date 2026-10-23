<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Esquema del libro mayor (`HWE_Loyalty_Schema`): tablas InnoDB, versión en una opción y
 * migración al detectar una versión vieja, no solo en la activación.
 *
 * `$wpdb` y `dbDelta` se simulan: lo que importa es que el SQL pida las cuatro tablas con sus
 * índices únicos, que no se reinstale en cada petición y que un fallo al cargar `dbDelta` se
 * registre sin romper el sitio.
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

        $GLOBALS['wpdb'] = new TestSchemaWpdb();

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

        $this->loadOwnFile('plugins/hwe-loyalty/includes/Schema.php');
    }

    private function installWithDbDelta(): void
    {
        Functions\when('dbDelta')->alias(function (string $sql): array {
            $this->dbDelta[] = $sql;
            return [];
        });
    }

    public function testAnOldVersionRunsTheSchemaAndStoresTheNewVersion(): void
    {
        $this->options['hwe_loyalty_db_version'] = '0';
        $this->installWithDbDelta();

        \HWE_Loyalty_Schema::maybeInstall();

        $this->assertCount(1, $this->dbDelta);
        $sql = $this->dbDelta[0];

        foreach (['ledger', 'balances', 'referrals', 'ledger_consumptions'] as $suffix) {
            $this->assertStringContainsString(
                'CREATE TABLE wp_hwe_loyalty_' . $suffix,
                $sql,
                'Falta la tabla ' . $suffix . '.'
            );
        }

        $this->assertStringContainsString('UNIQUE KEY idempotency_key (idempotency_key)', $sql);
        $this->assertStringContainsString('UNIQUE KEY user_id (user_id)', $sql, 'Un participante, una fila de referidos.');
        $this->assertStringContainsString('UNIQUE KEY code (code)', $sql);
        $this->assertStringContainsString('UNIQUE KEY debit_credit (debit_id,credit_id)', $sql);
        $this->assertStringContainsString(
            'KEY user_ref (user_id,ref_type,ref_id)',
            $sql,
            'La red suma los puntos generados por referido con este índice (sin él, recorre todo el libro del referidor).'
        );
        $this->assertSame(4, substr_count($sql, 'ENGINE=InnoDB'), 'Todas las tablas admiten transacciones.');

        $this->assertSame(\HWE_Loyalty_Schema::DB_VERSION, $this->options['hwe_loyalty_db_version']);
        $this->assertSame(
            [['hwe_loyalty_db_version', \HWE_Loyalty_Schema::DB_VERSION, true]],
            $this->optionWrites,
            'La versión se guarda con autoload para leerla en cada petición.'
        );
    }

    public function testTheCurrentVersionDoesNotRunTheSchemaAgain(): void
    {
        $this->options['hwe_loyalty_db_version'] = \HWE_Loyalty_Schema::DB_VERSION;
        Functions\expect('dbDelta')->never();

        \HWE_Loyalty_Schema::maybeInstall();

        $this->assertSame([], $this->dbDelta);
        $this->assertSame([], $this->optionWrites);
    }

    public function testWithoutDbDeltaTheInstallIsLoggedAndTheVersionIsNotAdvanced(): void
    {
        $this->options['hwe_loyalty_db_version'] = '0';

        // Sin stub: `function_exists('dbDelta')` es falso y ABSPATH/wp-admin no existe en los tests.
        \HWE_Loyalty_Schema::maybeInstall();

        $this->assertSame([], $this->dbDelta);
        $this->assertSame([], $this->optionWrites, 'No se marca la versión si no se crearon las tablas.');
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
    }

    public function testRegisterHooksTheInstallOnInit(): void
    {
        Actions\expectAdded('init')
            ->once()
            ->with([\HWE_Loyalty_Schema::class, 'maybeInstall'], 5);

        \HWE_Loyalty_Schema::register();
    }
}

/** Doble mínimo de `$wpdb` para la instalación del esquema. */
final class TestSchemaWpdb
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
