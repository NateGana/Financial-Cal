<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class FinancialCalculator
{
    private const WORK = 12;

    public function round(string $value, int $scale = 2): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';
        return bccomp($value, '0', self::WORK) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    public function sum(array $values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, $this->clean($value), 2);
        }
        return $total;
    }

    public function totalIncome(string|int|float $salary, string|int|float $additional = 0): string
    {
        return bcadd($this->clean($salary), $this->clean($additional), 2);
    }

    public function availableIncome(string $income, string $expenses): string
    {
        return bcsub($income, $expenses, 2);
    }

    public function remainingGoal(string|int|float $target, string|int|float $saved): string
    {
        $remaining = bcsub($this->clean($target), $this->clean($saved), 2);
        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    public function monthsToAfford(string|int|float $target, string|int|float $saved, string|int|float $monthly): ?int
    {
        $remaining = $this->remainingGoal($target, $saved);
        if (bccomp($remaining, '0', 2) === 0) {
            return 0;
        }
        $monthly = $this->clean($monthly);
        if (bccomp($monthly, '0', 2) <= 0) {
            return null;
        }
        $quotient = bcdiv($remaining, $monthly, 0);
        if (bccomp(bcmul($quotient, $monthly, 2), $remaining, 2) < 0) {
            $quotient = bcadd($quotient, '1', 0);
        }
        return (int) $quotient;
    }

    public function completionDate(?int $months, ?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        if ($months === null) {
            return null;
        }
        return ($from ?? new DateTimeImmutable('today'))->modify("+{$months} months");
    }

    public function monthsToAffordVariable(string|int|float $target, string|int|float $saved, array $contributions): ?int
    {
        $remaining = $this->remainingGoal($target, $saved);
        if (bccomp($remaining, '0', 2) === 0) {
            return 0;
        }
        $running = '0';
        foreach (array_values($contributions) as $index => $amount) {
            $running = bcadd($running, $this->clean($amount), 2);
            if (bccomp($running, $remaining, 2) >= 0) {
                return $index + 1;
            }
        }
        return null;
    }

    public function scenarios(string|int|float $target, string|int|float $saved, array $monthlyAmounts, ?DateTimeImmutable $from = null): array
    {
        $result = [];
        foreach ($monthlyAmounts as $amount) {
            $months = $this->monthsToAfford($target, $saved, $amount);
            $result[] = [
                'monthly' => $this->clean($amount),
                'months' => $months,
                'years' => $months === null ? null : $this->round(bcdiv((string) $months, '12', self::WORK), 1),
                'completion_date' => $this->completionDate($months, $from)?->format('Y-m-d'),
            ];
        }
        return $result;
    }

    public function cashTarget(string|int|float $price, array $selectedCosts = []): string
    {
        return bcadd($this->clean($price), $this->sum($selectedCosts), 2);
    }

    public function downpayment(string|int|float $price, string|int|float $percent): string
    {
        $percent = $this->clean($percent);
        if (bccomp($percent, '0', 4) < 0 || bccomp($percent, '100', 4) > 0) {
            throw new InvalidArgumentException('Downpayment percentage must be between 0 and 100.');
        }
        return $this->round(bcdiv(bcmul($this->clean($price), $percent, self::WORK), '100', self::WORK));
    }

    public function monthlyPayment(string|int|float $principal, string|int|float $annualRate, int $months): string
    {
        if ($months <= 0) {
            throw new InvalidArgumentException('Loan term must be at least one month.');
        }
        $principal = $this->clean($principal);
        $annualRate = $this->clean($annualRate);
        if (bccomp($principal, '0', 2) <= 0) {
            return '0.00';
        }
        if (bccomp($annualRate, '0', 6) === 0) {
            return $this->round(bcdiv($principal, (string) $months, self::WORK));
        }
        $r = bcdiv(bcdiv($annualRate, '12', self::WORK), '100', self::WORK);
        $growth = bcpow(bcadd('1', $r, self::WORK), (string) $months, self::WORK);
        $numerator = bcmul(bcmul($principal, $r, self::WORK), $growth, self::WORK);
        $denominator = bcsub($growth, '1', self::WORK);
        return $this->round(bcdiv($numerator, $denominator, self::WORK));
    }

    public function amortization(string|int|float $principal, string|int|float $annualRate, int $months): array
    {
        $payment = $this->monthlyPayment($principal, $annualRate, $months);
        $balance = $this->clean($principal);
        $r = bcdiv(bcdiv($this->clean($annualRate), '12', self::WORK), '100', self::WORK);
        $rows = [];
        $totalInterest = '0.00';
        $totalPaid = '0.00';
        for ($i = 1; $i <= $months; $i++) {
            $interest = $this->round(bcmul($balance, $r, self::WORK));
            $isLast = $i === $months;
            $principalPart = $isLast ? $balance : bcsub($payment, $interest, 2);
            $paid = bcadd($principalPart, $interest, 2);
            $balance = bcsub($balance, $principalPart, 2);
            $totalInterest = bcadd($totalInterest, $interest, 2);
            $totalPaid = bcadd($totalPaid, $paid, 2);
            $rows[] = [
                'number' => $i,
                'payment' => $paid,
                'principal' => $principalPart,
                'interest' => $interest,
                'balance' => $balance,
            ];
        }
        return [
            'monthly_payment' => $payment,
            'total_loan_payments' => $totalPaid,
            'total_interest' => $totalInterest,
            'rows' => $rows,
        ];
    }

    public function financing(string|int|float $price, string|int|float $downPercent, string|int|float $annualRate, int $months, string|int|float $fees = 0, string|int|float $otherInitial = 0): array
    {
        $down = $this->downpayment($price, $downPercent);
        $principal = bcsub($this->clean($price), $down, 2);
        $schedule = $this->amortization($principal, $annualRate, $months);
        $initialFees = bcadd($this->clean($fees), $this->clean($otherInitial), 2);
        $totalFinancingCost = bcadd($schedule['total_loan_payments'], $initialFees, 2);
        return [
            'downpayment' => $down,
            'principal' => $principal,
            'monthly_payment' => $schedule['monthly_payment'],
            'total_loan_payments' => $schedule['total_loan_payments'],
            'total_interest' => $schedule['total_interest'],
            'initial_fees' => $initialFees,
            'total_financing_cost' => $totalFinancingCost,
            'total_paid_including_downpayment' => bcadd($totalFinancingCost, $down, 2),
            'initial_cash_required' => bcadd($down, $initialFees, 2),
            'schedule' => $schedule['rows'],
        ];
    }

    public function ownership(array $monthlyItems, string|int|float $price, int $years): array
    {
        $monthly = $this->sum($monthlyItems);
        $annual = bcmul($monthly, '12', 2);
        $period = bcmul($annual, (string) $years, 2);
        return [
            'monthly' => $monthly,
            'annual' => $annual,
            'period_total' => $period,
            'total_with_price' => bcadd($period, $this->clean($price), 2),
        ];
    }

    public function budgetImpact(string $available, string $carSavings, string $ownershipMonthly): array
    {
        $commitment = bcadd($carSavings, $ownershipMonthly, 2);
        $remaining = bcsub($available, $commitment, 2);
        return [
            'monthly_commitment' => $commitment,
            'remaining_disposable' => $remaining,
            'exceeds_budget' => bccomp($remaining, '0', 2) < 0,
        ];
    }

    private function clean(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $text = is_float($value) ? number_format($value, 6, '.', '') : trim((string) $value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Invalid numeric input.');
        }
        return $text;
    }
}
