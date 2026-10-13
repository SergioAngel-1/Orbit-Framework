<?php
/**
 * Stubs mínimos de las clases de WPGraphQL, para probar resolvers sin el plugin.
 *
 * Si un test necesita más (tipos, loaders), se amplía aquí.
 */

declare(strict_types=1);

namespace WPGraphQL {
    /** Contexto que reciben los resolvers (type-hint). */
    class AppContext {
    }
}

namespace GraphQL\Error {
    /** Error visible para el cliente de GraphQL (`UserError` de webonyx/graphql-php). */
    class UserError extends \Exception {
    }
}

namespace WPGraphQL\Utils {
    /** Utilidades de WPGraphQL: solo la conversión de ID global a ID de base de datos. */
    class Utils {
        public static function get_database_id_from_id( $id ) {
            $decoded = base64_decode((string) $id, true);
            return $decoded !== false && str_contains($decoded, ':')
                ? (int) substr($decoded, (int) strrpos($decoded, ':') + 1)
                : (int) $id;
        }
    }
}
