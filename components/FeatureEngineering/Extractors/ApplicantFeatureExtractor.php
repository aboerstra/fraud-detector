<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class ApplicantFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Age calculation
        if (isset($data['applicant']['date_of_birth'])) {
            try {
                $dob = new \DateTime($data['applicant']['date_of_birth']);
                $age = $dob->diff(new \DateTime())->y;
                $features['applicant_age'] = floatval($age);
            } catch (\Exception $e) {
                $features['applicant_age'] = 35.0; // Default age
            }
        } else {
            $features['applicant_age'] = 35.0;
        }

        // Annual income
        $features['annual_income'] = isset($data['applicant']['annual_income']) 
            ? floatval($data['applicant']['annual_income']) 
            : 50000.0;

        // Employment months
        $features['employment_months'] = isset($data['applicant']['employment_months']) 
            ? floatval($data['applicant']['employment_months']) 
            : 24.0;

        // Monthly debt
        $features['monthly_debt'] = isset($data['applicant']['monthly_debt']) 
            ? floatval($data['applicant']['monthly_debt']) 
            : 0.0;

        // Address months (time at current address)
        $features['address_months'] = isset($data['applicant']['address_months']) 
            ? floatval($data['applicant']['address_months']) 
            : 24.0;

        // Employment type encoding
        $employmentType = isset($data['applicant']['employment_type']) 
            ? strtolower($data['applicant']['employment_type']) 
            : 'full-time';
        
        $features['employment_type_encoded'] = $this->encodeEmploymentType($employmentType);

        // Income verification flag
        $features['income_verified'] = isset($data['applicant']['income_verified']) 
            ? ($data['applicant']['income_verified'] ? 1.0 : 0.0) 
            : 0.0;

        // Probationary period flag
        $features['probationary_period'] = isset($data['applicant']['probationary_period']) 
            ? ($data['applicant']['probationary_period'] ? 1.0 : 0.0) 
            : 0.0;

        // Job changes in last 2 years
        $features['job_changes_2y'] = isset($data['applicant']['job_changes_2y']) 
            ? floatval($data['applicant']['job_changes_2y']) 
            : 0.0;

        // Industry risk encoding
        $industry = isset($data['applicant']['industry']) 
            ? strtolower($data['applicant']['industry']) 
            : 'other';
        
        $features['industry_risk_score'] = $this->encodeIndustryRisk($industry);

        // Marital status encoding
        $maritalStatus = isset($data['applicant']['marital_status']) 
            ? strtolower($data['applicant']['marital_status']) 
            : 'single';
        
        $features['marital_status_encoded'] = $this->encodeMaritalStatus($maritalStatus);

        // Education level encoding
        $education = isset($data['applicant']['education']) 
            ? strtolower($data['applicant']['education']) 
            : 'high school';
        
        $features['education_encoded'] = $this->encodeEducation($education);

        return $features;
    }

    private function encodeEmploymentType(string $type): float
    {
        $encoding = [
            'full-time' => 1.0,
            'part-time' => 2.0,
            'self-employed' => 3.0,
            'contractor' => 3.5,
            'freelancer' => 3.5,
            'temporary' => 4.0,
            'seasonal' => 4.5,
            'unemployed' => 5.0,
        ];

        return $encoding[$type] ?? 1.0;
    }

    private function encodeIndustryRisk(string $industry): float
    {
        $highRiskIndustries = [
            'restaurant' => 4.0,
            'hospitality' => 4.0,
            'retail' => 3.5,
            'construction' => 3.5,
            'entertainment' => 4.0,
            'tourism' => 4.0,
            'gig economy' => 4.5,
            'rideshare' => 4.5,
            'delivery' => 4.0,
        ];

        $mediumRiskIndustries = [
            'manufacturing' => 2.5,
            'transportation' => 2.5,
            'agriculture' => 3.0,
            'mining' => 3.0,
        ];

        $lowRiskIndustries = [
            'healthcare' => 1.0,
            'education' => 1.0,
            'government' => 1.0,
            'finance' => 1.5,
            'technology' => 1.5,
            'professional services' => 1.5,
        ];

        foreach ($highRiskIndustries as $riskIndustry => $score) {
            if (strpos($industry, $riskIndustry) !== false) {
                return $score;
            }
        }

        foreach ($mediumRiskIndustries as $riskIndustry => $score) {
            if (strpos($industry, $riskIndustry) !== false) {
                return $score;
            }
        }

        foreach ($lowRiskIndustries as $riskIndustry => $score) {
            if (strpos($industry, $riskIndustry) !== false) {
                return $score;
            }
        }

        return 2.0; // Default medium risk
    }

    private function encodeMaritalStatus(string $status): float
    {
        $encoding = [
            'married' => 1.0,
            'common-law' => 1.2,
            'single' => 2.0,
            'divorced' => 2.5,
            'separated' => 2.8,
            'widowed' => 2.2,
        ];

        return $encoding[$status] ?? 2.0;
    }

    private function encodeEducation(string $education): float
    {
        $encoding = [
            'graduate degree' => 1.0,
            'bachelor degree' => 1.2,
            'college diploma' => 1.5,
            'trade certificate' => 1.8,
            'high school' => 2.0,
            'some high school' => 2.5,
            'no formal education' => 3.0,
        ];

        foreach ($encoding as $level => $score) {
            if (strpos($education, $level) !== false) {
                return $score;
            }
        }

        return 2.0; // Default
    }

    public function getDefinitions(): array
    {
        return [
            'applicant_age' => 'Age of applicant in years',
            'annual_income' => 'Annual income in CAD',
            'employment_months' => 'Months at current employment',
            'monthly_debt' => 'Existing monthly debt payments',
            'address_months' => 'Months at current address',
            'employment_type_encoded' => 'Employment type risk score (1=full-time, 5=unemployed)',
            'income_verified' => 'Income verification flag (1=verified, 0=not verified)',
            'probationary_period' => 'Probationary period flag (1=yes, 0=no)',
            'job_changes_2y' => 'Number of job changes in last 2 years',
            'industry_risk_score' => 'Industry risk score (1=low risk, 5=high risk)',
            'marital_status_encoded' => 'Marital status risk score (1=married, 3=single)',
            'education_encoded' => 'Education level risk score (1=graduate, 3=no formal)',
        ];
    }
}
