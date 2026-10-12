<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweAuth;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verificación TOTP (RFC 6238) dentro de WordPress.
 *
 * `/wp-json/hwe/v1/auth/2fa-secret` es alcanzable directamente (el Caddyfile solo
 * cierra /wp-json/wc/* y /graphql), así que un access-token robado bastaba por sí
 * solo para activar o desactivar el 2FA de la víctima sin pasar por las
 * verificaciones que el BFF ya hacía. WordPress debe poder verificar el TOTP por
 * sí mismo, con los mismos parámetros que `otplib` en el frontend (SHA-1, 30 s, 6
 * dígitos).
 */
final class TotpTest extends HweAuthTestCase
{
    /** "12345678901234567890" en base32: la clave de los vectores de prueba del RFC 6238. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * Vectores del Apéndice B del RFC 6238 (SHA-1). El RFC publica 8 dígitos; los
     * 6 dígitos son su módulo 10^6, es decir, los 6 últimos.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield 'T=59' => [59, '287082'];                 // 94287082
        yield 'T=1111111109' => [1111111109, '081804'];  // 07081804
        yield 'T=1111111111' => [1111111111, '050471'];  // 14050471
        yield 'T=1234567890' => [1234567890, '005924'];  // 89005924
        yield 'T=2000000000' => [2000000000, '279037'];  // 69279037
        yield 'T=20000000000' => [20000000000, '353130']; // 65353130
    }

    #[DataProvider('rfcVectors')]
    public function test_acepta_los_vectores_oficiales_del_rfc_6238(int $time, string $code): void
    {
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, $code, $time));
    }

    #[DataProvider('rfcVectors')]
    public function test_rechaza_un_codigo_distinto_al_del_rfc(int $time, string $code): void
    {
        $other = $code === '000000' ? '000001' : '000000';

        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, $other, $time));
    }

    public function test_tolera_un_paso_de_desfase_de_reloj_pero_no_dos(): void
    {
        // El código de T=59 pertenece al paso 1 (30–59 s). Vale en los pasos 0, 1 y 2.
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287082', 59));
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287082', 59 + 30), 'un paso después');
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287082', 59 - 30), 'un paso antes');
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287082', 59 + 90), 'tres pasos después');
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287082', 59 + 1000000));
    }

    public function test_el_secreto_se_acepta_en_minusculas_con_espacios_y_relleno(): void
    {
        $formatted = strtolower(chunk_split(self::RFC_SECRET, 4, ' ')) . '====';

        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp($formatted, '287082', 59));
    }

    public function test_el_codigo_se_acepta_con_espacios(): void
    {
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, '287 082', 59));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'vacío' => [''];
        yield 'cinco dígitos' => ['28708'];
        yield 'siete dígitos' => ['2870820'];
        yield 'letras' => ['abcdef'];
        yield 'con signo' => ['+87082'];
        yield 'decimal' => ['287.08'];
    }

    #[DataProvider('invalidCodes')]
    public function test_rechaza_codigos_con_formato_invalido(string $code): void
    {
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp(self::RFC_SECRET, $code, 59));
    }

    public function test_un_secreto_vacio_o_sin_caracteres_base32_no_verifica_nada(): void
    {
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp('', '287082', 59));
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp('!!!---???', '287082', 59));
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp('1890', '287082', 59), 'el alfabeto base32 no incluye 0, 1, 8 ni 9');
    }

    public function test_sin_hora_explicita_usa_la_hora_actual(): void
    {
        $this->assertTrue(\HWE_Auth_Endpoints::verifyTotp(self::SECRET, $this->currentCode(self::SECRET)));
        $this->assertFalse(\HWE_Auth_Endpoints::verifyTotp(self::SECRET, $this->wrongCode(self::SECRET)));
    }
}
