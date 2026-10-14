<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Scripts;

/**
 * `check-versions.sh`: compara los plugins fijados en `setup.sh` con la última release
 * (wp.org y GitHub). Solo informa; con `--strict` sale con 1 si alguno está por detrás.
 *
 * Se EJECUTA con un `curl` falso que sirve la «última» versión de cada origen.
 */
final class CheckVersionsTest extends ShellScriptTestCase
{
    /** Valor por defecto de `NOMBRE="${NOMBRE:-x.y.z}"` en setup.sh (la fuente de verdad). */
    private function pinned(string $name): string
    {
        $setup = (string) file_get_contents($this->script('setup.sh'));
        $this->assertSame(1, preg_match('/^' . $name . '="\$\{' . $name . ':-([^}]+)\}"/m', $setup, $m), $name);
        return $m[1];
    }

    /** @param array<string,string> $latest tramo de URL → versión */
    private function fakeLatest(array $latest): void
    {
        $cases = '';
        foreach ($latest as $needle => $version) {
            $json = str_starts_with($needle, 'repos/')
                ? '{' . "\n" . '  "tag_name": "v' . $version . '",' . "\n" . '  "name": "x"' . "\n" . '}'
                : '{"name":"x","version":"' . $version . '","versions":{"1.0.0":"z"}}';
            $cases .= "  *{$needle}*) printf '%s' '" . str_replace("'", '', $json) . "' ;;\n";
        }
        $this->fakeBinary('curl', "for last; do :; done\ncase \"\$last\" in\n{$cases}  *) exit 22 ;;\nesac");
    }

    /** @return array<string,string> */
    private function everythingCurrent(): array
    {
        return [
            'plugins/info/1.0/woocommerce' => $this->pinned('WOOCOMMERCE_VERSION'),
            'plugins/info/1.0/wp-graphql.json' => $this->pinned('WPGRAPHQL_VERSION'),
            'plugins/info/1.0/redis-cache' => $this->pinned('REDISCACHE_VERSION'),
            'repos/wp-graphql/wp-graphql-woocommerce/releases/latest' => $this->pinned('WOOGRAPHQL_WPORG_VERSION'),
            'repos/wp-graphql/wp-graphql-jwt-authentication/releases/latest' => '0.7.2',
            'repos/funkhaus/wp-graphql-cors/releases/latest' => '2.1.1',
        ];
    }

    public function testEverythingCurrentIsReportedAndPassesStrict(): void
    {
        $this->fakeLatest($this->everythingCurrent());

        $result = $this->sh($this->script('check-versions.sh'), ['--strict']);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringNotContainsString('REVISAR', $result['stdout']);
        $this->assertStringContainsString('WooCommerce', $result['stdout']);
    }

    public function testAnOutdatedPluginIsFlaggedButOnlyStrictFails(): void
    {
        $latest = $this->everythingCurrent();
        $latest['plugins/info/1.0/wp-graphql.json'] = '99.0.0';
        $this->fakeLatest($latest);

        $normal = $this->sh($this->script('check-versions.sh'));
        $strict = $this->sh($this->script('check-versions.sh'), ['--strict']);

        $this->assertSame(0, $normal['code']);
        $this->assertMatchesRegularExpression('/WPGraphQL\s+' . preg_quote($this->pinned('WPGRAPHQL_VERSION'), '/') . '\s+99\.0\.0\s+REVISAR/', $normal['stdout']);
        $this->assertSame(1, $strict['code']);
    }

    public function testGithubJsonWithSpaceAfterTheColonIsParsed(): void
    {
        $latest = $this->everythingCurrent();
        $latest['repos/wp-graphql/wp-graphql-woocommerce/releases/latest'] = '9.9.9';
        $this->fakeLatest($latest);

        $result = $this->sh($this->script('check-versions.sh'));

        $this->assertMatchesRegularExpression('/WooGraphQL\s+\S+\s+9\.9\.9\s+REVISAR/', $result['stdout']);
    }

    public function testASourceThatDoesNotAnswerIsReportedNotHidden(): void
    {
        $latest = $this->everythingCurrent();
        unset($latest['plugins/info/1.0/redis-cache']);
        $this->fakeLatest($latest);

        $result = $this->sh($this->script('check-versions.sh'));

        $this->assertStringContainsString('sin dato', $result['stdout']);
        $this->assertStringContainsString('no respondió', $result['stdout']);
    }
}
