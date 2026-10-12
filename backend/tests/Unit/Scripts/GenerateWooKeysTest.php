<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `generate-woo-keys.sh` imprimía `WC_CONSUMER_KEY` y `WC_CONSUMER_SECRET` completos en
 * la salida SIEMPRE, incluso cuando ya los había escrito en `.env` y `frontend/.env.local`:
 * quedaban en la consola y en cualquier registro de la sesión (CI, terminal grabada con
 * `script`…). Ahora el valor completo solo se imprime cuando hace falta copiarlo a mano.
 */
final class GenerateWooKeysTest extends ShellScriptTestCase
{
    private const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';
    private const SECRET = 'cs_fedcba9876543210fedcba9876543210fedcba98';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeBinary('wp', <<<'SH'
case "$*" in
  *"plugin is-active"*) exit 0 ;;
  *" eval "*) printf 'WC_CONSUMER_KEY=ck_0123456789abcdef0123456789abcdef01234567\nWC_CONSUMER_SECRET=cs_fedcba9876543210fedcba9876543210fedcba98\n'; exit 0 ;;
esac
exit 0
SH);
    }

    private function workspace(bool $withEnvFiles = true): string
    {
        $ws = $this->dir . '/workspace';
        mkdir($ws . '/frontend', 0777, true);
        if ($withEnvFiles) {
            file_put_contents($ws . '/.env', "OTRA=1\nWC_CONSUMER_KEY=\nWC_CONSUMER_SECRET=\n");
            file_put_contents($ws . '/frontend/.env.local', "WC_CONSUMER_KEY=\nWC_CONSUMER_SECRET=\n");
        }
        return $ws;
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    private function generate(array $args): array
    {
        return $this->sh($this->script('generate-woo-keys.sh'), $args);
    }

    public function testWhenTheKeysArePersistedTheFullValuesAreNotPrinted(): void
    {
        $ws = $this->workspace();

        $result = $this->generate(["--workspace-dir=$ws"]);
        $out = $result['stdout'] . $result['stderr'];

        $this->assertSame(0, $result['code'], $out);
        $this->assertStringNotContainsString(self::KEY, $out, 'La clave completa salió por la consola.');
        $this->assertStringNotContainsString(self::SECRET, $out, 'El secreto completo salió por la consola.');
        // Solo una confirmación truncada (primeros 7 caracteres).
        $this->assertStringContainsString('WC_CONSUMER_KEY=ck_0123…(truncado)', $out);
        $this->assertStringContainsString('WC_CONSUMER_SECRET=cs_fedc…(truncado)', $out);
        // ...y SÍ quedaron escritas completas en los archivos.
        foreach (['.env', 'frontend/.env.local'] as $file) {
            $content = (string) file_get_contents("$ws/$file");
            $this->assertStringContainsString('WC_CONSUMER_KEY=' . self::KEY, $content);
            $this->assertStringContainsString('WC_CONSUMER_SECRET=' . self::SECRET, $content);
        }
        $this->assertStringContainsString('OTRA=1', (string) file_get_contents("$ws/.env"), 'No pisa el resto del .env.');
    }

    public function testPrintOnlyStillShowsTheFullValuesBecauseThatIsWhatItIsFor(): void
    {
        $ws = $this->workspace();

        $result = $this->generate(['--print-only', "--workspace-dir=$ws"]);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('WC_CONSUMER_KEY=' . self::KEY, $result['stdout']);
        $this->assertStringContainsString('WC_CONSUMER_SECRET=' . self::SECRET, $result['stdout']);
        $this->assertStringContainsString('WC_CONSUMER_KEY=', (string) file_get_contents("$ws/.env"));
        $this->assertStringNotContainsString(self::KEY, (string) file_get_contents("$ws/.env"), 'No escribe nada.');
    }

    public function testWithoutAMountedWorkspaceTheValuesMustBeCopiedByHand(): void
    {
        $ws = $this->workspace(false);

        $result = $this->generate(["--workspace-dir=$ws"]);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('WC_CONSUMER_KEY=' . self::KEY, $result['stdout']);
        $this->assertStringContainsString('WC_CONSUMER_SECRET=' . self::SECRET, $result['stdout']);
    }

    public function testIfTheAutomaticWriteFailsTheValuesAreShownSoTheyAreNotLost(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Como root, chmod 444 no impide escribir.');
        }
        $ws = $this->workspace();
        chmod("$ws/.env", 0444);

        $result = $this->generate(["--workspace-dir=$ws"]);

        $this->assertStringContainsString('WC_CONSUMER_KEY=' . self::KEY, $result['stdout'], 'Si no se pudo guardar, hay que poder copiarla.');
        $this->assertStringContainsString('WC_CONSUMER_SECRET=' . self::SECRET, $result['stdout']);
        $this->assertStringContainsString('No se pudo escribir', $result['stderr']);
    }
}
