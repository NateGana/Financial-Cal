<?php

namespace Tests\Unit;

use App\Services\FinancialCalculator;
use PHPUnit\Framework\TestCase;

class FinancialCalculatorTest extends TestCase
{
    private FinancialCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new FinancialCalculator();
    }

    public function test_months_to_afford_rounds_up(): void
    {
        $this->assertSame(591, $this->calc->monthsToAfford('30000000', '500000', '50000'));
    }

    public function test_zero_savings_returns_null(): void
    {
        $this->assertNull($this->calc->monthsToAfford('1000000', '0', '0'));
        $this->assertNull($this->calc->monthsToAfford('1000000', '0', '-5'));
    }

    public function test_goal_already_met(): void
    {
        $this->assertSame(0, $this->calc->monthsToAfford('100', '200', '10'));
    }

    public function test_loan_payment_known_example(): void
    {
        $this->assertSame('1199.10', $this->calc->monthlyPayment('200000', '6', 360));
        $this->assertSame('856.07', $this->calc->monthlyPayment('10000', '5', 12));
    }

    public function test_zero_interest(): void
    {
        $this->assertSame('1000.00', $this->calc->monthlyPayment('12000', '0', 12));
    }

    public function test_amortization_balance_reaches_zero(): void
    {
        $result = $this->calc->amortization('250000', '7.5', 48);
        $this->assertSame('0.00', end($result['rows'])['balance']);
        $this->assertCount(48, $result['rows']);
    }

    public function test_financing_totals(): void
    {
        $r = $this->calc->financing('1000000', '20', '0', 10, '5000', '0');
        $this->assertSame('200000.00', $r['downpayment']);
        $this->assertSame('80000.00', $r['monthly_payment']);
        $this->assertSame('0.00', $r['total_interest']);
        $this->assertSame('1005000.00', $r['total_paid_including_downpayment']);
    }

    public function test_variable_contributions(): void
    {
        $this->assertSame(3, $this->calc->monthsToAffordVariable('1000', '0', ['100', '400', '600', '900']));
        $this->assertNull($this->calc->monthsToAffordVariable('1000', '0', ['100', '100']));
    }
}
