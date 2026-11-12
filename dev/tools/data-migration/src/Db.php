<?php
/**
 * FroggaBio data migration - thin PDO wrapper with schema introspection.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration;

final class Db
{
    public readonly \PDO $pdo;
    public readonly string $prefix;
    public readonly string $schema;

    /** @var array<string, array<string, array{type:string,nullable:bool,default:?string,auto:bool,generated:bool}>> */
    private array $columns = [];
    /** @var array<string, string[]> */
    private array $pks = [];
    /** @var array<string, bool>|null */
    private ?array $tables = null;

    public function __construct(array $c, public readonly string $label)
    {
        foreach (['dbname', 'username'] as $k) {
            if (empty($c[$k])) {
                throw new \RuntimeException("Database config for '$label' is missing '$k'.");
            }
        }
        $this->prefix = (string)($c['table_prefix'] ?? '');
        $this->schema = (string)$c['dbname'];

        if (!empty($c['unix_socket'])) {
            $dsn = "mysql:unix_socket={$c['unix_socket']};dbname={$c['dbname']};charset=utf8mb4";
        } else {
            $host = (string)($c['host'] ?? '127.0.0.1');
            $port = $c['port'] ?? null;
            if (!$port && str_contains($host, ':')) {
                [$host, $port] = explode(':', $host, 2);
            }
            $dsn = "mysql:host=$host;" . ($port ? "port=$port;" : '') . "dbname={$c['dbname']};charset=utf8mb4";
        }

        $this->pdo = new \PDO($dsn, (string)$c['username'], (string)($c['password'] ?? ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => true,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        // UTC session so TIMESTAMP columns are copied verbatim; keep explicit 0 ids (store 0, group 0, ...).
        $this->pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    public function t(string $table): string
    {
        return $this->prefix . $table;
    }

    public function q(string $table): string
    {
        return '`' . $this->t($table) . '`';
    }

    public function hasTable(string $table): bool
    {
        if ($this->tables === null) {
            $this->tables = array_fill_keys($this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN), true);
        }
        return isset($this->tables[$this->t($table)]);
    }

    /** Raw (already prefixed / unprefixed) table name check, used for sales sequence tables. */
    public function hasRawTable(string $table): bool
    {
        $this->hasTable('__warmup__');
        return isset($this->tables[$table]);
    }

    /** @return array<string, array{type:string,nullable:bool,default:?string,auto:bool,generated:bool}> */
    public function columns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $rows = $this->all(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$this->schema, $this->t($table)]
            );
            $cols = [];
            foreach ($rows as $r) {
                $extra = strtolower((string)$r['EXTRA']);
                $cols[$r['COLUMN_NAME']] = [
                    'type' => strtolower((string)$r['DATA_TYPE']),
                    'nullable' => $r['IS_NULLABLE'] === 'YES',
                    'default' => $r['COLUMN_DEFAULT'],
                    'auto' => str_contains($extra, 'auto_increment'),
                    'generated' => str_contains($extra, 'generated') || str_contains($extra, 'virtual')
                        || str_contains($extra, 'persistent') || str_contains($extra, 'stored'),
                ];
            }
            $this->columns[$table] = $cols;
        }
        return $this->columns[$table];
    }

    /** Non-generated column names. @return string[] */
    public function columnNames(string $table): array
    {
        return array_keys(array_filter($this->columns($table), static fn ($m) => !$m['generated']));
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->columns($table)[$column]);
    }

    /** @return string[] */
    public function primaryKey(string $table): array
    {
        if (!isset($this->pks[$table])) {
            $this->pks[$table] = $this->col(
                "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
                . " AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                [$this->schema, $this->t($table)]
            );
        }
        return $this->pks[$table];
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function col(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function val(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function pairs(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($params));
        return $st->rowCount();
    }

    public function count(string $table, ?string $where = null, array $params = []): int
    {
        if (!$this->hasTable($table)) {
            return 0;
        }
        return (int)$this->val("SELECT COUNT(*) FROM {$this->q($table)}" . ($where ? " WHERE $where" : ''), $params);
    }

    /**
     * Stream a table in primary-key ordered batches.
     *
     * @param string[] $cols
     */
    public function iterate(string $table, array $cols, ?string $where = null, array $params = [], int $batch = 1000): \Generator
    {
        $pk = $this->primaryKey($table);
        $colSql = implode(',', array_map(static fn ($c) => "`$c`", $cols));
        $base = "SELECT $colSql FROM {$this->q($table)} WHERE " . ($where ? "($where)" : '1=1');
        $meta = $this->columns($table);

        if (count($pk) === 1 && in_array($pk[0], $cols, true)
            && preg_match('/int$/', $meta[$pk[0]]['type'] ?? '')) {
            $k = $pk[0];
            $last = null;
            while (true) {
                $sql = $base . ($last !== null ? " AND `$k` > ?" : '') . " ORDER BY `$k` LIMIT $batch";
                $rows = $this->all($sql, $last !== null ? [...$params, $last] : $params);
                foreach ($rows as $r) {
                    yield $r;
                }
                if (count($rows) < $batch) {
                    break;
                }
                $last = $rows[count($rows) - 1][$k];
            }
            return;
        }

        $order = $pk ?: [$cols[0]];
        $orderSql = implode(',', array_map(static fn ($c) => "`$c`", $order));
        for ($offset = 0; ; $offset += $batch) {
            $rows = $this->all("$base ORDER BY $orderSql LIMIT $batch OFFSET $offset", $params);
            foreach ($rows as $r) {
                yield $r;
            }
            if (count($rows) < $batch) {
                break;
            }
        }
    }

    /**
     * Multi-row insert.
     * mode: upsert (ON DUPLICATE KEY UPDATE non-PK columns), ignore (INSERT IGNORE), insert (plain).
     *
     * @param string[] $cols
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertRows(string $table, array $cols, array $rows, string $mode = 'upsert'): int
    {
        if (!$rows) {
            return 0;
        }
        $colSql = implode(',', array_map(static fn ($c) => "`$c`", $cols));
        $perStmt = max(1, min(1000, intdiv(50000, max(1, count($cols)))));
        $update = array_values(array_diff($cols, $this->primaryKey($table)));
        if ($mode === 'upsert' && !$update) {
            $mode = 'ignore';
        }
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $affected = 0;

        foreach (array_chunk($rows, $perStmt) as $chunk) {
            $sql = ($mode === 'ignore' ? 'INSERT IGNORE' : 'INSERT') . " INTO {$this->q($table)} ($colSql) VALUES "
                . implode(',', array_fill(0, count($chunk), $ph));
            if ($mode === 'upsert') {
                $sql .= ' ON DUPLICATE KEY UPDATE '
                    . implode(',', array_map(static fn ($c) => "`$c`=VALUES(`$c`)", $update));
            }
            $params = [];
            foreach ($chunk as $r) {
                foreach ($cols as $c) {
                    $params[] = $r[$c] ?? null;
                }
            }
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $affected += $st->rowCount();
        }
        return $affected;
    }

    /** Insert a single row (only columns that exist in the table) and return the new auto-increment id. */
    public function insert(string $table, array $row, bool $ignore = false): int
    {
        $cols = $this->columns($table);
        $row = array_filter($row, static fn ($k) => isset($cols[$k]) && !$cols[$k]['generated'], ARRAY_FILTER_USE_KEY);
        $names = array_keys($row);
        $sql = ($ignore ? 'INSERT IGNORE' : 'INSERT') . " INTO {$this->q($table)} ("
            . implode(',', array_map(static fn ($c) => "`$c`", $names)) . ') VALUES ('
            . implode(',', array_fill(0, count($names), '?')) . ')';
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($row));
        return $st->rowCount() ? (int)$this->pdo->lastInsertId() : 0;
    }

    public function truncate(string $table): void
    {
        if ($this->hasTable($table)) {
            $this->pdo->exec("TRUNCATE TABLE {$this->q($table)}");
        }
    }

    /** Value used for NOT NULL target columns that do not exist in the source table. */
    public static function filler(string $type): string|int
    {
        return match (true) {
            (bool)preg_match('/int$|decimal|float|double|numeric|bit/', $type) => 0,
            $type === 'date' => '1970-01-01',
            $type === 'datetime', $type === 'timestamp' => '1970-01-01 00:00:01',
            $type === 'time' => '00:00:00',
            $type === 'year' => '1970',
            default => '',
        };
    }
}
