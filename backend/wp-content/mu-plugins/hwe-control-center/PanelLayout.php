<?php

namespace HWE\ControlCenter;

/**
 * Reparte los campos del esquema efectivo en las pestañas y secciones de `PanelMap`.
 *
 * Garantías:
 *   · cada campo del esquema se coloca EXACTAMENTE una vez;
 *   · nada se pierde: lo que el mapa no nombra (campos nuevos, de módulos o de la instancia)
 *     cae en la pestaña por defecto de su clave raíz, en la sección final «Otros ajustes»;
 *   · las variantes traducibles (`<clave>_<idioma>`) van pegadas a su campo, sea cual sea su sitio;
 *   · el atributo `panel` del esquema sigue mandando a un campo a otra pestaña.
 *
 * No genera HTML de campos (lo hace `AdminFormWalker`) ni el marco de la página (`AdminPage`):
 * recibe los fragmentos del walker y devuelve secciones con el HTML ya unido.
 */
final class PanelLayout {

    /**
     * @param array<string,array>                                          $schema    Esquema efectivo (`Schema::get()`).
     * @param array<string,array{html:string,bare:string,group:bool}>      $fragments `AdminFormWalker::fragments()`.
     * @param array<string,list<array{title:string,intro:string,html:string,class?:string}>> $extras Secciones ya pintadas
     *        que `AdminPage` añade al final de una pestaña (prueba de correo, copias…), por clave de pestaña.
     * @param array<string,array<string,mixed>>|null                       $map       Mapa de pestañas (por defecto `PanelMap::tabs()`).
     * @return array{tabs:array<string,array<string,mixed>>,placed:list<string>}
     *         `tabs`: solo las pestañas con contenido (o `always`), con sus `sections` y el resto de
     *         atributos del mapa; `placed`: rutas de los campos colocados, en orden.
     */
    public static function compose(array $schema, array $fragments, array $extras = [], ?array $map = null): array {
        $map ??= PanelMap::tabs();
        $nodes    = [];
        $children = [];
        self::index($schema, '', $nodes, $children);

        /** @var array<string,true> $claimed rutas colocadas por el mapa (campo o bloque) */
        $claimed = [];
        /** @var array<string,true> $ancestors rutas con algo colocado por debajo */
        $ancestors = [];
        /** @var array<string,array<int,list<string>>> $mapped pestaña → sección → HTML */
        $mapped = [];
        $placed = [];

        $claim = static function (string $unit) use (&$claimed, &$ancestors, &$placed, $nodes, $children): void {
            $claimed[$unit] = true;
            $parts = explode('.', $unit);
            array_pop($parts);
            while ($parts !== []) {
                $ancestors[implode('.', $parts)] = true;
                array_pop($parts);
            }
            // Un bloque se coloca entero: sus hojas cuentan como colocadas.
            foreach (self::leavesOf($unit, $nodes, $children) as $leaf) {
                $placed[] = $leaf;
            }
        };

        foreach ($map as $tabKey => $tab) {
            foreach (($tab['sections'] ?? []) as $sectionIndex => $section) {
                foreach ((array) ($section['paths'] ?? []) as $entry) {
                    foreach (self::resolve((string) $entry, $nodes, $children) as $unit) {
                        if (isset($claimed[$unit]) || !isset($fragments[$unit])) {
                            continue;
                        }
                        $claim($unit);
                        $mapped[$tabKey][$sectionIndex][] = $fragments[$unit]['bare'];
                    }
                }
            }
        }

        // Lo que el mapa no nombra: por clave raíz, o donde diga `panel`.
        $rootTabs = PanelMap::rootTabs();
        /** @var array<string,array<string,list<string>>> $panelSections pestaña → título → HTML */
        $panelSections = [];
        /** @var array<string,list<string>> $rest */
        $rest = [];

        $sweep = function (string $parent, int $depth) use (&$sweep, &$claimed, &$ancestors, &$placed, &$panelSections, &$rest, $nodes, $children, $fragments, $map, $rootTabs): void {
            foreach ($children[$parent] ?? [] as $path) {
                if (isset($claimed[$path])) {
                    continue;
                }
                $node = $nodes[$path];
                if (self::isGroup($node) && ($depth === 0 || isset($ancestors[$path]))) {
                    $sweep($path, $depth + 1);
                    continue;
                }
                if (!isset($fragments[$path])) {
                    continue;
                }

                $root = explode('.', $path)[0];
                $tab  = $rootTabs[$root] ?? 'instance';
                $section = '';
                $panel = is_array($node) ? ($node['panel'] ?? null) : null;
                if (is_array($panel) && (string) ($panel['tab'] ?? '') !== '') {
                    $wanted = (string) $panel['tab'];
                    $dest   = isset($map[$wanted]) ? $wanted : ($rootTabs[$wanted] ?? null);
                    if ($dest !== null && isset($map[$dest])) {
                        $tab     = $dest;
                        $section = (string) ($panel['section'] ?? '');
                    } else {
                        Log::once(
                            'admin:panel:' . $wanted,
                            sprintf('«panel» apunta a la pestaña «%s», que no existe; los campos se quedan en la de su clave raíz.', $wanted)
                        );
                    }
                }
                if (!isset($map[$tab])) {
                    $tab = 'instance';
                }

                $units = array_merge([$path], self::variantsOf($path, $nodes, $children));
                foreach ($units as $unit) {
                    if (isset($claimed[$unit]) || !isset($fragments[$unit])) {
                        continue;
                    }
                    $claimed[$unit] = true;
                    foreach (self::leavesOf($unit, $nodes, $children) as $leaf) {
                        $placed[] = $leaf;
                    }
                    if ($section !== '') {
                        $panelSections[$tab][$section][] = $fragments[$unit]['html'];
                    } else {
                        $rest[$tab][] = $fragments[$unit]['html'];
                    }
                }
            }
        };
        $sweep('', 0);

        $tabs = [];
        foreach ($map as $tabKey => $tab) {
            $sections = [];
            foreach (($tab['sections'] ?? []) as $sectionIndex => $section) {
                if (empty($mapped[$tabKey][$sectionIndex])) {
                    continue;
                }
                $sections[] = [
                    'title' => (string) ($section['title'] ?? ''),
                    'intro' => (string) ($section['intro'] ?? ''),
                    'class' => (string) ($section['class'] ?? ''),
                    'html'  => implode("\n", $mapped[$tabKey][$sectionIndex]),
                ];
            }
            foreach ($panelSections[$tabKey] ?? [] as $title => $rows) {
                $sections[] = ['title' => (string) $title, 'intro' => '', 'class' => '', 'html' => implode("\n", $rows)];
            }
            if (!empty($rest[$tabKey])) {
                $sections[] = [
                    'title' => (string) ($tab['rest'] ?? ''),
                    'intro' => '',
                    'class' => '',
                    'html'  => implode("\n", $rest[$tabKey]),
                ];
            }
            foreach ($extras[$tabKey] ?? [] as $extra) {
                $sections[] = $extra + ['class' => ''];
            }
            if ($sections === [] && empty($tab['always'])) {
                continue;
            }
            unset($tab['sections'], $tab['rest']);
            $tabs[$tabKey] = ['sections' => $sections] + $tab;
        }

        return ['tabs' => $tabs, 'placed' => $placed];
    }

    // -------------------------------------------------------------------------
    // Índice del esquema
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>        $level
     * @param array<string,mixed>        $nodes    ruta → nodo
     * @param array<string,list<string>> $children ruta del padre ('' = raíz) → rutas de los hijos
     */
    private static function index(array $level, string $prefix, array &$nodes, array &$children): void {
        foreach ($level as $key => $node) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $nodes[$path]       = $node;
            $children[$prefix][] = $path;
            if (self::isGroup($node)) {
                self::index($node['children'], $path, $nodes, $children);
            }
        }
    }

    private static function isGroup(mixed $node): bool {
        return is_array($node) && ($node['type'] ?? null) === 'group' && is_array($node['children'] ?? null);
    }

    /**
     * Unidades de una entrada del mapa, en el orden del esquema y con las variantes
     * traducibles pegadas a su campo.
     *
     * @param array<string,mixed>        $nodes
     * @param array<string,list<string>> $children
     * @return list<string>
     */
    private static function resolve(string $entry, array $nodes, array $children): array {
        $paths = [];
        if (str_ends_with($entry, '.*')) {
            $parent = substr($entry, 0, -2);
            if (!self::isGroup($nodes[$parent] ?? null)) {
                return [];
            }
            $paths = $children[$parent] ?? [];
        } elseif (isset($nodes[$entry])) {
            $paths = [$entry];
        }

        $units = [];
        foreach ($paths as $path) {
            if (in_array($path, $units, true)) {
                continue;
            }
            $units[] = $path;
            foreach (self::variantsOf($path, $nodes, $children) as $variant) {
                if (!in_array($variant, $units, true)) {
                    $units[] = $variant;
                }
            }
        }
        return $units;
    }

    /**
     * Variantes traducibles de un campo: hermanos `<clave>_<idioma secundario>`.
     *
     * @param array<string,mixed>        $nodes
     * @param array<string,list<string>> $children
     * @return list<string>
     */
    private static function variantsOf(string $path, array $nodes, array $children): array {
        $node = $nodes[$path] ?? null;
        if (!is_array($node) || empty($node['translatable']) || self::isGroup($node)) {
            return [];
        }
        $dot    = strrpos($path, '.');
        $prefix = $dot === false ? '' : substr($path, 0, $dot + 1);
        $key    = $dot === false ? $path : substr($path, $dot + 1);

        $variants = [];
        foreach (Schema::secondaryLocales() as $suffix) {
            $variant = $prefix . $key . '_' . $suffix;
            if (isset($nodes[$variant]) && !self::isGroup($nodes[$variant])) {
                $variants[] = $variant;
            }
        }
        return $variants;
    }

    /**
     * Hojas bajo una ruta (la propia ruta si ya es una hoja).
     *
     * @param array<string,mixed>        $nodes
     * @param array<string,list<string>> $children
     * @return list<string>
     */
    private static function leavesOf(string $path, array $nodes, array $children): array {
        if (!self::isGroup($nodes[$path] ?? null)) {
            return [$path];
        }
        $leaves = [];
        foreach ($children[$path] ?? [] as $child) {
            array_push($leaves, ...self::leavesOf($child, $nodes, $children));
        }
        return $leaves;
    }
}
