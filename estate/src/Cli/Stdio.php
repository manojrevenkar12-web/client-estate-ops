<?php

declare(strict_types=1);

namespace Estate\Cli;

use Estate\Advisory\Advisory;
use Estate\Cost\Auditor;
use Estate\Policy;
use Estate\Update\CanaryJudge;
use Estate\Update\Planner;
use Estate\Uptime\Pager;

/**
 * The product's decisions over JSON lines on stdin and stdout: one request per line, one answer per line. The lab
 * drives every design through this, so the simulation exercises exactly the code that serves the API.
 */
final class Stdio
{
    private Policy $policy;

    public function __construct(private readonly Policy $base, string $design = 'gated')
    {
        $this->policy = $base->design($design);
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    public function run($in, $out): void
    {
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $answer = $this->answer(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                $answer = ['error' => get_class($e) . ': ' . $e->getMessage()];
            }
            fwrite($out, json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            fflush($out);
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array<mixed>
     */
    public function answer(array $r): array
    {
        return match ($r['op'] ?? '') {
            'design' => ['design' => $this->setDesign((string) $r['name'])],
            'plan' => ['jobs' => (new Planner($this->policy))->plan(
                $r['app'],
                array_map(fn ($a) => Advisory::fromArray($a), $r['advisories'] ?? []), new \DateTimeImmutable($r['now'])
            )],
            'plan_many' => ['plans' => $this->planMany($r)],
            'judge' => (new CanaryJudge($this->policy))->judge($r['baseline'], $r['canary'], (int) ($r['extensions'] ?? 0)),
            'pages' => ['pages' => (new Pager($this->policy))->evaluate($r['rounds'], $r['deploys'] ?? [])],
            'audit' => ['findings' => (new Auditor($this->policy))->audit($r['resources'])],
            'ping' => ['pong' => true],
            default => throw new \InvalidArgumentException('unknown op ' . json_encode($r['op'] ?? null)),
        };
    }

    private function setDesign(string $name): string
    {
        $this->policy = $this->base->design($name);
        return $name;
    }

    /**
     * @param array<string,mixed> $r {apps: list<app>, advisories: list<osv>, now}
     * @return array<string, list<array<string,mixed>>>
     */
    private function planMany(array $r): array
    {
        $byPkg = [];
        foreach ($r['advisories'] ?? [] as $a) {
            $adv = Advisory::fromArray($a);
            $byPkg[$adv->ecosystem . ':' . $adv->package][] = $adv;
        }
        return (new Planner($this->policy))->planEstate($r['apps'], $byPkg, new \DateTimeImmutable($r['now']));
    }
}
