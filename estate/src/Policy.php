<?php

declare(strict_types=1);

namespace Estate;

/** The product's settings (config/estate.json). The simulated world never reads this file. */
final readonly class Policy
{
    /** @param array<string,mixed> $c */
    public function __construct(public array $c)
    {
    }

    public static function load(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        /** @var array<string,mixed> $c */
        $c = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        return new self($c);
    }

    public static function default(): self
    {
        return self::load(dirname(__DIR__, 2) . '/config/estate.json');
    }

    /** The base settings with a design's overlay applied (lists are replaced, maps are merged). */
    public function design(string $name): self
    {
        $designs = $this->c['designs'] ?? [];
        if (!array_key_exists($name, $designs)) {
            throw new \OutOfBoundsException("unknown design {$name}");
        }
        return new self(self::merge($this->c, $designs[$name]));
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && !array_is_list($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    public function get(string $dotted): mixed
    {
        $v = $this->c;
        foreach (explode('.', $dotted) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                throw new \OutOfBoundsException("no setting {$dotted}");
            }
            $v = $v[$k];
        }
        return $v;
    }
}
