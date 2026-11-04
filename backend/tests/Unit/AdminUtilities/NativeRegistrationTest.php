<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Registro nativo cerrado: `users_can_register` sigue activo para `registerUser` de
 * WPGraphQL, pero wp-login.php ya no ofrece ni procesa el registro.
 *
 * Cada test en su proceso: el archivo define funciones globales.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            '__' => static fn ($v) => $v,
            'is_wp_error' => static fn ($v) => $v instanceof \WP_Error,
            'wp_login_url' => static fn () => 'https://cms.example.test/acceso-panel',
            'add_query_arg' => static fn ($k, $v, $url) => $url . (str_contains($url, '?') ? '&' : '?') . $k . '=' . $v,
        ]);
    }

    public function testHooksIntoTheLoginLinkTheRegisterActionAndRegisterNewUser(): void
    {
        Filters\expectAdded('register')->once()->with('hwe_native_registration_link');
        Filters\expectAdded('login_link_separator')->once()->with('hwe_native_registration_link_separator');
        Actions\expectAdded('login_form_register')->once()->with('hwe_native_registration_block_form');
        Filters\expectAdded('registration_errors')->once()->with('hwe_native_registration_errors');

        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
    }

    public function testTheRegisterLinkDisappears(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');

        $this->assertSame('', hwe_native_registration_link('<a class="wp-login-register" href="#">Registrarse</a>'));
        $this->assertSame('', hwe_native_registration_link_separator(' | '), 'Sin el enlace, el separador quedaría huérfano.');
    }

    public function testTheRegisterActionGoesBackToTheLoginWithTheNativeNotice(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('https://cms.example.test/acceso-panel?registration=disabled')
            ->andThrow(new \RuntimeException('redirect'));

        $this->expectExceptionMessage('redirect');
        hwe_native_registration_block_form();
    }

    public function testRegisterNewUserFailsOutsideGraphql(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
        Functions\when('is_graphql_request')->justReturn(false);

        $errors = hwe_native_registration_errors(new \WP_Error());

        $this->assertSame(['hwe_registration_disabled'], $errors->get_error_codes());
    }

    public function testWithoutWpgraphqlNativeRegistrationStaysClosed(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
        $this->assertFalse(function_exists('is_graphql_request'), 'Sin WPGraphQL no existe la función.');

        $errors = hwe_native_registration_errors(new \WP_Error());

        $this->assertSame(['hwe_registration_disabled'], $errors->get_error_codes());
    }

    public function testRegisterUserFromGraphqlStillGoesThrough(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
        Functions\when('is_graphql_request')->justReturn(true);
        Functions\expect('update_option')->never();

        $errors = hwe_native_registration_errors(new \WP_Error());

        $this->assertSame([], $errors->get_error_codes(), 'La mutación registerUser no recibe errores nuevos.');
    }

    public function testAnInstanceCanReopenNativeRegistration(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-native-registration.php');
        Filters\expectApplied('hwe_native_registration')->andReturn(true);
        Functions\when('is_graphql_request')->justReturn(false);
        Functions\expect('wp_safe_redirect')->never();

        hwe_native_registration_block_form();
        $this->assertSame('<a>Registrarse</a>', hwe_native_registration_link('<a>Registrarse</a>'));
        $this->assertSame(' | ', hwe_native_registration_link_separator(' | '));
        $this->assertSame([], hwe_native_registration_errors(new \WP_Error())->get_error_codes());
    }
}
