<?php
/**
 * Stub mínimo de `WP_Session_Tokens`, para comprobar que un cambio de contraseña o un
 * `logout-all` destruyen las sesiones nativas de WordPress sin WordPress.
 *
 * Solo implementa `get_instance()`/`destroy_all()` y guarda los ids afectados en
 * `$destroyed` para que los tests lo comprueben.
 */

declare(strict_types=1);

if (!class_exists('WP_Session_Tokens')) {
    class WP_Session_Tokens
    {
        /** @var list<int> Usuarios cuyas sesiones se destruyeron (en orden). */
        public static array $destroyed = [];

        private function __construct(private int $userId)
        {
        }

        public static function get_instance(int $userId): self
        {
            return new self($userId);
        }

        public function destroy_all(): void
        {
            self::$destroyed[] = $this->userId;
        }
    }
}
