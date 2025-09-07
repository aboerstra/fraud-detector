<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class EmploymentStabilityRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $triggered = false;
        $score = 0;
        $factor = '';
        $description = '';

        // Evaluate employment length
        if (isset($data['applicant']['employment_months'])) {
            $employmentMonths = intval($data['applicant']['employment_months']);

            if ($employmentMonths < 3) {
                $triggered = true;
                $score = 20;
                $factor = 'very_short_employment';
                $description = sprintf('Very short employment: %d months (<3)', $employmentMonths);
            } elseif ($employmentMonths < 6) {
                $triggered = true;
                $score = 12;
                $factor = 'short_employment';
                $description = sprintf('Short employment: %d months (<6)', $employmentMonths);
            } elseif ($employmentMonths < 12) {
                $triggered = true;
                $score = 6;
                $factor = 'limited_employment';
                $description = sprintf('Limited employment: %d months (<12)', $employmentMonths);
            }
        }

        // Evaluate employment type
        if (isset($data['applicant']['employment_type'])) {
            $employmentType = strtolower($data['applicant']['employment_type']);

            switch ($employmentType) {
                case 'unemployed':
                    $triggered = true;
                    $score += 30;
                    $factor = $factor ? $factor . '_unemployed' : 'unemployed';
                    $description .= $description ? ' + Unemployed' : 'Unemployed';
                    break;
                case 'self-employed':
                case 'contractor':
                case 'freelancer':
                    $triggered = true;
                    $score += 15;
                    $factor = $factor ? $factor . '_self_employed' : 'self_employed';
                    $description .= $description ? ' + Self-employed/contractor' : 'Self-employed/contractor';
                    break;
                case 'part-time':
                    $triggered = true;
                    $score += 8;
                    $factor = $factor ? $factor . '_part_time' : 'part_time';
                    $description .= $description ? ' + Part-time employment' : 'Part-time employment';
                    break;
                case 'temporary':
                case 'seasonal':
                    $triggered = true;
                    $score += 12;
                    $factor = $factor ? $factor . '_temporary' : 'temporary';
                    $description .= $description ? ' + Temporary/seasonal employment' : 'Temporary/seasonal employment';
                    break;
            }
        }

        // Evaluate job changes in recent years
        if (isset($data['applicant']['job_changes_2y'])) {
            $jobChanges = intval($data['applicant']['job_changes_2y']);

            if ($jobChanges > 3) {
                $triggered = true;
                $score += 15;
                $factor = $factor ? $factor . '_frequent_job_changes' : 'frequent_job_changes';
                $description .= $description ? sprintf(' + Frequent job changes: %d in 2 years', $jobChanges) : sprintf('Frequent job changes: %d in 2 years', $jobChanges);
            } elseif ($jobChanges > 2) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_some_job_changes' : 'some_job_changes';
                $description .= $description ? sprintf(' + Multiple job changes: %d in 2 years', $jobChanges) : sprintf('Multiple job changes: %d in 2 years', $jobChanges);
            }
        }

        // Evaluate industry risk
        if (isset($data['applicant']['industry'])) {
            $industry = strtolower($data['applicant']['industry']);
            $highRiskIndustries = [
                'restaurant',
                'hospitality',
                'retail',
                'construction',
                'entertainment',
                'tourism',
                'gig economy',
                'rideshare',
                'delivery',
            ];

            foreach ($highRiskIndustries as $riskIndustry) {
                if (strpos($industry, $riskIndustry) !== false) {
                    $triggered = true;
                    $score += 6;
                    $factor = $factor ? $factor . '_high_risk_industry' : 'high_risk_industry';
                    $description .= $description ? ' + High-risk industry' : 'High-risk industry';
                    break;
                }
            }
        }

        // Evaluate income verification
        if (isset($data['applicant']['income_verified'])) {
            $incomeVerified = $data['applicant']['income_verified'];

            if (!$incomeVerified) {
                $triggered = true;
                $score += 10;
                $factor = $factor ? $factor . '_unverified_income' : 'unverified_income';
                $description .= $description ? ' + Unverified income' : 'Unverified income';
            }
        }

        // Evaluate probationary period
        if (isset($data['applicant']['probationary_period'])) {
            $probationary = $data['applicant']['probationary_period'];

            if ($probationary) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_probationary' : 'probationary';
                $description .= $description ? ' + Currently in probationary period' : 'Currently in probationary period';
            }
        }

        return [
            'triggered' => $triggered,
            'score' => $score,
            'factor' => $factor,
            'description' => $description,
            'details' => [
                'employment_months' => $data['applicant']['employment_months'] ?? null,
                'employment_type' => $data['applicant']['employment_type'] ?? null,
                'job_changes_2y' => $data['applicant']['job_changes_2y'] ?? null,
                'industry' => $data['applicant']['industry'] ?? null,
                'income_verified' => $data['applicant']['income_verified'] ?? null,
                'probationary_period' => $data['applicant']['probationary_period'] ?? null,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Employment Stability Rule',
            'type' => 'risk_scoring',
            'description' => 'Evaluates employment stability and income security risk factors',
            'scoring' => [
                'Employment <3 months: +20 points',
                'Employment <6 months: +12 points',
                'Employment <12 months: +6 points',
                'Unemployed: +30 points',
                'Self-employed/contractor: +15 points',
                'Part-time: +8 points',
                'Temporary/seasonal: +12 points',
                'Job changes >3 in 2 years: +15 points',
                'Job changes >2 in 2 years: +8 points',
                'High-risk industry: +6 points',
                'Unverified income: +10 points',
                'Probationary period: +8 points',
            ],
            'rationale' => 'Employment instability indicates higher risk of income disruption',
        ];
    }
}
