<?php

declare(strict_types=1);

namespace Estate\Advisory;

/** Finds which installed dependencies an advisory affects. */
final class Matcher
{
    /** @var array<string, list<Advisory>> keyed by "ecosystem:package" */
    private array $byPackage = [];

    /** @param iterable<Advisory> $advisories */
    public function __construct(iterable $advisories)
    {
        foreach ($advisories as $a) {
            $this->byPackage[$a->ecosystem . ':' . $a->package][] = $a;
        }
    }

    /**
     * @param list<array{ecosystem:string, name:string, version:string}> $deps
     * @return list<array{advisory:Advisory, dependency:array{ecosystem:string, name:string, version:string}}>
     */
    public function exposures(array $deps): array
    {
        $out = [];
        foreach ($deps as $d) {
            foreach ($this->byPackage[strtolower($d['ecosystem']) . ':' . strtolower($d['name'])] ?? [] as $a) {
                if ($a->affects($d['version'])) {
                    $out[] = ['advisory' => $a, 'dependency' => $d];
                }
            }
        }
        return $out;
    }
}
