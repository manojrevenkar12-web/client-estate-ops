<?php

declare(strict_types=1);

namespace Estate\Uptime;

use Estate\Policy;

/**
 * Turns uptime probe rounds into pages. In "corroborated" mode an incident opens only when at least N regions fail
 * in the same round for M consecutive rounds, so one region's network blip pages nobody; a failure inside a deploy
 * window is attributed to the deploy and answered with a rollback instead of a page. In "any" mode (the common
 * default of uptime tools) every failed probe pages.
 */
final class Pager
{
    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * @param list<array{t:string, failed:list<string>}> $rounds  in time order; failed = regions whose probe failed
     * @param list<array{from:string, to:string, job:string}> $deploys
     * @return list<array{at:string, opened:string, action:string, cause:string, job?:string}>
     */
    public function evaluate(array $rounds, array $deploys = []): array
    {
        $p = $this->policy->get('pager');
        $any = $p['mode'] === 'any';
        $needRegions = $any ? 1 : (int) $p['regions_required'];
        $needRounds = $any ? 1 : (int) $p['consecutive_rounds'];
        $out = [];
        $streak = 0;
        $opened = null;
        $open = false;
        foreach ($rounds as $r) {
            $bad = count(array_unique($r['failed'])) >= $needRegions;
            if (!$bad) {
                $streak = 0;
                $opened = null;
                $open = false;
                continue;
            }
            $streak++;
            $opened ??= $r['t'];
            if ($open || $streak < $needRounds) {
                continue;
            }
            $open = true;
            $deploy = $this->deployAt($deploys, $r['t']);
            if ($deploy !== null && !$any) {
                $out[] = ['at' => $r['t'], 'opened' => $opened, 'action' => 'rollback', 'cause' => 'deploy',
                    'job' => $deploy['job']];
            } else {
                $out[] = ['at' => $r['t'], 'opened' => $opened, 'action' => 'page', 'cause' => 'outage'];
            }
        }
        return $out;
    }

    /**
     * @param list<array{from:string, to:string, job:string}> $deploys
     * @return array{from:string, to:string, job:string}|null
     */
    private function deployAt(array $deploys, string $t): ?array
    {
        $at = new \DateTimeImmutable($t);
        $window = (int) $this->policy->get('pager.deploy_window_minutes');
        foreach ($deploys as $d) {
            $from = new \DateTimeImmutable($d['from']);
            if ($at >= $from && $at <= $from->modify("+{$window} minutes")) {
                return $d;
            }
        }
        return null;
    }
}
