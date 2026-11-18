<?php

declare(strict_types=1);

namespace Estate\Cost;

use Estate\Policy;

/**
 * Finds cloud spend across client accounts that can go, with the evidence. Guarded mode keeps what looks idle but
 * is seasonal (a campaign site that was busy in the same weeks last year), schedules idle staging instead of
 * deleting it, snapshots before deleting a volume, and never touches a resource tagged keep. Naive mode is the
 * common script: anything idle for two weeks is deleted.
 */
final class Auditor
{
    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * @param list<array<string,mixed>> $resources {id, client, provider, kind: vm|volume|snapshot|db|staging,
     *        monthly_eur, cpu_p95, requests_14d, attached?, age_days?, last_year_requests_same_weeks?, tags?}
     * @return list<array{resource:string, client:string, action:string, monthly_saving_eur:float, reason:string}>
     */
    public function audit(array $resources): array
    {
        $c = $this->policy->get('cost');
        $naive = $c['mode'] === 'naive';
        $out = [];
        foreach ($resources as $r) {
            $tags = $r['tags'] ?? [];
            $eur = (float) $r['monthly_eur'];
            $idle = (float) ($r['cpu_p95'] ?? 0) < (float) $c['idle_cpu_p95'] && (int) ($r['requests_14d'] ?? 0) === 0;
            $finding = null;
            if ($naive) {
                if ($idle || ($r['kind'] === 'volume' && empty($r['attached']))) {
                    $finding = ['delete', $eur, 'idle for 14 days'];
                }
            } elseif (($tags['keep'] ?? false) === true) {
                continue;
            } elseif ($r['kind'] === 'volume' && empty($r['attached'])) {
                $finding = ['snapshot_and_delete', $eur * 0.8, 'unattached volume; snapshot kept for 30 days'];
            } elseif (
                $r['kind'] === 'snapshot' && (int) ($r['age_days'] ?? 0) > (int) $c['snapshot_age_days']
                && empty($r['source_exists'])
            ) {
                $finding = ['delete', $eur, 'snapshot of a volume that no longer exists, older than '
                    . $c['snapshot_age_days'] . ' days'];
            } elseif ($idle && $this->seasonal($r, (float) $c['seasonal_ratio'])) {
                $finding = ['keep', 0.0, 'idle now, but busy in the same weeks last year (seasonal)'];
            } elseif ($idle && ($r['kind'] === 'staging' || ($tags['env'] ?? '') === 'staging')) {
                $finding = ['schedule', $eur * (float) $c['schedule_saving_share'], 'idle staging: stop nights and weekends'];
            } elseif ($idle) {
                $finding = ['delete', $eur, 'no traffic and no CPU for 14 days, not seasonal'];
            } elseif (
                $r['kind'] === 'vm' && (float) ($r['cpu_p95'] ?? 100) < (float) $c['oversized_cpu_p95']
                && (float) ($r['cpu_p99'] ?? 100) < (float) $c['downsize_max_cpu_p99']
            ) { // spiky VMs keep their size
                $finding = ['downsize', $eur * (float) $c['downsize_saving_share'], sprintf(
                    'p95 CPU %.0f%%: one size down',
                    (float) $r['cpu_p95']
                )];
            }
            if ($finding !== null) {
                $out[] = ['resource' => (string) $r['id'], 'client' => (string) $r['client'], 'action' => $finding[0],
                    'monthly_saving_eur' => round($finding[1], 2), 'reason' => $finding[2]];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    private function seasonal(array $r, float $ratio): bool
    {
        $last = (int) ($r['last_year_requests_same_weeks'] ?? 0);
        return $last > 0 && $last >= $ratio * max(1, (int) ($r['requests_14d'] ?? 0)) && $last > 1000;
    }
}
