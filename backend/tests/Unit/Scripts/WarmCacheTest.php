<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `warm-cache.sh`: calienta la caché ISR tras un despliegue, UNA URL cada vez, y
 * nunca hace fallar el despliegue por una ruta que no responde.
 *
 * Se EJECUTA con un `curl` falso que sirve el índice del sitemap, sus segmentos y las
 * páginas, y registra cada petición.
 */
final class WarmCacheTest extends ShellScriptTestCase
{
    private const INDEX = '<sitemapindex><sitemap><loc>https://t.example/sitemap/paginas.xml</loc></sitemap>'
        . '<sitemap><loc>https://t.example/sitemap/catalogo.xml</loc></sitemap></sitemapindex>';
    private const PAGINAS = '<urlset><url><loc>https://t.example/</loc></url>'
        . '<url><loc>https://t.example/blog</loc></url></urlset>';
    private const CATALOGO = '<urlset><url><loc>https://t.example/products/a</loc></url>'
        . '<url><loc>https://t.example/products/b</loc></url>'
        . '<url><loc>https://t.example/products/rota</loc></url></urlset>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->write('sitemap.xml', self::INDEX);
        $this->write('paginas.xml', self::PAGINAS);
        $this->write('catalogo.xml', self::CATALOGO);

        // `curl -s -o /dev/null -w '%{http_code}' -m N URL` (calentar) o `curl -s -m N URL` (leer).
        $this->fakeBinary('curl', <<<SH
for last; do :; done
echo "GET \$last" >> "\$CALLS_LOG"
case "\$*" in *"%{http_code}"*) wants_code=1 ;; *) wants_code=0 ;; esac
case "\$last" in
  */sitemap.xml)            body="{$this->dir}/sitemap.xml"; code=200 ;;
  */sitemap/paginas.xml)    body="{$this->dir}/paginas.xml"; code=200 ;;
  */sitemap/catalogo.xml)   body="{$this->dir}/catalogo.xml"; code=200 ;;
  */products/rota)          body=""; code=500 ;;
  *)                        body=""; code=200 ;;
esac
if [ "\$wants_code" = 1 ]; then printf '%s' "\$code"; exit 0; fi
[ -n "\$body" ] && cat "\$body"
exit 0
SH);
    }

    /** @return list<string> URLs pedidas, en orden, sin las del propio sitemap. */
    private function warmed(): array
    {
        preg_match_all('/^GET (\S+)$/m', $this->calls(), $m);
        return array_values(array_filter(
            $m[1],
            static fn (string $url): bool => !str_contains($url, 'sitemap'),
        ));
    }

    public function testWarmsKeyRoutesFirstThenEverySitemapUrlInOrder(): void
    {
        $result = $this->sh($this->script('warm-cache.sh'), ['https://t.example'], ['WARM_DELAY' => '0']);

        $this->assertSame(0, $result['code']);
        $this->assertSame(
            [
                'https://t.example/',
                'https://t.example/products',
                'https://t.example/blog',
                'https://t.example/about',
                'https://t.example/contact',
                'https://t.example/',
                'https://t.example/blog',
                'https://t.example/products/a',
                'https://t.example/products/b',
                'https://t.example/products/rota',
            ],
            $this->warmed(),
        );
    }

    public function testAFailingRouteIsReportedButNeverFailsTheScript(): void
    {
        $result = $this->sh($this->script('warm-cache.sh'), ['https://t.example'], ['WARM_DELAY' => '0']);

        $this->assertSame(0, $result['code'], 'Calentar es best-effort: el veredicto lo da el smoke check.');
        $this->assertStringContainsString('FALLO 500', $result['stdout']);
        $this->assertStringContainsString('1 con error', $result['stdout']);
    }

    public function testOffsetAndMaxUrlsSelectASliceOfTheSitemap(): void
    {
        $result = $this->sh($this->script('warm-cache.sh'), ['https://t.example'], [
            'WARM_DELAY' => '0',
            'WARM_KEY_ROUTES' => '0',
            'WARM_OFFSET' => '2',
            'WARM_MAX_URLS' => '1',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertSame(['https://t.example/products/a'], $this->warmed());
    }

    public function testBaseUrlFallsBackToPublicDomainAndIgnoresATrailingSlash(): void
    {
        $this->sh($this->script('warm-cache.sh'), ['https://t.example/'], [
            'WARM_DELAY' => '0',
            'WARM_KEY_ROUTES' => '0',
            'WARM_MAX_URLS' => '1',
        ]);
        $this->assertContains('https://t.example/', $this->warmed());
        $this->assertStringNotContainsString('t.example//', $this->calls());

        @unlink($this->log);
        $this->sh($this->script('warm-cache.sh'), [], [
            'PUBLIC_DOMAIN' => 't.example',
            'WARM_DELAY' => '0',
            'WARM_KEY_ROUTES' => '0',
            'WARM_MAX_URLS' => '1',
        ]);
        $this->assertStringContainsString('GET https://t.example/sitemap.xml', $this->calls());
    }

    public function testAnUnreadableSitemapIsSkippedWithoutFailing(): void
    {
        $this->fakeBinary('curl', 'echo "GET $*" >> "$CALLS_LOG"; case "$*" in *"%{http_code}"*) printf 200 ;; esac; exit 0');

        $result = $this->sh($this->script('warm-cache.sh'), ['https://t.example'], [
            'WARM_DELAY' => '0',
            'WARM_KEY_ROUTES' => '0',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('no se pudo leer', $result['stdout']);
    }
}
