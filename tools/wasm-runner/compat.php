<?php
// Only used by tools/wasm-runner. Lets the MySQL code run on SQLite by translating
// the few MySQL-only statements the backend and its tests use.

class CompatPdo extends PDO
{
    private function translate(string $sql): string
    {
        if (preg_match('/^\s*SET FOREIGN_KEY_CHECKS\s*=\s*(\d)/i', $sql, $m) === 1) {
            return 'PRAGMA foreign_keys = ' . ($m[1] === '1' ? 'ON' : 'OFF');
        }
        $sql = (string) preg_replace('/^\s*TRUNCATE TABLE\s+`?(\w+)`?\s*$/i', 'DELETE FROM `$1`', $sql);
        $sql = (string) preg_replace(
            '/ON DUPLICATE KEY UPDATE\s+hits = IF\(window_start < \?, 1, hits \+ 1\),\s*window_start = IF\(window_start < \?, \?, window_start\)/i',
            'ON CONFLICT(bucket) DO UPDATE SET hits = CASE WHEN window_start < ? THEN 1 ELSE hits + 1 END, '
            . 'window_start = CASE WHEN window_start < ? THEN ? ELSE window_start END',
            $sql
        );
        $sql = (string) preg_replace_callback(
            '/DATE_ADD\(UTC_TIMESTAMP\(\),\s*INTERVAL\s+(\d+)\s+(MINUTE|SECOND|HOUR)\)/i',
            static fn(array $m): string => "datetime('now','+{$m[1]} " . strtolower($m[2]) . "s')",
            $sql
        );
        return $sql;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        return parent::prepare($this->translate($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        return parent::exec($this->translate($statement));
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        $query = $this->translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/** Opens the SQLite database and creates the tables if they do not exist yet. */
function wasm_open_db(string $file): CompatPdo
{
    $pdo = new CompatPdo('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($exists === false) {
        foreach (explode(';', (string) file_get_contents(__DIR__ . '/sqlite-schema.sql')) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
    return $pdo;
}
