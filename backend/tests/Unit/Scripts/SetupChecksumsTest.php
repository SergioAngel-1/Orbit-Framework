<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `setup.sh` verifica el SHA-256 de lo que descarga de GitHub (cadena de suministro).
 *
 * Los chequeos existentes (JWT Auth y WooGraphQL) instalaban igualmente desde la URL
 * directa cuando el hash NO coincidía: un artefacto manipulado entraba por el mismo
 * camino y la verificación no servía de nada. Y wp-graphql-cors se instalaba sin
 * verificar. Ahora un hash distinto significa que NO se instala nada de esa descarga:
 * si el plugin es imprescindible (JWT) el script aborta; si es opcional (CORS,
 * WooGraphQL desde GitHub) avisa y sigue.
 *
 * Se ejecuta el script de verdad con `wp` y `curl` falsos. Los hashes esperados se
 * pueden sobrescribir por entorno (como las versiones) para simular una descarga válida.
 */
final class SetupChecksumsTest extends ShellScriptTestCase
{
    private const GOOD = 'contenido-legitimo-del-zip';
    private const BAD = 'contenido-manipulado-por-un-atacante';

    /** @var array<string, string> */
    private array $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = [
            'WP_ADMIN_PASSWORD' => 'x',
            'JWT_AUTH_SHA256' => hash('sha256', self::GOOD),
            'WOOGRAPHQL_SHA256' => hash('sha256', self::GOOD),
            'WPGRAPHQL_CORS_SHA256' => hash('sha256', self::GOOD),
        ];

        // WordPress ya instalado y todo lo demás responde bien: el script llega hasta el final.
        $this->fakeBinary('wp', <<<'SH'
echo "wp $*" >> "$CALLS_LOG"
case "$*" in
  *"core is-installed"*) exit 0 ;;
  *"plugin install wp-graphql-woocommerce"*) [ "${FAKE_WOO_WPORG_FAILS:-}" = 1 ] && exit 1; exit 0 ;;
esac
exit 0
SH);
        // `curl -fsSL -o <archivo> <url>`: escribe el cuerpo que toque según la URL.
        $this->fakeBinary('curl', <<<'SH'
echo "curl $*" >> "$CALLS_LOG"
out=""; url=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
case "$url" in
  *jwt-authentication*)  body="${FAKE_JWT_BODY:-}" ;;
  *wp-graphql-cors*)     body="${FAKE_CORS_BODY:-}" ;;
  *wp-graphql-woocommerce*) body="${FAKE_WOO_BODY:-}" ;;
  *) body="" ;;
esac
[ "${FAKE_CURL_FAILS:-}" = 1 ] && exit 22
printf '%s' "$body" > "$out"
exit 0
SH);
    }

    /** @param array<string, string> $extra */
    private function runSetup(array $extra = []): array
    {
        return $this->sh($this->script('setup.sh'), [], $extra + $this->env + [
            'FAKE_JWT_BODY' => self::GOOD,
            'FAKE_CORS_BODY' => self::GOOD,
            'FAKE_WOO_BODY' => self::GOOD,
        ]);
    }

    /** Líneas de `wp plugin install …` con una URL de GitHub (instalación SIN verificar). */
    private function unverifiedGithubInstalls(): array
    {
        return array_values(array_filter(
            explode("\n", $this->calls()),
            static fn (string $l) => str_starts_with($l, 'wp ') && str_contains($l, 'plugin install') && str_contains($l, 'https://github.com')
        ));
    }

    private function installedFromLocalZip(string $name): bool
    {
        foreach (explode("\n", $this->calls()) as $line) {
            if (str_contains($line, 'plugin install') && str_contains($line, $name . '.zip') && !str_contains($line, 'https://')) {
                return true;
            }
        }
        return false;
    }

    // ── Camino feliz ──────────────────────────────────────────────────────────

    public function testWithValidChecksumsEveryDownloadIsInstalledFromTheVerifiedLocalFile(): void
    {
        $result = $this->runSetup(['FAKE_WOO_WPORG_FAILS' => '1']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertTrue($this->installedFromLocalZip('jwt'), 'JWT desde el archivo verificado.');
        $this->assertTrue($this->installedFromLocalZip('wp-graphql-cors'), 'CORS desde el archivo verificado.');
        $this->assertTrue($this->installedFromLocalZip('wp-graphql-woocommerce'), 'WooGraphQL desde el archivo verificado.');
        $this->assertSame([], $this->unverifiedGithubInstalls(), 'Nada se instala directamente desde una URL.');
    }

    // ── JWT: imprescindible → aborta ──────────────────────────────────────────

    public function testAJwtChecksumMismatchAbortsAndInstallsNothingFromThatDownload(): void
    {
        $result = $this->runSetup(['FAKE_JWT_BODY' => self::BAD]);

        $this->assertSame(1, $result['code'], 'Aborta: sin JWT verificado no hay autenticación.');
        $this->assertStringContainsString('SHA256', $result['stderr']);
        $this->assertSame([], $this->unverifiedGithubInstalls(), 'No hay fallback a la URL directa.');
        $this->assertFalse($this->installedFromLocalZip('jwt'));
    }

    public function testAFailedJwtDownloadAbortsToo(): void
    {
        $result = $this->runSetup(['FAKE_CURL_FAILS' => '1']);

        $this->assertNotSame(0, $result['code']);
        $this->assertSame([], $this->unverifiedGithubInstalls());
    }

    // ── CORS: opcional → avisa y sigue, sin instalar ──────────────────────────

    public function testACorsChecksumMismatchWarnsAndSkipsTheOptionalPluginWithoutInstallingIt(): void
    {
        $result = $this->runSetup(['FAKE_CORS_BODY' => self::BAD]);

        $this->assertSame(0, $result['code'], 'Es opcional: el resto de la instalación sigue.' . $result['stderr']);
        $this->assertStringContainsString('SHA256', $result['stderr']);
        $this->assertFalse($this->installedFromLocalZip('wp-graphql-cors'));
        $this->assertSame([], $this->unverifiedGithubInstalls());
        $this->assertTrue($this->installedFromLocalZip('jwt'), 'Lo verificado sí se instala.');
    }

    // ── WooGraphQL desde GitHub (solo si wp.org no lo tiene) ──────────────────

    public function testAWooGraphqlChecksumMismatchSkipsItInsteadOfInstallingFromTheDirectUrl(): void
    {
        $result = $this->runSetup(['FAKE_WOO_WPORG_FAILS' => '1', 'FAKE_WOO_BODY' => self::BAD]);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame([], $this->unverifiedGithubInstalls());
        $this->assertFalse($this->installedFromLocalZip('wp-graphql-woocommerce'));
        $this->assertStringContainsString('SHA256', $result['stderr']);
    }

    public function testWooGraphqlFromWpOrgNeedsNoGithubDownloadAtAll(): void
    {
        $this->runSetup();

        $this->assertStringNotContainsString('wp-graphql-woocommerce', implode("\n", array_filter(
            explode("\n", $this->calls()),
            static fn (string $l) => str_starts_with($l, 'curl ')
        )));
    }

    // ── Los hashes fijados son los reales ─────────────────────────────────────

    public function testThePinnedChecksumsAreDeclaredInTheScript(): void
    {
        $script = (string) file_get_contents($this->script('setup.sh'));

        foreach (['JWT_AUTH_SHA256', 'WOOGRAPHQL_SHA256', 'WPGRAPHQL_CORS_SHA256'] as $name) {
            $this->assertMatchesRegularExpression('/^' . $name . '="\$\{' . $name . ':-[0-9a-f]{64}\}"$/m', $script, $name);
        }
    }
}
