<?php

namespace HWE\ControlCenter;

/**
 * La marca de la tienda en el panel «HWE Config».
 *
 * Modo Operate: neutros, tipografía y controles son los de wp-admin. La marca de la instancia
 * (`design.colors.brand`, `brand.logo`, `brand.name`) hace un solo trabajo: estado activo y acción
 * (pestaña activa, botón Guardar, foco, interruptor encendido). Sin marca propia (sin logo y con el
 * nombre de serie) se usa la reserva de Orbit: tinta y cadmio.
 *
 * Los colores y la lectura de la marca salen de `hwe-admin-branding.php`, la misma regla que el
 * login: cada color se comprueba contra su fondo (WCAG AA) y cae a tinta o blanco si no llega. Si
 * ese mu-plugin no está o la instancia apaga la marca (`hwe_admin_branding`), el panel usa el color
 * de acento de wp-admin (ver las variables por defecto de `assets/admin.css`).
 */
final class AdminSkin {

    public const ORBIT_BRAND       = '#f2c200';
    public const ORBIT_BRAND_HOVER = '#d9ae00';
    private const INK              = '#111111';
    private const PAPER            = '#ffffff';
    /** Fondo de página más oscuro de los esquemas de wp-admin que usamos (Orbit): peor caso del anillo de foco. */
    private const PAGE             = '#e9e9e6';

    /**
     * Quién se muestra en la cabecera.
     *
     * @return array{name:string,logo:string,branded:bool}
     */
    public static function identity(): array {
        if (function_exists('hwe_admin_branding_enabled') && hwe_admin_branding_enabled()
            && function_exists('hwe_admin_branding_instance_brand')) {
            $brand = hwe_admin_branding_instance_brand();
            if ($brand !== null) {
                return ['name' => $brand['name'], 'logo' => $brand['logo'], 'branded' => true];
            }
        }

        $logo = function_exists('hwe_admin_branding_asset_url')
            ? hwe_admin_branding_asset_url('orbit-symbol.svg')
            : '';
        return ['name' => 'HWE Orbit', 'logo' => $logo, 'branded' => false];
    }

    /**
     * Variables de color del panel. Vacío cuando no hay marca que aplicar: el CSS ya trae el
     * acento de wp-admin como valor por defecto.
     */
    public static function css(): string {
        if (!function_exists('hwe_admin_branding_enabled') || !hwe_admin_branding_enabled()
            || !function_exists('hwe_admin_branding_readable')) {
            return '';
        }

        if (self::identity()['branded']) {
            $accent = hwe_admin_branding_color('brand', self::INK);
            $hover  = hwe_admin_branding_color('brand_dark', $accent);
            $second = hwe_admin_branding_color('secondary', self::INK);
        } else {
            $accent = self::ORBIT_BRAND;
            $hover  = self::ORBIT_BRAND_HOVER;
            $second = self::INK;
        }

        $vars = [
            '--hwe-accent'          => $accent,
            '--hwe-on-accent'       => hwe_admin_branding_readable(self::PAPER, $accent),
            '--hwe-accent-hover'    => $hover,
            '--hwe-on-accent-hover' => hwe_admin_branding_readable(self::PAPER, $hover),
            // La marca como texto, borde o trazo sobre papel: tinta si no llega a 4,5:1.
            '--hwe-accent-ink'      => hwe_admin_branding_readable($accent, self::PAPER),
            // Anillo de foco: el primero que llegue a 3:1 sobre la página y el papel.
            '--hwe-ring'            => hwe_admin_branding_focus_ring([$accent, $second, self::INK], self::PAGE, self::PAPER),
        ];

        $css = '';
        foreach ($vars as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }
        return '.hwe-wrap{' . $css . '}';
    }
}
