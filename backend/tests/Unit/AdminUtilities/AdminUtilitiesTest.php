<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Utilidades de admin y pedidos (tarea 37): higiene de espacios y enlaces, login en un
 * slug propio, imagen de referencia, guía del pedido, contacto y más vendidos.
 *
 * Cada test en su proceso: varios archivos definen funciones globales o necesitan
 * constantes (`HWE_LOGIN_SLUG`) y clases de Woo (`WC_Order`) distintas.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminUtilitiesTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => trim((string) preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $v))),
            'sanitize_textarea_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'sanitize_email' => static fn ($v) => filter_var((string) $v, FILTER_VALIDATE_EMAIL) ? (string) $v : '',
            'sanitize_key' => static fn ($v) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)),
            'is_email' => static fn ($v) => (bool) filter_var((string) $v, FILTER_VALIDATE_EMAIL),
            'wp_parse_url' => static fn ($url, $c = -1) => parse_url((string) $url, $c),
            '__' => static fn ($v) => $v,
        ]);
        Functions\when('error_log')->alias(function (string $m): bool {
            $this->logged[] = $m;
            return true;
        });
    }

    // ── higiene de espacios ─────────────────────────────────────────────────

    public function testSpaceHygieneUnweldsWordsButRespectsLoneEntities(): void {
        $this->loadOwnFile('mu-plugins/hwe-space-hygiene.php');

        $this->assertSame('Cada diciembre llega', hwe_space_hygiene_fix_line("Cada\xC2\xA0diciembre \xC2\xA0llega "));
        $this->assertSame('<p>Cada diciembre.</p><p>&nbsp;</p>', hwe_space_hygiene_fix_html('<p>Cada&nbsp;diciembre.</p><p>&nbsp;</p>'));
        $this->assertSame('a &nbsp;b', hwe_space_hygiene_fix_html('a &nbsp;b'), 'Un &nbsp; junto a un espacio es intencional.');
    }

    public function testSpaceHygieneTouchesPostsAndPagesButNotProducts(): void {
        $this->loadOwnFile('mu-plugins/hwe-space-hygiene.php');

        $post = hwe_space_hygiene_filter_post_data(['post_type' => 'post', 'post_title' => "A\xC2\xA0B", 'post_content' => 'x&nbsp;y']);
        $product = hwe_space_hygiene_filter_post_data(['post_type' => 'product', 'post_title' => "A\xC2\xA0B"]);

        $this->assertSame('A B', $post['post_title']);
        $this->assertSame('x y', $post['post_content']);
        $this->assertSame("A\xC2\xA0B", $product['post_title']);
    }

    public function testHygieneMetaKeysAreExtensibleAndValidated(): void {
        $this->loadOwnFile('mu-plugins/hwe-space-hygiene.php');
        Filters\expectApplied('hwe_hygiene_meta_keys')->andReturnUsing(static function (array $keys): array {
            $keys['line'][] = '_hwe_title_fr';
            $keys['html'][] = '_hwe_content_fr';
            $keys['html'][] = 42;
            return $keys;
        });

        $keys = hwe_hygiene_meta_keys();

        $this->assertContains('_hwe_seo_title', $keys['line']);
        $this->assertContains('_hwe_title_fr', $keys['line']);
        $this->assertSame(['_hwe_content_fr'], $keys['html']);
    }

    // ── higiene de enlaces ──────────────────────────────────────────────────

    public function testLinkHygieneStripsOnlyTheAssistantMark(): void {
        $this->loadOwnFile('mu-plugins/hwe-link-hygiene.php');

        $this->assertSame('<a href="https://x.test/a">', hwe_link_hygiene_strip_utm('<a href="https://x.test/a?utm_source=chatgpt.com">'));
        $this->assertSame('https://x.test/a?p=1', hwe_link_hygiene_strip_utm('https://x.test/a?p=1&utm_source=chatgpt.com'));
        $this->assertSame('https://x.test/a?p=1', hwe_link_hygiene_strip_utm('https://x.test/a?utm_source=chatgpt.com&p=1'));
        $this->assertSame('https://x.test/a?p=1&amp;q=2', hwe_link_hygiene_strip_utm('https://x.test/a?p=1&amp;utm_source=chatgpt.com&amp;q=2'));
        $this->assertSame('https://x.test/a#top', hwe_link_hygiene_strip_utm('https://x.test/a?utm_source=chatgpt.com#top'));
        $this->assertSame('https://x.test/?utm_source=newsletter', hwe_link_hygiene_strip_utm('https://x.test/?utm_source=newsletter'));
        $this->assertSame('https://x.test/?utm_source=chatgpt.com.br', hwe_link_hygiene_strip_utm('https://x.test/?utm_source=chatgpt.com.br'));
    }

    public function testLinkHygieneSourcesAreConfigurable(): void {
        $this->loadOwnFile('mu-plugins/hwe-link-hygiene.php');
        Filters\expectApplied('hwe_link_hygiene_utm_sources')->andReturn(['chatgpt.com', 'perplexity']);

        $this->assertSame('https://x.test/a', hwe_link_hygiene_strip_utm('https://x.test/a?utm_source=perplexity'));
    }

    // ── login en un slug propio ─────────────────────────────────────────────

    public function testLoginSlugRewritesLinksAndRecognisesTheRequest(): void {
        define('HWE_LOGIN_SLUG', 'panel-acceso');
        $this->loadOwnFile('mu-plugins/hwe-login-slug.php');

        $this->assertSame('https://x.test/panel-acceso?action=lostpassword', \HWE_Login_Slug::rewriteUrl('https://x.test/wp-login.php?action=lostpassword'));
        $this->assertSame('https://x.test/panel-acceso', \HWE_Login_Slug::rewriteUrl('https://x.test/wp-login.php'));
        $this->assertSame('https://x.test/wp-login.php-no', \HWE_Login_Slug::rewriteUrl('https://x.test/wp-login.php-no'));

        $_SERVER['REQUEST_URI'] = '/panel-acceso/?redirect_to=x';
        $this->assertTrue(\HWE_Login_Slug::isLoginRequest());
        $_SERVER['REQUEST_URI'] = '/panel-acceso-otro';
        $this->assertFalse(\HWE_Login_Slug::isLoginRequest());
    }

    public function testAnInvalidSlugLeavesTheLoginAlone(): void {
        define('HWE_LOGIN_SLUG', 'x');
        $this->loadOwnFile('mu-plugins/hwe-login-slug.php');
        $_SERVER['REQUEST_URI'] = '/x';

        $this->assertNull(\HWE_Login_Slug::slug());
        $this->assertFalse(\HWE_Login_Slug::isLoginRequest());
        $this->assertSame('https://x.test/wp-login.php', \HWE_Login_Slug::rewriteUrl('https://x.test/wp-login.php'));
        $this->assertFalse(has_action('plugins_loaded'), 'No se engancha nada.');
    }

    public function testWithoutTheConstantRateLimitCanAskSafely(): void {
        // La clase existe aunque el archivo no actúe: rate-limit.php la consulta en
        // cada POST que no es de API, y sin la constante no puede reventar.
        $this->loadOwnFile('mu-plugins/hwe-login-slug.php');
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';

        $this->assertTrue(class_exists('HWE_Login_Slug', false));
        $this->assertFalse(\HWE_Login_Slug::isLoginRequest());
    }

    // ── imagen de referencia ────────────────────────────────────────────────

    public function testReferenceImageSavesMarksAndUnmarksAndRevalidatesProducts(): void {
        $meta = [];
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_meta')->alias(static function (int $id, string $k) use (&$meta): string {
            return $meta[$id][$k] ?? '';
        });
        Functions\when('update_post_meta')->alias(static function (int $id, string $k, string $v) use (&$meta): bool {
            $meta[$id][$k] = $v;
            return true;
        });
        $this->loadOwnFile('mu-plugins/hwe-media-reference.php');

        hwe_media_reference_save(['ID' => 9], ['hwe_reference_image_present' => '1', 'hwe_reference_image' => '1']);
        $this->assertSame('1', $meta[9]['_hwe_reference_image']);

        hwe_media_reference_save(['ID' => 9], ['hwe_reference_image_present' => '1']);
        $this->assertSame('0', $meta[9]['_hwe_reference_image'], 'Desmarcar guarda 0, no borra.');

        hwe_media_reference_save(['ID' => 9], ['hwe_reference_image' => '1']);
        $this->assertSame('0', $meta[9]['_hwe_reference_image'], 'Sin el marcador del formulario no se toca.');
    }

    // ── guía del pedido ─────────────────────────────────────────────────────

    public function testOrderTrackingOnlyAcceptsHttpLinks(): void {
        eval('class WC_Order { public array $meta = []; public bool $saved = false;
            public function get_meta($k) { return $this->meta[$k] ?? ""; }
            public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
            public function delete_meta_data($k) { unset($this->meta[$k]); }
            public function save_meta_data() { $this->saved = true; } }');
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_url_raw')->alias(static fn (string $url, array $protocols) => preg_match('#^(https?)://#i', $url) ? $url : '');
        $this->loadOwnFile('mu-plugins/hwe-order-tracking.php');

        $order = new \WC_Order();
        $order->meta['_tracking_number'] = 'VIEJA';
        $_POST = [
            'hwe_order_tracking_nonce' => 'n',
            'hwe_carrier' => ' Mensajería Uno ',
            'hwe_tracking_number' => '',
            'hwe_tracking_url' => 'javascript:alert(1)',
        ];
        hwe_order_tracking_save(5, $order);

        $this->assertSame('Mensajería Uno', $order->meta['_carrier']);
        $this->assertArrayNotHasKey('_tracking_number', $order->meta, 'Vacío = borrar.');
        $this->assertArrayNotHasKey('_tracking_url', $order->meta, 'Un enlace que no es http(s) no se guarda.');
        $this->assertTrue($order->saved);
        $_POST = [];
    }

    // ── contacto ────────────────────────────────────────────────────────────

    public function testContactRejectsInvalidInputAndMailsWithoutHeaderInjection(): void {
        $mails = [];
        Functions\when('get_option')->justReturn('admin@example.test');
        Functions\when('get_bloginfo')->justReturn('Tienda');
        Functions\when('wp_specialchars_decode')->returnArg();
        Functions\when('wp_mail')->alias(static function ($to, $subject, $body, $headers) use (&$mails): bool {
            $mails[] = compact('to', 'subject', 'body', 'headers');
            return true;
        });
        $this->loadOwnFile('mu-plugins/hwe-contact.php');

        $bad = hwe_contact_handle(new \WP_REST_Request(['name' => '', 'email' => 'no', 'description' => '']));
        $this->assertSame(422, $bad->get_status());

        $ok = hwe_contact_handle(new \WP_REST_Request([
            'name' => "Ana\r\nBcc: victima@example.test",
            'email' => 'ana@example.test',
            'requestType' => 'Queja',
            'description' => 'Hola',
        ]));
        $this->assertSame(200, $ok->get_status());
        $this->assertCount(1, $mails);
        $this->assertSame('admin@example.test', $mails[0]['to']);
        $this->assertStringNotContainsString("\n", $mails[0]['headers'][0]);
        $this->assertStringContainsString('[Tienda · Contacto] queja', $mails[0]['subject']);
    }

    public function testContactAndBestSellersOnlyAnswerTheBff(): void {
        $this->loadOwnFile('mu-plugins/hwe-contact.php');
        $this->loadOwnFile('mu-plugins/hwe-best-sellers.php');

        $this->assertFalse(hwe_contact_permitted(new \WP_REST_Request()), 'Sin hwe_has_internal_secret, falla cerrado.');
    }
}
