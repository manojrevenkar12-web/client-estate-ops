<?php

declare(strict_types=1);

namespace Estate\Store;

use Estate\Advisory\Advisory;
use PDO;

/**
 * The estate's records on any PDO database (SQLite for development and tests, MySQL or PostgreSQL in production):
 * clients, their delivered apps and dependencies, advisories, update jobs, incidents and cost findings.
 */
final class Store
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS clients (id VARCHAR(64) PRIMARY KEY, name VARCHAR(200) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS apps (id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(64) NOT NULL, name VARCHAR(200) NOT NULL,
            stack VARCHAR(32) NOT NULL, provider VARCHAR(16) NOT NULL, url VARCHAR(300) NOT NULL, freeze TEXT NOT NULL)',
        'CREATE TABLE IF NOT EXISTS dependencies (app_id VARCHAR(64) NOT NULL, ecosystem VARCHAR(16) NOT NULL,
            name VARCHAR(200) NOT NULL, version VARCHAR(64) NOT NULL, PRIMARY KEY (app_id, ecosystem, name))',
        'CREATE TABLE IF NOT EXISTS advisories (id VARCHAR(64) PRIMARY KEY, ecosystem VARCHAR(16) NOT NULL,
            package VARCHAR(200) NOT NULL, severity VARCHAR(16) NOT NULL, published VARCHAR(32) NOT NULL, body TEXT NOT NULL)',
        'CREATE TABLE IF NOT EXISTS jobs (id VARCHAR(64) PRIMARY KEY, app_id VARCHAR(64) NOT NULL, lane VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL, changes TEXT NOT NULL, verdict TEXT NOT NULL, created_at VARCHAR(32) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS incidents (id VARCHAR(64) PRIMARY KEY, app_id VARCHAR(64) NOT NULL,
            opened_at VARCHAR(32) NOT NULL, acted_at VARCHAR(32) NOT NULL, cause VARCHAR(16) NOT NULL, action VARCHAR(16) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS findings (resource VARCHAR(128) PRIMARY KEY, client_id VARCHAR(64) NOT NULL,
            provider VARCHAR(16) NOT NULL, action VARCHAR(32) NOT NULL, monthly_saving_eur DECIMAL(12,2) NOT NULL,
            reason TEXT NOT NULL)',
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function open(string $dsn, ?string $user = null, ?string $password = null): self
    {
        return new self(new PDO($dsn, $user, $password));
    }

    public function migrate(): void
    {
        foreach (self::SCHEMA as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function reset(): void
    {
        foreach (['clients', 'apps', 'dependencies', 'advisories', 'jobs', 'incidents', 'findings'] as $t) {
            $this->pdo->exec("DELETE FROM {$t}");
        }
    }

    /**
     * Loads a snapshot (the shape `lab` writes for the sandbox, or an export from the agency's own inventory).
     *
     * @param array<string,mixed> $s
     */
    public function import(array $s): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($s['clients'] ?? [] as $c) {
                $this->upsert('clients', ['id' => $c['id'], 'name' => $c['name']], ['id']);
            }
            foreach ($s['apps'] ?? [] as $a) {
                $this->upsert('apps', ['id' => $a['id'], 'client_id' => $a['client'], 'name' => $a['name'],
                    'stack' => $a['stack'], 'provider' => $a['provider'], 'url' => $a['url'] ?? '',
                    'freeze' => json_encode($a['freeze'] ?? [], JSON_THROW_ON_ERROR)], ['id']);
                foreach ($a['dependencies'] ?? [] as $d) {
                    $this->upsert('dependencies', ['app_id' => $a['id'], 'ecosystem' => $d['ecosystem'],
                        'name' => $d['name'], 'version' => $d['version']], ['app_id', 'ecosystem', 'name']);
                }
            }
            foreach ($s['advisories'] ?? [] as $adv) {
                $this->putAdvisory($adv);
            }
            foreach ($s['jobs'] ?? [] as $j) {
                $this->upsert('jobs', ['id' => $j['id'], 'app_id' => $j['app'], 'lane' => $j['lane'],
                    'status' => $j['status'], 'changes' => json_encode($j['changes'], JSON_THROW_ON_ERROR),
                    'verdict' => json_encode($j['verdict'] ?? null, JSON_THROW_ON_ERROR), 'created_at' => $j['created_at']], ['id']);
            }
            foreach ($s['incidents'] ?? [] as $i) {
                $this->upsert('incidents', ['id' => $i['id'], 'app_id' => $i['app'], 'opened_at' => $i['opened'],
                    'acted_at' => $i['at'], 'cause' => $i['cause'], 'action' => $i['action']], ['id']);
            }
            foreach ($s['findings'] ?? [] as $f) {
                $this->upsert('findings', ['resource' => $f['resource'], 'client_id' => $f['client'],
                    'provider' => $f['provider'] ?? '', 'action' => $f['action'],
                    'monthly_saving_eur' => $f['monthly_saving_eur'], 'reason' => $f['reason']], ['resource']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $osv */
    public function putAdvisory(array $osv): void
    {
        $a = Advisory::fromArray($osv);
        $this->upsert('advisories', ['id' => $a->id, 'ecosystem' => $a->ecosystem, 'package' => $a->package,
            'severity' => $a->severity, 'published' => $a->published,
            'body' => json_encode($osv, JSON_THROW_ON_ERROR)], ['id']);
    }

    /** @return list<Advisory> */
    public function advisories(?string $before = null): array
    {
        $rows = $this->all('SELECT body, published FROM advisories ORDER BY id');
        $out = [];
        foreach ($rows as $r) {
            if ($before !== null && $r['published'] > $before) {
                continue;
            }
            $out[] = Advisory::fromArray(json_decode($r['body'], true, 64, JSON_THROW_ON_ERROR));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function clients(): array
    {
        return $this->all('SELECT id, name FROM clients ORDER BY name');
    }

    /** @return list<array<string,mixed>> */
    public function apps(?string $client = null): array
    {
        $rows = $client === null
            ? $this->all('SELECT * FROM apps ORDER BY id')
            : $this->all('SELECT * FROM apps WHERE client_id = ? ORDER BY id', [$client]);
        return array_map(fn ($r) => $r + ['freeze_windows' => json_decode($r['freeze'], true)], $rows);
    }

    /** @return array<string,mixed>|null */
    public function app(string $id): ?array
    {
        $rows = $this->all('SELECT * FROM apps WHERE id = ?', [$id]);
        return $rows === [] ? null : $rows[0] + ['freeze_windows' => json_decode($rows[0]['freeze'], true)];
    }

    /** @return list<array{ecosystem:string, name:string, version:string}> */
    public function dependencies(string $app): array
    {
        /** @var list<array{ecosystem:string, name:string, version:string}> */
        return $this->all('SELECT ecosystem, name, version FROM dependencies WHERE app_id = ? ORDER BY ecosystem, name', [$app]);
    }

    /** @return list<array<string,mixed>> */
    public function jobs(?string $app = null, int $limit = 200): array
    {
        $sql = 'SELECT * FROM jobs' . ($app === null ? '' : ' WHERE app_id = ?') . ' ORDER BY created_at DESC LIMIT ' . $limit;
        $rows = $this->all($sql, $app === null ? [] : [$app]);
        return array_map(fn ($r) => ['changes' => json_decode($r['changes'], true),
            'verdict' => json_decode($r['verdict'], true)] + $r, $rows);
    }

    /** @return list<array<string,mixed>> */
    public function incidents(?string $app = null): array
    {
        return $app === null
            ? $this->all('SELECT * FROM incidents ORDER BY opened_at DESC')
            : $this->all('SELECT * FROM incidents WHERE app_id = ? ORDER BY opened_at DESC', [$app]);
    }

    /** @return list<array<string,mixed>> */
    public function findings(): array
    {
        return array_map(
            fn ($r) => ['monthly_saving_eur' => (float) $r['monthly_saving_eur']] + $r,
            $this->all('SELECT * FROM findings ORDER BY monthly_saving_eur DESC')
        );
    }

    /**
     * @param list<mixed> $args
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        /** @var list<array<string,mixed>> */
        return $st->fetchAll();
    }

    /**
     * Insert or replace one row, portably (no driver-specific upsert syntax).
     *
     * @param array<string,mixed> $row
     * @param list<string> $key
     */
    private function upsert(string $table, array $row, array $key): void
    {
        $where = implode(' AND ', array_map(fn ($k) => "{$k} = ?", $key));
        $this->pdo->prepare("DELETE FROM {$table} WHERE {$where}")->execute(array_map(fn ($k) => $row[$k], $key));
        $cols = array_keys($row);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $this->pdo->prepare("INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES ({$ph})")
            ->execute(array_values($row));
    }
}
