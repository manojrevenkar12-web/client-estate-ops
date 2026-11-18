<?php

declare(strict_types=1);

namespace Estate\Tests;

use Estate\Version\Pep440;
use Estate\Version\Range;
use Estate\Version\Semver;
use Estate\Version\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testSemverOrdering(): void
    {
        $sorted = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11',
            '1.0.0-rc.1', '1.0.0', '1.0.1', '1.1.0', '2.0.0'];
        for ($i = 0; $i + 1 < count($sorted); $i++) {
            $this->assertLessThan(0, Semver::compare($sorted[$i], $sorted[$i + 1]), "{$sorted[$i]} < {$sorted[$i + 1]}");
        }
        $this->assertSame(0, Semver::compare('v2.3.0', '2.3'));
        $this->assertSame(0, Semver::compare('5.4.1.0', '5.4.1'), 'Composer four-part versions');
        $this->assertGreaterThan(0, Semver::compare('1.0.0-p1', '1.0.0-rc1'), 'Composer patch releases are stable');
        $this->assertSame(0, Semver::compare('1.2.3+build.5', '1.2.3'));
    }

    public function testPep440Ordering(): void
    {
        $sorted = ['1.0.dev0', '1.0a1', '1.0a2', '1.0b1', '1.0rc1', '1.0', '1.0.post1', '1.0.1', '1.1', '2!0.1'];
        for ($i = 0; $i + 1 < count($sorted); $i++) {
            $this->assertLessThan(0, Pep440::compare($sorted[$i], $sorted[$i + 1]), "{$sorted[$i]} < {$sorted[$i + 1]}");
        }
        $this->assertSame(0, Pep440::compare('1.0.0', '1'));
        $this->assertSame(0, Pep440::compare('4.2.0RC1', '4.2rc1'));
    }

    public function testBumps(): void
    {
        $this->assertSame('patch', Version::bump('packagist', '10.4.1', '10.4.3'));
        $this->assertSame('minor', Version::bump('npm', '4.17.0', '4.18.2'));
        $this->assertSame('major', Version::bump('packagist', '10.4.1', '11.0.0'));
        $this->assertSame('major', Version::bump('npm', '0.27.2', '0.28.0'), '0.x minors may break');
        $this->assertSame('patch', Version::bump('pypi', '4.2.11', '4.2.16'));
        $this->assertSame('downgrade', Version::bump('pypi', '2.0', '1.9'));
        $this->assertTrue(Version::isPrerelease('pypi', '5.0b1'));
        $this->assertFalse(Version::isPrerelease('npm', '5.0.0'));
    }

    public function testRanges(): void
    {
        $r = new Range('packagist', [['introduced' => '0'], ['fixed' => '6.4.2'], ['introduced' => '7.0.0'], ['fixed' => '7.0.2']]);
        $this->assertTrue($r->affects('6.4.1'));
        $this->assertFalse($r->affects('6.4.2'));
        $this->assertFalse($r->affects('6.9.0'), 'between a fix and the next introduction');
        $this->assertTrue($r->affects('7.0.1'));
        $this->assertFalse($r->affects('7.1.0'));
        $this->assertSame(['6.4.2', '7.0.2'], $r->fixes());
        $last = new Range('pypi', [['introduced' => '2.0'], ['last_affected' => '2.3']]);
        $this->assertTrue($last->affects('2.3'));
        $this->assertFalse($last->affects('2.3.1'));
        $this->assertFalse($last->affects('1.9'));
    }
}
