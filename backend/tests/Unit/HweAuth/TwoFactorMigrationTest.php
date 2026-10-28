<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;

/**
 * La migración de códigos de recuperación 2FA no debe tocar la base de datos
 * mientras WordPress no esté instalado (o se esté instalando).
 *
 * En una instalación nueva, WP-CLI carga los mu-plugins y dispara `init` antes
 * de que existan `wp_options`/`wp_usermeta`. La migración intentaba leer la
 * opción y borrar metas en ese momento, y WordPress escribía errores de «tabla
 * inexistente» durante `setup.sh`. Al no marcar la migración, se reintenta en la
 * primera carga con el sitio instalado y sigue siendo idempotente.
 */
final class TwoFactorMigrationTest extends HweAuthTestCase
{
    public function test_no_migra_mientras_wordpress_se_esta_instalando(): void
    {
        Functions\when('wp_installing')->justReturn(true);
        Functions\when('is_blog_installed')->justReturn(false);
        Functions\when('get_option')->justReturn(false);

        // Si la migración siguiera adelante, borraría metas y marcaría la opción
        // sobre tablas que aún no existen (el defecto original).
        Functions\expect('delete_metadata')->never();
        Functions\expect('update_option')->never();

        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();
    }

    public function test_no_migra_cuando_wordpress_no_esta_instalado(): void
    {
        Functions\when('wp_installing')->justReturn(false);
        Functions\when('is_blog_installed')->justReturn(false);
        Functions\when('get_option')->justReturn(false);

        Functions\expect('delete_metadata')->never();
        Functions\expect('update_option')->never();

        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();
    }

    public function test_migra_una_sola_vez_cuando_wordpress_esta_instalado(): void
    {
        $options = [];
        $deleted = [];

        Functions\when('wp_installing')->justReturn(false);
        Functions\when('is_blog_installed')->justReturn(true);
        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$options) {
                return $options[$name] ?? $default;
            }
        );
        Functions\when('update_option')->alias(
            static function (string $name, mixed $value, bool $autoload = true) use (&$options): bool {
                $options[$name] = $value;
                return true;
            }
        );
        Functions\when('delete_metadata')->alias(
            static function (string $type, int $objectId, string $key, mixed $value = '', bool $deleteAll = false) use (&$deleted): bool {
                $deleted[] = [$type, $objectId, $key, $deleteAll];
                return true;
            }
        );

        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();
        \HWE_Auth_Endpoints::migrateRecoveryCodesV2();

        $this->assertSame(
            [['user', 0, 'hwe_2fa_recovery', true]],
            $deleted,
            'con WordPress instalado borra los códigos antiguos una sola vez'
        );
        $this->assertSame('1', $options['hwe_2fa_recovery_v2_migrated'] ?? null);
    }
}
