<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;

/**
 * Base de los tests de `mu-plugins/hwe-bff-only.php`.
 *
 * El archivo registra dos filtros al cargarse; se capturan sus callbacks para
 * invocarlos como lo haría WordPress. Los tests que dependen de constantes
 * (`HWE_REVALIDATION_SECRET`, `WP_CLI`, `REST_REQUEST`…) usan
 * `#[RunInSeparateProcess]`: son globales al proceso y no se pueden deshacer
 * (ver tests/bootstrap.php).
 */
abstract class HweBffOnlyTestCase extends TestCase
{
    protected const SECRET = 'secreto-interno-de-prueba-0123456789';

    /** @var callable|null  callback de `graphql_is_graphql_http_request` */
    protected $graphqlFilter = null;
    /** @var callable|null  callback de `rest_pre_dispatch` */
    protected $restFilter = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER = [];
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            'wp_unslash' => static fn ($value) => $value,
            '__' => static fn ($text) => $text,
        ]);

        // Los filtros se registran al CARGAR el archivo: se capturan sus callbacks.
        Filters\expectAdded('graphql_is_graphql_http_request')
            ->once()
            ->whenHappen(function (callable $callback): void {
                $this->graphqlFilter = $callback;
            });
        Filters\expectAdded('rest_pre_dispatch')
            ->once()
            ->whenHappen(function (callable $callback): void {
                $this->restFilter = $callback;
            });
        // `error_log` es redefinible (backend/patchwork.json); por defecto no escribe.
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('mu-plugins/hwe-bff-only.php');

        // `exit` mataría PHPUnit: el archivo lo aísla en una función que aquí se sustituye.
        Functions\when('hwe_bff_only_terminate')->alias(static function (): void {
            throw new TerminatedException();
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        parent::tearDown();
    }

    /** Presenta el secreto como lo haría el BFF. */
    protected function sendSecret(string $secret): void
    {
        $_SERVER['HTTP_X_HWE_INTERNAL_SECRET'] = $secret;
    }

    /** Aplica el filtro de GraphQL; devuelve true si la petición sigue su curso. */
    protected function graphqlAllowed(): bool
    {
        Functions\when('status_header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);
        // Al rechazar, el archivo escribe el cuerpo del 404: se descarta aquí.
        ob_start();
        try {
            return ($this->graphqlFilter)(true) === true;
        } catch (TerminatedException) {
            return false;
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Aplica el filtro REST a una ruta ya resuelta.
     *
     * @return mixed null si deja pasar la petición; un WP_Error si la rechaza.
     */
    protected function dispatch(string $route): mixed
    {
        return ($this->restFilter)(null, new \stdClass(), new \WP_REST_Request([], [], $route));
    }
}
