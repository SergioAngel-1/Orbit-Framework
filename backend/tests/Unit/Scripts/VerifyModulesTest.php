<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `scripts/verify-modules.sh`: la verificación de módulos. Aquí se ejecuta sobre un árbol de
 * repositorio ficticio (el script se coloca en `backend/scripts/` de ese árbol) y con un `docker`
 * falso: se comprueba el propio script (qué exige, qué omite, qué no toca), no WordPress.
 */
final class VerifyModulesTest extends ShellScriptTestCase
{
    /** Árbol mínimo: dos módulos completos y montados. Devuelve la raíz del árbol. */
    private function repo(): string
    {
        $root = $this->dir . '/repo';
        $this->write('repo/backend/scripts/verify-modules.sh', (string) file_get_contents($this->script('verify-modules.sh')));
        $this->write('repo/backend/scripts/lib/load-env.sh', (string) file_get_contents($this->script('lib/load-env.sh')));
        $this->write('repo/backend/scripts/lib/compose-containers.sh', (string) file_get_contents($this->script('lib/compose-containers.sh')));
        $this->write('repo/backend/wp-content/mu-plugins/hwe-control-center/Modules.php', <<<PHP
        <?php
            public const CORE_IDS = [
                'alfa',
                'beta-uno',
            ];
        PHP);
        $this->write('repo/frontend/src/lib/config/modules.ts', <<<TS
        export const MODULE_IDS: readonly ModuleId[] = [
          "alfa",
          "beta-uno",
        ];
        TS);
        $compose = "services:\n";
        foreach (['alfa', 'beta-uno'] as $id) {
            $this->write("repo/backend/wp-content/plugins/hwe-$id/hwe-$id.php", "<?php\n/**\n * Plugin Name: HWE $id\n */\n");
            $this->write("repo/backend/wp-content/plugins/hwe-$id/README.md", '# readme');
            $this->write("repo/backend/wp-content/plugins/hwe-$id/MANUAL.md", '# manual');
            foreach (['wordpress', 'wpcli', 'wp-cron'] as $service) {
                $compose .= "  $service:\n    volumes:\n      - ./backend/wp-content/plugins/hwe-$id:/var/www/html/wp-content/plugins/hwe-$id\n";
            }
        }
        $this->write('repo/docker-compose.yml', $compose);
        return $root;
    }

    /**
     * Árbol mínimo con un solo módulo (`memberships`). Así la sección `toggle` no depende de
     * módulos ajenos y se puede comprobar en detalle lo que exige la verificación del módulo.
     */
    private function membershipsRepo(): string
    {
        $root = $this->dir . '/repo-memberships';
        $this->write('repo-memberships/backend/scripts/verify-modules.sh', (string) file_get_contents($this->script('verify-modules.sh')));
        $this->write('repo-memberships/backend/scripts/lib/load-env.sh', (string) file_get_contents($this->script('lib/load-env.sh')));
        $this->write('repo-memberships/backend/scripts/lib/compose-containers.sh', (string) file_get_contents($this->script('lib/compose-containers.sh')));
        $this->write('repo-memberships/backend/wp-content/mu-plugins/hwe-control-center/Modules.php', <<<PHP
        <?php
            public const CORE_IDS = [
                'memberships',
            ];
        PHP);
        $this->write('repo-memberships/frontend/src/lib/config/modules.ts', <<<TS
        export const MODULE_IDS: readonly ModuleId[] = [
          "memberships",
        ];
        TS);
        $this->write('repo-memberships/backend/wp-content/plugins/hwe-memberships/hwe-memberships.php', "<?php\n/**\n * Plugin Name: HWE Memberships\n */\n");
        $this->write('repo-memberships/backend/wp-content/plugins/hwe-memberships/README.md', '# readme');
        $this->write('repo-memberships/backend/wp-content/plugins/hwe-memberships/MANUAL.md', '# manual');
        $compose = "services:\n";
        foreach (['wordpress', 'wpcli', 'wp-cron'] as $service) {
            $compose .= "  $service:\n    volumes:\n      - ./backend/wp-content/plugins/hwe-memberships:/var/www/html/wp-content/plugins/hwe-memberships\n";
        }
        $this->write('repo-memberships/docker-compose.yml', $compose);

        return $root;
    }

    /**
     * Pila de pega para el módulo `memberships`: el estado del plugin vive en un archivo, así
     * `plugin activate`/`deactivate` cambian lo que responden la config, las rutas y la Store API.
     */
    private function membershipsStack(): string
    {
        $state = $this->dir . '/memberships-state';
        mkdir($state, 0777, true);
        file_put_contents($state . '/active', '1');

        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
echo "env:${FAKE_ENV_FROM_DOT_ENV:-}" >> "$CALLS_LOG"
active() { [ -f "$FAKE_STATE/active" ]; }
case "$*" in
  *"compose ps -q"*) exit 0 ;;
  *"inspect -f"*) printf '%s\n' "${FAKE_PROJECT:-pila-de-prueba}" ;;
  *"inspect "*) exit 0 ;;
  *"exec hwe_wp_cron sh -c"* | *"exec hwe_wpcli sh -c"* | *"exec hwe_wordpress sh -c"*)
    [ "${FAKE_NO_WP_CONTAINER:-}" = "1" ] && exit 1
    exit 0
    ;;
  *"exec hwe_wordpress true"*) exit 0 ;;
  *"printenv WORDPRESS_CONFIG_EXTRA"*) printf "define( 'HWE_REVALIDATION_SECRET', 'secreto' );\n" ;;
  *"test -f"*) exit 0 ;;
  *"plugin is-active"*) active && exit 0 || exit 1 ;;
  *"plugin activate"*) : > "$FAKE_STATE/active" ;;
  *"plugin deactivate"*) rm -f "$FAKE_STATE/active" ;;
  *"wp-json/hwe/v1/config"*)
    if active; then echo '{"modules":{"memberships":true}}'; else echo '{"modules":{"memberships":false}}'; fi
    ;;
  *"SHOW TABLES"*) echo "todas" ;;
  *"HWE_Memberships_Levels"*) echo "7" ;;
  *"rest_get_server"*)
    if active; then
      printf '/hwe/v1/memberships/card\tGET\n'
      printf '/hwe/v1/memberships/history\tGET\n'
      printf '/hwe/v1/memberships/levels\tGET\n'
      printf '/hwe/v1/memberships/me\tGET\n'
      printf '/hwe/v1/memberships/prices\tGET\n'
      printf '/hwe/v1/memberships/product\tGET\n'
      printf '/hwe/v1/memberships/verify\tGET\n'
    else
      printf '/otra\tGET\n'
    fi
    ;;
  *"wp_insert_post"*"hwe_membership_level"*) echo "901" ;;
  *"new WC_Product_Simple"*) echo "902" ;;
  *"wp_delete_post"*) : ;;
  *"exec hwe_wordpress curl"*)
    if printf '%s' "$*" | grep -q -- "-o /dev/null"; then
      case "$*" in
        *"X-HWE-Internal-Secret"*"/memberships/product"*) active && echo "400" || echo "404" ;;
        *"X-HWE-Internal-Secret"*"/memberships/"*) active && echo "200" || echo "404" ;;
        *) echo "404" ;;
      esac
    else
      case "$*" in
        *"/graphql"*"search:"*) echo '{"data":{"products":{"nodes":[]}}}' ;;
        *"/graphql"*)
          if [ "${FAKE_GRAPHQL_LEAK:-}" = "1" ]; then
            echo '{"data":{"product":{"description":"contenido-privado","hweMembership":{"locked":true,"requiredLevel":{"rank":7,"name":"verify-memberships"}}}}}'
          else
            echo '{"data":{"product":{"description":null,"shortDescription":null,"hweMembership":{"locked":true,"requiredLevel":{"rank":7,"name":"verify-memberships"}}}}}'
          fi
          ;;
        *"/wc/store/v1/cart"*) echo '{"extensions":{"hwe-memberships":{}}}' ;;
        *"/wp/v2/product?search"*) echo '[]' ;;
        *"/wp/v2/product/902"*) echo '{"id":902,"locked":true}' ;;
        *"/wc/store/v1/products/verify-memberships"*) echo '{"id":902,"name":"verify-memberships","locked":true}' ;;
        *"/wc/store/v1/products/902"*) echo '{"id":902,"name":"verify-memberships","locked":true,"hwe_membership":{"required_level":{"rank":7,"name":"verify-memberships"}}}' ;;
        *) echo '{}' ;;
      esac
    fi
    ;;
  *) echo "docker inesperado: $*" >> "$CALLS_LOG"; exit 42 ;;
esac
exit 0
SH);

        return $state;
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function runIn(string $root, array $args, array $env = []): array
    {
        // El script exige que la pila sea del proyecto de Compose de SU repo: las pruebas
        // declaran uno fijo (`basename($root)` por defecto es lo que haría Compose).
        $env += ['COMPOSE_PROJECT_NAME' => 'pila-de-prueba'];

        return $this->sh($root . '/backend/scripts/verify-modules.sh', $args, $env, null, $root);
    }

    // ── memberships ───────────────────────────────────────────────────────────

    public function testMembershipsCompruebaTablasRutasStoreApiYRecorteDelCatalogo(): void
    {
        $state = $this->membershipsStack();
        $root = $this->membershipsRepo();

        $result = $this->runIn($root, ['toggle'], ['FAKE_STATE' => $state]);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertSame(0, $result['code'], $out);
        // Tablas y rutas internas con el secreto.
        $this->assertStringContainsString('memberships activo: las 5 tablas existen', $out);
        foreach (['levels', 'me?user_id=1', 'history?user_id=1', 'prices?user_id=1&ids=1'] as $route) {
            $this->assertStringContainsString("memberships activo: /hwe/v1/memberships/$route con el secreto → 200", $out);
            $this->assertStringContainsString("memberships: /hwe/v1/memberships/$route sin el secreto → 404", $out);
        }
        // Extensión de la Store API y recorte en las dos vías públicas.
        $this->assertStringContainsString('memberships activo: la Store API publica extensions.hwe-memberships', $out);
        $this->assertStringContainsString('memberships activo: GraphQL oculta la descripción del producto bloqueado', $out);
        $this->assertStringContainsString('memberships activo: GraphQL marca el producto como bloqueado', $out);
        $this->assertStringContainsString('memberships activo: la Store API no publica la descripción del producto bloqueado', $out);
        $this->assertStringContainsString('memberships activo: la Store API marca el producto como bloqueado', $out);
        $this->assertStringContainsString('memberships activo: la Store API por slug no publica la descripción del producto bloqueado', $out);
        $this->assertStringContainsString('memberships activo: wp/v2/product no publica el contenido del producto bloqueado', $out);
        $this->assertStringContainsString('memberships activo: la búsqueda de GraphQL no compara contra la descripción bloqueada', $out);
        $this->assertStringContainsString('memberships activo: la búsqueda de wp/v2/product no compara contra la descripción bloqueada', $out);
        // Apagado: no queda ninguna ruta interna registrada.
        $this->assertStringContainsString('memberships apagado: las rutas internas no están registradas', $out);
        $this->assertStringContainsString('memberships apagado: /hwe/v1/memberships/levels → 404', $out);

        // Los fixtures se borran al salir, también el nivel además del producto.
        $this->assertStringContainsString('wp_delete_post(902, true)', $this->calls());
        $this->assertStringContainsString('wp_delete_post(901, true)', $this->calls());
    }

    public function testMembershipsFallaSiGraphQLNoRecortaLaDescripcion(): void
    {
        $state = $this->membershipsStack();
        $root = $this->membershipsRepo();

        $result = $this->runIn($root, ['toggle'], [
            'FAKE_STATE' => $state,
            'FAKE_GRAPHQL_LEAK' => '1',
        ]);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString(
            'FALLA  memberships activo: GraphQL oculta la descripción del producto bloqueado',
            $result['stdout']
        );
    }

    public function testElScriptTieneSintaxisValida(): void
    {
        $result = $this->sh('-n', [$this->script('verify-modules.sh')]);

        $this->assertSame(0, $result['code'], $result['stderr']);
    }

    public function testWpCliSeEjecutaDentroDeUnContenedorEnMarchaYNuncaConCompose(): void
    {
        $state = $this->membershipsStack();
        $root = $this->membershipsRepo();

        $result = $this->runIn($root, ['toggle'], ['FAKE_STATE' => $state]);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $calls = $this->calls();
        $this->assertStringContainsString(
            'exec hwe_wp_cron wp --allow-root --path=/var/www/html plugin activate hwe-memberships',
            $calls,
            'Con un contenedor en marcha, WP-CLI corre dentro de él.'
        );
        $this->assertStringNotContainsString(
            'compose run',
            $calls,
            'No debe lanzar `compose run`: podría recrear wordpress con otro entorno.'
        );
        // Resolver el contenedor sí consulta `compose ps -q` (proyecto actual), pero no arranca nada.
        $this->assertStringContainsString('compose ps -q wp-cron', $calls);
    }

    public function testSinContenedorElRespaldoNoPuedeRecrearServiciosYUsaElEnvDelRepo(): void
    {
        $state = $this->membershipsStack();
        $root = $this->membershipsRepo();
        file_put_contents($root . '/.env', "FAKE_ENV_FROM_DOT_ENV=puesto\n");

        $result = $this->runIn($root, ['toggle'], [
            'FAKE_STATE' => $state,
            'FAKE_NO_WP_CONTAINER' => '1',
            'FAKE_PROJECT' => 'pila-de-prueba',
        ]);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        $calls = $this->calls();
        $this->assertStringContainsString(
            'compose --project-name pila-de-prueba --profile cli run --rm --no-deps',
            $calls,
            'El respaldo usa el proyecto de la pila y no arranca dependencias.'
        );
        $this->assertStringContainsString('env:puesto', $calls, 'El .env del repo se carga como datos para el respaldo.');

        foreach (preg_split('/\R/', $calls) ?: [] as $line) {
            if (!str_contains($line, 'docker compose ')) {
                continue;
            }
            if (str_contains($line, ' compose ps -q ')) {
                continue; // solo resuelve el contenedor: no arranca ni recrea nada
            }
            $this->assertStringContainsString('--no-deps', $line, 'Ningún compose puede recrear servicios: ' . $line);
            $this->assertStringNotContainsString(' up ', ' ' . $line . ' ', 'El script no arranca servicios: ' . $line);
        }
    }

    public function testUnaSeccionDesconocidaFallaSinTocarLaPila(): void
    {
        $this->fakeBinary('docker', 'echo "docker $*" >> "$CALLS_LOG"');
        $root = $this->repo();

        $result = $this->runIn($root, ['inventada']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('sección desconocida', $result['stdout']);
        $this->assertSame('', $this->calls(), 'No debe llamar a docker.');
    }

    public function testLaSeccionEstaticaPasaConModulosCompletosYMontados(): void
    {
        $this->fakeBinary('docker', 'echo "docker $*" >> "$CALLS_LOG"');
        $root = $this->repo();

        $result = $this->runIn($root, ['static']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $this->assertStringContainsString('TODO OK', $result['stdout']);
        $this->assertStringContainsString('alfa: entrada, README y MANUAL', $result['stdout']);
        $this->assertStringContainsString('beta-uno: montado en los 3 servicios', $result['stdout']);
        $this->assertSame('', $this->calls(), 'La sección estática no necesita la pila.');
    }

    public function testFallaSiAUnModuloLeFaltaElManual(): void
    {
        $root = $this->repo();
        unlink($root . '/backend/wp-content/plugins/hwe-beta-uno/MANUAL.md');

        $result = $this->runIn($root, ['static']);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('FALLA  beta-uno: faltan archivos', $result['stdout']);
        $this->assertStringContainsString('MANUAL.md', $result['stdout']);
    }

    public function testFallaCuandoUnModuloNoEstaMontadoEnLosTresServicios(): void
    {
        $root = $this->repo();
        file_put_contents(
            $root . '/docker-compose.yml',
            str_replace(
                "  wp-cron:\n    volumes:\n      - ./backend/wp-content/plugins/hwe-alfa:/var/www/html/wp-content/plugins/hwe-alfa\n",
                "  wp-cron:\n    volumes:\n",
                (string) file_get_contents($root . '/docker-compose.yml')
            )
        );

        $result = $this->runIn($root, ['static']);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('FALLA  alfa: montado en 2 de 3 servicios', $result['stdout']);
    }

    public function testFallaSiLosCatalogosDePhpYTsDifieren(): void
    {
        $root = $this->repo();
        file_put_contents(
            $root . '/frontend/src/lib/config/modules.ts',
            str_replace('  "beta-uno",' . "\n", '', (string) file_get_contents($root . '/frontend/src/lib/config/modules.ts'))
        );

        $result = $this->runIn($root, ['static']);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('FALLA  el catálogo de PHP y el de TS difieren', $result['stdout']);
    }

    public function testSinPilaLasSeccionesVivasSalenConDos(): void
    {
        $this->fakeBinary('docker', 'exit 1');
        $root = $this->repo();

        $result = $this->runIn($root, ['toggle']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('La pila no responde', $result['stdout']);
    }

    public function testUnModuloQueLaPilaNoTieneMontadoSeOmiteYConStrictFalla(): void
    {
        // `docker exec … true` responde; `docker exec … test -f` (plugin presente) falla.
        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
case "$*" in
  *"compose ps -q"*) exit 0 ;;
  *"inspect -f"*) printf '%s\n' "pila-de-prueba" ;;
  *"inspect "*) exit 0 ;;
  *"exec hwe_wp_cron sh -c"*) exit 0 ;;
  *"exec hwe_wordpress true"*) exit 0 ;;
  *"test -f"*) exit 1 ;;
  *printenv*) echo "define( 'HWE_REVALIDATION_SECRET', 'secreto' );" ;;
  *) echo "docker inesperado: $*" >> "$CALLS_LOG"; exit 42 ;;
esac
SH);
        $root = $this->repo();

        $lax = $this->runIn($root, ['toggle']);
        $strict = $this->runIn($root, ['toggle', '--strict']);

        $this->assertSame(0, $lax['code'], $lax['stdout']);
        $this->assertStringContainsString('omite  alfa: el plugin no está montado en la pila', $lax['stdout']);
        $this->assertStringContainsString('TODO OK (2 omitida(s))', $lax['stdout']);
        $this->assertSame(2, $strict['code'], $strict['stdout']);
        $this->assertStringContainsString('cuentan como fallo', $strict['stdout']);
        // Sin plugin montado no se activa ni se desactiva nada.
        $this->assertStringNotContainsString('plugin activate', $this->calls());
        $this->assertStringNotContainsString('plugin deactivate', $this->calls());
    }

    // ── varias pilas en la misma máquina ─────────────────────────────────────

    /** `docker` de una pila resuelta por servicio, con ids distintos de los nombres fijos. */
    private function fakeComposeStack(string $project = 'pila-de-prueba'): void
    {
        $this->fakeBinary('docker', <<<SH
echo "docker \$*" >> "\$CALLS_LOG"
case "\$*" in
  *"compose ps -q wordpress"*) echo "ctr-wordpress" ;;
  *"compose ps -q wp-cron"*) echo "ctr-wp-cron" ;;
  *"compose ps -q wpcli"*) echo "ctr-wpcli" ;;
  *"inspect -f"*) printf '%s\n' "$project" ;;
  *"exec ctr-wp-cron sh -c"*) exit 0 ;;
  *"exec ctr-wp-cron wp "*) echo "co,generic" ;;
  *"exec ctr-wordpress true"*) exit 0 ;;
  *"exec ctr-wordpress printenv"*) echo "define( 'HWE_REVALIDATION_SECRET', 'secreto' );" ;;
  *"exec ctr-wordpress curl"*"/config"*) echo '{"country":"CO"}' ;;
  *"exec ctr-wordpress curl"*"/geo"*) echo '{"country":"CO","fallback":false}' ;;
  *) echo "docker inesperado: \$*" >> "\$CALLS_LOG"; exit 42 ;;
esac
SH);
    }

    /** `docker` de una pila que se resuelve por los nombres con prefijo (sin `compose ps`). */
    private function fakePrefixStack(string $prefix = 'hwe'): void
    {
        $this->fakeBinary('docker', <<<SH
echo "docker \$*" >> "\$CALLS_LOG"
case "\$*" in
  *"compose ps -q"*) exit 0 ;;
  *"inspect -f"*) printf '%s\n' "pila-de-prueba" ;;
  *"inspect "*) exit 0 ;;
  *"exec {$prefix}_wp_cron sh -c"*) exit 0 ;;
  *"exec {$prefix}_wp_cron wp "*) echo "co,generic" ;;
  *"exec {$prefix}_wordpress true"*) exit 0 ;;
  *"exec {$prefix}_wordpress printenv"*) echo "define( 'HWE_REVALIDATION_SECRET', 'secreto' );" ;;
  *"exec {$prefix}_wordpress curl"*"/config"*) echo '{"country":"CO"}' ;;
  *"exec {$prefix}_wordpress curl"*"/geo"*) echo '{"country":"CO","fallback":false}' ;;
  *) echo "docker inesperado: \$*" >> "\$CALLS_LOG"; exit 42 ;;
esac
SH);
    }

    public function testElContenedorDeWpCliSeResuelvePorElServicioDeCompose(): void
    {
        $this->fakeComposeStack();
        $root = $this->repo();

        $result = $this->runIn($root, ['country']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $calls = $this->calls();
        $this->assertStringContainsString('docker compose ps -q wp-cron', $calls);
        $this->assertStringContainsString('docker exec ctr-wp-cron wp', $calls);
        $this->assertStringContainsString('docker compose ps -q wordpress', $calls);
        $this->assertStringContainsString('docker exec ctr-wordpress curl', $calls);
    }

    public function testHweCliContainerAnulaLaResolucionPorServicio(): void
    {
        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
case "$*" in
  *"compose ps -q wordpress"*) echo "ctr-wordpress" ;;
  *"inspect -f"*) printf '%s\n' "pila-de-prueba" ;;
  *"inspect wp-a-mano"*) exit 0 ;;
  *"exec wp-a-mano wp "*) echo "co,generic" ;;
  *"exec ctr-wordpress true"*) exit 0 ;;
  *"exec ctr-wordpress printenv"*) echo "define( 'HWE_REVALIDATION_SECRET', 'secreto' );" ;;
  *"exec ctr-wordpress curl"*"/config"*) echo '{"country":"CO"}' ;;
  *"exec ctr-wordpress curl"*"/geo"*) echo '{"country":"CO","fallback":false}' ;;
  *) echo "docker inesperado: $*" >> "$CALLS_LOG"; exit 42 ;;
esac
SH);
        $root = $this->repo();

        $result = $this->runIn($root, ['country'], ['HWE_CLI_CONTAINER' => 'wp-a-mano']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $calls = $this->calls();
        $this->assertStringContainsString('docker exec wp-a-mano wp', $calls);
        $this->assertStringNotContainsString('compose ps -q wp-cron', $calls);
    }

    public function testSinResolucionPorComposeCaeAlNombreConPrefijo(): void
    {
        $this->fakePrefixStack();
        $root = $this->repo();

        $result = $this->runIn($root, ['country']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $calls = $this->calls();
        $this->assertStringContainsString('docker exec hwe_wp_cron wp', $calls);
        $this->assertStringContainsString('docker exec hwe_wordpress curl', $calls);
    }

    public function testElPrefijoDeContenedoresSePuedeCambiar(): void
    {
        $this->fakePrefixStack('acme');
        $root = $this->repo();

        $result = $this->runIn($root, ['country'], ['HWE_CONTAINER_PREFIX' => 'acme']);

        $this->assertSame(0, $result['code'], $result['stdout']);
        $this->assertStringContainsString('docker exec acme_wp_cron wp', $this->calls());
    }

    public function testAbortaSiElContenedorEsDeOtraPila(): void
    {
        // El nombre por defecto existe, pero su etiqueta dice otro proyecto de Compose: el
        // script no puede activar/desactivar módulos en la pila de otra instancia.
        $this->fakeBinary('docker', <<<'SH'
echo "docker $*" >> "$CALLS_LOG"
case "$*" in
  *"compose ps -q"*) exit 0 ;;
  *"inspect -f"*) printf '%s\n' "otra-pila" ;;
  *"inspect "*) exit 0 ;;
  *) echo "docker inesperado: $*" >> "$CALLS_LOG"; exit 42 ;;
esac
SH);
        $root = $this->repo();

        $result = $this->runIn($root, ['toggle']);

        $out = $result['stdout'] . $result['stderr'];
        $this->assertSame(2, $result['code'], $out);
        $this->assertStringContainsString('otra-pila', $out);
        $this->assertStringContainsString('pila-de-prueba', $out);
        $this->assertStringNotContainsString('docker exec', $this->calls(), 'No debe ejecutar nada en otra pila.');
    }
}
