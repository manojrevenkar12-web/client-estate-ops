<?php

declare(strict_types=1);

namespace Estate\Version;

/** Semantic versions as npm and Composer write them: 1.2.3, v1.2.3, 1.2.3.4 (Composer), 1.2.3-beta.2+build. */
final class Semver
{
    /** @return array{release: list<int>, pre: list<string|int>} */
    public static function parse(string $v): array
    {
        $v = trim($v);
        $v = preg_replace('/^[vV=]/', '', $v) ?? $v;
        $v = explode('+', $v, 2)[0];
        if (!preg_match('/^(\d+(?:\.\d+)*)(?:-?([0-9A-Za-z.-]+))?$/', $v, $m)) {
            throw new \InvalidArgumentException("not a version: {$v}");
        }
        $release = array_map('intval', explode('.', $m[1]));
        $pre = [];
        if (isset($m[2]) && $m[2] !== '') {
            foreach (preg_split('/[.-]/', $m[2]) ?: [] as $id) {
                $pre[] = ctype_digit($id) ? (int) $id : strtolower($id);
            }
            // Composer writes stable patch releases as "-p1"/"-patch1": those sort after the release.
            if (is_string($pre[0]) && preg_match('/^(p|pl|patch)\d*$/', $pre[0])) {
                $pre = [];
            }
        }
        return ['release' => $release, 'pre' => $pre];
    }

    public static function compare(string $a, string $b): int
    {
        $x = self::parse($a);
        $y = self::parse($b);
        $n = max(count($x['release']), count($y['release']), 3);
        for ($i = 0; $i < $n; $i++) {
            $d = ($x['release'][$i] ?? 0) <=> ($y['release'][$i] ?? 0);
            if ($d !== 0) {
                return $d;
            }
        }
        // A release sorts after its pre-releases.
        if ($x['pre'] === [] || $y['pre'] === []) {
            return ($x['pre'] === [] ? 1 : 0) - ($y['pre'] === [] ? 1 : 0);
        }
        $n = max(count($x['pre']), count($y['pre']));
        for ($i = 0; $i < $n; $i++) {
            if (!array_key_exists($i, $x['pre'])) {
                return -1;
            }
            if (!array_key_exists($i, $y['pre'])) {
                return 1;
            }
            [$p, $q] = [$x['pre'][$i], $y['pre'][$i]];
            if (is_int($p) && is_int($q)) {
                $d = $p <=> $q;
            } elseif (is_int($p) !== is_int($q)) {
                $d = is_int($p) ? -1 : 1; // numeric identifiers sort before alphanumeric ones
            } else {
                $d = strcmp((string) $p, (string) $q) <=> 0;
            }
            if ($d !== 0) {
                return $d;
            }
        }
        return 0;
    }
}
