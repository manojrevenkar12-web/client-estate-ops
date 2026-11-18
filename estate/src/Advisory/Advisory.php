<?php

declare(strict_types=1);

namespace Estate\Advisory;

use Estate\Version\Range;

/** A security advisory in OSV shape, reduced to what the estate needs. */
final readonly class Advisory
{
    /** @param list<Range> $ranges */
    public function __construct(
        public string $id,
        public string $ecosystem,
        public string $package,
        public string $severity, // critical, high, medium, low
        public string $published, // ISO date
        public array $ranges,
    ) {
    }

    /** @param array<string,mixed> $a OSV-like: id, ecosystem, package, severity, published, ranges: [{events: [...]}] */
    public static function fromArray(array $a): self
    {
        $eco = strtolower((string) $a['ecosystem']);
        $ranges = [];
        foreach ($a['ranges'] ?? [] as $r) {
            $ranges[] = new Range($eco, $r['events']);
        }
        return new self(
            (string) $a['id'],
            $eco,
            strtolower((string) $a['package']),
            strtolower((string) ($a['severity'] ?? 'medium')),
            (string) ($a['published'] ?? ''),
            $ranges
        );
    }

    public function affects(string $version): bool
    {
        foreach ($this->ranges as $r) {
            if ($r->affects($version)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> every fixed version in any range */
    public function fixes(): array
    {
        $out = [];
        foreach ($this->ranges as $r) {
            array_push($out, ...$r->fixes());
        }
        return $out;
    }
}
