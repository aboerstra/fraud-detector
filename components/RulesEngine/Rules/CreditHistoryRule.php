<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class CreditHistoryRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $triggered = false;
        $score = 0;
        $factor = '';
        $description = '';

        // Evaluate credit score if provided
        if (isset($data['applicant']['credit_score'])) {
            $creditScore = intval($data['applicant']['credit_score']);

            if ($creditScore < 500) {
                $triggered = true;
                $score = 30;
                $factor = 'very_poor_credit';
                $description = sprintf('Very poor credit score: %d (<500)', $creditScore);
            } elseif ($creditScore < 600) {
                $triggered = true;
                $score = 20;
                $factor = 'poor_credit';
                $description = sprintf('Poor credit score: %d (<600)', $creditScore);
            } elseif ($creditScore < 650) {
                $triggered = true;
                $score = 12;
                $factor = 'fair_credit';
                $description = sprintf('Fair credit score: %d (<650)', $creditScore);
            } elseif ($creditScore < 700) {
                $triggered = true;
                $score = 5;
                $factor = 'below_good_credit';
                $description = sprintf('Below good credit score: %d (<700)', $creditScore);
            }
        }

        // Evaluate credit history length
        if (isset($data['applicant']['credit_history_years'])) {
            $creditHistoryYears = floatval($data['applicant']['credit_history_years']);

            if ($creditHistoryYears < 1) {
                $triggered = true;
                $score += 15;
                $factor = $factor ? $factor . '_thin_file' : 'thin_file';
                $description .= $description ? ' + Very limited credit history (<1 year)' : 'Very limited credit history (<1 year)';
            } elseif ($creditHistoryYears < 3) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_short_history' : 'short_history';
                $description .= $description ? ' + Short credit history (<3 years)' : 'Short credit history (<3 years)';
            }
        }

        // Evaluate recent credit inquiries
        if (isset($data['applicant']['recent_inquiries_6m'])) {
            $recentInquiries = intval($data['applicant']['recent_inquiries_6m']);

            if ($recentInquiries > 6) {
                $triggered = true;
                $score += 12;
                $factor = $factor ? $factor . '_many_inquiries' : 'many_inquiries';
                $description .= $description ? sprintf(' + Many recent inquiries: %d (>6)', $recentInquiries) : sprintf('Many recent inquiries: %d (>6)', $recentInquiries);
            } elseif ($recentInquiries > 3) {
                $triggered = true;
                $score += 6;
                $factor = $factor ? $factor . '_elevated_inquiries' : 'elevated_inquiries';
                $description .= $description ? sprintf(' + Elevated recent inquiries: %d (>3)', $recentInquiries) : sprintf('Elevated recent inquiries: %d (>3)', $recentInquiries);
            }
        }

        // Evaluate delinquencies
        if (isset($data['applicant']['delinquencies_24m'])) {
            $delinquencies = intval($data['applicant']['delinquencies_24m']);

            if ($delinquencies > 3) {
                $triggered = true;
                $score += 20;
                $factor = $factor ? $factor . '_many_delinquencies' : 'many_delinquencies';
                $description .= $description ? sprintf(' + Many recent delinquencies: %d (>3)', $delinquencies) : sprintf('Many recent delinquencies: %d (>3)', $delinquencies);
            } elseif ($delinquencies > 1) {
                $triggered = true;
                $score += 10;
                $factor = $factor ? $factor . '_some_delinquencies' : 'some_delinquencies';
                $description .= $description ? sprintf(' + Some recent delinquencies: %d (>1)', $delinquencies) : sprintf('Some recent delinquencies: %d (>1)', $delinquencies);
            } elseif ($delinquencies > 0) {
                $triggered = true;
                $score += 5;
                $factor = $factor ? $factor . '_minor_delinquency' : 'minor_delinquency';
                $description .= $description ? ' + Recent delinquency' : 'Recent delinquency';
            }
        }

        // Evaluate bankruptcies
        if (isset($data['applicant']['bankruptcies_7y'])) {
            $bankruptcies = intval($data['applicant']['bankruptcies_7y']);

            if ($bankruptcies > 0) {
                $triggered = true;
                $score += 25;
                $factor = $factor ? $factor . '_bankruptcy' : 'bankruptcy';
                $description .= $description ? sprintf(' + Recent bankruptcy (%d)', $bankruptcies) : sprintf('Recent bankruptcy (%d)', $bankruptcies);
            }
        }

        // Evaluate credit utilization
        if (isset($data['applicant']['credit_utilization'])) {
            $utilization = floatval($data['applicant']['credit_utilization']);

            if ($utilization > 90) {
                $triggered = true;
                $score += 15;
                $factor = $factor ? $factor . '_maxed_credit' : 'maxed_credit';
                $description .= $description ? sprintf(' + Very high credit utilization: %.1f%% (>90%%)', $utilization) : sprintf('Very high credit utilization: %.1f%% (>90%%)', $utilization);
            } elseif ($utilization > 70) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_high_utilization' : 'high_utilization';
                $description .= $description ? sprintf(' + High credit utilization: %.1f%% (>70%%)', $utilization) : sprintf('High credit utilization: %.1f%% (>70%%)', $utilization);
            }
        }

        return [
            'triggered' => $triggered,
            'score' => $score,
            'factor' => $factor,
            'description' => $description,
            'details' => [
                'credit_score' => $data['applicant']['credit_score'] ?? null,
                'credit_history_years' => $data['applicant']['credit_history_years'] ?? null,
                'recent_inquiries_6m' => $data['applicant']['recent_inquiries_6m'] ?? null,
                'delinquencies_24m' => $data['applicant']['delinquencies_24m'] ?? null,
                'bankruptcies_7y' => $data['applicant']['bankruptcies_7y'] ?? null,
                'credit_utilization' => $data['applicant']['credit_utilization'] ?? null,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Credit History Rule',
            'type' => 'risk_scoring',
            'description' => 'Evaluates credit risk based on credit score, history, and payment behavior',
            'scoring' => [
                'Credit score <500: +30 points',
                'Credit score <600: +20 points',
                'Credit score <650: +12 points',
                'Credit score <700: +5 points',
                'Credit history <1 year: +15 points',
                'Credit history <3 years: +8 points',
                'Recent inquiries >6: +12 points',
                'Recent inquiries >3: +6 points',
                'Delinquencies >3: +20 points',
                'Delinquencies >1: +10 points',
                'Any delinquency: +5 points',
                'Recent bankruptcy: +25 points',
                'Credit utilization >90%: +15 points',
                'Credit utilization >70%: +8 points',
            ],
            'rationale' => 'Poor credit history indicates higher likelihood of default',
        ];
    }
}
