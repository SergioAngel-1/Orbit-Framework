<?php
/**
 * Plugin Name: HWE — Cargador de instancia
 * Description: Punto de enganche del código de una instancia (una tienda concreta construida
 *              sobre el framework). Carga los .php de primer nivel de mu-plugins/instance/ en
 *              orden alfabético. Con la carpeta vacía o ausente no hace nada.
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * POR QUÉ EXISTE
 * --------------
 * Una instancia es un clon del framework que añade sus propias funcionalidades: campos del
 * Control Center, tipos de campo, tags de revalidación… Si ese código vive mezclado con el del
 * núcleo, cada actualización del framework genera conflictos de merge. La solución es una
 * frontera: el código de la instancia vive en rutas RESERVADAS que el framework nunca toca
 * (`mu-plugins/instance/` aquí, `frontend/src/instance/` en el frontend) y se engancha al
 * núcleo por hooks estables, versionados por `HWE_CORE_CONTRACT_VERSION`.
 *
 * `mu-plugins/` carga SOLO los .php de su primer nivel y no desciende a subcarpetas: de ahí
 * este cargador, que hace lo mismo para `instance/`. Un archivo de instancia que quiera
 * organizar su código en subcarpetas las carga él mismo.
 *
 * QUÉ GARANTIZA
 * -------------
 *  · Orden alfabético por nombre de archivo (comparación de bytes): usa prefijos numéricos
 *    (`10-campos.php`, `20-tipos.php`) si el orden importa.
 *  · Un archivo roto NO tumba el sitio: la excepción o el error de sintaxis se registra
 *    (`[hwe] instance-loader: …`) y se sigue con el siguiente. (Un `exit` o un error fatal
 *    sí lo tumba: PHP no permite capturarlos.)
 *  · Cada archivo se carga con `require_once`, en un ámbito propio: no comparten variables.
 *  · Los hooks del núcleo se evalúan de forma perezosa (el schema, al servir o guardar la
 *    config; no al cargar), así que el orden de carga respecto a otros mu-plugins no importa.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versión del contrato núcleo ↔ instancia (hooks, formas de datos). Una instancia puede
 * comprobarla para avisar si el núcleo es más antiguo de lo que espera.
 */
if (!defined('HWE_CORE_CONTRACT_VERSION')) {
    define('HWE_CORE_CONTRACT_VERSION', '2.1.0');
}

/**
 * Carga los .php de primer nivel de $dir en orden alfabético.
 *
 * @return list<string> Rutas de los archivos que se cargaron sin error.
 */
function hwe_instance_load(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
    sort($files, SORT_STRING);

    $loaded = [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        try {
            // Ámbito propio: ni ven ni pisan las variables de este archivo ni las de otros.
            (static function (string $path): void {
                require_once $path;
            })($file);
            $loaded[] = $file;
        } catch (\Throwable $e) {
            // ParseError, Error y Exception: la instancia rota no tumba el sitio, pero se ve.
            error_log(sprintf(
                '[hwe] instance-loader: no se pudo cargar %s: %s: %s',
                basename($file),
                get_class($e),
                $e->getMessage()
            ));
        }
    }

    return $loaded;
}

hwe_instance_load(__DIR__ . '/instance');
