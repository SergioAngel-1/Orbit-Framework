<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Functions;
use TestMembershipsServiceWpdb;

/**
 * Piezas comunes de los tests de la cola de reversiones de puntos (M-3): doble de `$wpdb`,
 * opciones en memoria, nivel de prueba y contrato de `loyalty` simulado.
 *
 * La cola es una opción más; las funciones del contrato se definen solo en la clase que
 * representa «loyalty cargado», porque `function_exists()` es la señal real que usa el puente.
 * Vive fuera de los archivos `*Test.php` para que PHPUnit no lo trate como una suite.
 */
trait ActivationPointsQueueFixture
{
    private const BUYER = 7;
    private const LEVEL = 101;

    private int $now = 1_800_000_000;

    /** @var array<string,mixed> */
    private array $options = [];
    /** @var array<int,array<string,string>> */
    private array $levelMeta = [];
    /** @var array<int,\WP_Post> */
    private array $levelPosts = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string,mixed> */
    private array $loyalty = [];
    private TestMembershipsServiceWpdb $wpdb;

    private function setUpQueueFixture(): void
    {
        $this->now        = 1_800_000_000;
        $this->options    = [];
        $this->levelMeta  = [];
        $this->levelPosts = [];
        $this->logged     = [];
        $this->loyalty    = [
            'reverse_calls' => [],
            'reverse'       => true,
        ];

        $this->wpdb      = new TestMembershipsServiceWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/LoyaltyBridge.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        \HWE_Memberships_Events::clear();

        $post                          = new \WP_Post();
        $post->ID                      = self::LEVEL;
        $post->post_type               = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status             = 'publish';
        $post->post_title              = 'Nivel ' . self::LEVEL;
        $post->post_name               = 'nivel-' . self::LEVEL;
        $post->post_content            = '';
        $this->levelPosts[self::LEVEL] = $post;
        $this->levelMeta[self::LEVEL]  = [
            \HWE_Memberships_Levels::RANK_META => '5',
        ];

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'hwe_config'     => ['memberships' => ['expiring_notice_days' => '7']],
                'active_plugins' => ['hwe-memberships/hwe-memberships.php', 'hwe-loyalty/hwe-loyalty.php'],
                default          => $this->options[$name] ?? $default,
            };
        });
        Functions\when('update_option')->alias(function (string $name, mixed $value, mixed $autoload = null): bool {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->levelPosts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->levelMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(
            fn (int $id) => self::BUYER === $id ? (object) ['ID' => $id] : false
        );
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args): mixed => $value
        );
        Functions\when('wp_next_scheduled')->alias(static fn (string $hook): int|false => false);
        Functions\when('wp_schedule_event')->alias(static fn (int $timestamp, string $recurrence, string $hook): bool => true);
        Functions\when('wp_schedule_single_event')->alias(static fn (int $timestamp, string $hook): bool => true);
        Functions\when('wp_clear_scheduled_hook')->alias(static fn (string $hook): int => 0);
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    private function benefit(): \HWE_Memberships_Benefit_Activation_Points
    {
        return new \HWE_Memberships_Benefit_Activation_Points();
    }

    /** Concesión de compra cobrada, como la deja `Purchase::processOrder`. */
    private function seedOrderGrant(string $key): int
    {
        $grantId = \HWE_Memberships_Grants::grant(
            self::BUYER,
            self::LEVEL,
            30,
            'order',
            $key,
            ['type' => 'order', 'id' => 55]
        );

        $this->assertIsInt($grantId);

        return $grantId;
    }

    /** @return array<string,array<string,mixed>> */
    private function queue(): array
    {
        $raw = $this->options[\HWE_Memberships_Benefit_Activation_Points::PENDING_OPTION] ?? [];

        return is_array($raw) ? $raw : [];
    }

    private function seedQueueEntry(string $key): void
    {
        $this->options[\HWE_Memberships_Benefit_Activation_Points::PENDING_OPTION] = [
            $key => [
                'user_id'   => self::BUYER,
                'grant_id'  => 1,
                'level_id'  => self::LEVEL,
                'attempts'  => 0,
                'queued_at' => '2026-01-01 00:00:00',
            ],
        ];
    }

    /** Contrato público de `loyalty`; solo existe cuando el plugin está cargado. */
    private function defineLoyaltyContract(): void
    {
        Functions\when('hwe_loyalty_available')->alias(static fn (string $feature): bool => in_array($feature, ['points', 'referrals'], true));
        Functions\when('hwe_loyalty_credit')->alias(static fn (int $userId, int $points, string $type, string $key): int => 1);
        Functions\when('hwe_loyalty_reverse_credit')->alias(function (string $key, string $reversalKey): ?int {
            $this->loyalty['reverse_calls'][] = [$key, $reversalKey];

            return $this->loyalty['reverse'] ? 900 : null;
        });
    }
}
