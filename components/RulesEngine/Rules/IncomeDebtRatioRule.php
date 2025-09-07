<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class IncomeDebtRatioRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $triggered = false;
        $score = 0;
        $factor = '';
        $description = '';

        // Calculate debt-to-income ratio
        if (isset($data['applicant']['annual_income']) && isset($data['loan']['amount']) && isset($data['loan']['term_months'])) {
            $annualIncome = floatval($data['applicant']['annual_income']);
            $loanAmount = floatval($data['loan']['amount']);
            $termMonths = intval($data['loan']['term_months']);

            if ($annualIncome > 0 && $termMonths > 0) {
                // Calculate monthly payment (simplified calculation without interest)
                $monthlyPayment = $loanAmount / $termMonths;
                $monthlyIncome = $annualIncome / 12;
                $debtToIncomeRatio = ($monthlyPayment / $monthlyIncome) * 100;

                // Include existing monthly debt if provided
                $existingMonthlyDebt = isset($data['applicant']['monthly_debt']) ? floatval($data['applicant']['monthly_debt']) : 0;
                $totalMonthlyDebt = $monthlyPayment + $existingMonthlyDebt;
                $totalDebtToIncomeRatio = ($totalMonthlyDebt / $monthlyIncome) * 100;

                // Risk scoring based on debt-to-income ratio
                if ($totalDebtToIncomeRatio > 50) {
                    $triggered = true;
                    $score = 25;
                    $factor = 'high_debt_to_income';
                    $description = sprintf('Very high debt-to-income ratio: %.1f%% (>50%%)', $totalDebtToIncomeRatio);
                } elseif ($totalDebtToIncomeRatio > 40) {
                    $triggered = true;
                    $score = 15;
                    $factor = 'elevated_debt_to_income';
                    $description = sprintf('Elevated debt-to-income ratio: %.1f%% (>40%%)', $totalDebtToIncomeRatio);
                } elseif ($totalDebtToIncomeRatio > 30) {
                    $triggered = true;
                    $score = 8;
                    $factor = 'moderate_debt_to_income';
                    $description = sprintf('Moderate debt-to-income ratio: %.1f%% (>30%%)', $totalDebtToIncomeRatio);
                }

                // Additional risk for very low income
                if ($annualIncome < 25000) {
                    $triggered = true;
                    $score += 10;
                    $factor = $factor ? $factor . '_low_income' : 'low_income';
                    $description .= $description ? ' + Low annual income (<$25,000)' : 'Low annual income (<$25,000)';
                }

                // Additional risk for very high loan amount relative to income
                $loanToIncomeRatio = ($loanAmount / $annualIncome) * 100;
                if ($loanToIncomeRatio > 80) {
                    $triggered = true;
                    $score += 12;
                    $factor = $factor ? $factor . '_high_loan_ratio' : 'high_loan_ratio';
                    $description .= $description ? sprintf(' + High loan-to-income ratio: %.1f%%', $loanToIncomeRatio) : sprintf('High loan-to-income ratio: %.1f%%', $loanToIncomeRatio);
                }
            }
        }

        return [
            'triggered' => $triggered,
            'score' => $score,
            'factor' => $factor,
            'description' => $description,
            'details' => [
                'annual_income' => $data['applicant']['annual_income'] ?? null,
                'loan_amount' => $data['loan']['amount'] ?? null,
                'term_months' => $data['loan']['term_months'] ?? null,
                'existing_monthly_debt' => $data['applicant']['monthly_debt'] ?? null,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Income Debt Ratio Rule',
            'type' => 'risk_scoring',
            'description' => 'Evaluates financial risk based on debt-to-income ratios and income levels',
            'scoring' => [
                'Debt-to-income >50%: +25 points',
                'Debt-to-income >40%: +15 points',
                'Debt-to-income >30%: +8 points',
                'Annual income <$25,000: +10 points',
                'Loan-to-income >80%: +12 points',
            ],
            'rationale' => 'High debt-to-income ratios indicate potential repayment difficulties',
        ];
    }
}
