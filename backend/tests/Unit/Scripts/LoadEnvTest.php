<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `scripts/lib/load-env.sh`: carga un `.env` SIN ejecutarlo.
 *
 * Los scripts de backup hacían `. ./.env` (source). Eso trata el archivo como código de
 * shell: un valor sin comillas con espacios (`WP_TITLE=Mi Tienda`) rompe con «orden no
 * encontrada», y cualquier línea con `$(…)` se ejecutaría. Se parsea línea a línea.
 */
final class LoadEnvTest extends ShellScriptTestCase
{
    /**
     * Carga `$envFile` y devuelve el valor de cada variable pedida (una por línea).
     *
     * @param list<string>          $vars
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function load(string $envContent, array $vars, array $env = []): array
    {
        $this->write('.env', $envContent);
        $probe = $this->write('probe.sh', "set -eu\n. " . escapeshellarg($this->script('lib/load-env.sh')) . "\nhwe_load_env .env\n"
            . implode("\n", array_map(static fn (string $v) => "printf '%s=<%s>\\n' $v \"\${{$v}-UNSET}\"", $vars)) . "\n");

        $result = $this->sh($probe, [], $env);
        $this->assertSame(0, $result['code'], $result['stderr']);

        $out = [];
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            [$name, $value] = explode('=<', $line, 2);
            $out[$name] = substr($value, 0, -1);
        }
        return $out;
    }

    public function testSimpleValues(): void
    {
        $this->assertSame(['A' => '1', 'B' => 'dos'], $this->load("A=1\nB=dos\n", ['A', 'B']));
    }

    public function testValuesWithSpacesDoNotBreakIt(): void
    {
        $this->assertSame(['WP_TITLE' => 'Mi Tienda Online'], $this->load("WP_TITLE=Mi Tienda Online\n", ['WP_TITLE']));
    }

    public function testEqualsSignsInsideTheValueArePreserved(): void
    {
        // Relleno de base64: termina en «==».
        $this->assertSame(['SECRET' => 'abc+def/ghi=='], $this->load("SECRET=abc+def/ghi==\n", ['SECRET']));
    }

    public function testHashInsideAValueIsNotAComment(): void
    {
        $this->assertSame(['PASS' => 'abc#123'], $this->load("PASS=abc#123\n", ['PASS']));
    }

    public function testATrailingCommentAfterASpaceIsStripped(): void
    {
        $this->assertSame(['PORT' => '8080'], $this->load("PORT=8080 # puerto local\n", ['PORT']));
    }

    public function testSurroundingQuotesAreRemoved(): void
    {
        $this->assertSame(
            ['A' => 'con espacios', 'B' => 'otra'],
            $this->load("A=\"con espacios\"\nB='otra'\n", ['A', 'B'])
        );
    }

    public function testWindowsLineEndingsDoNotLeaveACarriageReturnInTheValue(): void
    {
        $this->assertSame(['PASS' => 'secreto'], $this->load("PASS=secreto\r\n", ['PASS']));
    }

    public function testCommentsBlankLinesAndExportPrefixAreHandled(): void
    {
        $env = "# comentario\n\n   # otro\nexport A=1\n  B=2\n";

        $this->assertSame(['A' => '1', 'B' => '2'], $this->load($env, ['A', 'B']));
    }

    public function testTheFileIsDataNotCode(): void
    {
        $marker = $this->dir . '/EJECUTADO';
        $env = "A=\$(touch " . $marker . ")\nB=\`touch " . $marker . "\`\nC=\$HOME\n";

        $values = $this->load($env, ['A', 'B', 'C']);

        $this->assertFileDoesNotExist($marker, 'El contenido del .env se ejecutó como shell.');
        $this->assertSame('$HOME', $values['C'], 'No se expanden variables.');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLines(): iterable
    {
        yield 'clave con guion' => ["MI-CLAVE=1\n"];
        yield 'clave que empieza por dígito' => ["1CLAVE=1\n"];
        yield 'línea sin igual' => ["esto no es una asignación\n"];
        yield 'clave vacía' => ["=valor\n"];
        yield 'clave con espacios' => ["MI CLAVE=1\n"];
    }

    #[DataProvider('invalidLines')]
    public function testInvalidLinesAreIgnoredWithoutFailing(string $line): void
    {
        $values = $this->load($line . "OK=1\n", ['OK']);

        $this->assertSame(['OK' => '1'], $values);
    }

    public function testAnAlreadyDefinedVariableWinsOverTheFile(): void
    {
        // Mismo criterio que Docker Compose: el entorno explícito manda sobre el .env.
        $values = $this->load("MYSQL_PASSWORD=del_env\n", ['MYSQL_PASSWORD'], ['MYSQL_PASSWORD' => 'del_entorno']);

        $this->assertSame(['MYSQL_PASSWORD' => 'del_entorno'], $values);
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $probe = $this->write('probe.sh', "set -eu\n. " . escapeshellarg($this->script('lib/load-env.sh')) . "\nhwe_load_env /no/existe/.env\necho ok\n");

        $result = $this->sh($probe);

        $this->assertSame(0, $result['code']);
        $this->assertSame("ok\n", $result['stdout']);
    }

    public function testTheLastLineWithoutANewlineIsStillRead(): void
    {
        $this->assertSame(['A' => '1', 'B' => '2'], $this->load("A=1\nB=2", ['A', 'B']));
    }
}
