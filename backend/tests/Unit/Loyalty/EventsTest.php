<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), parte de ganancias por eventos (tarea 7).
 *
 * Se prueba la DECISIÓN de spec §6.3 y §5.5: una reseña (de producto o del sitio) abona
 * `events.review` UNA vez por pedido, venga por donde venga y aunque se publique en varios
 * productos; una pendiente espera a aprobarse; un invitado no abona; el cumpleaños abona una
 * vez por año natural en la zona del sitio, acepta `MM-DD` y `AAAA-MM-DD`, y el 29-02 se paga
 * el 28-02 de los años no bisiestos. Todo exige puntos activos y una cuenta participante, y un
 * importe 0 apaga el evento sin dejar rastro de abono.
 *
 * `HWE_Loyalty_Settings` y `HWE_Loyalty_Ledger` se sustituyen por dobles con alias: el test
 * mide la decisión (a quién, con qué clave y qué datos se abona) y deja el libro real a
 * `LedgerTest`. El doble del libro respeta la idempotencia por clave como el de verdad.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EventsTest extends TestCase
{
    private const USER = 7;
    private const OTHER = 8;
    private const NON_PARTICIPANT = 99;
    private const ORDER = 55;
    private const PRODUCT = 101;
    private const OTHER_PRODUCT = 102;

    private \DateTimeImmutable $now;
    /** @var array<int,bool> */
    private array $participants = [];
    /** @var array<string,int> Correo (en minúsculas) => usuario. */
    private array $usersByEmail = [];
    /** @var array<int,list<string>> Meta `hwe_birthday` por usuario. */
    private array $userMeta = [];
    /** @var list<list<int>> Lotes que devuelve `get_users`, en orden. */
    private array $userBatches = [];
    /** @var list<array<string,mixed>> Argumentos de cada `get_users`. */
    private array $userQueries = [];
    /** @var list<list<TestEventOrder>> Páginas que devuelve `wc_get_orders`, en orden. */
    private array $orderPages = [];
    /** @var array<int,TestEventOrder> Pedidos que devuelve `wc_get_order`. */
    private array $orders = [];
    /** @var list<array<string,mixed>> Argumentos de cada `wc_get_orders`. */
    private array $orderQueries = [];
    /** @var list<array<string,mixed>> Intentos de abono, en orden (incluidos los rechazados por clave). */
    private array $creditAttempts = [];
    /** @var array<string,int> Claves ya abonadas por el doble del libro. */
    private array $creditedKeys = [];
    /** @var list<array<string,mixed>> Abonos que el doble del libro aceptó. */
    private array $credited = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now            = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $this->participants   = [self::USER => true, self::OTHER => true, self::NON_PARTICIPANT => false];
        $this->usersByEmail   = [];
        $this->userMeta       = [];
        $this->userBatches    = [];
        $this->userQueries    = [];
        $this->orderPages     = [];
        $this->orders         = [];
        $this->orderQueries   = [];
        $this->creditAttempts = [];
        $this->creditedKeys   = [];
        $this->credited       = [];
        $this->logged         = [];

        Functions\when('current_datetime')->alias(fn (): \DateTimeImmutable => $this->now);
        Functions\when('get_user_by')->alias(function (string $field, string $value) {
            if ('email' !== $field) {
                return false;
            }
            $id = $this->usersByEmail[strtolower($value)] ?? 0;

            return $id > 0 ? (object) ['ID' => $id] : false;
        });
        Functions\when('get_user_meta')->alias(
            function (int $userId, string $key, bool $single = false) {
                if ('hwe_birthday' !== $key) {
                    return $single ? '' : [];
                }
                $values = $this->userMeta[$userId] ?? [];

                return $single ? ($values[0] ?? '') : $values;
            }
        );
        Functions\when('get_users')->alias(function (array $args = []): array {
            $this->userQueries[] = $args;

            return array_shift($this->userBatches) ?? [];
        });
        Functions\when('wc_get_orders')->alias(function (array $args = []): array {
            $this->orderQueries[] = $args;

            return array_shift($this->orderPages) ?? [];
        });
        Functions\when('wc_get_order')->alias(fn (int $id) => $this->orders[$id] ?? false);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;

            return true;
        });
    }

    /**
     * Declara los dobles de ajustes y libro y carga el planificador y los eventos.
     *
     * @param array{review?:int,birthday?:int} $events
     */
    private function boot(array $events = ['review' => 25, 'birthday' => 15], bool $active = true): void
    {
        $settings = Mockery::mock('alias:HWE_Loyalty_Settings');
        $settings->shouldReceive('pointsActive')->andReturn($active);
        $settings->shouldReceive('get')->andReturn([
            'events'               => [
                'registration' => 0,
                'review'       => $events['review'] ?? 0,
                'birthday'     => $events['birthday'] ?? 0,
            ],
            'currency_name_plural' => 'puntos',
            'expiry_days'          => 0,
        ]);
        $settings->shouldReceive('canParticipate')->andReturnUsing(
            fn (int $userId): bool => $this->participants[$userId] ?? false
        );

        $ledger = Mockery::mock('alias:HWE_Loyalty_Ledger');
        $ledger->shouldReceive('credit')->andReturnUsing(
            function (
                int $userId,
                int $points,
                string $type,
                string $key,
                array $ref = [],
                ?int $expiryDays = null,
                array $data = []
            ): ?int {
                $attempt = [
                    'user_id'     => $userId,
                    'points'      => $points,
                    'type'        => $type,
                    'key'         => $key,
                    'ref'         => $ref,
                    'expiry_days' => $expiryDays,
                    'data'        => $data,
                ];
                $this->creditAttempts[] = $attempt;

                if (isset($this->creditedKeys[$key])) {
                    return null;
                }

                $id                    = count($this->creditedKeys) + 1;
                $this->creditedKeys[$key] = $id;
                $this->credited[]      = $attempt;

                return $id;
            }
        );

        $this->loadOwnFile('plugins/hwe-loyalty/includes/Scheduler.php');
        $this->loadOwnFile('plugins/hwe-loyalty/includes/Events.php');
    }

    /** @param array<string,mixed> $overrides */
    private function comment(array $overrides = []): object
    {
        return (object) array_merge([
            'comment_ID'           => 900,
            'comment_type'         => 'review',
            'comment_approved'     => 1,
            'comment_post_ID'      => self::PRODUCT,
            'comment_author_email' => 'ana@example.com',
            'user_id'              => 0,
        ], $overrides);
    }

    /** @param list<int> $productIds */
    private function order(int $id = self::ORDER, array $productIds = [self::PRODUCT]): TestEventOrder
    {
        $order = new TestEventOrder($id, $productIds);
        $this->orders[$id] = $order;

        return $order;
    }

    /** @return list<string> */
    private function metaQueryValues(array $query): array
    {
        $values = [];
        foreach ($query as $clause) {
            if (is_array($clause) && isset($clause['value'])) {
                $values[] = (string) $clause['value'];
            }
        }

        return $values;
    }

    // ── Reseñas de producto ──────────────────────────────────────────────────

    public function test_an_approved_product_review_credits_the_review_event_once_per_order(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $order = $this->order(self::ORDER, [self::PRODUCT, self::OTHER_PRODUCT]);
        // Dos peticiones del BFF independientes: cada una vuelve a resolver el pedido.
        $this->orderPages = [[$order], [$order]];

        \HWE_Loyalty_Events::onCommentInserted(
            900,
            $this->comment(['comment_ID' => 900, 'comment_post_ID' => self::PRODUCT])
        );
        \HWE_Loyalty_Events::onCommentInserted(
            901,
            $this->comment(['comment_ID' => 901, 'comment_post_ID' => self::OTHER_PRODUCT])
        );

        $this->assertCount(2, $this->creditAttempts, 'Cada reseña con autor intenta el abono.');
        $this->assertCount(1, $this->credited, 'Un pedido reseñado se abona una sola vez, aunque tenga varias reseñas.');
        $this->assertSame('review:order:' . self::ORDER, $this->credited[0]['key']);
        $this->assertSame(self::USER, $this->credited[0]['user_id']);
        $this->assertSame(25, $this->credited[0]['points']);
        $this->assertSame('review', $this->credited[0]['type']);
        $this->assertSame(['type' => 'order', 'id' => self::ORDER], $this->credited[0]['ref']);
        $this->assertSame(
            ['review_id' => 900, 'source' => 'product'],
            $this->credited[0]['data'],
            'La traza guarda la reseña concreta y su origen.'
        );
        $this->assertNull($this->credited[0]['expiry_days'], 'La caducidad la aplica el libro con el ajuste vigente.');

        $this->assertCount(1, $order->notes, 'Una nota privada por abono, no una por reseña del mismo pedido.');
        $this->assertStringContainsString('puntos', $order->notes[0]['note']);
        $this->assertFalse($order->notes[0]['is_customer'], 'La trazabilidad es una nota privada.');

        $this->assertSame(self::USER, $this->orderQueries[0]['customer_id'] ?? null);
        $this->assertSame(['completed'], $this->orderQueries[0]['status'] ?? null);
        $this->assertSame('date', $this->orderQueries[0]['orderby'] ?? null);
        $this->assertSame('DESC', $this->orderQueries[0]['order'] ?? null);
        $this->assertSame(1, $this->orderQueries[0]['page'] ?? null);
        $this->assertLessThanOrEqual(100, $this->orderQueries[0]['limit'] ?? 0, 'La consulta va acotada por página.');
    }

    public function test_a_pending_product_review_is_credited_only_when_it_is_approved(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $order            = $this->order();
        $this->orderPages = [[$order]];

        $pending = $this->comment(['comment_ID' => 902, 'comment_approved' => 0]);
        \HWE_Loyalty_Events::onCommentInserted(902, $pending);
        $this->assertSame([], $this->creditAttempts, 'Una reseña pendiente no abona al insertarse.');

        // WordPress entrega el comentario con su estado ANTERIOR en la transición.
        \HWE_Loyalty_Events::onCommentTransition('approved', 'unapproved', $pending);
        $this->assertCount(1, $this->credited, 'Al aprobarse, la reseña abona su pedido.');
        $this->assertSame('review:order:' . self::ORDER, $this->credited[0]['key']);

        \HWE_Loyalty_Events::onCommentTransition('approved', 'approved', $pending);
        $this->assertCount(1, $this->credited, 'Una segunda transición a aprobada no duplica.');
    }

    public function test_a_guest_product_review_does_not_credit_and_is_logged(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);

        \HWE_Loyalty_Events::onCommentInserted(
            903,
            $this->comment(['comment_ID' => 903, 'comment_author_email' => 'invitado@example.com'])
        );

        $this->assertSame([], $this->creditAttempts, 'Sin usuario no hay cuenta a la que abonar.');
        $this->assertSame([], $this->orderQueries, 'Tampoco se busca un pedido para un invitado.');
        $this->assertNotEmpty($this->logged, 'La reseña sin usuario se registra, no se traga.');
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
        $this->assertStringContainsString('903', $this->logged[0], 'Solo ids, sin PII.');
        $this->assertStringNotContainsString('invitado@example.com', $this->logged[0]);
    }

    public function test_a_non_participating_author_is_not_credited(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::NON_PARTICIPANT;
        $this->orderPages = [[$this->order()]];

        \HWE_Loyalty_Events::onCommentInserted(904, $this->comment(['comment_ID' => 904]));

        $this->assertSame([], $this->creditAttempts);
    }

    public function test_a_product_review_without_a_resolvable_order_is_not_credited_and_is_logged(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $this->orderPages = [[]];

        \HWE_Loyalty_Events::onCommentInserted(905, $this->comment(['comment_ID' => 905]));

        $this->assertSame([], $this->creditAttempts);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('905', $this->logged[0]);
        $this->assertSame($this->orderQueries[0]['customer_id'] ?? null, self::USER);
    }

    public function test_the_order_lookup_is_bounded_and_stops_on_the_first_incomplete_page(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $this->orderPages = [[]];

        \HWE_Loyalty_Events::onCommentInserted(906, $this->comment(['comment_ID' => 906]));

        $this->assertCount(1, $this->orderQueries, 'Una página incompleta cierra la búsqueda.');
    }

    // ── Reseña del sitio ─────────────────────────────────────────────────────

    public function test_a_site_review_shares_the_same_order_key_as_a_product_review(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $order            = $this->order();
        $this->orderPages = [[$order]];

        \HWE_Loyalty_Events::onSiteReviewPublished(910, self::USER, self::ORDER);
        \HWE_Loyalty_Events::onCommentInserted(911, $this->comment(['comment_ID' => 911]));

        $this->assertCount(2, $this->creditAttempts, 'Las dos reseñas usan la misma clave por pedido.');
        $this->assertCount(1, $this->credited, 'Un pedido reseñado abona una sola vez, venga por donde venga.');
        $this->assertSame('review:order:' . self::ORDER, $this->credited[0]['key']);
        $this->assertSame('site', $this->credited[0]['data']['source']);
        $this->assertSame(910, $this->credited[0]['data']['review_id']);
        $this->assertSame('review:order:' . self::ORDER, $this->creditAttempts[1]['key']);
    }

    public function test_a_site_review_without_a_user_or_order_is_not_credited_and_is_logged(): void
    {
        $this->boot(['review' => 25, 'birthday' => 0]);

        \HWE_Loyalty_Events::onSiteReviewPublished(912, 0, self::ORDER);
        \HWE_Loyalty_Events::onSiteReviewPublished(913, self::USER, 0);

        $this->assertSame([], $this->creditAttempts);
        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('912', $this->logged[0]);
        $this->assertStringContainsString('913', $this->logged[1]);
    }

    // ── Cumpleaños ───────────────────────────────────────────────────────────

    public function test_birthdays_are_credited_once_per_calendar_year_for_both_formats(): void
    {
        $this->boot(['review' => 0, 'birthday' => 15]);
        $this->userMeta = [
            self::USER            => ['06-15'],
            self::OTHER           => ['1990-06-15'],
            self::NON_PARTICIPANT => ['06-15'],
        ];
        $this->userBatches = [
            [self::USER, self::OTHER, self::NON_PARTICIPANT],
            [self::USER, self::OTHER, self::NON_PARTICIPANT],
            [self::USER, self::OTHER, self::NON_PARTICIPANT],
        ];

        $this->assertSame(2, \HWE_Loyalty_Events::runBirthdays(), 'MM-DD y AAAA-MM-DD abonan; el no participante no.');
        $this->assertSame(
            ['birthday:' . self::USER . ':2026', 'birthday:' . self::OTHER . ':2026'],
            array_column($this->credited, 'key')
        );
        $this->assertSame(15, $this->credited[0]['points']);
        $this->assertSame(['year' => 2026], $this->credited[0]['data']);
        $this->assertSame([], $this->credited[0]['ref'], 'El cumpleaños no referencia un pedido.');
        $this->assertSame('birthday', $this->credited[0]['type']);

        $this->assertSame(0, \HWE_Loyalty_Events::runBirthdays(), 'La misma pasada diaria no vuelve a abonar el año.');

        $this->now = new \DateTimeImmutable('2027-06-15 12:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(2, \HWE_Loyalty_Events::runBirthdays(), 'El año natural siguiente vuelve a abonar.');
        $this->assertSame(
            ['birthday:' . self::USER . ':2027', 'birthday:' . self::OTHER . ':2027'],
            array_slice(array_column($this->credited, 'key'), 2)
        );

        $query = $this->userQueries[0];
        $this->assertSame('ID', $query['fields'] ?? null);
        $this->assertSame(\HWE_Loyalty_Events::BIRTHDAY_BATCH, $query['number'] ?? null);
        $this->assertSame(0, $query['offset'] ?? null);
        $this->assertFalse($query['count_total'] ?? true, 'No hace falta contar el total de la consulta.');
        $this->assertContains('06-15', $this->metaQueryValues($query['meta_query'] ?? []));
        $this->assertContains('-06-15', $this->metaQueryValues($query['meta_query'] ?? []), 'También las fechas completas.');
    }

    public function test_birthdays_follow_the_site_timezone_and_its_natural_year(): void
    {
        $this->boot(['review' => 0, 'birthday' => 10]);
        // 31-12-2026 a las 23:30 en la zona del sitio; en UTC ya es 01-01-2027.
        $this->now = new \DateTimeImmutable('2026-12-31 23:30:00', new \DateTimeZone('America/Bogota'));
        $this->userMeta    = [self::USER => ['12-31']];
        $this->userBatches = [[self::USER]];

        $this->assertSame(1, \HWE_Loyalty_Events::runBirthdays());
        $this->assertSame(
            'birthday:' . self::USER . ':2026',
            $this->credited[0]['key'],
            'El año natural es el del sitio, no el de UTC.'
        );
        $this->assertContains('12-31', $this->metaQueryValues($this->userQueries[0]['meta_query'] ?? []));
        $this->assertNotContains('01-01', $this->metaQueryValues($this->userQueries[0]['meta_query'] ?? []));
    }

    public function test_february_29_is_credited_on_february_28_of_a_non_leap_year(): void
    {
        $this->boot(['review' => 0, 'birthday' => 10]);
        $this->participants[10] = true;
        $this->now = new \DateTimeImmutable('2026-02-28 12:00:00', new \DateTimeZone('UTC'));
        $this->userMeta = [
            self::USER  => ['02-29'],
            self::OTHER => ['2000-02-29'],
            10          => ['02-28'],
        ];
        $this->userBatches = [[self::USER, self::OTHER, 10]];

        $this->assertSame(3, \HWE_Loyalty_Events::runBirthdays(), 'En un año no bisiesto el 29-02 se paga el 28-02.');
        $this->assertSame(
            ['birthday:' . self::USER . ':2026', 'birthday:' . self::OTHER . ':2026', 'birthday:10:2026'],
            array_column($this->credited, 'key')
        );

        // En bisiesto, el 28-02 solo paga a quien cumple el 28; el 29 espera a mañana.
        $this->now = new \DateTimeImmutable('2028-02-28 12:00:00', new \DateTimeZone('UTC'));
        $this->userBatches = [[self::USER, self::OTHER, 10]];
        $this->assertSame(1, \HWE_Loyalty_Events::runBirthdays());
        $this->assertSame('birthday:10:2028', end($this->credited)['key']);
    }

    public function test_birthdays_are_queried_in_bounded_batches(): void
    {
        $this->boot(['review' => 0, 'birthday' => 1]);
        $this->userMeta    = [self::USER => ['06-15']];
        $this->userBatches = [
            array_fill(0, \HWE_Loyalty_Events::BIRTHDAY_BATCH, self::USER),
            [self::USER],
        ];

        $this->assertSame(1, \HWE_Loyalty_Events::runBirthdays(), 'El mismo usuario repetido abona una sola vez.');
        $this->assertSame(0, $this->userQueries[0]['offset'] ?? null);
        $this->assertSame(\HWE_Loyalty_Events::BIRTHDAY_BATCH, $this->userQueries[1]['offset'] ?? null);
        $this->assertSame(\HWE_Loyalty_Events::BIRTHDAY_BATCH, $this->userQueries[1]['number'] ?? null);
    }

    public function test_an_invalid_birthday_value_is_ignored_and_logged(): void
    {
        $this->boot(['review' => 0, 'birthday' => 10]);
        $this->userMeta    = [self::USER => ['13-45']];
        $this->userBatches = [[self::USER]];

        $this->assertSame(0, \HWE_Loyalty_Events::runBirthdays());
        $this->assertSame([], $this->creditAttempts);
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] loyalty:', $this->logged[0]);
        $this->assertStringContainsString((string) self::USER, $this->logged[0]);
    }

    // ── Apagados ─────────────────────────────────────────────────────────────

    public function test_a_zero_amount_turns_the_event_off_without_crediting_or_querying(): void
    {
        $this->boot(['review' => 0, 'birthday' => 0]);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $this->orderPages = [[$this->order()]];

        \HWE_Loyalty_Events::onSiteReviewPublished(920, self::USER, self::ORDER);
        \HWE_Loyalty_Events::onCommentInserted(921, $this->comment(['comment_ID' => 921]));

        $this->assertSame(0, \HWE_Loyalty_Events::runBirthdays());
        $this->assertSame([], $this->creditAttempts, 'Importe 0 = evento apagado: ni abono ni nota.');
        $this->assertSame([], $this->userQueries, 'Sin importe no se consultan usuarios.');
        $this->assertSame([], $this->orderQueries, 'Sin importe no se busca el pedido para dejarlo anotado.');
        $this->assertSame([], $this->orders[self::ORDER]->notes);
    }

    public function test_events_require_the_points_program(): void
    {
        $this->boot(['review' => 25, 'birthday' => 15], false);
        $this->usersByEmail['ana@example.com'] = self::USER;
        $this->userMeta    = [self::USER => ['06-15']];
        $this->userBatches = [[self::USER]];

        \HWE_Loyalty_Events::onSiteReviewPublished(922, self::USER, self::ORDER);
        \HWE_Loyalty_Events::onCommentInserted(923, $this->comment(['comment_ID' => 923]));

        $this->assertSame(0, \HWE_Loyalty_Events::runBirthdays());
        $this->assertSame([], $this->creditAttempts);
        $this->assertSame([], $this->userQueries);
    }

    // ── Registro de hooks ────────────────────────────────────────────────────

    public function test_register_hooks_comments_site_reviews_and_the_daily_birthday(): void
    {
        $this->boot(['review' => 25, 'birthday' => 15]);

        Actions\expectAdded('wp_insert_comment')
            ->once()
            ->with([\HWE_Loyalty_Events::class, 'onCommentInserted'], 10, 2);
        Actions\expectAdded('transition_comment_status')
            ->once()
            ->with([\HWE_Loyalty_Events::class, 'onCommentTransition'], 10, 3);
        Actions\expectAdded('hwe_site_review_published')
            ->once()
            ->with([\HWE_Loyalty_Events::class, 'onSiteReviewPublished'], 10, 3);
        Actions\expectAdded(\HWE_Loyalty_Scheduler::HOOK)
            ->once()
            ->with([\HWE_Loyalty_Events::class, 'runBirthdays']);

        \HWE_Loyalty_Events::register();
    }
}

/** Pedido mínimo: id, líneas y notas privadas. */
final class TestEventOrder
{
    /** @var list<TestEventItem> */
    private array $items;
    /** @var list<array{note:string,is_customer:bool}> */
    public array $notes = [];

    /** @param list<int> $productIds */
    public function __construct(
        private int $id,
        array $productIds
    ) {
        $this->items = array_map(
            static fn (int $productId): TestEventItem => new TestEventItem($productId),
            $productIds
        );
    }

    public function get_id(): int
    {
        return $this->id;
    }

    /** @return list<TestEventItem> */
    public function get_items(string $type = 'line_item'): array
    {
        return 'line_item' === $type ? $this->items : [];
    }

    public function add_order_note(string $note, bool $isCustomerNote = false): int
    {
        $this->notes[] = ['note' => $note, 'is_customer' => $isCustomerNote];

        return count($this->notes);
    }
}

/** Línea de pedido mínima: solo el producto. */
final class TestEventItem
{
    public function __construct(private int $productId)
    {
    }

    public function get_product_id(): int
    {
        return $this->productId;
    }
}
