<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\SiteReviews;

use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-site-reviews` (módulo `site-reviews`): la reseña del SITIO que un cliente da a
 * su pedido. Una por pedido, solo de un pedido completado y solo de su dueño.
 *
 * Cada test en su proceso: el archivo define una clase global y `WC_Order` se simula con
 * `eval` (Woo no está disponible).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SiteReviewsTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    /** @var list<array<string,mixed>> */
    private array $inserted = [];

    protected function setUp(): void
    {
        parent::setUp();

        eval('class WC_Order {
            public array $meta = [];
            public int $saves = 0;
            public function __construct(
                public int $id = 10,
                public int $customer = 7,
                public string $status = "completed",
            ) {}
            public function get_customer_id() { return $this->customer; }
            public function get_status() { return $this->status; }
            public function get_order_number() { return (string) $this->id; }
            public function get_billing_first_name() { return "Ana"; }
            public function get_billing_last_name() { return "Ruiz"; }
            public function get_billing_email() { return "ana@example.com"; }
            public function get_meta($k) { return $this->meta[$k] ?? ""; }
            public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
            public function delete_meta_data($k) { unset($this->meta[$k]); }
            public function save() { $this->saves++; }
        }');

        $this->options = [];
        $this->inserted = [];
        Functions\stubs([
            'sanitize_textarea_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'is_wp_error' => static fn ($v) => $v instanceof \WP_Error,
            'get_post_time' => static fn () => '2026-10-13T10:00:00+00:00',
        ]);
        Functions\when('add_option')->alias(function (string $name, $value): bool {
            if (array_key_exists($name, $this->options)) {
                return false;
            }
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('get_option')->alias(fn (string $name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, $value): bool {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);
            return true;
        });
    }

    private function load(): void
    {
        $this->loadOwnFile('plugins/hwe-site-reviews/hwe-site-reviews.php');
    }

    /** Simula la inserción del post y devuelve el id que WordPress le daría. */
    private function wpInsertsPost(int $id = 501): void
    {
        Functions\when('wp_insert_post')->alias(function (array $args) use ($id) {
            $this->inserted[] = $args;
            return $id;
        });
        Functions\when('get_post')->alias(static function ($postId) {
            $post = new \WP_Post();
            $post->ID = (int) $postId;
            $post->post_type = 'hwe_site_review';
            $post->post_content = 'Todo llegó perfecto y muy bien embalado.';
            return $post;
        });
        Functions\when('get_post_meta')->alias(static fn ($postId, $key) => $key === '_hwe_rating' ? 5 : '');
    }

    private function request(array $params = []): \WP_REST_Request
    {
        return new \WP_REST_Request($params + [
            'order_id' => 10,
            'user_id' => 7,
            'rating' => 5,
            'content' => 'Todo llegó perfecto y muy bien embalado.',
        ]);
    }

    // ── registro ────────────────────────────────────────────────────────────

    public function testRegistersItsRevalidationTagAndKeepsTheOthers(): void
    {
        $this->load();

        $this->assertSame(['posts', 'site-reviews'], \HWE_Site_Reviews::registerTag(['posts']));
        $this->assertSame(['site-reviews'], \HWE_Site_Reviews::registerTag(['site-reviews']), 'Sin duplicar.');
        $this->assertSame(['site-reviews'], \HWE_Site_Reviews::registerTag('no-es-una-lista'));
    }

    // ── crear la reseña ─────────────────────────────────────────────────────

    public function testRejectsTooShortOrTooLongContent(): void
    {
        $this->load();

        $short = \HWE_Site_Reviews::handleCreate($this->request(['content' => 'Bien']));
        $long = \HWE_Site_Reviews::handleCreate($this->request(['content' => str_repeat('a', 3001)]));

        $this->assertSame(422, $short->get_status());
        $this->assertSame(422, $long->get_status());
    }

    public function testRejectsARatingOutsideOneToFive(): void
    {
        $this->load();

        $this->assertSame(422, \HWE_Site_Reviews::handleCreate($this->request(['rating' => 0]))->get_status());
        $this->assertSame(422, \HWE_Site_Reviews::handleCreate($this->request(['rating' => 6]))->get_status());
    }

    public function testAnOrderOfAnotherCustomerLooksLikeAMissingOne(): void
    {
        Functions\when('wc_get_order')->alias(static fn ($id) => $id === 10 ? new \WC_Order(10, 99) : false);
        $this->load();

        $foreign = \HWE_Site_Reviews::handleCreate($this->request());
        $missing = \HWE_Site_Reviews::handleCreate($this->request(['order_id' => 11]));

        $this->assertSame(404, $foreign->get_status());
        $this->assertSame($missing->get_data(), $foreign->get_data(), 'No confirma que el pedido exista.');
    }

    public function testOnlyACompletedOrderCanBeRated(): void
    {
        Functions\when('wc_get_order')->justReturn(new \WC_Order(10, 7, 'processing'));
        $this->load();

        $response = \HWE_Site_Reviews::handleCreate($this->request());

        $this->assertSame(422, $response->get_status());
        $this->assertSame('order_not_rateable', $response->get_data()['error']);
    }

    public function testCreatesTheReviewAndMirrorsItOnTheOrder(): void
    {
        $order = new \WC_Order(10, 7, 'completed');
        Functions\when('wc_get_order')->justReturn($order);
        $this->wpInsertsPost(501);
        $this->load();

        $response = \HWE_Site_Reviews::handleCreate($this->request());

        $this->assertSame(201, $response->get_status());
        $this->assertCount(1, $this->inserted);
        $this->assertSame('hwe_site_review', $this->inserted[0]['post_type']);
        $this->assertSame(7, $this->inserted[0]['post_author']);
        $this->assertSame(5, $this->inserted[0]['meta_input']['_hwe_rating']);
        $this->assertSame('ana@example.com', $this->inserted[0]['meta_input']['_hwe_reviewer_email']);
        $this->assertSame(501, $order->meta['_hwe_site_review']['id']);
        $this->assertSame(5, $order->meta['_hwe_site_review']['rating']);
        $this->assertSame(1, $order->saves);
        $this->assertArrayNotHasKey('hwe_site_review_lock_10', $this->options, 'El cerrojo se libera.');
    }

    public function testASecondReviewOfTheSameOrderIsRejected(): void
    {
        $order = new \WC_Order(10, 7, 'completed');
        $order->meta['_hwe_site_review'] = ['id' => 500, 'rating' => 4, 'content' => 'ya', 'date' => ''];
        Functions\when('wc_get_order')->justReturn($order);
        $this->wpInsertsPost();
        $this->load();

        $response = \HWE_Site_Reviews::handleCreate($this->request());

        $this->assertSame(409, $response->get_status());
        $this->assertCount(0, $this->inserted);
    }

    public function testAnInFlightRequestForTheSameOrderIsRejectedAndKeepsItsLock(): void
    {
        $this->options['hwe_site_review_lock_10'] = time();
        Functions\when('wc_get_order')->justReturn(new \WC_Order(10, 7, 'completed'));
        $this->wpInsertsPost();
        $this->load();

        $response = \HWE_Site_Reviews::handleCreate($this->request());

        $this->assertSame(409, $response->get_status());
        $this->assertCount(0, $this->inserted);
        $this->assertArrayHasKey('hwe_site_review_lock_10', $this->options, 'El cerrojo ajeno no se toca.');
    }

    public function testAnOrphanLockOlderThanAMinuteExpires(): void
    {
        $this->options['hwe_site_review_lock_10'] = time() - 120;
        Functions\when('wc_get_order')->justReturn(new \WC_Order(10, 7, 'completed'));
        $this->wpInsertsPost();
        $this->load();

        $response = \HWE_Site_Reviews::handleCreate($this->request());

        $this->assertSame(201, $response->get_status());
    }

    // ── papelera ────────────────────────────────────────────────────────────

    public function testTrashingTheReviewFreesTheOrderToBeRatedAgain(): void
    {
        $order = new \WC_Order(10, 7, 'completed');
        $order->meta['_hwe_site_review'] = ['id' => 501, 'rating' => 5, 'content' => 'x', 'date' => ''];
        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('get_post_type')->justReturn('hwe_site_review');
        Functions\when('get_post_meta')->justReturn(10);
        $this->load();

        \HWE_Site_Reviews::onTrash(501);

        $this->assertArrayNotHasKey('_hwe_site_review', $order->meta);
    }

    public function testTrashingAnOlderReviewKeepsTheMirrorOfTheCurrentOne(): void
    {
        $order = new \WC_Order(10, 7, 'completed');
        $order->meta['_hwe_site_review'] = ['id' => 777, 'rating' => 5, 'content' => 'x', 'date' => ''];
        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('get_post_type')->justReturn('hwe_site_review');
        Functions\when('get_post_meta')->justReturn(10);
        $this->load();

        \HWE_Site_Reviews::onTrash(501);

        $this->assertSame(777, $order->meta['_hwe_site_review']['id']);
    }

    public function testRestoringFromTheTrashRepublishesAndRestoresTheMirror(): void
    {
        Functions\when('get_post_type')->justReturn('hwe_site_review');
        $this->load();

        $this->assertSame('publish', \HWE_Site_Reviews::untrashStatus('draft', 501));

        Functions\when('get_post_type')->justReturn('post');
        $this->assertSame('draft', \HWE_Site_Reviews::untrashStatus('draft', 5), 'Otros tipos no se tocan.');
    }
}
