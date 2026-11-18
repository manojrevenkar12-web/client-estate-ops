<?php

declare(strict_types=1);

namespace Estate\Http;

use Estate\Advisory\Matcher;
use Estate\Cost\Auditor;
use Estate\Policy;
use Estate\Store\Store;
use Estate\Update\CanaryJudge;
use Estate\Update\Planner;

/**
 * The estate API. Framework-free on purpose: one class maps a method and a path to a handler and returns a status
 * and a JSON body, so it runs the same behind php-fpm, the built-in server and the tests.
 */
final class Api
{
    /** @param (\Closure(): ?string)|null $report reads the latest study report */
    public function __construct(
        private readonly Store $store,
        private readonly Policy $policy,
        private readonly string $token,
        private readonly ?\Closure $report = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param array<string,string> $headers lower-case names
     * @return array{0:int, 1:array<mixed>}
     */
    public function handle(string $method, string $path, string $body = '', array $headers = []): array
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');
        try {
            return match (true) {
                $method === 'GET' && $path === '/api/health' => [200, ['ok' => true]],
                $method === 'GET' && $path === '/api/estate' => [200, $this->estate()],
                $method === 'GET' && (bool) preg_match('#^/api/apps/([\w.-]+)$#', $path, $m) => $this->app($m[1]),
                $method === 'GET' && $path === '/api/findings' => [200, $this->findings()],
                $method === 'GET' && $path === '/api/report' => $this->report(),
                $method === 'POST' && $path === '/api/advisories' => $this->authed($headers, fn () => $this->ingest($body)),
                $method === 'POST' && (bool) preg_match('#^/api/apps/([\w.-]+)/plan$#', $path, $m) =>
                    $this->authed($headers, fn () => $this->plan($m[1])),
                $method === 'POST' && $path === '/api/canary/verdict' =>
                    $this->authed($headers, fn () => $this->verdict($body)),
                $method === 'POST' && $path === '/api/cost/audit' =>
                    $this->authed($headers, fn () => [200, (new Auditor($this->policy))->audit($this->json($body)['resources'] ?? [])]),
                default => [404, ['error' => "no route for {$method} {$path}"]],
            };
        } catch (\JsonException) {
            return [400, ['error' => 'the body is not valid JSON']];
        } catch (\InvalidArgumentException $e) {
            return [422, ['error' => $e->getMessage()]];
        }
    }

    /**
     * @param array<string,string> $headers
     * @param \Closure(): array{0:int, 1:array<mixed>} $fn
     * @return array{0:int, 1:array<mixed>}
     */
    private function authed(array $headers, \Closure $fn): array
    {
        $given = preg_replace('/^Bearer\s+/i', '', $headers['authorization'] ?? '') ?? '';
        if ($this->token === '' || !hash_equals($this->token, $given)) {
            return [401, ['error' => 'a valid bearer token is required to change the estate']];
        }
        return $fn();
    }

    /** @return array<string,mixed> */
    private function json(string $body): array
    {
        /** @var array<string,mixed> */
        return json_decode($body === '' ? '{}' : $body, true, 64, JSON_THROW_ON_ERROR);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array<string,mixed> */
    private function estate(): array
    {
        $matcher = new Matcher($this->store->advisories($this->now()->format('c')));
        $savings = [];
        foreach ($this->store->findings() as $f) {
            $savings[$f['client_id']] = ($savings[$f['client_id']] ?? 0) + $f['monthly_saving_eur'];
        }
        $openJobs = [];
        foreach ($this->store->jobs() as $j) {
            if (in_array($j['status'], ['open', 'review', 'canary'], true)) {
                $openJobs[$j['app_id']] = ($openJobs[$j['app_id']] ?? 0) + 1;
            }
        }
        $incidents = [];
        foreach ($this->store->incidents() as $i) {
            $incidents[$i['app_id']] = ($incidents[$i['app_id']] ?? 0) + 1;
        }
        $clients = [];
        foreach ($this->store->clients() as $c) {
            $apps = [];
            foreach ($this->store->apps($c['id']) as $a) {
                $exp = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
                foreach ($matcher->exposures($this->store->dependencies($a['id'])) as $e) {
                    $exp[$e['advisory']->severity] = ($exp[$e['advisory']->severity] ?? 0) + 1;
                }
                $apps[] = ['id' => $a['id'], 'name' => $a['name'], 'stack' => $a['stack'], 'provider' => $a['provider'],
                    'exposures' => $exp, 'open_jobs' => $openJobs[$a['id']] ?? 0, 'incidents' => $incidents[$a['id']] ?? 0,
                    'frozen' => $this->frozen($a['freeze_windows'])];
            }
            $clients[] = ['id' => $c['id'], 'name' => $c['name'], 'apps' => $apps,
                'monthly_savings_eur' => round($savings[$c['id']] ?? 0, 2)];
        }
        return ['as_of' => $this->now()->format('c'), 'clients' => $clients];
    }

    /** @param list<array{from:string,to:string}>|null $windows */
    private function frozen(?array $windows): bool
    {
        foreach ($windows ?? [] as $w) {
            if ($this->now() >= new \DateTimeImmutable($w['from']) && $this->now() < new \DateTimeImmutable($w['to'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function app(string $id): array
    {
        $a = $this->store->app($id);
        if ($a === null) {
            return [404, ['error' => "no app {$id}"]];
        }
        $matcher = new Matcher($this->store->advisories($this->now()->format('c')));
        $deps = $this->store->dependencies($id);
        $exposures = array_map(fn ($e) => ['advisory' => $e['advisory']->id, 'severity' => $e['advisory']->severity,
            'package' => $e['dependency']['name'], 'version' => $e['dependency']['version'],
            'fixed_in' => $e['advisory']->fixes()], $matcher->exposures($deps));
        return [200, ['app' => ['id' => $a['id'], 'client' => $a['client_id'], 'name' => $a['name'], 'stack' => $a['stack'],
            'provider' => $a['provider'], 'url' => $a['url'], 'freeze' => $a['freeze_windows'],
            'frozen' => $this->frozen($a['freeze_windows'])],
            'dependencies' => $deps, 'exposures' => $exposures, 'jobs' => $this->store->jobs($id, 50),
            'incidents' => $this->store->incidents($id)]];
    }

    /** @return list<array<string,mixed>> */
    private function findings(): array
    {
        return $this->store->findings();
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function report(): array
    {
        $raw = $this->report !== null ? ($this->report)() : null;
        if ($raw === null) {
            return [404, ['error' => 'no study report yet: run `make study`']];
        }
        return [200, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function ingest(string $body): array
    {
        $in = $this->json($body);
        $list = array_is_list($in) ? $in : [$in];
        foreach ($list as $osv) {
            foreach (['id', 'ecosystem', 'package', 'ranges'] as $k) {
                if (!isset($osv[$k])) {
                    return [422, ['error' => "advisory without {$k}"]];
                }
            }
            $this->store->putAdvisory($osv);
        }
        return [202, ['stored' => count($list)]];
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function plan(string $id): array
    {
        $a = $this->store->app($id);
        if ($a === null) {
            return [404, ['error' => "no app {$id}"]];
        }
        $deps = array_map(fn ($d) => $d + ['available' => []], $this->store->dependencies($id));
        $jobs = (new Planner($this->policy))->plan(
            ['id' => $id, 'freeze' => $a['freeze_windows'], 'dependencies' => $deps],
            $this->store->advisories($this->now()->format('c')),
            $this->now()
        );
        return [200, ['jobs' => $jobs]];
    }

    /** @return array{0:int, 1:array<mixed>} */
    private function verdict(string $body): array
    {
        $in = $this->json($body);
        if (!isset($in['baseline']['requests'], $in['canary']['requests'])) {
            return [422, ['error' => 'baseline and canary need requests and errors']];
        }
        return [200, (new CanaryJudge($this->policy))->judge($in['baseline'], $in['canary'], (int) ($in['extensions'] ?? 0))];
    }
}
