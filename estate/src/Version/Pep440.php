<?php

declare(strict_types=1);

namespace Estate\Version;

/** PEP 440 versions as PyPI orders them: epochs, release segments, a/b/rc, post and dev releases. */
final class Pep440
{
    private const PRE = ['a' => 0, 'alpha' => 0, 'b' => 1, 'beta' => 1, 'c' => 2, 'rc' => 2, 'pre' => 2, 'preview' => 2];

    /**
     * @return array{epoch:int, release:list<int>, pre:?array{0:int,1:int}, post:?int, dev:?int}
     */
    public static function parse(string $v): array
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/^v/', '', $v) ?? $v;
        $v = explode('+', $v, 2)[0];
        $re = '/^(?:(\d+)!)?(\d+(?:\.\d+)*)'
            . '(?:[-_.]?(a|alpha|b|beta|c|rc|pre|preview)[-_.]?(\d*))?'
            . '(?:(?:-(\d+))|(?:[-_.]?(?:post|rev|r)[-_.]?(\d*)))?'
            . '(?:[-_.]?dev[-_.]?(\d*))?$/';
        if (!preg_match($re, $v, $m)) {
            throw new \InvalidArgumentException("not a PEP 440 version: {$v}");
        }
        $release = array_map('intval', explode('.', $m[2]));
        while (count($release) > 1 && end($release) === 0) {
            array_pop($release); // 1.0.0 == 1.0 == 1
        }
        $pre = isset($m[3]) && $m[3] !== '' ? [self::PRE[$m[3]], (int) ($m[4] ?? 0)] : null;
        $post = null;
        if (isset($m[5]) && $m[5] !== '') {
            $post = (int) $m[5];
        } elseif (isset($m[6]) && preg_match('/(post|rev|r)/', $v)) {
            $post = (int) $m[6];
        }
        $dev = isset($m[7]) && preg_match('/dev/', $v) ? (int) $m[7] : null;
        return ['epoch' => (int) ($m[1] ?? 0), 'release' => $release, 'pre' => $pre, 'post' => $post, 'dev' => $dev];
    }

    public static function compare(string $a, string $b): int
    {
        return self::key(self::parse($a)) <=> self::key(self::parse($b));
    }

    /**
     * A sortable key (PEP 440's own ordering): a dev release sorts before its pre-releases, pre-releases before the
     * release, the release before its post-releases.
     *
     * @param array{epoch:int, release:list<int>, pre:?array{0:int,1:int}, post:?int, dev:?int} $p
     * @return list<int|float>
     */
    private static function key(array $p): array
    {
        $release = array_pad($p['release'], 6, 0);
        if ($p['pre'] === null && $p['post'] === null && $p['dev'] !== null) {
            $pre = [-INF, 0];
        } elseif ($p['pre'] === null) {
            $pre = [INF, 0];
        } else {
            $pre = $p['pre'];
        }
        $post = $p['post'] ?? -INF;
        $dev = $p['dev'] ?? INF;
        return [$p['epoch'], ...$release, ...$pre, $post, $dev];
    }
}
