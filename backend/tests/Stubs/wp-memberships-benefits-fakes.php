<?php
/**
 * Dobles globales de `HWE_Memberships_Levels` y `HWE_Memberships_Members` para los tests que
 * prueban el registro de beneficios y los requisitos de acceso.
 *
 * Solo se sustituyen las lecturas que esas piezas necesitan (niveles publicados, configuración
 * de beneficios del nivel, rango efectivo y estado del socio), para no cargar la capa de datos
 * (que exigiría `$wpdb`, CPT y metas). Si se necesita otra lectura, se añade aquí y no se
 * duplica la clase en otro test: el archivo se carga una sola vez por proceso.
 */

declare(strict_types=1);

final class HWE_Memberships_Levels
{
    public const POST_TYPE = 'hwe_membership_level';

    /** @var array<int,array<string,mixed>> Niveles: id, name, slug, rank y published. */
    public static array $levels = [];

    /** @var array<int,array<string,mixed>> Configuración de beneficios por nivel. */
    public static array $configs = [];

    /** Si es `true`, `all()` lanza: simula un fallo de evaluación. */
    public static bool $throwOnAll = false;

    /** @return list<array<string,mixed>> */
    public static function all(bool $includeUnpublished = false): array
    {
        if (self::$throwOnAll) {
            throw new \RuntimeException('fallo simulado al leer los niveles');
        }

        $levels = [];
        foreach (self::$levels as $level) {
            if (!$includeUnpublished && empty($level['published'])) {
                continue;
            }
            $levels[] = $level;
        }

        usort(
            $levels,
            static fn (array $a, array $b): int => ((int) ($a['rank'] ?? 0)) <=> ((int) ($b['rank'] ?? 0))
        );

        return $levels;
    }

    /** @return array<string,mixed>|null */
    public static function byRank(int $rank): ?array
    {
        foreach (self::all() as $level) {
            if ((int) ($level['rank'] ?? 0) === $rank) {
                return $level;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public static function benefitsConfig(int $levelId): array
    {
        return self::$configs[$levelId] ?? [];
    }
}

final class HWE_Memberships_Members
{
    public const STATUS_NONE   = 'none';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';

    /** @var array<int,array<string,mixed>> */
    public static array $states = [];

    /** @return array<string,mixed> */
    public static function state(int $userId): array
    {
        return self::$states[$userId] ?? [
            'level_id'  => 0,
            'rank'      => 0,
            'status'    => self::STATUS_NONE,
            'ends_at'   => null,
            'queue'     => [],
            'grant_key' => '',
        ];
    }

    /** Rango efectivo del socio; 0 si está congelado o no tiene nivel vigente. */
    public static function effectiveRank(int $userId): int
    {
        $state = self::state($userId);

        return in_array((string) ($state['status'] ?? ''), [self::STATUS_NONE, self::STATUS_FROZEN], true)
            ? 0
            : (int) ($state['rank'] ?? 0);
    }
}
