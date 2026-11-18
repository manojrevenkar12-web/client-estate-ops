<?php

declare(strict_types=1);

namespace Estate\Tests;

use Estate\Advisory\Advisory;
use Estate\Policy;
use Estate\Update\Planner;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    private function policy(string $design = 'gated'): Policy
    {
        return Policy::load(dirname(__DIR__, 2) . '/config/estate.json')->design($design);
    }

    /** @return array<string,mixed> */
    private function app(array $freeze = []): array
    {
        return ['id' => 'acme-shop', 'freeze' => $freeze, 'dependencies' => [
            ['ecosystem' => 'packagist', 'name' => 'guzzlehttp/guzzle', 'version' => '7.4.4', 'available' => [
                ['version' => '7.4.5', 'released' => '2026-03-01T00:00:00Z'],
                ['version' => '7.8.1', 'released' => '2026-03-02T00:00:00Z'],
                ['version' => '8.0.0', 'released' => '2026-03-02T00:00:00Z'],
                ['version' => '7.9.0-beta1', 'released' => '2026-03-02T00:00:00Z'],
            ]],
            ['ecosystem' => 'npm', 'name' => 'axios', 'version' => '1.6.0', 'available' => [
                ['version' => '1.6.8', 'released' => '2026-03-09T00:00:00Z'],
            ]],
        ]];
    }

    private function advisory(string $severity, string $fixed = '7.4.5'): Advisory
    {
        return Advisory::fromArray(['id' => 'GHSA-test', 'ecosystem' => 'Packagist', 'package' => 'guzzlehttp/guzzle',
            'severity' => $severity, 'published' => '2026-03-03', 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => $fixed]]]]]);
    }

    /** @param list<array<string,mixed>> $jobs */
    private function lane(array $jobs, string $lane): ?array
    {
        foreach ($jobs as $j) {
            if ($j['lane'] === $lane) {
                return $j;
            }
        }
        return null;
    }

    public function testHighAdvisoryGoesFastWithTheSmallestFix(): void
    {
        $wednesday = new \DateTimeImmutable('2026-03-11T09:00:00Z');
        $jobs = (new Planner($this->policy()))->plan($this->app(), [$this->advisory('high')], $wednesday);
        $fast = $this->lane($jobs, 'fast');
        $this->assertNotNull($fast);
        $this->assertSame('7.4.5', $fast['changes'][0]['to'], 'the smallest step that fixes it, not the newest');
        $this->assertSame(['GHSA-test'], $fast['changes'][0]['fixes']);
        $this->assertSame(['traffic_share' => 0.1, 'minutes' => 30], $fast['canary']);
        $this->assertSame(6, $fast['watch_hours']);
        $this->assertNull($this->lane($jobs, 'routine'), 'no routine work on a Wednesday');
    }

    public function testRoutineTuesdayTakesNewestMinorAndSendsMajorsToReview(): void
    {
        $tuesday = new \DateTimeImmutable('2026-03-10T09:00:00Z');
        $jobs = (new Planner($this->policy()))->plan($this->app(), [], $tuesday);
        $routine = $this->lane($jobs, 'routine');
        $this->assertNotNull($routine);
        $to = array_column($routine['changes'], 'to', 'name');
        $this->assertSame('7.8.1', $to['guzzlehttp/guzzle'], 'newest in the same major, never a pre-release');
        $this->assertArrayNotHasKey('axios', $to, 'released one day ago: younger than the minimum age');
        $review = $this->lane($jobs, 'review');
        $this->assertSame('8.0.0', $review['changes'][0]['to']);
        $this->assertIsArray($review['canary'], 'a reviewed major still goes through the canary');
    }

    public function testFreezeHoldsHighButNotCritical(): void
    {
        $freeze = [['from' => '2026-03-01T00:00:00Z', 'to' => '2026-03-31T00:00:00Z']];
        $now = new \DateTimeImmutable('2026-03-11T09:00:00Z');
        $planner = new Planner($this->policy());
        $jobs = $planner->plan($this->app($freeze), [$this->advisory('high')], $now);
        $this->assertNull($this->lane($jobs, 'fast'));
        $this->assertStringContainsString('freeze', $this->lane($jobs, 'held')['held'][0]['reason']);
        $jobs = $planner->plan($this->app($freeze), [$this->advisory('critical')], $now);
        $this->assertNotNull($this->lane($jobs, 'fast'), 'a critical fix ships during a freeze');
    }

    public function testFixOnlyInNewMajorNeedsAPerson(): void
    {
        $jobs = (new Planner($this->policy()))->plan(
            $this->app(),
            [$this->advisory('critical', '8.0.0')],
            new \DateTimeImmutable('2026-03-11T09:00:00Z')
        );
        $this->assertNull($this->lane($jobs, 'fast'));
        $this->assertSame('the fix needs a new major version', $this->lane($jobs, 'review')['reason']);
    }

    public function testAutoMergeTakesEverythingEveryDay(): void
    {
        $jobs = (new Planner($this->policy('auto_merge')))->plan(
            $this->app(),
            [],
            new \DateTimeImmutable('2026-03-11T09:00:00Z')
        );
        $to = array_column($this->lane($jobs, 'routine')['changes'], 'to', 'name');
        $this->assertSame('1.6.8', $to['axios']);
        $this->assertNull($this->lane($jobs, 'review'), 'majors merge without review');
        $this->assertFalse($this->lane($jobs, 'routine')['canary']);
    }

    public function testNoFixReleasedIsHeldWithTheReason(): void
    {
        $adv = $this->advisory('critical', '9.9.9');
        $jobs = (new Planner($this->policy()))->plan($this->app(), [$adv], new \DateTimeImmutable('2026-03-11T09:00:00Z'));
        $this->assertStringContainsString('no released version fixes', $this->lane($jobs, 'held')['held'][0]['reason']);
    }

    public function testYankedSkippedAndYoungFollowUpWaitedFor(): void
    {
        $app = $this->app();
        $app['dependencies'][0]['available'][1]['yanked'] = true; // 7.8.1 withdrawn
        $tuesday = new \DateTimeImmutable('2026-03-10T09:00:00Z');
        $to = array_column($this->lane((new Planner($this->policy()))->plan($app, [], $tuesday), 'routine')['changes'], 'to', 'name');
        $this->assertSame('7.4.5', $to['guzzlehttp/guzzle']);
        $app = $this->app();
        $app['dependencies'][0]['available'][] = ['version' => '7.8.2', 'released' => '2026-03-09T00:00:00Z'];
        $jobs = (new Planner($this->policy()))->plan($app, [], $tuesday);
        $routine = $this->lane($jobs, 'routine');
        $this->assertNotContains(
            'guzzlehttp/guzzle',
            array_column($routine['changes'] ?? [], 'name'),
            '7.8.2 is a day old: wait for it rather than take 7.8.1'
        );
        $app = $this->app();
        $app['dependencies'][0]['skip'] = ['7.8.1'];
        $to = array_column($this->lane((new Planner($this->policy()))->plan($app, [], $tuesday), 'routine')['changes'], 'to', 'name');
        $this->assertSame('7.4.5', $to['guzzlehttp/guzzle'], 'a version that failed for this app is not retried');
    }
}
