<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class LoanFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Basic loan features
        $features['loan_amount'] = isset($data['loan']['amount']) 
            ? floatval($data['loan']['amount']) 
            : 25000.0;

        $features['loan_term_months'] = isset($data['loan']['term_months']) 
            ? floatval($data['loan']['term_months']) 
            : 60.0;

        // Interest rate (if provided)
        $features['interest_rate'] = isset($data['loan']['interest_rate']) 
            ? floatval($data['loan']['interest_rate']) 
            : 7.5;

        // Calculate loan-to-value ratio
        $vehicleValue = isset($data['vehicle']['estimated_value']) 
            ? floatval($data['vehicle']['estimated_value']) 
            : 30000.0;

        if ($vehicleValue > 0) {
            $features['loan_to_value_ratio'] = ($features['loan_amount'] / $vehicleValue) * 100;
        } else {
            $features['loan_to_value_ratio'] = 85.0;
        }

        // Calculate monthly payment
        $monthlyPayment = $this->calculateMonthlyPayment(
            $features['loan_amount'], 
            $features['interest_rate'], 
            $features['loan_term_months']
        );
        $features['monthly_payment'] = $monthlyPayment;

        // Calculate payment-to-income ratio
        $annualIncome = isset($data['applicant']['annual_income']) 
            ? floatval($data['applicant']['annual_income']) 
            : 50000.0;

        if ($annualIncome > 0) {
            $monthlyIncome = $annualIncome / 12;
            $features['payment_to_income_ratio'] = ($monthlyPayment / $monthlyIncome) * 100;
        } else {
            $features['payment_to_income_ratio'] = 15.0;
        }

        // Loan amount tier
        $features['loan_amount_tier'] = $this->encodeLoanAmountTier($features['loan_amount']);

        // Loan term tier
        $features['loan_term_tier'] = $this->encodeLoanTermTier($features['loan_term_months']);

        // Down payment information
        $downPayment = isset($data['loan']['down_payment']) 
            ? floatval($data['loan']['down_payment']) 
            : 0.0;

        $features['down_payment'] = $downPayment;

        // Down payment percentage
        if ($features['loan_amount'] > 0) {
            $totalFinanced = $features['loan_amount'] + $downPayment;
            $features['down_payment_percentage'] = ($downPayment / $totalFinanced) * 100;
        } else {
            $features['down_payment_percentage'] = 0.0;
        }

        // Loan purpose encoding
        $loanPurpose = isset($data['loan']['purpose']) 
            ? strtolower($data['loan']['purpose']) 
            : 'purchase';
        
        $features['loan_purpose_encoded'] = $this->encodeLoanPurpose($loanPurpose);

        // Trade-in value
        $tradeInValue = isset($data['loan']['trade_in_value']) 
            ? floatval($data['loan']['trade_in_value']) 
            : 0.0;

        $features['trade_in_value'] = $tradeInValue;

        // Trade-in to loan ratio
        if ($features['loan_amount'] > 0) {
            $features['trade_in_to_loan_ratio'] = ($tradeInValue / $features['loan_amount']) * 100;
        } else {
            $features['trade_in_to_loan_ratio'] = 0.0;
        }

        // Financing type
        $financingType = isset($data['loan']['financing_type']) 
            ? strtolower($data['loan']['financing_type']) 
            : 'standard';
        
        $features['financing_type_encoded'] = $this->encodeFinancingType($financingType);

        // Loan risk composite score
        $features['loan_risk_composite'] = $this->calculateLoanRiskComposite($features);

        return $features;
    }

    private function calculateMonthlyPayment(float $loanAmount, float $annualRate, float $termMonths): float
    {
        if ($termMonths <= 0 || $annualRate <= 0) {
            return $loanAmount / max(1, $termMonths);
        }

        $monthlyRate = $annualRate / 100 / 12;
        $payment = $loanAmount * ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) / 
                   (pow(1 + $monthlyRate, $termMonths) - 1);

        return round($payment, 2);
    }

    private function encodeLoanAmountTier(float $amount): float
    {
        if ($amount < 10000) return 1.0;
        if ($amount < 20000) return 2.0;
        if ($amount < 35000) return 3.0;
        if ($amount < 50000) return 4.0;
        return 5.0;
    }

    private function encodeLoanTermTier(float $termMonths): float
    {
        if ($termMonths <= 36) return 1.0;
        if ($termMonths <= 48) return 2.0;
        if ($termMonths <= 60) return 3.0;
        if ($termMonths <= 72) return 4.0;
        return 5.0;
    }

    private function encodeLoanPurpose(string $purpose): float
    {
        $encoding = [
            'purchase' => 1.0,
            'refinance' => 2.0,
            'lease buyout' => 2.5,
            'debt consolidation' => 3.0,
            'other' => 3.5,
        ];

        return $encoding[$purpose] ?? 1.0;
    }

    private function encodeFinancingType(string $type): float
    {
        $encoding = [
            'standard' => 1.0,
            'promotional' => 1.5,
            'subprime' => 3.0,
            'alternative' => 4.0,
            'buy here pay here' => 5.0,
        ];

        return $encoding[$type] ?? 1.0;
    }

    private function calculateLoanRiskComposite(array $features): float
    {
        $score = 0.0;

        // LTV component (30% weight)
        $ltvPenalty = max(0, $features['loan_to_value_ratio'] - 80) * 2;
        $score += min(30, $ltvPenalty) * 0.3;

        // Payment-to-income component (25% weight)
        $ptiPenalty = max(0, $features['payment_to_income_ratio'] - 15) * 1.5;
        $score += min(25, $ptiPenalty) * 0.25;

        // Loan term component (20% weight)
        $termPenalty = max(0, $features['loan_term_months'] - 60) / 12 * 10;
        $score += min(20, $termPenalty) * 0.2;

        // Down payment component (15% weight)
        $downPaymentBonus = min(15, $features['down_payment_percentage'] / 2);
        $score += (15 - $downPaymentBonus) * 0.15;

        // Loan amount component (10% weight)
        if ($features['loan_amount'] > 50000) {
            $score += 10 * 0.1;
        } elseif ($features['loan_amount'] < 5000) {
            $score += 5 * 0.1;
        }

        return round($score, 2);
    }

    public function getDefinitions(): array
    {
        return [
            'loan_amount' => 'Loan amount in CAD',
            'loan_term_months' => 'Loan term in months',
            'interest_rate' => 'Annual interest rate percentage',
            'loan_to_value_ratio' => 'Loan-to-value ratio percentage',
            'monthly_payment' => 'Calculated monthly payment',
            'payment_to_income_ratio' => 'Payment-to-income ratio percentage',
            'loan_amount_tier' => 'Loan amount tier (1=<10k, 5=>50k)',
            'loan_term_tier' => 'Loan term tier (1=≤36mo, 5=>72mo)',
            'down_payment' => 'Down payment amount',
            'down_payment_percentage' => 'Down payment as percentage of total',
            'loan_purpose_encoded' => 'Loan purpose risk score (1=purchase, 3.5=other)',
            'trade_in_value' => 'Trade-in vehicle value',
            'trade_in_to_loan_ratio' => 'Trade-in value to loan amount ratio',
            'financing_type_encoded' => 'Financing type risk score (1=standard, 5=BHPH)',
            'loan_risk_composite' => 'Composite loan risk score (0-100)',
        ];
    }
}
