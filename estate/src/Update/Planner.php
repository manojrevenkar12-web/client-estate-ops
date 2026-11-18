<?php

declare(strict_types=1);

namespace Estate\Update;

use Estate\Advisory\Advisory;
use Estate\Advisory\Matcher;
use Estate\Policy;
use Estate\Version\Version;

/**
 * Decides which dependency updates each delivered app gets today and through which lane:
 *  - fast: a fix for a critical or high advisory, as small a step as fixes it, today (also during a client's freeze
 *    for critical ones);
 *  - routine: on the routine weekday, the newest non-major release of each package that is old enough;
 *  - review: a new major version, or a fix that only exists in one, waits for a person.
 */
final class Planner
{
    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * @param array<string,mixed> $app {id, freeze?: list<{from,to}>, dependencies: list<{ecosystem,name,version,
     *                                  available: list<{version, released}>}>}
     * @param list<Advisory> $advisories
     * @return list<array<string,mixed>> jobs
     */
    public function plan(array $app, array $advisories, \DateTimeImmutable $now): array
    {
        $u = $this->policy->get('updates');
        $frozen = ($u['respect_freeze'] ?? true) && $this->frozen($app['freeze'] ?? [], $now);
        $fastSev = $u['fast_lane_severities'];
        $freezeSev = $u['fast_lane_in_freeze'];
        $routineDay = (int) $u['routine_weekday']; // 0: every day
        $isRoutineDay = $routineDay === 0 || (int) $now->format('N') === $routineDay;
        $minAge = (int) $u['min_release_age_days'];

        $fast = [];
        $routine = [];
        $review = [];
        $held = [];
        foreach ($app['dependencies'] as $dep) {
            $eco = $dep['ecosystem'];
            $mine = array_values(array_filter($advisories, fn (Advisory $a) => $a->ecosystem === strtolower($eco)
                && $a->package === strtolower($dep['name'])));
            $exposed = array_map(fn ($e) => $e['advisory'], (new Matcher($mine))->exposures([$dep]));
            $candidates = $this->candidates($dep, $mine, $now);
            if ($exposed !== []) {
                $worst = $this->worst($exposed);
                $fix = $this->smallestFix($dep, $candidates, $exposed);
                if ($fix === null) {
                    $held[] = ['dependency' => $dep['name'], 'reason' => 'no released version fixes '
                        . implode(', ', array_map(fn ($a) => $a->id, $exposed))];
                    continue;
                }
                $change = $this->change($dep, $fix, $exposed) + ['severity' => $worst];
                if ($change['bump'] === 'major' && $u['majors'] === 'review') {
                    $review[] = $change + ['reason' => 'the fix needs a new major version'];
                } elseif (in_array($worst, $fastSev, true) && (!$frozen || in_array($worst, $freezeSev, true))) {
                    $fast[] = $change;
                } elseif ($frozen) {
                    $held[] = ['dependency' => $dep['name'], 'reason' => "{$worst} advisory held for the client's freeze"];
                } elseif ($isRoutineDay) {
                    $routine[] = $change;
                }
                continue;
            }
            if (!$isRoutineDay || $frozen) {
                continue;
            }
            $aged = array_values(array_filter($candidates, fn ($c) => $this->ageDays($c, $now) >= $minAge));
            $sameMajor = array_values(array_filter($aged, fn ($c) => Version::bump($eco, $dep['version'], $c['version'])
                !== 'major'));
            // A newer release in the same line that is still too young usually means the aged one had a problem
            // worth a quick follow-up: wait for the follow-up to age instead of taking the release it replaced.
            $youngerFix = array_filter($candidates, fn ($c) => $this->ageDays($c, $now) < $minAge
                && Version::bump($eco, $dep['version'], $c['version']) !== 'major');
            if ($sameMajor !== [] && $youngerFix === []) {
                $routine[] = $this->change($dep, end($sameMajor), []);
            }
            $majors = array_values(array_filter($aged, fn ($c) => Version::bump($eco, $dep['version'], $c['version'])
                === 'major'));
            if ($majors !== []) {
                $c = $this->change($dep, end($majors), []);
                if ($u['majors'] === 'review') {
                    $review[] = $c + ['reason' => 'new major version'];
                } else {
                    $routine[] = $c;
                }
            }
        }

        $jobs = [];
        if ($fast !== []) {
            $jobs[] = $this->job($app['id'], 'fast', $fast);
        }
        foreach (array_chunk($routine, max(1, (int) $u['max_changes_per_job'])) as $chunk) {
            $jobs[] = $this->job($app['id'], 'routine', $chunk);
        }
        foreach ($review as $r) {
            $jobs[] = $this->job($app['id'], 'review', [$r]) + ['reason' => $r['reason']];
        }
        if ($held !== []) {
            $jobs[] = ['app' => $app['id'], 'lane' => 'held', 'changes' => [], 'held' => $held, 'canary' => false];
        }
        return $jobs;
    }

    /**
     * Plans the whole estate and spends the week's review budget where it matters: majors that carry a critical or
     * high fix are always reviewed; other majors (security fixes first, then the furthest behind) only up to the
     * budget. The rest wait for a later week.
     *
     * @param list<array<string,mixed>> $apps
     * @param array<string, list<Advisory>> $byPackage advisories keyed by "ecosystem:package"
     * @return array<string, list<array<string,mixed>>>
     */
    public function planEstate(array $apps, array $byPackage, \DateTimeImmutable $now): array
    {
        $out = [];
        $optional = [];
        foreach ($apps as $app) {
            $mine = [];
            foreach ($app['dependencies'] as $d) {
                array_push($mine, ...($byPackage[strtolower($d['ecosystem']) . ':' . strtolower($d['name'])] ?? []));
            }
            $out[$app['id']] = $this->plan($app, $mine, $now);
            foreach ($out[$app['id']] as $i => $job) {
                $sev = $job['changes'][0]['severity'] ?? null;
                if ($job['lane'] === 'review' && !in_array($sev, $this->policy->get('updates.fast_lane_severities'), true)) {
                    $optional[] = [$app['id'], $i, $job];
                }
            }
        }
        $budget = (int) ($this->policy->get('updates')['review_budget_per_week'] ?? PHP_INT_MAX);
        usort($optional, function (array $x, array $y): int {
            $gap = fn (array $j) => Version::release($j['changes'][0]['ecosystem'], $j['changes'][0]['to'])[0]
                - Version::release($j['changes'][0]['ecosystem'], $j['changes'][0]['from'])[0];
            $security = fn (array $j) => $j['changes'][0]['fixes'] === [] ? 0 : 1;
            return [$security($y[2]), $gap($y[2]), $x[0], $x[2]['changes'][0]['name']]
                <=> [$security($x[2]), $gap($x[2]), $y[0], $y[2]['changes'][0]['name']];
        });
        foreach (array_slice($optional, $budget) as [$app, $i]) {
            $out[$app][$i] = ['app' => $app, 'lane' => 'deferred', 'changes' => $out[$app][$i]['changes'], 'canary' => false,
                'reason' => 'the review budget for this week is spent'];
        }
        return $out;
    }

    /** @param list<array{from:string,to:string}> $windows */
    private function frozen(array $windows, \DateTimeImmutable $now): bool
    {
        foreach ($windows as $w) {
            if ($now >= new \DateTimeImmutable($w['from']) && $now < new \DateTimeImmutable($w['to'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Released, stable, newer versions that no advisory affects, oldest first.
     *
     * @param array<string,mixed> $dep
     * @param list<Advisory> $advisories
     * @return list<array{version:string, released:string}>
     */
    private function candidates(array $dep, array $advisories, \DateTimeImmutable $now): array
    {
        $eco = $dep['ecosystem'];
        $out = [];
        foreach ($dep['available'] ?? [] as $c) {
            if (Version::compare($eco, $c['version'], $dep['version']) <= 0 || Version::isPrerelease($eco, $c['version'])) {
                continue;
            }
            if (new \DateTimeImmutable($c['released']) > $now || !empty($c['yanked'])
                || in_array($c['version'], $dep['skip'] ?? [], true)) {
                continue; // not out yet, withdrawn by its maintainers, or already failed for this app
            }
            foreach ($advisories as $a) {
                if ($a->affects($c['version'])) {
                    continue 2;
                }
            }
            $out[] = $c;
        }
        usort($out, fn ($x, $y) => Version::compare($eco, $x['version'], $y['version']));
        return $out;
    }

    /**
     * The smallest step that fixes everything: same major if possible.
     *
     * @param array<string,mixed> $dep
     * @param list<array{version:string, released:string}> $candidates
     * @param list<Advisory> $exposed
     * @return array{version:string, released:string}|null
     */
    private function smallestFix(array $dep, array $candidates, array $exposed): ?array
    {
        foreach ($candidates as $c) {
            foreach ($exposed as $a) {
                if ($a->affects($c['version'])) {
                    continue 2;
                }
            }
            return $c; // candidates are sorted and already exclude every known affected version
        }
        return null;
    }

    /** @param list<Advisory> $advisories */
    private function worst(array $advisories): string
    {
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $w = 'low';
        foreach ($advisories as $a) {
            if (($rank[$a->severity] ?? 1) > $rank[$w]) {
                $w = $a->severity;
            }
        }
        return $w;
    }

    /** @param array{version:string, released:string} $c */
    private function ageDays(array $c, \DateTimeImmutable $now): float
    {
        return ($now->getTimestamp() - (new \DateTimeImmutable($c['released']))->getTimestamp()) / 86400;
    }

    /**
     * @param array<string,mixed> $dep
     * @param array{version:string, released:string} $to
     * @param list<Advisory> $fixes
     * @return array<string,mixed>
     */
    private function change(array $dep, array $to, array $fixes): array
    {
        return [
            'ecosystem' => $dep['ecosystem'],
            'name' => $dep['name'],
            'from' => $dep['version'],
            'to' => $to['version'],
            'bump' => Version::bump($dep['ecosystem'], $dep['version'], $to['version']),
            'fixes' => array_map(fn (Advisory $a) => $a->id, $fixes),
        ];
    }

    /**
     * @param list<array<string,mixed>> $changes
     * @return array<string,mixed>
     */
    private function job(string $app, string $lane, array $changes): array
    {
        return [
            'app' => $app,
            'lane' => $lane,
            'changes' => $changes,
            'canary' => (bool) $this->policy->get('updates.canary') ? [
                'traffic_share' => (float) $this->policy->get('canary.traffic_share'),
                'minutes' => (int) $this->policy->get($lane === 'review' ? 'canary.review_minutes' : 'canary.minutes'),
            ] : false,
            // After a full rollout, compare the next hours of production errors with the hours before.
            'watch_hours' => (int) ($this->policy->get('watch')['hours'] ?? 0),
        ];
    }
}
