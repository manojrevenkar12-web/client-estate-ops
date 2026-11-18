<?php

declare(strict_types=1);

namespace Estate\Version;

/**
 * Version ordering per ecosystem. Packagist and npm follow semantic versioning (Composer also accepts a fourth
 * number and a leading "v"); PyPI follows PEP 440. Every comparison in the product goes through here.
 */
final class Version
{
    public const PACKAGIST = 'packagist';
    public const NPM = 'npm';
    public const PYPI = 'pypi';

    /** @return int negative, zero or positive, like strcmp */
    public static function compare(string $ecosystem, string $a, string $b): int
    {
        return match ($ecosystem) {
            self::PYPI => Pep440::compare($a, $b),
            self::PACKAGIST, self::NPM => Semver::compare($a, $b),
            default => throw new \InvalidArgumentException("unknown ecosystem {$ecosystem}"),
        };
    }

    /** The kind of change from one version to another: major, minor, patch or none (or downgrade). */
    public static function bump(string $ecosystem, string $from, string $to): string
    {
        $cmp = self::compare($ecosystem, $from, $to);
        if ($cmp === 0) {
            return 'none';
        }
        if ($cmp > 0) {
            return 'downgrade';
        }
        [$fa, $fb] = [self::release($ecosystem, $from), self::release($ecosystem, $to)];
        if ($fa[0] !== $fb[0]) {
            return 'major';
        }
        // Below 1.0 a minor change is allowed to break (semver item 4): treat 0.x minors as major.
        if ($fa[0] === 0 && $fa[1] !== $fb[1]) {
            return 'major';
        }
        return $fa[1] !== $fb[1] ? 'minor' : 'patch';
    }

    /** @return array{0:int,1:int,2:int} */
    public static function release(string $ecosystem, string $v): array
    {
        $r = $ecosystem === self::PYPI ? Pep440::parse($v)['release'] : Semver::parse($v)['release'];
        return [$r[0] ?? 0, $r[1] ?? 0, $r[2] ?? 0];
    }

    public static function isPrerelease(string $ecosystem, string $v): bool
    {
        if ($ecosystem === self::PYPI) {
            $p = Pep440::parse($v);
            return $p['pre'] !== null || $p['dev'] !== null;
        }
        return Semver::parse($v)['pre'] !== [];
    }
}
