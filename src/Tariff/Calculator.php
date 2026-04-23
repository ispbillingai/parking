<?php
declare(strict_types=1);

namespace Parking\Tariff;

use DateTimeInterface;

class Calculator
{
    public function __construct(private array $cfg) {}

    public function amountCents(DateTimeInterface $enteredAt, DateTimeInterface $now): int
    {
        $minutes    = (int) floor(($now->getTimestamp() - $enteredAt->getTimestamp()) / 60);
        $chargeable = max(0, $minutes - (int) ($this->cfg['grace_minutes'] ?? 0));

        $hourly   = (int) $this->cfg['hourly_cents'];
        $dailyCap = (int) ($this->cfg['daily_cap_cents'] ?? 0);
        $minimum  = (int) ($this->cfg['minimum_cents'] ?? 0);
        $dayMin   = 24 * 60;

        if ($chargeable === 0) {
            return $minimum;
        }

        $fullDays     = intdiv($chargeable, $dayMin);
        $remainingMin = $chargeable - $fullDays * $dayMin;
        $remainingHrs = (int) ceil($remainingMin / 60);
        $partialDay   = $remainingHrs * $hourly;

        if ($dailyCap > 0 && $partialDay > $dailyCap) {
            $partialDay = $dailyCap;
        }

        $perDayRate = $dailyCap > 0 ? $dailyCap : $hourly * 24;
        $total      = $fullDays * $perDayRate + $partialDay;

        return max($total, $minimum);
    }
}
