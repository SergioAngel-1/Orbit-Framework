<?php

namespace HWE\ControlCenter;

/**
 * Registro de avisos del Control Center en el log de PHP, con el prefijo `[hwe] control-center:`.
 *
 * El esquema y la lista de tags se evalúan varias veces por petición, y un problema de
 * configuración de la instancia (un tag mal escrito, una clave raíz no permitida) se repetiría en
 * cada una. `once()` deja UNA línea por problema y proceso: el fallo no pasa desapercibido, pero
 * tampoco inunda el log.
 */
final class Log {

    /** @var array<string,true> */
    private static array $seen = [];

    /**
     * @param string $key     Identifica el problema (dos avisos con la misma clave salen una vez).
     * @param string $message Texto del aviso, sin prefijo.
     */
    public static function once(string $key, string $message): void {
        if (isset(self::$seen[$key])) {
            return;
        }
        self::$seen[$key] = true;
        error_log('[hwe] control-center: ' . $message);
    }
}
