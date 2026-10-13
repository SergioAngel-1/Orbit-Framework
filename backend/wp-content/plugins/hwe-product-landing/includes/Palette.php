<?php
namespace HWE\ProductLanding;

/**
 * Sugerencias de color para el selector de acentos de la landing.
 *
 * El `<select>` RGB nativo (WP color picker / Iris) se alimenta con una lista de
 * swatches DERIVADA de la paleta de marca real (HWE Control Center →
 * `design.colors.*`), no de una constante suelta: si el cliente cambia su
 * paleta, las sugerencias la siguen. Cada color de marca aporta su propio hex
 * más una variante clara y una oscura, y `brand` / `secondary` aportan además
 * un giro de matiz (análogo) para dar opciones que "van con" la gama sin salir
 * de ella. Sin pila de Control Center (build/tests) cae a los hex de
 * `globals.css` `@theme`.
 */
final class Palette {

    /**
     * Hex de marca de respaldo (la paleta por defecto del framework: `design.colors` del esquema
     * del Control Center y `frontend/src/app/globals.css` `@theme`). Sin
     * `surface`/`background`/`foreground`: son papel y tinta, no acentos, y ensuciarían las
     * sugerencias.
     */
    private const FALLBACK = [
        'brand'          => '#2563eb',
        'brand_dark'     => '#1e40af',
        'brand_light'    => '#3b82f6',
        'secondary'      => '#16a34a',
        'secondary_dark' => '#15803d',
        'accent'         => '#f59e0b',
    ];

    /**
     * Lista de hex `#rrggbb` únicos para el picker, ordenada de más saturada a
     * menos. Cap suave en 12 para no desbordar el popover de Iris.
     *
     * @return list<string>
     */
    public static function suggestions(): array {
        $base = self::brandColors();

        $out = [];
        foreach ($base as $role => $hex) {
            $out[] = $hex;
            $out[] = self::shift($hex, 0.16);   // más claro
            $out[] = self::shift($hex, -0.16);  // más oscuro
            if ($role === 'brand' || $role === 'secondary') {
                $out[] = self::rotate($hex, 24);
                $out[] = self::rotate($hex, -24);
            }
        }

        // Únicos preservando orden, y priorizando los más saturados.
        $seen = [];
        $uniq = [];
        foreach ($out as $hex) {
            $key = strtolower($hex);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniq[]     = $key;
        }
        // Ordena por "colorido" (s · min(l,1-l)), no por saturación HSL cruda:
        // esta última se dispara para los casi-blancos/negros y los colaba
        // arriba. Así los tonos vivos van primero y los muy claros/oscuros al
        // final.
        usort($uniq, static fn ($a, $b) => self::colorfulness($b) <=> self::colorfulness($a));

        return array_slice($uniq, 0, 12);
    }

    /** Paleta de marca actual (Control Center) con respaldo a los hex de build. */
    private static function brandColors(): array {
        $colors = self::FALLBACK;
        if (class_exists('\\HWE\\ControlCenter\\Storage')) {
            $stored = \HWE\ControlCenter\Storage::get(['design', 'colors'], []);
            if (is_array($stored)) {
                foreach (array_keys($colors) as $role) {
                    $val = $stored[$role] ?? '';
                    if (is_string($val) && preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                        $colors[$role] = strtolower($val);
                    }
                }
            }
        }
        return $colors;
    }

    // ── Helpers hex ↔ HSL ────────────────────────────────────────────────────

    /** @return array{0:float,1:float,2:float} [h 0-360, s 0-1, l 0-1] */
    private static function toHsl(string $hex): array {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l   = ($max + $min) / 2;
        $d   = $max - $min;
        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        if ($max === $r) {
            $h = fmod(($g - $b) / $d, 6);
        } elseif ($max === $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }
        return [$h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default  => [$c, 0.0, $x],
        };
        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255)
        );
    }

    /** Aclara ($delta>0) u oscurece ($delta<0) manteniendo matiz y saturación. */
    private static function shift(string $hex, float $delta): string {
        [$h, $s, $l] = self::toHsl($hex);
        return self::fromHsl($h, $s, max(0.06, min(0.94, $l + $delta)));
    }

    /** Gira el matiz $deg grados (análogo dentro de la gama). */
    private static function rotate(string $hex, float $deg): string {
        [$h, $s, $l] = self::toHsl($hex);
        return self::fromHsl(fmod($h + $deg + 360, 360), $s, $l);
    }

    private static function colorfulness(string $hex): float {
        [, $s, $l] = self::toHsl($hex);
        return $s * min($l, 1 - $l);
    }
}
