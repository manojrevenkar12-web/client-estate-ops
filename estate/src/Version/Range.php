<?php

declare(strict_types=1);

namespace Estate\Version;

/**
 * An affected range in OSV form: a sorted list of events, each "introduced", "fixed" or "last_affected". A version
 * is affected when the latest event at or below it is an introduction (and it is not past a last_affected).
 */
final readonly class Range
{
    /** @param list<array<string,string>> $events */
    public function __construct(public string $ecosystem, public array $events)
    {
    }

    public function affects(string $version): bool
    {
        $affected = false;
        foreach ($this->sorted() as [$kind, $at]) {
            if ($kind === 'introduced') {
                if ($at === '0' || Version::compare($this->ecosystem, $version, $at) >= 0) {
                    $affected = true;
                }
            } elseif ($kind === 'fixed') {
                if (Version::compare($this->ecosystem, $version, $at) >= 0) {
                    $affected = false;
                }
            } elseif ($kind === 'last_affected') {
                if (Version::compare($this->ecosystem, $version, $at) > 0) {
                    $affected = false;
                }
            }
        }
        return $affected;
    }

    /** The fixed versions, oldest first. @return list<string> */
    public function fixes(): array
    {
        $out = [];
        foreach ($this->sorted() as [$kind, $at]) {
            if ($kind === 'fixed') {
                $out[] = $at;
            }
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private function sorted(): array
    {
        $flat = [];
        foreach ($this->events as $event) {
            foreach ($event as $kind => $at) {
                $flat[] = [$kind, (string) $at];
            }
        }
        usort($flat, function (array $x, array $y): int {
            if ($x[1] === '0') {
                return -1;
            }
            if ($y[1] === '0') {
                return 1;
            }
            return Version::compare($this->ecosystem, $x[1], $y[1]);
        });
        return $flat;
    }
}
