<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * La puerta anti-instancia (`.github/scripts/check-anti-instance.sh`) se ejecuta sobre
 * un repositorio git de juguete: debe cazar un resto de instancia en CUALQUIER archivo
 * versionado (también en tests, componentes y documentación) y respetar sus exclusiones.
 */
final class CheckAntiInstanceTest extends ShellScriptTestCase
{
    /**
     * Tokens del patrón, partidos para que este archivo no se cace a sí mismo: la
     * puerta también revisa los tests.
     */
    private const TOKEN_A = 'sheil' . 'ak';
    private const TOKEN_B = 'atl' . 'as';
    private const TOKEN_C = 'dormi' . 'lab';
    private const TOKEN_D = 'masc' . 'ota';
    private const TOKEN_E = 'doo' . 'hi';

    private const SCRIPT = __DIR__ . '/../../../../.github/scripts/check-anti-instance.sh';

    protected function setUp(): void
    {
        parent::setUp();
        $script = (string) file_get_contents(self::SCRIPT);
        $this->write('.github/scripts/check-anti-instance.sh', $script);
        $this->git('init', '-q');
    }

    /** @param list<string> $args */
    private function git(string ...$args): void
    {
        $process = proc_open(
            array_merge(['git', '-C', $this->dir], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
    }

    /** @return array{code: int, output: string} */
    private function gate(array $env = []): array
    {
        $process = proc_open(
            ['bash', $this->dir . '/.github/scripts/check-anti-instance.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            array_merge(getenv(), $env),
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return ['code' => proc_close($process), 'output' => $out];
    }

    public function testACleanRepositoryPasses(): void
    {
        $this->write('frontend/src/lib/a.ts', "export const x = 1;\n");
        $this->git('add', '-A');

        $result = $this->gate();

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('0 coincidencias', $result['output']);
    }

    /** @return array<string, array{string}> */
    public static function scannedPaths(): array
    {
        return [
            'núcleo' => ['frontend/src/lib/a.ts'],
            'starter' => ['frontend/src/components/a.tsx'],
            'tests' => ['frontend/tests/unit/a.test.ts'],
            'scripts' => ['frontend/scripts/a.mjs'],
            'documentación' => ['docs/A.md'],
            'CI' => ['.github/workflows/a.yml'],
        ];
    }

    /** @dataProvider scannedPaths */
    #[\PHPUnit\Framework\Attributes\DataProvider('scannedPaths')]
    public function testItCatchesAnInstanceLeftoverInAnyVersionedFile(string $path): void
    {
        $this->write($path, "// la tienda de " . self::TOKEN_A . "\n");
        $this->git('add', '-A');

        $result = $this->gate();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString($path, $result['output']);
    }

    public function testItCatchesAFileThatIsNotCommittedYetButNotIgnoredOnes(): void
    {
        $this->write('.gitignore', "notas/\n");
        $this->write('notas/privado.md', self::TOKEN_B . "\n");
        $this->write('frontend/src/nuevo.ts', "// " . self::TOKEN_B . "\n");

        $result = $this->gate();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('frontend/src/nuevo.ts', $result['output']);
        $this->assertStringNotContainsString('notas/privado.md', $result['output']);
    }

    public function testExamplesAndPlanningDocsAreNotScanned(): void
    {
        $this->write('examples/instance-minimal/a.php', "<?php // " . self::TOKEN_C . "\n");
        $this->write('docs/superpowers/specs/cruce.md', self::TOKEN_D . "\n");
        $this->write('package-lock.json', "{\"name\": \"" . self::TOKEN_B . "\"}\n");
        $this->git('add', '-A');

        $result = $this->gate();

        $this->assertSame(0, $result['code'], $result['output']);
    }

    /**
     * El token de la instancia nueva está en el patrón desde que se autorizó el lote:
     * sin él, el código de esa instancia entraría en el framework sin que la puerta lo vea.
     */
    public function testItCatchesTheNewInstanceToken(): void
    {
        $this->write('frontend/src/lib/a.ts', "// la tienda " . self::TOKEN_E . "\n");
        $this->git('add', '-A');

        $result = $this->gate();

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('frontend/src/lib/a.ts', $result['output']);
    }

    /**
     * En el repositorio de una instancia la puerta no aplica: su propio código lleva
     * los tokens que el patrón persigue. El interruptor es fail-closed: solo el valor
     * exacto `true` la desactiva.
     */
    public function testAnInstanceRepositorySkipsTheGate(): void
    {
        $this->write('frontend/src/lib/a.ts', "// la tienda " . self::TOKEN_A . "\n");
        $this->git('add', '-A');

        $result = $this->gate(['HWE_INSTANCE' => 'true']);

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('repositorio de instancia', $result['output']);
    }

    /** @return array<string, array{string}> */
    public static function nonInstanceValues(): array
    {
        return [
            'vacío' => [''],
            'false' => ['false'],
            '1' => ['1'],
            'TRUE en mayúsculas' => ['TRUE'],
            'true con espacios' => [' true '],
        ];
    }

    /** @dataProvider nonInstanceValues */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonInstanceValues')]
    public function testAnyOtherValueKeepsTheGateOn(string $value): void
    {
        $this->write('frontend/src/lib/a.ts', "// la tienda " . self::TOKEN_A . "\n");
        $this->git('add', '-A');

        $result = $this->gate(['HWE_INSTANCE' => $value]);

        $this->assertSame(1, $result['code'], $result['output']);
        $this->assertStringContainsString('frontend/src/lib/a.ts', $result['output']);
    }
}
