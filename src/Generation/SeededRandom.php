<?php

namespace AISeeder\Generation;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * An isolated, seeded random source. Unlike mt_rand(), it shares no global state,
 * so application code can't disturb the sequence and each table gets its own stream.
 */
final class SeededRandom
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * An independent stream derived from a base seed and a label (e.g. a table name).
     */
    public static function derive(int $seed, string $label): self
    {
        return new self(self::deriveSeed($seed, $label));
    }

    public static function deriveSeed(int $seed, string $label): int
    {
        return (int) (hexdec(substr(hash('xxh3', $seed.':'.$label), 0, 7)));
    }

    public function int(int $min, int $max): int
    {
        return $min >= $max ? $min : $this->randomizer->getInt($min, $max);
    }

    /**
     * A float in [0, 1).
     */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    public function chance(float $probability): bool
    {
        return $probability > 0 && $this->float() < $probability;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @param  array<array-key, float|int>  $weights  value => weight
     */
    public function weighted(array $weights): string|int
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return array_key_first($weights);
        }

        $target = $this->float() * $total;

        foreach ($weights as $value => $weight) {
            $target -= $weight;

            if ($target < 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    /**
     * An index in [0, $count) biased towards low indexes. With skew 1 the pick is
     * uniform; higher values concentrate activity on fewer records, which is how
     * real usage looks (a few very active users, many occasional ones).
     */
    public function skewedIndex(int $count, float $skew = 2.0): int
    {
        return min($count - 1, (int) floor($count * ($this->float() ** $skew)));
    }

    public function bytes(int $length): string
    {
        return $this->randomizer->getBytes($length);
    }

    public function uuid(): string
    {
        $bytes = $this->bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * A ULID whose time component matches the record's timestamp, so ULID order follows creation order.
     */
    public function ulid(int $milliseconds): string
    {
        $time = '';

        for ($i = 0; $i < 10; $i++) {
            $time = self::CROCKFORD[$milliseconds % 32].$time;
            $milliseconds = intdiv($milliseconds, 32);
        }

        $random = '';

        for ($i = 0; $i < 16; $i++) {
            $random .= self::CROCKFORD[$this->int(0, 31)];
        }

        return $time.$random;
    }

    public function string(int $length, string $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
    {
        return $this->randomizer->getBytesFromString($alphabet, $length);
    }
}
