<?php

namespace HWE\ControlCenter;

/**
 * Recorrido recursivo genérico del árbol de esquema de configuración.
 *
 * El árbol se procesa depth-first: los hijos se visitan antes que su grupo
 * padre. `visitGroup` recibe los resultados YA procesados de sus hijos.
 *
 * Subclases concretas:
 *   - Walkers\AdminFormWalker  → produce HTML del formulario wp-admin
 *   - Walkers\PublicConfigWalker → extrae valores públicos para la API REST
 *   - Walkers\DefaultsWalker    → extrae valores por defecto del esquema
 */
abstract class Walker {

    /**
     * Recorre el árbol de esquema de forma recursiva.
     *
     * @param array<string,array> $schema Subárbol del esquema.
     * @param array               $values Valores almacenados para este subárbol.
     * @param list<string>        $path   Ruta al nodo actual en el esquema completo.
     * @return array              Resultados indexados por la clave del esquema.
     */
    final public function walk(array $schema, array $values = [], array $path = []): array {
        $result = [];

        foreach ($schema as $key => $node) {
            $currentPath  = array_merge($path, [$key]);
            $nodeType     = $node['type'] ?? 'text';

            if ($nodeType === 'group') {
                $children   = $node['children'] ?? [];
                $childVals  = (isset($values[$key]) && is_array($values[$key]))
                    ? $values[$key]
                    : [];

                $processed  = $this->walk($children, $childVals, $currentPath);
                $result[$key] = $this->visitGroup($node, $key, $currentPath, $processed);
            } else {
                $value        = $values[$key] ?? ($node['default'] ?? null);
                $result[$key] = $this->visitField($node, $key, $currentPath, $value);
            }
        }

        return $result;
    }

    /**
     * Procesa un nodo hoja (campo de formulario).
     *
     * @param array        $field Definición del nodo en el esquema.
     * @param string       $key   Clave de este nodo.
     * @param list<string> $path  Ruta completa (p. ej. ['design','colors','brand']).
     * @param mixed        $value Valor almacenado o por defecto.
     * @return mixed       Resultado específico del walker (HTML, valor, etc.).
     */
    abstract protected function visitField(
        array  $field,
        string $key,
        array  $path,
        mixed  $value
    ): mixed;

    /**
     * Procesa un nodo grupo una vez procesados todos sus hijos.
     *
     * @param array        $group    Definición del nodo grupo.
     * @param string       $key      Clave de este grupo.
     * @param list<string> $path     Ruta completa.
     * @param array        $children Resultados ya procesados de los hijos.
     * @return mixed       Resultado específico del walker.
     */
    abstract protected function visitGroup(
        array  $group,
        string $key,
        array  $path,
        array  $children
    ): mixed;
}
