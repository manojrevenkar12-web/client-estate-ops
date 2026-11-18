<?php

declare(strict_types=1);

namespace Estate\Tests;

use Estate\Cost\Auditor;
use Estate\Policy;
use Estate\Update\CanaryJudge;
use Estate\Uptime\Pager;
use PHPUnit\Framework\TestCase;

final class DecisionsTest extends TestCase
{
    private function policy(string $design = 'gated'): Policy
    {
        return Policy::load(dirname(__DIR__, 2) . '/config/estate.json')->design($design);
    }

    public function testCanary(): void
    {
        $j = new CanaryJudge($this->policy());
        $base = ['requests' => 20000, 'errors' => 80]; // 0.4%
        $this->assertSame('promote', $j->judge($base, ['requests' => 2000, 'errors' => 9])['verdict']);
        $this->assertSame('rollback', $j->judge($base, ['requests' => 2000, 'errors' => 40])['verdict']);
        $this->assertSame('extend', $j->judge($base, ['requests' => 120, 'errors' => 3])['verdict']);
        $this->assertNotSame(
            'extend',
            $j->judge($base, ['requests' => 120, 'errors' => 3], 2)['verdict'],
            'after the allowed extensions it decides on what there is'
        );
        $this->assertSame('rollback', $j->judge($base, ['requests' => 10, 'errors' => 0, 'health_failed' => true])['verdict']);
        // Significant but tiny on a busy site: below the minimum effect, so it ships.
        $this->assertSame('promote', $j->judge(['requests' => 2000000, 'errors' => 8000], ['requests' => 200000, 'errors' => 880])['verdict']);
        // A quiet site: one error in 48 canary requests is not evidence of anything.
        $this->assertSame('promote', $j->judge(['requests' => 430, 'errors' => 2], ['requests' => 48, 'errors' => 1], 2)['verdict']);
        $this->assertSame('rollback', $j->judge(['requests' => 430, 'errors' => 2], ['requests' => 48, 'errors' => 6], 2)['verdict']);
        $this->assertEqualsWithDelta(0.0755, CanaryJudge::poissonTail(2, 0.4502), 0.001);
        $this->assertEqualsWithDelta(2.326, CanaryJudge::zFor(0.01), 0.01);
        $this->assertEqualsWithDelta(1.645, CanaryJudge::zFor(0.05), 0.01);
    }

    public function testPagerIgnoresOneRegionBlipsAndRollsBackDeploys(): void
    {
        $rounds = [
            ['t' => '2026-03-11T10:00:00Z', 'failed' => ['eu-west']],
            ['t' => '2026-03-11T10:01:00Z', 'failed' => []],
            ['t' => '2026-03-11T11:00:00Z', 'failed' => ['eu-west', 'us-east']],
            ['t' => '2026-03-11T11:01:00Z', 'failed' => ['eu-west', 'us-east', 'ap-south']],
            ['t' => '2026-03-11T11:02:00Z', 'failed' => ['eu-west', 'us-east']],
            ['t' => '2026-03-11T11:03:00Z', 'failed' => []],
        ];
        $pages = (new Pager($this->policy()))->evaluate($rounds);
        $this->assertCount(1, $pages, 'one blip and one real outage');
        $this->assertSame('2026-03-11T11:01:00Z', $pages[0]['at']);
        $this->assertSame('2026-03-11T11:00:00Z', $pages[0]['opened']);
        $this->assertCount(2, (new Pager($this->policy('gated_naive_pager')))->evaluate($rounds), 'any failure pages');

        $deploys = [['from' => '2026-03-11T10:40:00Z', 'to' => '2026-03-11T10:45:00Z', 'job' => 'job-7']];
        $after = (new Pager($this->policy()))->evaluate($rounds, $deploys);
        $this->assertSame('rollback', $after[0]['action']);
        $this->assertSame('job-7', $after[0]['job']);
    }

    public function testCostAuditGuards(): void
    {
        $resources = [
            ['id' => 'vm-xmas', 'client' => 'c1', 'provider' => 'aws', 'kind' => 'vm', 'monthly_eur' => 180, 'cpu_p95' => 1,
                'requests_14d' => 0, 'last_year_requests_same_weeks' => 250000],
            ['id' => 'vm-old', 'client' => 'c1', 'provider' => 'aws', 'kind' => 'vm', 'monthly_eur' => 120, 'cpu_p95' => 1,
                'requests_14d' => 0, 'last_year_requests_same_weeks' => 0],
            ['id' => 'stg-1', 'client' => 'c2', 'provider' => 'gcp', 'kind' => 'staging', 'monthly_eur' => 90, 'cpu_p95' => 1,
                'requests_14d' => 0],
            ['id' => 'vol-1', 'client' => 'c2', 'provider' => 'gcp', 'kind' => 'volume', 'monthly_eur' => 40, 'attached' => false],
            ['id' => 'vm-big', 'client' => 'c2', 'provider' => 'gcp', 'kind' => 'vm', 'monthly_eur' => 300, 'cpu_p95' => 9,
                'cpu_p99' => 35, 'requests_14d' => 50000],
            ['id' => 'vm-spiky', 'client' => 'c2', 'provider' => 'gcp', 'kind' => 'vm', 'monthly_eur' => 300, 'cpu_p95' => 9,
                'cpu_p99' => 97, 'requests_14d' => 50000],
            ['id' => 'vm-kept', 'client' => 'c2', 'provider' => 'gcp', 'kind' => 'vm', 'monthly_eur' => 300, 'cpu_p95' => 0,
                'requests_14d' => 0, 'tags' => ['keep' => true]],
        ];
        $by = array_column((new Auditor($this->policy()))->audit($resources), null, 'resource');
        $this->assertSame('keep', $by['vm-xmas']['action'], 'seasonal campaign site');
        $this->assertSame('delete', $by['vm-old']['action']);
        $this->assertSame('schedule', $by['stg-1']['action']);
        $this->assertSame('snapshot_and_delete', $by['vol-1']['action']);
        $this->assertSame('downsize', $by['vm-big']['action']);
        $this->assertArrayNotHasKey('vm-kept', $by);
        $this->assertArrayNotHasKey('vm-spiky', $by, 'busy at p99: not downsized');
        $naive = array_column((new Auditor($this->policy('naive_cost')))->audit($resources), null, 'resource');
        $this->assertSame('delete', $naive['vm-xmas']['action'], 'the naive script deletes the campaign site');
    }
}
