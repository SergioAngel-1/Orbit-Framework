<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Nombre público de un usuario para otros usuarios (red, transferencias, movimientos).
 *
 * El registro del BFF crea la cuenta con `registerUser(username, email, password)` y WordPress
 * pone el login como `display_name`: devolver el `display_name` tal cual enseñaba el login de
 * un cliente a otro (visto en la pila real). El nombre público nunca es el login ni un email.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PublicNameTest extends TestCase
{
    /** @var array<int, object> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('sanitize_text_field')->alias(static fn ($value): string => trim(strip_tags((string) $value)));

        $this->loadOwnFile('plugins/hwe-loyalty/includes/PublicName.php');
    }

    private function user(int $id, string $login, string $display, string $first = '', string $last = '', string $email = ''): void
    {
        $this->users[$id] = (object) [
            'ID'           => $id,
            'user_login'   => $login,
            'user_email'   => '' !== $email ? $email : $login . '@example.test',
            'display_name' => $display,
            'first_name'   => $first,
            'last_name'    => $last,
        ];
    }

    public function test_a_chosen_display_name_is_used(): void
    {
        $this->user(1, 'ana_login', 'Ana Visible');

        $this->assertSame('Ana Visible', \HWE_Loyalty_PublicName::of(1));
    }

    public function test_a_display_name_equal_to_the_login_falls_back_to_first_name_and_initial(): void
    {
        $this->user(2, 'cliente_42', 'cliente_42', 'Lucía', 'Pérez');
        $this->user(3, 'Cliente_43', 'cliente_43', 'Marta');

        $this->assertSame('Lucía P.', \HWE_Loyalty_PublicName::of(2));
        $this->assertSame('Marta', \HWE_Loyalty_PublicName::of(3), 'La comparación con el login no distingue mayúsculas.');
    }

    public function test_never_returns_the_login_or_an_email(): void
    {
        $this->user(4, 'solo_login', 'solo_login');
        $this->user(5, 'otro', 'persona@correo.test');
        $this->user(6, 'tercero', 'tercero@example.test', '', '', 'tercero@example.test');

        $this->assertSame('', \HWE_Loyalty_PublicName::of(4));
        $this->assertSame('', \HWE_Loyalty_PublicName::of(5));
        $this->assertSame('', \HWE_Loyalty_PublicName::of(6));
    }

    public function test_a_missing_account_has_no_name(): void
    {
        $this->assertSame('', \HWE_Loyalty_PublicName::of(99));
        $this->assertSame('', \HWE_Loyalty_PublicName::of(0));
    }

    public function test_markup_is_stripped(): void
    {
        $this->user(7, 'x_login', '<b>Nombre</b>');

        $this->assertSame('Nombre', \HWE_Loyalty_PublicName::of(7));
    }
}
