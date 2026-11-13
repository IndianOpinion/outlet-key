<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Why a URL got its key: an ordered, read-only list of steps, each with the
 * value after the step and a short note.
 *
 * @implements IteratorAggregate<int, array{step: string, value: string, note: string}>
 */
final class Trace implements IteratorAggregate, Countable
{
    /** @var list<array{step: string, value: string, note: string}> */
    private array $steps;

    /**
     * @param list<array{0: string, 1: string, 2: string}> $steps
     */
    public function __construct(array $steps)
    {
        $out = [];
        foreach ($steps as [$step, $value, $note]) {
            $out[] = ['step' => $step, 'value' => $value, 'note' => $note];
        }
        $this->steps = $out;
    }

    /**
     * @return list<array{step: string, value: string, note: string}>
     */
    public function toArray(): array
    {
        return $this->steps;
    }

    /**
     * Step names in order.
     *
     * @return list<string>
     */
    public function stepNames(): array
    {
        return array_column($this->steps, 'step');
    }

    /**
     * The first step with this name, or null.
     *
     * @return array{step: string, value: string, note: string}|null
     */
    public function find(string $step): ?array
    {
        foreach ($this->steps as $s) {
            if ($s['step'] === $step) {
                return $s;
            }
        }

        return null;
    }

    /** The value of the last step: the key that was produced. */
    public function result(): string
    {
        $last = end($this->steps);

        return $last === false ? '' : $last['value'];
    }

    /** The map version that produced the key. */
    public function mapVersion(): string
    {
        return $this->find('map-version')['value'] ?? '';
    }

    public function count(): int
    {
        return count($this->steps);
    }

    /**
     * @return Traversable<int, array{step: string, value: string, note: string}>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->steps);
    }

    public function __toString(): string
    {
        $width = 0;
        foreach ($this->steps as $s) {
            $width = max($width, strlen($s['step']));
        }
        $lines = [];
        foreach ($this->steps as $s) {
            $line = str_pad($s['step'], $width) . '  ' . ($s['value'] === '' ? "''" : $s['value']);
            if ($s['note'] !== '') {
                $line .= '  (' . $s['note'] . ')';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
    }
}
