<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Instance;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `mu-plugins/hwe-instance-loader.php`: el punto de enganche del código de una instancia.
 *
 * Una instancia (una tienda concreta construida sobre el framework) añade su código en
 * `mu-plugins/instance/*.php`; el framework NUNCA toca esa carpeta, así que actualizar el
 * núcleo no genera conflictos. El cargador hace `require` de los `.php` de primer nivel en
 * orden alfabético. Con la carpeta vacía o ausente no hace nada.
 *
 * Un archivo de instancia roto no debe tumbar el sitio: se registra y se sigue con el
 * siguiente (el contenido falla de forma suave).
 *
 * El archivo define constantes y funciones globales: cada test corre en su proceso.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstanceLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hwe-instance-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
        $GLOBALS['hwe_instance_test_log'] = [];

        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        $this->loadOwnFile('mu-plugins/hwe-instance-loader.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{*,*/*}', GLOB_BRACE) ?: [] as $path) {
            is_file($path) && unlink($path);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            rmdir($sub);
        }
        rmdir($this->dir);
        unset($GLOBALS['hwe_instance_test_log']);
        parent::tearDown();
    }

    /** Escribe un archivo de instancia que anota su nombre al cargarse. */
    private function instanceFile(string $name, string $body = ''): void
    {
        $path = $this->dir . '/' . $name;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\n\$GLOBALS['hwe_instance_test_log'][] = " . var_export($name, true) . ";\n" . $body . "\n");
    }

    /** @return list<string> */
    private function loadedNames(): array
    {
        return $GLOBALS['hwe_instance_test_log'];
    }

    public function testItDefinesTheContractVersion(): void
    {
        $this->assertTrue(defined('HWE_CORE_CONTRACT_VERSION'));
        $this->assertSame('2.0.0', HWE_CORE_CONTRACT_VERSION);
    }

    public function testAMissingFolderDoesNothing(): void
    {
        $this->assertSame([], hwe_instance_load($this->dir . '/no-existe'));
        $this->assertSame([], $this->logged);
    }

    public function testAnEmptyFolderDoesNothing(): void
    {
        $this->assertSame([], hwe_instance_load($this->dir));
        $this->assertSame([], $this->logged);
    }

    public function testItLoadsTwoFilesInAlphabeticalOrderNotCreationOrder(): void
    {
        // Se crean al revés para que el orden de creación no coincida con el alfabético.
        $this->instanceFile('20-segundo.php');
        $this->instanceFile('10-primero.php');

        $loaded = hwe_instance_load($this->dir);

        $this->assertSame(['10-primero.php', '20-segundo.php'], array_map('basename', $loaded));
        $this->assertSame(['10-primero.php', '20-segundo.php'], $this->loadedNames());
    }

    public function testTheOrderIsByBytesNotByLocaleOrCase(): void
    {
        // El orden no debe depender de la configuración regional del servidor: en bytes, las
        // mayúsculas van antes que las minúsculas.
        $this->instanceFile('b-minuscula.php');
        $this->instanceFile('A-mayuscula.php');

        hwe_instance_load($this->dir);

        $this->assertSame(['A-mayuscula.php', 'b-minuscula.php'], $this->loadedNames());
    }

    public function testOnlyTopLevelPhpFilesAreLoaded(): void
    {
        $this->instanceFile('campos.php');
        $this->instanceFile('lib/ayuda.php');          // subcarpeta: la carga el propio archivo si quiere
        file_put_contents($this->dir . '/README.md', '# no es código');
        file_put_contents($this->dir . '/notas.txt', 'nada');
        file_put_contents($this->dir . '/campos.php.bak', '<?php throw new Exception("no debería cargarse");');

        $loaded = hwe_instance_load($this->dir);

        $this->assertSame(['campos.php'], array_map('basename', $loaded));
        $this->assertSame(['campos.php'], $this->loadedNames());
    }

    public function testABrokenFileIsLoggedAndTheNextOneStillLoads(): void
    {
        $this->instanceFile('10-roto.php', 'throw new \RuntimeException("fallo de la instancia");');
        $this->instanceFile('20-sano.php');

        $loaded = hwe_instance_load($this->dir);

        $this->assertSame(['20-sano.php'], array_map('basename', $loaded), 'El roto no cuenta como cargado.');
        $this->assertContains('20-sano.php', $this->loadedNames());
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('[hwe] instance-loader:', $this->logged[0]);
        $this->assertStringContainsString('10-roto.php', $this->logged[0]);
        $this->assertStringContainsString('fallo de la instancia', $this->logged[0]);
    }

    public function testAFileWithASyntaxErrorIsLoggedToo(): void
    {
        file_put_contents($this->dir . '/00-sintaxis.php', "<?php\nthis is not php (\n");
        $this->instanceFile('10-sano.php');

        $loaded = hwe_instance_load($this->dir);

        $this->assertSame(['10-sano.php'], array_map('basename', $loaded));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('00-sintaxis.php', $this->logged[0]);
    }

    public function testLoadingTwiceDoesNotRunAFileTwice(): void
    {
        $this->instanceFile('una-vez.php');

        hwe_instance_load($this->dir);
        hwe_instance_load($this->dir);

        $this->assertSame(['una-vez.php'], $this->loadedNames());
    }

    public function testTheRealInstanceFolderShipsOnlyItsReadme(): void
    {
        // El framework solo versiona el README de la carpeta: el cargador no carga nada de aquí.
        $real = HWE_WP_CONTENT_DIR . '/mu-plugins/instance';

        $this->assertDirectoryExists($real);
        $this->assertSame(['README.md'], array_values(array_diff(scandir($real) ?: [], ['.', '..'])));
        $this->assertSame([], hwe_instance_load($real));
    }
}
