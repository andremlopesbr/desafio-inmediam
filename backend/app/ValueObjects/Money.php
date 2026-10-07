<?php

namespace App\ValueObjects;

final class Money
{
    public function __construct(private float $amount)
    {
        $this->amount = max(0.0, round($amount, 2));
    }

    public static function fromDecimal(float $amount): self
    {
        return new self($amount);
    }

    public function asDecimal(): float
    {
        return round($this->amount, 2);
    }

    public function asCents(): int
    {
        return (int) round($this->amount * 100);
    }
}
