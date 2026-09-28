<?php

namespace Ayangzy\RealSeed\Generation;

/**
 * Places rows and their timestamps on a shared timeline so history reads coherently:
 * children happen after their parents, updates after creation, and events after the
 * record they belong to. All values are Unix timestamps.
 */
final class TemporalGenerator
{
    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(
        public readonly int $start,
        public readonly int $end,
    ) {
    }

    /**
     * When a row "happened". Roots spread across the timeline with growth towards the
     * present; children follow their latest parent, usually soon after it.
     */
    public function rowTime(?int $after, SeededRandom $random): int
    {
        if ($after === null) {
            return $this->start + (int) (($this->end - $this->start) * ($random->float() ** 0.7));
        }

        $after = max($after, $this->start);

        return $after + (int) (max(0, $this->end - $after) * ($random->float() ** 1.6));
    }

    public function updated(int $created, int $latest, SeededRandom $random): int
    {
        $base = max($created, $latest);

        if ($random->chance(0.4)) {
            return $base;
        }

        return $base + (int) (max(0, $this->end - $base) * ($random->float() ** 3));
    }

    /**
     * An event that already happened, after the row was created.
     */
    public function past(int $rowTime, SeededRandom $random): int
    {
        return $rowTime + (int) (max(0, $this->end - $rowTime) * ($random->float() ** 1.5));
    }

    /**
     * An event scheduled relative to the row: may be in the past (history) or up to 90 days ahead.
     */
    public function future(int $rowTime, SeededRandom $random): int
    {
        $horizon = $this->end + 90 * self::DAY;

        return $rowTime + (int) (($horizon - $rowTime) * $random->float());
    }

    /**
     * The end of a span that started at $start (e.g. ends_at after starts_at).
     */
    public function spanEnd(int $start, SeededRandom $random): int
    {
        return $start + $random->int(self::HOUR, 30 * self::DAY);
    }

    public function birthDate(SeededRandom $random): int
    {
        return $this->end - $random->int(18 * 365, 80 * 365) * self::DAY;
    }
}
