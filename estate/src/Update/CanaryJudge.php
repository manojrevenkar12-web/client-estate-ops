<?php

declare(strict_types=1);

namespace Estate\Update;

use Estate\Policy;

/**
 * Promote, extend or roll back a canary by comparing its error rate with the stable fleet's over the same minutes.
 * A one-sided two-proportion z-test at the configured alpha, plus a minimum effect so that a tiny real difference
 * on a busy site does not roll back a harmless update. Too few requests extends the canary; after the allowed
 * extensions it is decided on what there is.
 */
final class CanaryJudge
{
    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * @param array{requests:int, errors:int} $baseline
     * @param array{requests:int, errors:int, health_failed?:bool} $canary
     * @return array{verdict:string, reason:string, z:float, extra_error_rate:float}
     */
    public function judge(array $baseline, array $canary, int $extensions = 0): array
    {
        $c = $this->policy->get('canary');
        if (!empty($canary['health_failed'])) {
            return ['verdict' => 'rollback', 'reason' => 'health check failed on the canary', 'z' => 99.0,
                'extra_error_rate' => 1.0];
        }
        $n1 = max(1, $baseline['requests']);
        $n2 = $canary['requests'];
        if ($n2 < (int) $c['min_requests'] && $extensions < (int) $c['max_extensions']) {
            return ['verdict' => 'extend', 'reason' => "only {$n2} canary requests", 'z' => 0.0, 'extra_error_rate' => 0.0];
        }
        $n2 = max(1, $n2);
        $p1 = $baseline['errors'] / $n1;
        $p2 = $canary['errors'] / $n2;
        $pool = ($baseline['errors'] + $canary['errors']) / ($n1 + $n2);
        $se = sqrt(max($pool * (1 - $pool), 1e-12) * (1 / $n1 + 1 / $n2));
        $z = ($p2 - $p1) / $se;
        $extra = $p2 - $p1;
        $alpha = (float) $c['alpha'];
        $expected = $n2 * max($p1, 1 / $n1);
        // Few expected errors (a quiet site): the normal approximation lies, so use the exact Poisson tail.
        $significant = $expected < 10
            ? self::poissonTail($canary['errors'], $expected) < $alpha
            : $z > self::zFor($alpha);
        if ($significant && $extra >= (float) $c['min_extra_error_rate']) {
            return ['verdict' => 'rollback', 'reason' => sprintf(
                'canary errors %.2f%% vs %.2f%% (z %.1f)',
                100 * $p2,
                100 * $p1,
                $z
            ), 'z' => $z, 'extra_error_rate' => $extra];
        }
        return ['verdict' => 'promote', 'reason' => sprintf('canary errors %.2f%% vs %.2f%%', 100 * $p2, 100 * $p1),
            'z' => $z, 'extra_error_rate' => $extra];
    }

    /** P(X >= k) for X ~ Poisson(lambda). */
    public static function poissonTail(int $k, float $lambda): float
    {
        if ($k <= 0) {
            return 1.0;
        }
        $term = exp(-$lambda);
        $cdf = $term;
        for ($i = 1; $i < $k; $i++) {
            $term *= $lambda / $i;
            $cdf += $term;
        }
        return max(0.0, 1.0 - $cdf);
    }

    /** Upper-tail critical value of the standard normal (Acklam's approximation of the inverse CDF). */
    public static function zFor(float $alpha): float
    {
        $p = 1 - $alpha;
        $a = [-39.69683028665376, 220.9460984245205, -275.9285104469687, 138.3577518672690, -30.66479806614716, 2.506628277459239];
        $b = [-54.47609879822406, 161.5858368580409, -155.6989798598866, 66.80131188771972, -13.28068155288572];
        $cc = [-0.007784894002430293, -0.3223964580411365, -2.400758277161838, -2.549732539343734, 4.374664141464968, 2.938163982698783];
        $d = [0.007784695709041462, 0.3224671290700398, 2.445134137142996, 3.754408661907416];
        if ($p > 0.97575) {
            $q = sqrt(-2 * log(1 - $p));
            return -((((($cc[0] * $q + $cc[1]) * $q + $cc[2]) * $q + $cc[3]) * $q + $cc[4]) * $q + $cc[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        $q = $p - 0.5;
        $r = $q * $q;
        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}
