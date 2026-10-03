<?php

namespace Tests\Unit;

use App\Support\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function test_a_known_code_gets_its_symbol_and_an_unknown_one_shows_the_code(): void
    {
        $this->assertSame('₹', Currency::symbol('INR'));
        $this->assertSame('₹', Currency::symbol('inr'));
        $this->assertSame('USD', Currency::symbol('USD'));
    }
}
