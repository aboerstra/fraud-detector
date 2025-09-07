<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class CreditFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Credit score (most important feature)
        $features['credit_score'] = isset($data['applicant']['credit_score']) 
            ? floatval($data['applicant']['credit_score']) 
            : 650.0;

        // Credit history length in years
        $features['credit_history_years'] = isset($data['applicant']['credit_history_years']) 
            ? floatval($data['applicant']['credit_history_years']) 
            : 5.0;

        // Recent credit inquiries (6 months)
        $features['recent_inquiries_6m'] = isset($data['applicant']['recent_inquiries_6m']) 
            ? floatval($data['applicant']['recent_inquiries_6m']) 
            : 1.0;

        // Delinquencies in last 24 months
        $features['delinquencies_24m'] = isset($data['applicant']['delinquencies_24m']) 
            ? floatval($data['applicant']['delinquencies_24m']) 
            : 0.0;

        // Bankruptcies in last 7 years
        $features['bankruptcies_7y'] = isset($data['applicant']['bankruptcies_7y']) 
            ? floatval($data['applicant']['bankruptcies_7y']) 
            : 0.0;

        // Credit utilization percentage
        $features['credit_utilization'] = isset($data['applicant']['credit_utilization']) 
            ? floatval($data['applicant']['credit_utilization']) 
            : 30.0;

        // Number of open credit accounts
        $features['open_credit_accounts'] = isset($data['applicant']['open_credit_accounts']) 
            ? floatval($data['applicant']['open_credit_accounts']) 
            : 3.0;

        // Total credit limit
        $features['total_credit_limit'] = isset($data['applicant']['total_credit_limit']) 
            ? floatval($data['applicant']['total_credit_limit']) 
            : 15000.0;

        // Calculate debt-to-income ratio
        $annualIncome = isset($data['applicant']['annual_income']) 
            ? floatval($data['applicant']['annual_income']) 
            : 50000.0;
        
        $loanAmount = isset($data['loan']['amount']) 
            ? floatval($data['loan']['amount']) 
            : 25000.0;
        
        $termMonths = isset($data['loan']['term_months']) 
            ? intval($data['loan']['term_months']) 
            : 60;

        $monthlyDebt = isset($data['applicant']['monthly_debt']) 
            ? floatval($data['applicant']['monthly_debt']) 
            : 0.0;

        if ($annualIncome > 0 && $termMonths > 0) {
            $monthlyPayment = $loanAmount / $termMonths;
            $monthlyIncome = $annualIncome / 12;
            $totalMonthlyDebt = $monthlyPayment + $monthlyDebt;
            $features['debt_to_income_ratio'] = ($totalMonthlyDebt / $monthlyIncome) * 100;
        } else {
            $features['debt_to_income_ratio'] = 35.0;
        }

        // Credit score tier encoding
        $features['credit_score_tier'] = $this->encodeCreditScoreTier($features['credit_score']);

        // Credit risk composite score
        $features['credit_risk_composite'] = $this->calculateCreditRiskComposite($features);

        // Payment history score (derived from delinquencies)
        $features['payment_history_score'] = $this->calculatePaymentHistoryScore(
            $features['delinquencies_24m'], 
            $features['bankruptcies_7y']
        );

        // Credit mix score (diversity of credit types)
        $features['credit_mix_score'] = isset($data['applicant']['credit_mix_score']) 
            ? floatval($data['applicant']['credit_mix_score']) 
            : 3.0;

        // Recent credit activity score
        $features['recent_credit_activity'] = $this->calculateRecentCreditActivity(
            $features['recent_inquiries_6m'],
            $features['credit_history_years']
        );

        return $features;
    }

    private function encodeCreditScoreTier(float $creditScore): float
    {
        if ($creditScore >= 800) return 1.0; // Excellent
        if ($creditScore >= 740) return 2.0; // Very Good
        if ($creditScore >= 670) return 3.0; // Good
        if ($creditScore >= 580) return 4.0; // Fair
        return 5.0; // Poor
    }

    private function calculateCreditRiskComposite(array $features): float
    {
        $score = 0.0;

        // Credit score component (40% weight)
        $creditScoreNormalized = max(0, min(100, ($features['credit_score'] - 300) / 5.5));
        $score += (100 - $creditScoreNormalized) * 0.4;

        // Utilization component (25% weight)
        $utilizationPenalty = max(0, $features['credit_utilization'] - 30) * 2;
        $score += min(25, $utilizationPenalty) * 0.25;

        // Delinquencies component (20% weight)
        $delinquencyPenalty = $features['delinquencies_24m'] * 10;
        $score += min(20, $delinquencyPenalty) * 0.2;

        // Inquiries component (10% weight)
        $inquiryPenalty = max(0, $features['recent_inquiries_6m'] - 2) * 5;
        $score += min(10, $inquiryPenalty) * 0.1;

        // History length component (5% weight)
        $historyBonus = min(5, $features['credit_history_years']);
        $score += (5 - $historyBonus) * 0.05;

        return round($score, 2);
    }

    private function calculatePaymentHistoryScore(float $delinquencies, float $bankruptcies): float
    {
        $score = 100.0; // Start with perfect score

        // Deduct for delinquencies
        $score -= $delinquencies * 15;

        // Deduct heavily for bankruptcies
        $score -= $bankruptcies * 40;

        return max(0, $score);
    }

    private function calculateRecentCreditActivity(float $inquiries, float $historyYears): float
    {
        if ($historyYears < 1) {
            return 5.0; // High risk for thin file
        }

        $inquiryRate = $inquiries / max(1, $historyYears / 2); // Inquiries per 6 months normalized by history

        if ($inquiryRate > 3) return 5.0; // Very high activity
        if ($inquiryRate > 2) return 4.0; // High activity
        if ($inquiryRate > 1) return 3.0; // Moderate activity
        if ($inquiryRate > 0.5) return 2.0; // Low activity
        return 1.0; // Very low activity
    }

    public function getDefinitions(): array
    {
        return [
            'credit_score' => 'Credit score (300-850 range)',
            'credit_history_years' => 'Length of credit history in years',
            'recent_inquiries_6m' => 'Number of credit inquiries in last 6 months',
            'delinquencies_24m' => 'Number of delinquencies in last 24 months',
            'bankruptcies_7y' => 'Number of bankruptcies in last 7 years',
            'credit_utilization' => 'Credit utilization percentage',
            'open_credit_accounts' => 'Number of open credit accounts',
            'total_credit_limit' => 'Total available credit limit',
            'debt_to_income_ratio' => 'Debt-to-income ratio percentage',
            'credit_score_tier' => 'Credit score tier (1=excellent, 5=poor)',
            'credit_risk_composite' => 'Composite credit risk score (0-100)',
            'payment_history_score' => 'Payment history score (0-100)',
            'credit_mix_score' => 'Credit mix diversity score (1-5)',
            'recent_credit_activity' => 'Recent credit activity risk score (1-5)',
        ];
    }
}
