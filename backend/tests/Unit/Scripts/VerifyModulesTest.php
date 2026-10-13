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

    /** @return array{code:int,stdout:string,stderr:string} */
    private function runIn(string $root, array $args): array
    {
        return $this->sh($root . '/backend/scripts/verify-modules.sh', $args, [], null, $root);
    }

    public function testElScriptTieneSintaxisValida(): void
    {
        $result = $this->sh('-n', [$this->script('verify-modules.sh')]);

        $this->assertSame(0, $result['code'], $result['stderr']);
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
  *"test -f"*) exit 1 ;;
  *printenv*) echo "define( 'HWE_REVALIDATION_SECRET', 'secreto' );" ;;
esac
exit 0
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
}
