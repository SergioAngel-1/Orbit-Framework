<?php
/**
 * Doble de `$wpdb` para los servicios de membresías (Tarea 5).
 *
 * Mantiene el estado mínimo de las cuatro tablas, la fila de `members`, el contador de número
 * de socio en `options` y los tokens del carné. Interpreta solo las sentencias que emiten
 * `HWE_Memberships_Grants`, `HWE_Memberships_Members` y `HWE_Memberships_Events`; cualquier otra
 * consulta se registra y se responde como vacía. Las transacciones se simulan con una foto del
 * estado para poder comprobar que un `ROLLBACK` no deja concesiones, socios ni historial.
 *
 * No se carga desde `tests/bootstrap.php`: lo requieren los dos tests del módulo para no añadir
 * peso a la suite del resto del backend.
 */

declare(strict_types=1);

final class TestMembershipsServiceWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $inserts = [];
    /** @var list<array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
    public array $updates = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> Consultas y acciones en orden, para comparar COMMIT con los hooks. */
    public array $events = [];
    /** @var array<int,array<string,mixed>> */
    public array $grants = [];
    /** @var array<int,array<string,mixed>> user_id => fila */
    public array $members = [];
    /** @var array<int,array<string,mixed>> */
    public array $event_rows = [];
    /** @var list<array<string,mixed>> */
    public array $tokens = [];
    /** @var array<string,string> option_name => option_value */
    public array $option_rows = [];
    public string $failQueryNeedle = '';
    public string $failInsertSuffix = '';
    public string $failUpdateSuffix = '';
    public bool $failCommit = false;

    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;
    private int $nextGrantId = 1;
    private int $nextEventId = 1;
    private int $nextSeedKey = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return $sql . ' /* ' . json_encode(array_values($args)) . ' */';
    }

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data];

        if ('' !== $this->failInsertSuffix && str_ends_with($table, $this->failInsertSuffix)) {
            return false;
        }

        if (str_ends_with($table, 'hwe_memberships_grants')) {
            $key = (string) ($data['idempotency_key'] ?? '');
            if ('' !== $key && null !== $this->grantIdByKey($key)) {
                return false;
            }
            $id              = $this->nextGrantId++;
            $this->insert_id = $id;
            $this->grants[$id] = array_merge(
                [
                    'queued_seconds'       => null,
                    'frozen_seconds'       => 0,
                    'shipping_quota_used'  => 0,
                    'shipping_quota_reserved' => 0,
                    'referral_gifts'       => 0,
                    'expiry_notified_at'   => null,
                    'revoked_at'           => null,
                ],
                $data,
                ['id' => $id]
            );
            return 1;
        }

        if (str_ends_with($table, 'hwe_memberships_events')) {
            $id              = $this->nextEventId++;
            $this->insert_id = $id;
            $this->event_rows[$id] = array_merge($data, ['id' => $id]);
            return 1;
        }

        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        $this->updates[] = [$table, $data, $where];

        if ('' !== $this->failUpdateSuffix && str_ends_with($table, $this->failUpdateSuffix)) {
            return false;
        }

        if (str_ends_with($table, 'hwe_memberships_grants')) {
            $id = (int) ($where['id'] ?? 0);
            if (!isset($this->grants[$id])) {
                return 0;
            }
            $new = array_merge($this->grants[$id], $data);
            if ($new === $this->grants[$id]) {
                return 0;
            }
            $this->grants[$id] = $new;
            return 1;
        }

        if (str_ends_with($table, 'hwe_memberships_members')) {
            $userId = (int) ($where['user_id'] ?? 0);
            if (!isset($this->members[$userId])) {
                return 0;
            }
            $new = array_merge($this->members[$userId], $data);
            if ($new === $this->members[$userId]) {
                return 0;
            }
            $this->members[$userId] = $new;
            return 1;
        }

        return 0;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $this->events[]  = $sql;

        if ('START TRANSACTION' === $sql) {
            $this->snapshot = [
                'grants'       => $this->grants,
                'members'      => $this->members,
                'event_rows'   => $this->event_rows,
                'tokens'       => $this->tokens,
                'option_rows'  => $this->option_rows,
                'nextGrantId'  => $this->nextGrantId,
                'nextEventId'  => $this->nextEventId,
            ];
            return 1;
        }

        if ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->grants      = $this->snapshot['grants'];
                $this->members     = $this->snapshot['members'];
                $this->event_rows  = $this->snapshot['event_rows'];
                $this->tokens      = $this->snapshot['tokens'];
                $this->option_rows = $this->snapshot['option_rows'];
                $this->nextGrantId = $this->snapshot['nextGrantId'];
                $this->nextEventId = $this->snapshot['nextEventId'];
            }
            $this->snapshot = null;
            return 1;
        }

        if ('COMMIT' === $sql) {
            if ($this->failCommit) {
                return false;
            }
            $this->snapshot = null;
            return 1;
        }

        if ('' !== $this->failQueryNeedle && str_contains($sql, $this->failQueryNeedle)) {
            return false;
        }

        $args = $this->args($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            if (isset($this->members[$userId])) {
                return 0;
            }
            $this->members[$userId] = [
                'user_id'        => $userId,
                'level_id'       => null,
                'covers_until'   => null,
                'frozen_at'      => null,
                'frozen_seconds' => 0,
                'member_number'  => null,
                'member_since'   => null,
                'updated_at'     => (string) ($args[1] ?? ''),
            ];
            return 1;
        }

        if (str_starts_with($sql, 'INSERT IGNORE INTO ' . $this->options)) {
            $name = (string) ($args[0] ?? '');
            if (array_key_exists($name, $this->option_rows)) {
                return 0;
            }
            $this->option_rows[$name] = (string) ($args[1] ?? '0');
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->options)) {
            $value = (string) ($args[0] ?? '');
            $name  = (string) ($args[1] ?? '');
            $this->option_rows[$name] = $value;
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE ' . $this->prefix . 'hwe_memberships_card_tokens')) {
            $now    = (string) ($args[0] ?? '');
            $userId = (int) ($args[1] ?? 0);
            $count  = 0;
            foreach ($this->tokens as $index => $token) {
                if ((int) ($token['user_id'] ?? 0) === $userId && null === ($token['revoked_at'] ?? null)) {
                    $this->tokens[$index]['revoked_at'] = $now;
                    $count++;
                }
            }
            return $count;
        }

        return 1;
    }

    public function get_row(string $sql): ?object
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_members')) {
            $userId = (int) ($args[0] ?? 0);
            return isset($this->members[$userId]) ? (object) $this->members[$userId] : null;
        }

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_grants')) {
            if (str_contains($sql, 'idempotency_key')) {
                $id = $this->grantIdByKey((string) ($args[0] ?? ''));
                return null === $id ? null : (object) $this->grants[$id];
            }
            if (str_contains($sql, 'id = %d')) {
                $id = (int) ($args[0] ?? 0);
                return isset($this->grants[$id]) ? (object) $this->grants[$id] : null;
            }
        }

        if (str_contains($sql, 'FROM ' . $this->options)) {
            $name = (string) ($args[0] ?? '');
            return array_key_exists($name, $this->option_rows)
                ? (object) ['option_value' => $this->option_rows[$name]]
                : null;
        }

        return null;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_grants')) {
            $userId = (int) ($args[0] ?? 0);
            $rows   = [];
            foreach ($this->grants as $row) {
                if ((int) ($row['user_id'] ?? 0) !== $userId) {
                    continue;
                }
                if (!in_array((string) ($row['status'] ?? ''), ['active', 'scheduled'], true)) {
                    continue;
                }
                $rows[] = $row;
            }
            usort(
                $rows,
                static function (array $a, array $b): int {
                    $left  = $a['starts_at'] ?? null;
                    $right = $b['starts_at'] ?? null;
                    if (null === $left && null === $right) {
                        return (int) $a['id'] <=> (int) $b['id'];
                    }
                    if (null === $left) {
                        return -1;
                    }
                    if (null === $right) {
                        return 1;
                    }
                    return strcmp((string) $left, (string) $right) ?: (int) $a['id'] <=> (int) $b['id'];
                }
            );
            return array_map(static fn (array $row): object => (object) $row, $rows);
        }

        return [];
    }

    public function get_var(string $sql): string|int|null
    {
        $this->queries[] = $sql;
        $args = $this->args($sql);

        if (str_contains($sql, 'FROM ' . $this->prefix . 'hwe_memberships_grants')
            && str_contains($sql, 'idempotency_key')) {
            return $this->grantIdByKey((string) ($args[0] ?? ''));
        }

        return null;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public function grantsOf(int $userId): array
    {
        return array_values(array_filter(
            $this->grants,
            static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId,
        ));
    }

    public function grantByKey(string $key): ?array
    {
        $id = $this->grantIdByKey($key);
        return null === $id ? null : $this->grants[$id];
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_values(array_column($this->event_rows, 'type'));
    }

    /** @return list<string> Consultas que escriben (o abren/cierran transacción). */
    public function writeQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => 1 === preg_match('/^(INSERT|UPDATE|DELETE|START TRANSACTION|COMMIT|ROLLBACK)\b/', $sql),
        ));
    }

    public function seedGrant(array $overrides = []): int
    {
        $id = (int) ($overrides['id'] ?? 0);
        if ($id <= 0) {
            $id = $this->nextGrantId++;
        } else {
            $this->nextGrantId = max($this->nextGrantId, $id + 1);
        }

        $this->grants[$id] = array_merge(
            [
                'id'              => $id,
                'user_id'         => 7,
                'level_id'        => 101,
                'origin'          => 'order',
                'ref_type'        => null,
                'ref_id'          => null,
                'idempotency_key' => 'seed:' . $this->nextSeedKey++,
                'duration_days'   => 30,
                'queued_seconds'  => null,
                'starts_at'       => null,
                'ends_at'         => null,
                'status'          => 'active',
                'actor'           => 'system',
                'actor_id'        => null,
                'note'            => null,
                'data'            => null,
                'shipping_quota_used' => 0,
                'shipping_quota_reserved' => 0,
                'referral_gifts'  => 0,
                'expiry_notified_at' => null,
                'frozen_seconds'  => 0,
                'revoked_at'      => null,
                'created_at'      => gmdate('Y-m-d H:i:s'),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['id' => $id]
        );

        return $id;
    }

    public function seedMember(int $userId, array $overrides = []): void
    {
        $this->members[$userId] = array_merge(
            [
                'user_id'        => $userId,
                'level_id'       => null,
                'covers_until'   => null,
                'frozen_at'      => null,
                'frozen_seconds' => 0,
                'member_number'  => null,
                'member_since'   => null,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ],
            $overrides,
            ['user_id' => $userId]
        );
    }

    public function seedToken(int $userId, string $hash = 'hash', ?string $expires = null, ?string $revoked = null): void
    {
        $this->tokens[] = [
            'user_id'    => $userId,
            'token_hash' => $hash,
            'expires_at' => $expires ?? gmdate('Y-m-d H:i:s', time() + 3600),
            'revoked_at' => $revoked,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function args(string $sql): array
    {
        if (preg_match('~/\\* (\\[.*\\]) \\*/$~s', $sql, $matches)) {
            $decoded = json_decode($matches[1], true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }

    private function grantIdByKey(string $key): ?int
    {
        if ('' === $key) {
            return null;
        }
        foreach ($this->grants as $id => $row) {
            if ((string) ($row['idempotency_key'] ?? '') === $key) {
                return (int) $id;
            }
        }
        return null;
    }
}
