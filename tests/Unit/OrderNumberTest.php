<?php

namespace Tests\Unit;

use App\Support\OrderNumber;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OrderNumberTest extends TestCase
{
    public function test_it_is_the_date_and_a_readable_random_suffix(): void
    {
        $orderNumber = OrderNumber::unique(CarbonImmutable::parse('2026-10-03'), fn () => false);

        $this->assertMatchesRegularExpression('/^AH-20261003-[2-9A-HJ-NP-Z]{6}$/', $orderNumber);
    }

    public function test_a_taken_number_is_drawn_again(): void
    {
        $tried = [];

        $orderNumber = OrderNumber::unique(CarbonImmutable::parse('2026-10-03'), function (string $candidate) use (&$tried) {
            $tried[] = $candidate;

            return count($tried) < 3;
        });

        $this->assertCount(3, $tried);
        $this->assertSame($tried[2], $orderNumber);
    }
}
