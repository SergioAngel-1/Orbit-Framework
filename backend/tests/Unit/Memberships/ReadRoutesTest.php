<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Memberships;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rutas internas de lectura (`hwe/v1/memberships/levels|me|history|prices|product`).
 *
 * Solo las consume el BFF con `X-HWE-Internal-Secret`. Se prueba el CONTRATO JSON, no el SQL:
 * la puerta del secreto interno, el 404 con el módulo apagado, la validación de `user_id`, los
 * topes de paginación y de ids, las fechas ISO 8601 UTC, la forma de cada respuesta, que
 * `product` sin acceso devuelve EXACTAMENTE el recorte de `Teaser` y que ninguna ruta escribe
 * en la base de datos.
 *
 * Los datos que el cliente ve (nombres de beneficio, «10 % en …») se componen en el BFF: aquí
 * solo viajan claves y datos estructurados. Un usuario inexistente recibe una respuesta vacía
 * coherente (200), nunca un 404 que permita enumerar cuentas.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReadRoutesTest extends TestCase
{
    private const USER      = 7;   // socio activo en el nivel alto, indefinido
    private const FROZEN    = 8;   // congelado, con una concesión finita del nivel base
    private const NEW_USER  = 9;   // existe, sin membresía
    private const MISSING   = 999;

    private const LEVEL_BASE   = 101; // rango 1, comprable
    private const LEVEL_TOP    = 102; // rango 5, comprable, antigüedad mínima 30 días
    private const LEVEL_NO_BUY = 104; // rango 2, no comprable
    private const LEVEL_DRAFT  = 103; // borrador

    private const CAT_DISCOUNT = 30;
    private const CAT_OTHER    = 40;

    private const PRODUCT_DISCOUNT = 601; // variable, categoría con descuento
    private const PRODUCT_PUBLIC   = 602; // simple, público
    private const PRODUCT_LOCKED   = 603; // exige rango 5
    private const PRODUCT_DRAFT    = 604; // borrador

    private const PRODUCT_MEMBERSHIP     = 501; // producto de membresía del nivel alto (30 días)
    private const PRODUCT_MEMBERSHIP_INF = 502; // del nivel base (indefinida)

    private const SLUG_LOCKED = 'producto-restringido';

    private int $now = 1_800_000_000;

    /** @var array<string,mixed> Opción `hwe_config`. */
    private array $config = [];
    /** @var list<string> */
    private array $activePlugins = ['hwe-memberships/hwe-memberships.php'];
    /** @var array<int,object> */
    private array $users = [];
    /** @var array<int,\WP_Post> */
    private array $posts = [];
    /** @var array<int,array<string,mixed>> */
    private array $postMeta = [];
    /** @var array<int,array<string,array<string,string>>> Traducciones por post, campo y sufijo. */
    private array $translations = [];
    /** @var array<int,list<int>> */
    private array $productTerms = [];
    /** @var array<int,array<string,mixed>> */
    private array $termMeta = [];
    /** @var array<int,list<int>> */
    private array $termAncestors = [];
    /** @var array<int,\WP_Term> */
    private array $terms = [];
    /** @var array<int,TestMembershipsReadProduct> */
    private array $products = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var int */
    private int $productIdQueries = 0;
    /** @var int */
    private int $productListQueries = 0;
    /** @var list<string> */
    private array $fieldLocales = [];
    private bool $failLevelQuery = false;
    private bool $loyaltyPoints   = false;
    private bool $loyaltyReferrals = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now            = 1_800_000_000;
        $this->config         = [];
        $this->activePlugins  = ['hwe-memberships/hwe-memberships.php'];
        $this->posts          = [];
        $this->postMeta       = [];
        $this->translations   = [];
        $this->productTerms   = [];
        $this->termMeta       = [];
        $this->termAncestors  = [];
        $this->terms          = [];
        $this->products       = [];
        $this->logged             = [];
        $this->productIdQueries   = 0;
        $this->productListQueries = 0;
        $this->fieldLocales       = [];
        $this->failLevelQuery     = false;
        $this->loyaltyPoints      = false;
        $this->loyaltyReferrals   = true;

        $this->users = [
            self::USER => (object) [
                'ID'              => self::USER,
                'user_login'      => 'ana_login',
                'user_email'      => 'ana@example.com',
                'display_name'    => 'Ana',
                'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 200 * DAY_IN_SECONDS),
            ],
            self::FROZEN => (object) [
                'ID'              => self::FROZEN,
                'user_login'      => 'beto_login',
                'user_email'      => 'beto@example.com',
                'display_name'    => 'Beto',
                'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 100 * DAY_IN_SECONDS),
            ],
            self::NEW_USER => (object) [
                'ID'              => self::NEW_USER,
                'user_login'      => 'carla_login',
                'user_email'      => 'carla@example.com',
                'display_name'    => 'Carla',
                'user_registered' => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
            ],
        ];

        $GLOBALS['wpdb'] = new TestMembershipsReadWpdb();

        $this->loadOwnFile('plugins/hwe-memberships/includes/Schema.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Levels.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Timeline.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Events.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Members.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Grants.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Settings.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Benefit.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/InfoPerk.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/EarlyAccess.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ActivationPoints.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralBonus.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/ReferralGift.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/MemberDiscount.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Benefits/Registry.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Access.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Teaser.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Products.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Purchase.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Pricing.php');
        $this->loadOwnFile('plugins/hwe-memberships/includes/Rest/ReadRoutes.php');

        $this->seedCatalog();
        $this->seedMembers();

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if ('hwe_config' === $name) {
                return $this->config;
            }
            if ('active_plugins' === $name) {
                return $this->activePlugins;
            }
            return $default;
        });
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->postMeta[$id][$key] ?? ''
        );
        Functions\when('get_userdata')->alias(fn (int $id) => $this->users[$id] ?? false);
        Functions\when('get_term')->alias(fn (int $id, string $taxonomy = '') => $this->terms[$id] ?? null);
        Functions\when('get_term_meta')->alias(
            fn (int $id, string $key, bool $single = false) => $this->termMeta[$id][$key] ?? ''
        );
        Functions\when('get_ancestors')->alias(
            fn (int $id, string $taxonomy = '', string $context = 'taxonomy'): array => $this->termAncestors[$id] ?? []
        );
        Functions\when('wp_get_post_terms')->alias(
            fn (int $id, string $taxonomy = '', array $args = []): array => $this->productTerms[$id] ?? []
        );
        Functions\when('get_posts')->alias(function (array $args) {
            $type = (string) ($args['post_type'] ?? 'post');

            if (\HWE_Memberships_Levels::POST_TYPE === $type) {
                if ($this->failLevelQuery) {
                    throw new \RuntimeException('fallo simulado al leer los niveles');
                }
                $statuses = (array) ($args['post_status'] ?? ['publish']);
                $posts    = [];
                foreach ($this->posts as $post) {
                    if (\HWE_Memberships_Levels::POST_TYPE !== $post->post_type) {
                        continue;
                    }
                    if (in_array($post->post_status, $statuses, true)) {
                        $posts[] = $post;
                    }
                }
                usort($posts, static fn (\WP_Post $a, \WP_Post $b): int => $a->ID <=> $b->ID);
                return $posts;
            }

            if ('product' === $type) {
                $this->productIdQueries++;
                $ids = [];
                foreach ($this->products as $id => $product) {
                    if ('publish' !== $product->status) {
                        continue;
                    }
                    if ('' === (string) ($this->postMeta[$id][\HWE_Memberships_Products::LEVEL_META] ?? '')) {
                        continue;
                    }
                    $ids[] = (int) $id;
                }
                sort($ids);
                return $ids;
            }

            return [];
        });
        Functions\when('wc_get_product')->alias(fn (int $id) => $this->products[$id] ?? false);
        Functions\when('wc_get_products')->alias(function (array $args) {
            $this->productListQueries++;
            $ids = [];
            foreach ((array) ($args['include'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
            sort($ids);

            $status = $args['status'] ?? 'any';
            $limit  = isset($args['limit']) ? (int) $args['limit'] : -1;

            $out = [];
            foreach ($ids as $id) {
                $product = $this->products[$id] ?? null;
                if (null === $product) {
                    continue;
                }
                if ('any' !== $status && is_string($status) && $status !== $product->status) {
                    continue;
                }
                $out[] = $product;
                if ($limit > 0 && count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        });
        Functions\when('get_page_by_path')->alias(function (string $slug, string $output = 'OBJECT', string $postType = 'post') {
            foreach ($this->posts as $post) {
                if ('' !== $slug && $post->post_type === $postType && $post->post_name === $slug) {
                    return $post;
                }
            }
            return null;
        });
        Functions\when('wp_get_attachment_image_url')->alias(
            static fn (int $id, string $size = 'thumbnail'): string => 'https://example.com/media/' . $id . '.jpg'
        );
        Functions\when('wc_format_decimal')->alias(
            static fn (mixed $value, int|false $decimals = false): string
                => number_format((float) $value, false === $decimals ? 2 : (int) $decimals, '.', '')
        );
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('time')->alias(fn (): int => $this->now);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value) => $value);
        Functions\when('hwe_loyalty_available')->alias(
            fn (string $feature): bool => 'points' === $feature ? $this->loyaltyPoints : $this->loyaltyReferrals
        );
        Functions\when('hwe_i18n_locales')->alias(static fn (): array => ['en', 'pt_br']);
        Functions\when('hwe_i18n_locale_suffix')->alias(
            static fn (string $locale): string => (string) preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower(trim($locale))))
        );
        Functions\when('hwe_i18n_get')->alias(
            fn (string $kind, int $id, string $field, string $suffix): string => $this->translations[$id][$field][$suffix] ?? ''
        );
        Functions\when('hwe_product_fields_values')->alias(
            function (int $id, string $locale = ''): array {
                $this->fieldLocales[] = $locale;

                return self::PRODUCT_DISCOUNT === $id
                    ? [['key' => 'material', 'value' => 'Algodón']]
                    : [];
            }
        );
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
        Functions\stubs([
            'sanitize_text_field' => static fn ($value) => is_string($value) ? trim($value) : '',
            '__'                  => static fn (string $text): string => $text,
            'esc_html'            => static fn ($value) => (string) $value,
        ]);
    }

    private function wpdb(): TestMembershipsReadWpdb
    {
        return $GLOBALS['wpdb'];
    }

    // ── Catálogo simulado ────────────────────────────────────────────────────

    private function makeLevel(int $id, string $name, string $slug, int $rank, string $status, bool $purchasable, int $minAge, array $benefits): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = \HWE_Memberships_Levels::POST_TYPE;
        $post->post_status  = $status;
        $post->post_title   = $name;
        $post->post_name    = $slug;
        $post->post_content = 'Acceso ' . $name;
        $this->posts[ $id ] = $post;

        $this->postMeta[ $id ] = [
            \HWE_Memberships_Levels::RANK_META         => (string) $rank,
            \HWE_Memberships_Levels::PURCHASABLE_META  => $purchasable ? '1' : '0',
            \HWE_Memberships_Levels::MIN_AGE_META      => (string) $minAge,
            \HWE_Memberships_Levels::BENEFITS_META     => $benefits,
            \HWE_Memberships_Levels::ICON_META         => '',
            \HWE_Memberships_Levels::COLOR_META        => '',
        ];
    }

    private function makeProduct(int $id, string $type, string $status, string $slug, array $data = []): TestMembershipsReadProduct
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = 'product';
        $post->post_status  = $status;
        $post->post_title   = (string) ($data['name'] ?? 'Producto ' . $id);
        $post->post_name    = $slug;
        $post->post_content = (string) ($data['description'] ?? '');
        $this->posts[ $id ] = $post;

        $product = new TestMembershipsReadProduct();
        $product->id               = $id;
        $product->name             = (string) ($data['name'] ?? 'Producto ' . $id);
        $product->slug             = $slug;
        $product->type             = $type;
        $product->status           = $status;
        $product->price            = (string) ($data['price'] ?? '0');
        $product->regular_price    = (string) ($data['regular_price'] ?? ($data['price'] ?? '0'));
        $product->sale_price       = (string) ($data['sale_price'] ?? '');
        $product->on_sale          = (bool) ($data['on_sale'] ?? false);
        $product->short_description = (string) ($data['short_description'] ?? '');
        $product->description      = (string) ($data['description'] ?? '');
        $product->category_ids     = (array) ($data['category_ids'] ?? []);
        $product->image_id         = (int) ($data['image_id'] ?? 0);
        $product->gallery_ids      = (array) ($data['gallery_ids'] ?? []);
        $product->attributes       = (array) ($data['attributes'] ?? []);
        $product->child_ids        = (array) ($data['child_ids'] ?? []);
        $product->variation_attributes = (array) ($data['variation_attributes'] ?? []);
        $this->products[ $id ] = $product;

        return $product;
    }

    private function seedCatalog(): void
    {
        foreach ([self::CAT_DISCOUNT => 'Descuento', self::CAT_OTHER => 'Otros'] as $id => $name) {
            $term           = new \WP_Term();
            $term->term_id  = $id;
            $term->taxonomy = 'product_cat';
            $term->name     = $name;
            $term->slug     = strtolower($name);
            $this->terms[ $id ] = $term;
        }
        $this->productTerms = [
            self::PRODUCT_DISCOUNT => [self::CAT_DISCOUNT],
            self::PRODUCT_PUBLIC   => [self::CAT_OTHER],
            self::PRODUCT_LOCKED   => [self::CAT_OTHER],
        ];

        $this->translations = [
            self::LEVEL_BASE => [
                'title'   => ['en' => 'Base level'],
                'content' => ['en' => 'Base access'],
                'slug'    => ['en' => 'base-level'],
            ],
            self::LEVEL_TOP => [
                'title'   => ['en' => 'Top level'],
                'content' => ['en' => 'Top access'],
                'slug'    => ['en' => 'top-level'],
            ],
            self::PRODUCT_LOCKED => [
                'title'   => ['en' => 'Restricted product'],
                'content' => ['en' => 'Members only'],
                'excerpt' => ['en' => 'Members only summary'],
                'slug'    => ['en' => 'restricted-product'],
            ],
        ];

        $this->makeLevel(self::LEVEL_BASE, 'Nivel base', 'nivel-base', 1, 'publish', true, 0, [
            'early_access' => ['enabled' => '1'],
        ]);
        $this->makeLevel(self::LEVEL_TOP, 'Nivel alto', 'nivel-alto', 5, 'publish', true, 30, [
            'info_perk' => [
                'enabled' => '1',
                'title'   => ['es' => 'Atención prioritaria', 'en' => 'Priority support'],
                'text'    => ['es' => 'Soporte dedicado', 'en' => 'Dedicated support'],
            ],
            // Depende de «Puntos»: con esa función apagada NO sale.
            'activation_points' => ['enabled' => '1', 'points' => 50],
            'referral_gift'     => ['enabled' => '1', 'level' => self::LEVEL_BASE, 'days' => 30, 'max_per_period' => 3],
            'member_discount'   => ['enabled' => '1', 'percent' => 10, 'scope' => 'categories', 'categories' => [self::CAT_DISCOUNT]],
        ]);
        $this->makeLevel(self::LEVEL_NO_BUY, 'Nivel medio', 'nivel-medio', 2, 'publish', false, 0, []);
        $this->makeLevel(self::LEVEL_DRAFT, 'Nivel oculto', 'nivel-oculto', 3, 'draft', true, 0, []);

        $attribute = new TestMembershipsReadAttribute(1, 'Talla', ['S', 'M'], true, true);

        $this->makeProduct(self::PRODUCT_MEMBERSHIP, 'simple', 'publish', 'membresia-alta', [
            'name' => 'Membresía alta',
            'price' => '100.00',
        ]);
        $this->postMeta[ self::PRODUCT_MEMBERSHIP ][ \HWE_Memberships_Products::LEVEL_META ] = (string) self::LEVEL_TOP;
        $this->postMeta[ self::PRODUCT_MEMBERSHIP ][ \HWE_Memberships_Products::DAYS_META ]  = '30';

        $this->makeProduct(self::PRODUCT_MEMBERSHIP_INF, 'simple', 'publish', 'membresia-base', [
            'name' => 'Membresía base',
            'price' => '50.00',
        ]);
        $this->postMeta[ self::PRODUCT_MEMBERSHIP_INF ][ \HWE_Memberships_Products::LEVEL_META ] = (string) self::LEVEL_BASE;
        $this->postMeta[ self::PRODUCT_MEMBERSHIP_INF ][ \HWE_Memberships_Products::DAYS_META ]  = '0';

        // Producto de un nivel en borrador y producto en borrador: ninguno se ofrece.
        $this->makeProduct(505, 'simple', 'publish', 'membresia-oculta', ['price' => '70.00']);
        $this->postMeta[505][ \HWE_Memberships_Products::LEVEL_META ] = (string) self::LEVEL_DRAFT;
        $this->postMeta[505][ \HWE_Memberships_Products::DAYS_META ]  = '30';

        $this->makeProduct(506, 'simple', 'draft', 'membresia-borrador', ['price' => '80.00']);
        $this->postMeta[506][ \HWE_Memberships_Products::LEVEL_META ] = (string) self::LEVEL_TOP;
        $this->postMeta[506][ \HWE_Memberships_Products::DAYS_META ]  = '30';

        $this->makeProduct(self::PRODUCT_DISCOUNT, 'variable', 'publish', 'producto-descuento', [
            'name'              => 'Producto con descuento',
            'price'             => '20.00',
            'regular_price'     => '25.00',
            'sale_price'        => '20.00',
            'on_sale'           => true,
            'short_description' => '<p>Resumen</p>',
            'description'       => '<p>Descripción completa</p>',
            'category_ids'      => [self::CAT_DISCOUNT],
            'image_id'          => 900,
            'gallery_ids'       => [901, 902],
            'attributes'        => [$attribute],
            'child_ids'         => [701, 702],
        ]);
        $this->makeProduct(701, 'variation', 'publish', 'producto-descuento-s', [
            'name' => 'Producto con descuento S',
            'price' => '20.00',
            'variation_attributes' => ['Talla' => 'S'],
        ]);
        $this->makeProduct(702, 'variation', 'publish', 'producto-descuento-m', [
            'name' => 'Producto con descuento M',
            'price' => '21.00',
            'variation_attributes' => ['Talla' => 'M'],
        ]);

        $this->makeProduct(self::PRODUCT_PUBLIC, 'simple', 'publish', 'producto-publico', [
            'name'  => 'Producto público',
            'price' => '12.00',
            'category_ids' => [self::CAT_OTHER],
            'description'  => '<p>Público</p>',
        ]);

        $this->makeProduct(self::PRODUCT_LOCKED, 'simple', 'publish', self::SLUG_LOCKED, [
            'name'  => 'Producto restringido',
            'price' => '25.00',
            'category_ids' => [self::CAT_OTHER],
            'description'  => '<p>Solo socios</p>',
        ]);
        $this->postMeta[ self::PRODUCT_LOCKED ][ \HWE_Memberships_Access::MIN_RANK_META ] = '5';

        $this->makeProduct(self::PRODUCT_DRAFT, 'simple', 'draft', 'producto-borrador', [
            'name'  => 'Producto en borrador',
            'price' => '30.00',
        ]);

        $this->postMeta[900][ '_wp_attachment_image_alt' ] = 'Imagen principal';
        $this->postMeta[901][ '_wp_attachment_image_alt' ] = 'Imagen dos';
    }

    private function seedMembers(): void
    {
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL_TOP,
            'idempotency_key' => 'grant:top',
            'duration_days'   => 0,
            'starts_at'       => gmdate('Y-m-d H:i:s', $this->now - 10 * DAY_IN_SECONDS),
            'ends_at'         => null,
            'status'          => 'active',
            'referral_gifts'  => 1,
            'origin'          => 'admin',
        ]);
        $this->wpdb()->seedGrant([
            'user_id'         => self::USER,
            'level_id'        => self::LEVEL_BASE,
            'idempotency_key' => 'grant:base',
            'duration_days'   => 30,
            'starts_at'       => gmdate('Y-m-d H:i:s', $this->now + 40 * DAY_IN_SECONDS),
            'ends_at'         => gmdate('Y-m-d H:i:s', $this->now + 70 * DAY_IN_SECONDS),
            'status'          => 'scheduled',
        ]);
        $this->wpdb()->seedMember(self::USER, [
            'member_number' => '000123',
            'member_since'  => '2026-01-02 03:04:05',
        ]);

        $this->wpdb()->seedGrant([
            'user_id'         => self::FROZEN,
            'level_id'        => self::LEVEL_BASE,
            'idempotency_key' => 'grant:frozen',
            'duration_days'   => 10,
            'starts_at'       => gmdate('Y-m-d H:i:s', $this->now - 5 * DAY_IN_SECONDS),
            'ends_at'         => gmdate('Y-m-d H:i:s', $this->now + 5 * DAY_IN_SECONDS),
            'status'          => 'active',
        ]);
        $this->wpdb()->seedMember(self::FROZEN, [
            'frozen_at' => gmdate('Y-m-d H:i:s', $this->now - HOUR_IN_SECONDS),
        ]);
    }

    private function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    // ── Registro y puertas ───────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function routes(): array
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(
            function (string $namespace, string $route, array $args) use (&$routes): bool {
                $routes[ $namespace . $route ] = $args;
                return true;
            }
        );

        \HWE_Memberships_ReadRoutes::registerRoutes();

        return $routes;
    }

    public function testRegisterHooksTheRoutesOnRestApiInit(): void
    {
        Actions\expectAdded('rest_api_init')
            ->once()
            ->with([\HWE_Memberships_ReadRoutes::class, 'registerRoutes']);

        \HWE_Memberships_ReadRoutes::register();
    }

    public function testItRegistersTheFiveReadRoutesWithGet(): void
    {
        $routes = $this->routes();

        $this->assertSame(
            [
                'hwe/v1/memberships/levels',
                'hwe/v1/memberships/me',
                'hwe/v1/memberships/history',
                'hwe/v1/memberships/prices',
                'hwe/v1/memberships/product',
            ],
            array_keys($routes)
        );
        foreach ($routes as $route => $args) {
            $this->assertSame('GET', $args['methods'], $route . ' solo lee.');
            $this->assertIsCallable($args['permission_callback'], $route . ' exige el secreto interno.');
            $this->assertIsCallable($args['callback'], $route . ' necesita manejador.');
        }
    }

    public function testWithoutTheInternalSecretEveryRouteFailsClosed(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(false);
        $request = new \WP_REST_Request();

        foreach ($this->routes() as $route => $args) {
            $this->assertFalse($args['permission_callback']($request), $route . ' debe fallar cerrada.');
        }
    }

    public function testWithTheSecretAndTheModuleOnEveryRouteIsAllowed(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $request = new \WP_REST_Request();

        foreach ($this->routes() as $route => $args) {
            $this->assertTrue($args['permission_callback']($request), $route . ' debe atender al BFF.');
        }
    }

    public function testWithTheModuleOffEveryRouteAnswers404(): void
    {
        Functions\when('hwe_has_internal_secret')->justReturn(true);
        $this->activePlugins = [];
        $request = new \WP_REST_Request();

        foreach ($this->routes() as $route => $args) {
            $closed = $args['permission_callback']($request);
            $this->assertInstanceOf(\WP_Error::class, $closed, $route . ' se cierra con el módulo apagado.');
            $this->assertSame('rest_no_route', $closed->get_error_code());
            $this->assertSame(['status' => 404], $closed->get_error_data());
        }
    }

    public function testUserIdIsMandatoryAndPositiveOnTheUserRoutes(): void
    {
        foreach ([null, 0, -3, 'abc', ['7']] as $invalid) {
            $params = null === $invalid ? [] : ['user_id' => $invalid];

            $me = \HWE_Memberships_ReadRoutes::handleMe(new \WP_REST_Request($params));
            $this->assertSame(400, $me->get_status());
            $this->assertSame(['error' => 'invalid_request'], $me->get_data());

            $history = \HWE_Memberships_ReadRoutes::handleHistory(new \WP_REST_Request($params));
            $this->assertSame(400, $history->get_status());
            $this->assertSame(['error' => 'invalid_request'], $history->get_data());

            $prices = \HWE_Memberships_ReadRoutes::handlePrices(new \WP_REST_Request($params));
            $this->assertSame(400, $prices->get_status());
            $this->assertSame(['error' => 'invalid_request'], $prices->get_data());

            $product = \HWE_Memberships_ReadRoutes::handleProduct(
                new \WP_REST_Request($params + ['slug' => self::SLUG_LOCKED])
            );
            $this->assertSame(400, $product->get_status());
            $this->assertSame(['error' => 'invalid_request'], $product->get_data());
        }
    }

    // ── Niveles ──────────────────────────────────────────────────────────────

    public function testLevelsReturnsTheVisibleLevelsWithTheirBenefits(): void
    {
        $response = \HWE_Memberships_ReadRoutes::handleLevels(new \WP_REST_Request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(['items'], array_keys($data));

        $items = $data['items'];
        $this->assertCount(3, $items, 'El borrador no se ofrece.');
        $this->assertSame(
            [self::LEVEL_BASE, self::LEVEL_NO_BUY, self::LEVEL_TOP],
            array_column($items, 'id'),
            'Ordenados por rango.'
        );

        $base = $items[0];
        $this->assertSame(
            [
                'id',
                'name',
                'description',
                'slug',
                'slugs',
                'rank',
                'icon',
                'color',
                'purchasable',
                'min_account_age_days',
                'benefits',
                'products',
            ],
            array_keys($base)
        );
        $this->assertSame('Nivel base', $base['name']);
        $this->assertSame('nivel-base', $base['slug']);
        $this->assertSame(1, $base['rank']);
        $this->assertTrue($base['purchasable']);
        $this->assertSame(0, $base['min_account_age_days']);
        $this->assertSame(
            ['en' => 'base-level', 'pt_br' => 'nivel-base'],
            $base['slugs'],
            'Mapa de idiomas secundarios; el slug nativo es el respaldo de un idioma sin traducir.'
        );

        // Beneficios: claves y datos estructurados, nunca frases. El beneficio cuya
        // dependencia está apagada no sale.
        $this->assertSame(
            [['key' => 'early_access', 'enabled' => true]],
            $base['benefits']
        );
        $this->assertSame(
            [
                [
                    'key'    => 'info_perk',
                    'title'  => ['es' => 'Atención prioritaria', 'en' => 'Priority support'],
                    'text'   => ['es' => 'Soporte dedicado', 'en' => 'Dedicated support'],
                ],
                [
                    'key'            => 'referral_gift',
                    'level'          => self::LEVEL_BASE,
                    'days'           => 30,
                    'max_per_period' => 3,
                ],
                [
                    'key'        => 'member_discount',
                    'percent'    => 10,
                    'scope'      => 'categories',
                    'categories' => [self::CAT_DISCOUNT],
                ],
            ],
            $items[2]['benefits']
        );
        $this->assertSame([], $items[1]['benefits'], 'Un nivel sin beneficios configurados no inventa ninguno.');

        // Ni la marca del borrador ni datos internos del nivel salen.
        $json = json_encode($items) ?: '';
        $this->assertStringNotContainsString('oculto', $json);
        $this->assertStringNotContainsString('nivel-oculto', $json);
    }

    public function testLevelsAppliesTheRequestedLocaleWithFallbackToTheBaseContent(): void
    {
        $portuguese = \HWE_Memberships_ReadRoutes::handleLevels(
            new \WP_REST_Request(['locale' => 'pt-BR'])
        )->get_data()['items'];
        $this->assertSame('Nivel base', $portuguese[0]['name'], 'Sin traducción, cae al contenido nativo.');
        $this->assertSame('nivel-base', $portuguese[0]['slug']);

        $spanish = \HWE_Memberships_ReadRoutes::handleLevels(
            new \WP_REST_Request(['locale' => 'es'])
        )->get_data()['items'];
        $this->assertSame('Nivel base', $spanish[0]['name']);

        $english = \HWE_Memberships_ReadRoutes::handleLevels(
            new \WP_REST_Request(['locale' => 'en'])
        )->get_data()['items'];
        $this->assertSame('Base level', $english[0]['name']);
        $this->assertSame('Base access', $english[0]['description']);
        $this->assertSame('base-level', $english[0]['slug']);

        $garbage = \HWE_Memberships_ReadRoutes::handleLevels(
            new \WP_REST_Request(['locale' => ['en']])
        );
        $this->assertSame(200, $garbage->get_status(), 'Un locale mal formado no rompe la lectura.');
        $this->assertSame('Nivel base', $garbage->get_data()['items'][0]['name']);
    }

    public function testLevelsListsThePurchaseProductsInASingleQuery(): void
    {
        $items = \HWE_Memberships_ReadRoutes::handleLevels(new \WP_REST_Request())->get_data()['items'];

        $this->assertSame(
            [['id' => self::PRODUCT_MEMBERSHIP_INF, 'price' => '50.00', 'days' => null]],
            $items[0]['products'],
            'Días null = membresía indefinida.'
        );
        $this->assertSame(
            [['id' => self::PRODUCT_MEMBERSHIP, 'price' => '100.00', 'days' => 30]],
            $items[2]['products']
        );
        $this->assertSame([], $items[1]['products'], 'El nivel no comprable no tiene productos de compra.');

        $this->assertSame(1, $this->productIdQueries, 'Una sola consulta de ids para todos los niveles.');
        $this->assertSame(1, $this->productListQueries, 'Una sola carga de productos para todos los niveles.');
    }

    public function testLevelsAnswers500AndLogsWhenWordPressFails(): void
    {
        $this->failLevelQuery = true;

        $response = \HWE_Memberships_ReadRoutes::handleLevels(new \WP_REST_Request());

        $this->assertSame(500, $response->get_status());
        $this->assertSame(['error' => 'internal_error'], $response->get_data());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    // ── Estado del socio ─────────────────────────────────────────────────────

    public function testMeReturnsTheStateActiveBenefitsGiftsAndPurchaseEligibility(): void
    {
        $response = \HWE_Memberships_ReadRoutes::handleMe(
            new \WP_REST_Request(['user_id' => self::USER])
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(
            [
                'status',
                'level',
                'ends_at',
                'member_since',
                'member_number',
                'queue',
                'benefits',
                'gifts',
                'purchasable',
            ],
            array_keys($data)
        );

        $this->assertSame('active', $data['status']);
        $this->assertSame(['id' => self::LEVEL_TOP, 'rank' => 5], $data['level']);
        $this->assertNull($data['ends_at'], 'Una concesión indefinida no tiene fin.');
        $this->assertSame('2026-01-02T03:04:05Z', $data['member_since']);
        $this->assertSame('000123', $data['member_number']);

        $this->assertSame(
            [
                [
                    'level_id'  => self::LEVEL_BASE,
                    'rank'      => 1,
                    'seconds'   => 30 * DAY_IN_SECONDS,
                    'days'      => 30.0,
                    'starts_at' => $this->iso($this->now + 40 * DAY_IN_SECONDS),
                    'ends_at'   => $this->iso($this->now + 70 * DAY_IN_SECONDS),
                    'undated'   => false,
                    'infinite'  => false,
                ],
            ],
            $data['queue'],
            'La cola programada por nivel, con fechas ISO.'
        );

        $this->assertSame(['info_perk', 'referral_gift', 'member_discount'], array_column($data['benefits'], 'key'));
        $this->assertSame(
            [
                'key'      => 'member_discount',
                'level_id' => self::LEVEL_TOP,
                'public'   => [
                    'key'        => 'member_discount',
                    'percent'    => 10,
                    'scope'      => 'categories',
                    'categories' => [self::CAT_DISCOUNT],
                ],
                'state'    => [
                    'active'   => true,
                    'frozen'   => false,
                    'level_id' => self::LEVEL_TOP,
                    'percent'  => 10,
                    'scope'    => 'categories',
                ],
            ],
            $data['benefits'][2]
        );
        $this->assertSame(
            [
                'active'          => true,
                'frozen'          => false,
                'level_id'        => self::LEVEL_TOP,
                'gifts_max'       => 3,
                'gifts_used'      => 1,
                'gifts_remaining' => 2,
            ],
            $data['benefits'][1]['state']
        );
        $this->assertSame(
            ['max' => 3, 'used' => 1, 'remaining' => 2],
            $data['gifts'],
            'Regalos restantes del referidor en el periodo.'
        );

        $this->assertSame([self::LEVEL_BASE, self::LEVEL_TOP], array_column($data['purchasable'], 'level_id'));
        $this->assertSame(
            [
                'level_id'             => self::LEVEL_BASE,
                'ok'                   => false,
                'reason'               => 'membership_already_indefinite',
                'days_left'            => 0,
                'min_account_age_days' => 0,
                'account_age_days'     => 200,
                'current_level_id'     => self::LEVEL_TOP,
            ],
            $data['purchasable'][0]
        );

        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('grant_key', $data);
    }

    public function testMeOfAFrozenMemberHasNoEffectiveLevel(): void
    {
        $data = \HWE_Memberships_ReadRoutes::handleMe(
            new \WP_REST_Request(['user_id' => self::FROZEN])
        )->get_data();

        $this->assertSame('frozen', $data['status']);
        $this->assertNull($data['level']);
        $this->assertNull($data['ends_at']);
        $this->assertSame([], $data['benefits'], 'Congelado no tiene beneficios activos.');
        $this->assertSame(['max' => 0, 'used' => 0, 'remaining' => 0], $data['gifts']);
        $this->assertSame(
            [true, true],
            array_column($data['purchasable'], 'ok'),
            'Comprar estando congelado está permitido.'
        );
    }

    public function testMeExplainsTheAccountAgeRequirementPerLevel(): void
    {
        $data = \HWE_Memberships_ReadRoutes::handleMe(
            new \WP_REST_Request(['user_id' => self::NEW_USER])
        )->get_data();

        $this->assertSame('none', $data['status']);
        $this->assertNull($data['level']);
        $this->assertSame('', $data['member_number']);
        $this->assertSame([], $data['queue']);
        $this->assertSame([], $data['benefits']);
        $this->assertSame(['max' => 0, 'used' => 0, 'remaining' => 0], $data['gifts']);

        $byLevel = array_column($data['purchasable'], null, 'level_id');
        $this->assertTrue($byLevel[ self::LEVEL_BASE ]['ok']);
        $this->assertSame('membership_account_too_new', $byLevel[ self::LEVEL_TOP ]['reason']);
        $this->assertSame(25, $byLevel[ self::LEVEL_TOP ]['days_left'], '30 días mínimos − 5 de antigüedad.');
        $this->assertSame(5, $byLevel[ self::LEVEL_TOP ]['account_age_days']);
    }

    public function testMeOfAnUnknownUserIsAnEmptyAnswer(): void
    {
        $response = \HWE_Memberships_ReadRoutes::handleMe(
            new \WP_REST_Request(['user_id' => self::MISSING])
        );

        $this->assertSame(200, $response->get_status(), 'No un 404: la ruta no sirve para enumerar cuentas.');
        $this->assertSame(
            [
                'status'        => 'none',
                'level'         => null,
                'ends_at'       => null,
                'member_since'  => null,
                'member_number' => '',
                'queue'         => [],
                'benefits'      => [],
                'gifts'         => ['max' => 0, 'used' => 0, 'remaining' => 0],
                'purchasable'   => [],
            ],
            $response->get_data()
        );
        $this->assertSame([], $this->wpdb()->queries, 'Ni una consulta para una cuenta inexistente.');
    }

    // ── Historial ────────────────────────────────────────────────────────────

    private function seedHistory(): void
    {
        $this->wpdb()->seedEvent([
            'user_id'    => self::USER,
            'type'       => 'activated',
            'level_from' => 0,
            'level_to'   => self::LEVEL_TOP,
            'data'       => [
                'origin'    => 'order',
                'key'       => 'order:55:item:3',
                'days'      => 30,
                'starts_at' => '2026-01-01 00:00:00',
                'ends_at'   => '2026-01-31 00:00:00',
                'status'    => 'active',
                'ref'       => ['type' => 'order', 'id' => '55'],
            ],
            'created_at' => '2026-10-01 10:00:00',
        ]);
        $this->wpdb()->seedEvent([
            'user_id'    => self::USER,
            'type'       => 'revoked',
            'level_from' => self::LEVEL_TOP,
            'level_to'   => self::LEVEL_TOP,
            'data'       => [
                'key'      => 'order:55:item:3',
                'reason'   => 'refund',
                'grant_id' => 9,
                'active'   => true,
            ],
            'created_at' => '2026-10-02 10:00:00',
        ]);
        $this->wpdb()->seedEvent([
            'user_id'    => self::USER,
            'type'       => 'referral_gift_limit_reached',
            'level_from' => self::LEVEL_TOP,
            'level_to'   => self::LEVEL_TOP,
            'data'       => [
                'referee'        => self::FROZEN,
                'gift_level_id'  => self::LEVEL_BASE,
                'days'           => 30,
                'max_per_period' => 1,
                'used'           => 1,
                'grant_key'      => 'grant:top',
            ],
            'created_at' => '2026-10-03 10:00:00',
        ]);
        $this->wpdb()->seedEvent([
            'user_id'    => self::USER,
            'type'       => 'custom_event',
            'level_from' => 0,
            'level_to'   => 0,
            'data'       => [
                'email' => 'filtrado@example.com',
                'login' => 'filtrado_login',
            ],
            'created_at' => '2026-10-04 10:00:00',
        ]);
        $this->wpdb()->seedEvent([
            'user_id'    => self::FROZEN,
            'type'       => 'activated',
            'level_from' => 0,
            'level_to'   => self::LEVEL_BASE,
            'data'       => ['origin' => 'admin'],
            'created_at' => '2026-10-05 10:00:00',
        ]);
    }

    public function testHistoryPageShapeAndCuratedData(): void
    {
        $this->seedHistory();

        $first = \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 1, 'per_page' => 2])
        );
        $this->assertSame(200, $first->get_status());
        $page = $first->get_data();
        $this->assertSame(['items', 'total', 'pages'], array_keys($page));
        $this->assertSame(4, $page['total']);
        $this->assertSame(2, $page['pages']);
        $this->assertSame([4, 3], array_column($page['items'], 'id'), 'Del más nuevo al más antiguo.');

        $byId = array_column($page['items'], null, 'id');
        $this->assertSame(
            ['id', 'type', 'level_from', 'level_to', 'data', 'created_at'],
            array_keys($byId[4])
        );
        $this->assertSame('2026-10-04T10:00:00Z', $byId[4]['created_at']);
        $this->assertSame([], $byId[4]['data'], 'Un tipo desconocido no arrastra datos.');
        $this->assertSame(
            ['gift_level_id' => self::LEVEL_BASE, 'days' => 30, 'max_per_period' => 1, 'used' => 1],
            $byId[3]['data'],
            'Ni el referido ni la clave interna del regalo salen.'
        );
        $this->assertSame(0, $byId[4]['level_from']);
        $this->assertSame(0, $byId[4]['level_to']);

        $second = \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 2, 'per_page' => 2])
        )->get_data();
        $this->assertSame([2, 1], array_column($second['items'], 'id'));
        $byId = array_column($second['items'], null, 'id');

        $this->assertSame(['reason' => 'refund', 'active' => true], $byId[2]['data']);
        $this->assertSame(
            [
                'origin'    => 'order',
                'days'      => 30,
                'starts_at' => '2026-01-01T00:00:00Z',
                'ends_at'   => '2026-01-31T00:00:00Z',
                'status'    => 'active',
            ],
            $byId[1]['data'],
            'Fechas del movimiento en ISO 8601 UTC y sin la referencia interna del pedido.'
        );

        $json = json_encode([$first->get_data(), $second]) ?: '';
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('_login', $json);
        $this->assertStringNotContainsString('"ref"', $json);
        $this->assertStringNotContainsString('"key"', $json);
        $this->assertStringNotContainsString('referee', $json);
        $this->assertStringNotContainsString('grant_key', $json);
    }

    public function testHistoryPageSizeIsClampedToOneHundred(): void
    {
        \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER, 'page' => 0, 'per_page' => 5000])
        );
        $this->assertSame([[self::USER, 100, 0]], $this->wpdb()->historyLimits);

        \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER, 'per_page' => 0])
        );
        $this->assertSame([self::USER, 1, 0], $this->wpdb()->historyLimits[1], 'per_page mínimo 1.');

        \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER])
        );
        $this->assertSame([self::USER, 20, 0], $this->wpdb()->historyLimits[2], 'Sin per_page, 20.');
    }

    public function testHistoryAnswers500AndLogsWhenTheQueryFails(): void
    {
        $this->wpdb()->failEventQuery = true;

        $response = \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::USER])
        );

        $this->assertSame(500, $response->get_status(), 'Nunca un historial inventado.');
        $this->assertSame(['error' => 'internal_error'], $response->get_data());
        $this->assertNotEmpty($this->logged);
        $this->assertStringContainsString('[hwe] memberships:', $this->logged[0]);
    }

    public function testHistoryOfAnUnknownUserIsAnEmptyPage(): void    {
        $response = \HWE_Memberships_ReadRoutes::handleHistory(
            new \WP_REST_Request(['user_id' => self::MISSING])
        );

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['items' => [], 'total' => 0, 'pages' => 0], $response->get_data());
    }

    // ── Precios ──────────────────────────────────────────────────────────────

    public function testPricesReturnsTheQuoteOfEachProduct(): void
    {
        $response = \HWE_Memberships_ReadRoutes::handlePrices(
            new \WP_REST_Request(['user_id' => self::USER, 'ids' => '601,602,abc,601'])
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(['items'], array_keys($data));
        $this->assertSame([self::PRODUCT_DISCOUNT, self::PRODUCT_PUBLIC], array_column($data['items'], 'id'));
        $this->assertSame(
            [
                'id'           => self::PRODUCT_DISCOUNT,
                'regular'      => '25.00',
                'price'        => '20.00',
                'member_price' => '18.00',
                'percent'      => 10,
            ],
            $data['items'][0],
            'El descuento se aplica al precio que la tienda cobraría (oferta incluida).'
        );
        $this->assertSame(
            [
                'id'           => self::PRODUCT_PUBLIC,
                'regular'      => '12.00',
                'price'        => '12.00',
                'member_price' => '12.00',
                'percent'      => 0,
            ],
            $data['items'][1],
            'Fuera del alcance del descuento, el precio público.'
        );
    }

    public function testPricesAcceptsARepeatedParameterAndRejectsMoreThanFiftyIds(): void
    {
        $repeated = \HWE_Memberships_ReadRoutes::handlePrices(
            new \WP_REST_Request(['user_id' => self::USER, 'ids' => ['601', '602']])
        );
        $this->assertSame(200, $repeated->get_status());
        $this->assertSame([601, 602], array_column($repeated->get_data()['items'], 'id'));

        $empty = \HWE_Memberships_ReadRoutes::handlePrices(
            new \WP_REST_Request(['user_id' => self::USER])
        );
        $this->assertSame(['items' => []], $empty->get_data(), 'Sin ids no hay nada que cotizar.');

        $tooMany = implode(',', range(1, 51));
        $response = \HWE_Memberships_ReadRoutes::handlePrices(
            new \WP_REST_Request(['user_id' => self::USER, 'ids' => $tooMany])
        );
        $this->assertSame(400, $response->get_status());
        $this->assertSame(['error' => 'invalid_request'], $response->get_data());

        $atLimit = implode(',', range(1, 50));
        $this->assertSame(
            200,
            \HWE_Memberships_ReadRoutes::handlePrices(
                new \WP_REST_Request(['user_id' => self::USER, 'ids' => $atLimit])
            )->get_status(),
            '50 ids es el tope aceptado.'
        );
    }

    // ── Ficha de producto ────────────────────────────────────────────────────

    public function testProductReturnsTheFullDetailWhenTheMemberCanViewIt(): void
    {
        $response = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'slug' => self::SLUG_LOCKED])
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(
            [
                'id',
                'name',
                'slug',
                'type',
                'status',
                'purchasable',
                'on_sale',
                'price',
                'regular_price',
                'sale_price',
                'stock_status',
                'manage_stock',
                'stock_quantity',
                'backorders_allowed',
                'short_description',
                'description',
                'categories',
                'images',
                'attributes',
                'variations',
                'hweFields',
                'hwe_membership',
                'locked',
            ],
            array_keys($data)
        );
        $this->assertSame(self::PRODUCT_LOCKED, $data['id']);
        $this->assertSame('Producto restringido', $data['name']);
        $this->assertSame('publish', $data['status']);
        $this->assertSame('25.00', $data['price']);
        $this->assertSame('<p>Solo socios</p>', $data['description']);
        $this->assertSame([['id' => self::CAT_OTHER, 'name' => 'Otros', 'slug' => 'otros']], $data['categories']);
        $this->assertFalse($data['locked']);
        $this->assertSame(
            [
                'required_level' => ['slug' => 'nivel-alto', 'rank' => 5, 'name' => 'Nivel alto'],
                'opens_at'       => null,
            ],
            $data['hwe_membership']
        );
    }

    public function testProductIncludesGalleryAttributesVariationsAndInstanceFields(): void
    {
        $data = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'id' => self::PRODUCT_DISCOUNT])
        )->get_data();

        $this->assertSame('variable', $data['type']);
        $this->assertTrue($data['on_sale']);
        $this->assertSame('20.00', $data['price']);
        $this->assertSame('25.00', $data['regular_price']);
        $this->assertSame('20.00', $data['sale_price']);
        $this->assertSame(
            [
                ['id' => 900, 'src' => 'https://example.com/media/900.jpg', 'alt' => 'Imagen principal'],
                ['id' => 901, 'src' => 'https://example.com/media/901.jpg', 'alt' => 'Imagen dos'],
                ['id' => 902, 'src' => 'https://example.com/media/902.jpg', 'alt' => ''],
            ],
            $data['images'],
            'La imagen principal va primero y después la galería.'
        );
        $this->assertSame(
            [['id' => 1, 'name' => 'Talla', 'options' => ['S', 'M'], 'visible' => true, 'variation' => true]],
            $data['attributes']
        );
        $this->assertSame(
            [
                [
                    'id'                  => 701,
                    'price'               => '20.00',
                    'regular_price'       => '20.00',
                    'sale_price'          => null,
                    'on_sale'             => false,
                    'stock_status'        => 'instock',
                    'manage_stock'        => false,
                    'stock_quantity'      => null,
                    'backorders_allowed'  => false,
                    'attributes'          => [['name' => 'Talla', 'option' => 'S']],
                ],
                [
                    'id'                  => 702,
                    'price'               => '21.00',
                    'regular_price'       => '21.00',
                    'sale_price'          => null,
                    'on_sale'             => false,
                    'stock_status'        => 'instock',
                    'manage_stock'        => false,
                    'stock_quantity'      => null,
                    'backorders_allowed'  => false,
                    'attributes'          => [['name' => 'Talla', 'option' => 'M']],
                ],
            ],
            $data['variations']
        );
        $this->assertSame([['key' => 'material', 'value' => 'Algodón']], $data['hweFields']);
        $this->assertSame(
            ['required_level' => null, 'opens_at' => null],
            $data['hwe_membership'],
            'Un producto sin requisito no inventa nivel.'
        );
    }

    public function testProductWithoutAccessReturnsExactlyTheTeaserCard(): void
    {
        $full = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'id' => self::PRODUCT_LOCKED])
        )->get_data();

        $response = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::NEW_USER, 'id' => self::PRODUCT_LOCKED])
        );
        $this->assertSame(200, $response->get_status());
        $teaser = $response->get_data();

        $this->assertSame(
            \HWE_Memberships_Teaser::trimWcProduct($full, self::NEW_USER),
            $teaser,
            'El recorte es EXACTAMENTE el de Teaser, no una copia.'
        );
        $this->assertTrue($teaser['locked']);
        $this->assertSame(
            [
                'required_level' => ['slug' => 'nivel-alto', 'rank' => 5, 'name' => 'Nivel alto'],
                'opens_at'       => null,
            ],
            $teaser['hwe_membership']
        );

        $keys = array_keys($teaser);
        sort($keys);
        $this->assertSame(
            [
                'backorders_allowed',
                'categories',
                'hwe_membership',
                'id',
                'images',
                'locked',
                'manage_stock',
                'name',
                'on_sale',
                'price',
                'purchasable',
                'regular_price',
                'sale_price',
                'slug',
                'status',
                'stock_quantity',
                'stock_status',
                'type',
            ],
            $keys,
            'La ficha de escaparate es una lista blanca.'
        );
        $this->assertArrayNotHasKey('description', $teaser);
        $this->assertArrayNotHasKey('short_description', $teaser);
        $this->assertArrayNotHasKey('attributes', $teaser);
        $this->assertArrayNotHasKey('variations', $teaser);
        $this->assertArrayNotHasKey('hweFields', $teaser);
    }

    public function testProductAppliesTheRequestedLocaleToContentAndInstanceFields(): void
    {
        $base = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'slug' => self::SLUG_LOCKED])
        )->get_data();
        $this->assertSame('Producto restringido', $base['name']);
        $this->assertSame('<p>Solo socios</p>', $base['description']);

        $english = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'slug' => self::SLUG_LOCKED, 'locale' => 'en'])
        )->get_data();
        $this->assertSame('Restricted product', $english['name']);
        $this->assertSame('Members only', $english['description']);
        $this->assertSame('Members only summary', $english['short_description']);
        $this->assertSame('restricted-product', $english['slug']);

        $this->assertSame(['', 'en'], $this->fieldLocales, 'Los campos de instancia se resuelven al idioma pedido.');
    }

    public function testProductThatDoesNotExistOrIsNotPublishedAnswers404(): void    {
        $missing = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'id' => 987654])
        );
        $this->assertSame(404, $missing->get_status());
        $this->assertSame(['error' => 'not_found'], $missing->get_data());

        $draft = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'id' => self::PRODUCT_DRAFT])
        );
        $this->assertSame(404, $draft->get_status(), 'Un borrador se responde igual que un producto inexistente.');
        $this->assertSame($missing->get_data(), $draft->get_data());

        $notPublished = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'slug' => 'producto-borrador'])
        );
        $this->assertSame(404, $notPublished->get_status());

        $notAProduct = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'id' => self::LEVEL_TOP])
        );
        $this->assertSame(404, $notAProduct->get_status());
    }

    public function testProductRequiresAValidSlugOrIdentifier(): void
    {
        $none = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER])
        );
        $this->assertSame(400, $none->get_status());
        $this->assertSame(['error' => 'invalid_request'], $none->get_data());

        foreach (['0', '-5', 'abc', '1.5'] as $invalid) {
            $response = \HWE_Memberships_ReadRoutes::handleProduct(
                new \WP_REST_Request(['user_id' => self::USER, 'id' => $invalid])
            );
            $this->assertSame(400, $response->get_status(), 'id «' . $invalid . '» inválido.');
            $this->assertSame(['error' => 'invalid_request'], $response->get_data());
        }

        $emptySlug = \HWE_Memberships_ReadRoutes::handleProduct(
            new \WP_REST_Request(['user_id' => self::USER, 'slug' => '   '])
        );
        $this->assertSame(400, $emptySlug->get_status());
        $this->assertSame(['error' => 'invalid_request'], $emptySlug->get_data());
    }

    // ── Solo lectura ─────────────────────────────────────────────────────────

    public function testNoRouteWritesToTheDatabase(): void
    {
        $this->seedHistory();

        \HWE_Memberships_ReadRoutes::handleLevels(new \WP_REST_Request());
        \HWE_Memberships_ReadRoutes::handleMe(new \WP_REST_Request(['user_id' => self::USER]));
        \HWE_Memberships_ReadRoutes::handleHistory(new \WP_REST_Request(['user_id' => self::USER]));
        \HWE_Memberships_ReadRoutes::handlePrices(new \WP_REST_Request(['user_id' => self::USER, 'ids' => '601,602']));
        \HWE_Memberships_ReadRoutes::handleProduct(new \WP_REST_Request(['user_id' => self::USER, 'id' => self::PRODUCT_LOCKED]));
        \HWE_Memberships_ReadRoutes::handleProduct(new \WP_REST_Request(['user_id' => self::NEW_USER, 'id' => self::PRODUCT_LOCKED]));

        $wpdb = $this->wpdb();
        $this->assertSame([], $wpdb->inserts, 'Ninguna lectura inserta.');
        $this->assertSame([], $wpdb->updates, 'Ninguna lectura actualiza.');
        $this->assertSame([], $wpdb->deletes, 'Ninguna lectura borra.');
        $this->assertSame([], $wpdb->transactions, 'Ninguna lectura abre transacción.');
        $this->assertNotEmpty($wpdb->queries);
        foreach ($wpdb->queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*SELECT\b/i', $sql);
        }
    }
}

/**
 * Producto de WooCommerce simulado: solo los lectores que usa la ficha del catálogo.
 */
final class TestMembershipsReadProduct
{
    public int $id = 0;
    public string $name = '';
    public string $slug = '';
    public string $type = 'simple';
    public string $status = 'publish';
    public string $price = '0';
    public string $regular_price = '0';
    public string $sale_price = '';
    public bool $on_sale = false;
    public bool $purchasable = true;
    public string $stock_status = 'instock';
    public bool $manage_stock = false;
    public ?int $stock_quantity = null;
    public bool $backorders = false;
    public string $short_description = '';
    public string $description = '';
    /** @var list<int> */
    public array $category_ids = [];
    public int $image_id = 0;
    /** @var list<int> */
    public array $gallery_ids = [];
    /** @var list<TestMembershipsReadAttribute> */
    public array $attributes = [];
    /** @var list<int> */
    public array $child_ids = [];
    /** @var array<string,string> */
    public array $variation_attributes = [];

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_slug(): string
    {
        return $this->slug;
    }

    public function get_type(): string
    {
        return $this->type;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function get_price(string $context = 'view'): string
    {
        return $this->price;
    }

    public function get_regular_price(string $context = 'view'): string
    {
        return $this->regular_price;
    }

    public function get_sale_price(string $context = 'view'): string
    {
        return $this->sale_price;
    }

    public function is_on_sale(): bool
    {
        return $this->on_sale;
    }

    public function is_purchasable(): bool
    {
        return $this->purchasable;
    }

    public function get_stock_status(string $context = 'view'): string
    {
        return $this->stock_status;
    }

    public function get_manage_stock(string $context = 'view'): bool
    {
        return $this->manage_stock;
    }

    public function get_stock_quantity(string $context = 'view'): ?int
    {
        return $this->stock_quantity;
    }

    public function backorders_allowed(): bool
    {
        return $this->backorders;
    }

    public function get_short_description(): string
    {
        return $this->short_description;
    }

    public function get_description(): string
    {
        return $this->description;
    }

    /** @return list<int> */
    public function get_category_ids(): array
    {
        return $this->category_ids;
    }

    public function get_image_id(): int
    {
        return $this->image_id;
    }

    /** @return list<int> */
    public function get_gallery_image_ids(): array
    {
        return $this->gallery_ids;
    }

    /** @return array<int|string,mixed> */
    public function get_attributes(): array
    {
        if ('variation' === $this->type) {
            return $this->variation_attributes;
        }

        return $this->attributes;
    }

    /** @return list<int> */
    public function get_children(): array
    {
        return $this->child_ids;
    }
}

/** Atributo de producto simulado (`WC_Product_Attribute`). */
final class TestMembershipsReadAttribute
{
    /** @param list<string> $options */
    public function __construct(
        private int $id,
        private string $name,
        private array $options,
        private bool $visible = true,
        private bool $variation = false
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function get_options(): array
    {
        return $this->options;
    }

    public function get_visible(): bool
    {
        return $this->visible;
    }

    public function get_variation(): bool
    {
        return $this->variation;
    }
}

/**
 * Doble de `$wpdb` para las lecturas del módulo.
 *
 * Entiende solo las sentencias que emiten `HWE_Memberships_Members`, `Grants::forUser` y la
 * consulta del historial. Registra cualquier escritura para poder afirmar que una lectura no
 * toca la base de datos.
 */
final class TestMembershipsReadWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** @var list<array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public array $updates = [];
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $deletes = [];
    /** @var list<string> */
    public array $transactions = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var array<int,array<string,mixed>> */
    public array $grants = [];
    /** @var array<int,array<string,mixed>> */
    public array $members = [];
    /** @var array<int,array<string,mixed>> */
    public array $events = [];
    /** @var list<array{0:int,1:int,2:int}> argumentos de LIMIT/OFFSET del historial */
    public array $historyLimits = [];
    public string $last_error = '';
    public bool $failEventQuery = false;
    public int $nextGrantId = 1;
    public int $nextEventId = 1;

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode(array_values($args)) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data];
        return false;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        $this->updates[] = [$table, $data, $where];
        return 0;
    }

    public function delete(string $table, array $where, ?array $whereFormat = null): int|false
    {
        $this->deletes[] = [$table, $where];
        return 0;
    }

    public function query(string $sql): int|false
    {
        $this->transactions[] = $sql;
        return 0;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->members[ $userId ]) ? (object) $this->members[ $userId ] : null;
        }

        return null;
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'hwe_memberships_events')) {
            $userId = (int) ($args[0] ?? 0);
            $count  = 0;
            foreach ($this->events as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId) {
                    $count++;
                }
            }
            return $count;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_grants')) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = [];
            foreach ($this->grants as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if (!in_array((string) ($row['status'] ?? ''), ['active', 'scheduled'], true)) {
                    continue;
                }
                $rows[] = $row;
            }
            usort($rows, static function (array $left, array $right): int {
                return [(string) ($left['starts_at'] ?? ''), (int) $left['id']]
                    <=> [(string) ($right['starts_at'] ?? ''), (int) $right['id']];
            });
            return array_map(static fn (array $row): object => (object) $row, $rows);
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_events')) {
            if ($this->failEventQuery) {
                $this->last_error = 'fallo simulado';
                return [];
            }

            $userId  = (int) ($args[0] ?? 0);
            $perPage = (int) ($args[1] ?? 0);
            $offset  = (int) ($args[2] ?? 0);
            $this->historyLimits[] = [$userId, $perPage, $offset];

            $rows = [];
            foreach ($this->events as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId) {
                    $rows[] = $row;
                }
            }
            usort($rows, static function (array $left, array $right): int {
                return [(string) $right['created_at'], (int) $right['id']]
                    <=> [(string) $left['created_at'], (int) $left['id']];
            });

            return array_map(
                static fn (array $row): object => (object) $row,
                array_slice($rows, $offset, $perPage)
            );
        }

        return [];
    }

    public function seedGrant(array $overrides = []): int
    {
        $id = $this->nextGrantId++;
        $this->grants[ $id ] = array_merge(
            [
                'id'              => $id,
                'user_id'         => 7,
                'level_id'        => 101,
                'origin'          => 'order',
                'ref_type'        => null,
                'ref_id'          => null,
                'idempotency_key' => 'seed:' . $id,
                'duration_days'   => 30,
                'queued_seconds'  => null,
                'starts_at'       => null,
                'ends_at'         => null,
                'status'          => 'active',
                'frozen_seconds'  => 0,
                'referral_gifts'  => 0,
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['id' => $id]
        );

        return $id;
    }

    public function seedMember(int $userId, array $overrides = []): void
    {
        $this->members[ $userId ] = array_merge(
            [
                'user_id'        => $userId,
                'level_id'       => null,
                'covers_until'   => null,
                'frozen_at'      => null,
                'frozen_seconds' => 0,
                'member_number'  => null,
                'member_since'   => null,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['user_id' => $userId]
        );
    }

    /**
     * @param array{user_id:int,type:string,level_from?:int,level_to?:int,data?:array<string,mixed>,created_at?:string} $overrides
     */
    public function seedEvent(array $overrides): void
    {
        $id = $this->nextEventId++;
        $this->events[ $id ] = [
            'id'         => $id,
            'user_id'    => (int) ($overrides['user_id'] ?? 0),
            'grant_id'   => $overrides['grant_id'] ?? null,
            'type'       => (string) ($overrides['type'] ?? 'activated'),
            'level_from' => (int) ($overrides['level_from'] ?? 0),
            'level_to'   => (int) ($overrides['level_to'] ?? 0),
            'actor'      => 'system',
            'actor_id'   => null,
            'data'       => isset($overrides['data']) ? json_encode($overrides['data']) : null,
            'created_at' => (string) ($overrides['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ];
    }

    /** @return array<int,mixed> */
    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }
}
