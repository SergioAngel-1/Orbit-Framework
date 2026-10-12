<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;

/**
 * Base de los tests de `mu-plugins/hwe-auth.php`: carga el archivo con las
 * funciones de WordPress simuladas y un almacén de user meta en memoria.
 *
 * Los tests que dependen de constantes de cifrado (`HWE_2FA_KEY`, `AUTH_KEY`…)
 * usan `#[RunInSeparateProcess]`: las constantes son globales al proceso y no se
 * pueden deshacer (ver tests/bootstrap.php).
 */
abstract class HweAuthTestCase extends TestCase
{
    protected const USER_ID = 7;
    /** Secreto base32 de ejemplo (el de la documentación de Google Authenticator). */
    protected const SECRET = 'JBSWY3DPEHPK3PXP';

    /** @var array<int, array<string, mixed>> */
    protected array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'wp_parse_url' => static fn ($url, $component = -1) => parse_url((string) $url, (int) $component),
        ]);
        Functions\when('get_current_user_id')->justReturn(self::USER_ID);

        Functions\when('get_user_meta')->alias(
            fn (int $userId, string $key, bool $single = false) => $this->meta[$userId][$key] ?? ''
        );
        Functions\when('update_user_meta')->alias(function (int $userId, string $key, $value): bool {
            $this->meta[$userId][$key] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function (int $userId, string $key): bool {
            unset($this->meta[$userId][$key]);
            return true;
        });

        $this->loadOwnFile('mu-plugins/hwe-auth.php');
    }

    /** Valor de un user meta del usuario de prueba. */
    protected function meta(string $key): mixed
    {
        return $this->meta[self::USER_ID][$key] ?? null;
    }

    protected function setMeta(string $key, mixed $value): void
    {
        $this->meta[self::USER_ID][$key] = $value;
    }

    /** Código TOTP vigente para un secreto, calculado con el mismo algoritmo (RFC 6238). */
    protected function currentCode(string $secret): string
    {
        $key = \HWE_Auth_Endpoints::base32Decode($secret);
        return \HWE_Auth_Endpoints::totpAt($key, (int) floor(time() / 30));
    }

    /** Un código TOTP que seguro NO es válido ahora para ese secreto. */
    protected function wrongCode(string $secret): string
    {
        $key = \HWE_Auth_Endpoints::base32Decode($secret);
        $counter = (int) floor(time() / 30);
        $valid = [];
        for ($offset = -1; $offset <= 1; $offset++) {
            $valid[] = \HWE_Auth_Endpoints::totpAt($key, $counter + $offset);
        }
        for ($candidate = 0; $candidate < 1000000; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
            if (!in_array($code, $valid, true)) {
                return $code;
            }
        }
        throw new \LogicException('imposible');
    }
}
