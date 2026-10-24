<?php
/**
 * Dobles globales de `HWE_Memberships_Levels` y `HWE_Memberships_Members` para el test del
 * registro de beneficios.
 *
 * El registro y la clase base solo necesitan dos lecturas: la configuración de beneficios del
 * nivel y el estado calculado del socio. Se sustituyen aquí para no cargar la capa de datos
 * (que exigiría `$wpdb`, CPT y metas) y poder probar el contrato del registro aislado.
 */

declare(strict_types=1);

final class HWE_Memberships_Levels
{
    /** @var array<int,array<string,mixed>> */
    public static array $configs = [];

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
}
