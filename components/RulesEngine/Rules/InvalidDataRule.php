<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class InvalidDataRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $violations = [];

        // Check required fields
        $requiredFields = [
            'applicant.first_name',
            'applicant.last_name',
            'applicant.sin',
            'applicant.date_of_birth',
            'applicant.email',
            'applicant.phone',
            'applicant.address.street',
            'applicant.address.city',
            'applicant.address.province',
            'applicant.address.postal_code',
            'loan.amount',
            'loan.term_months',
            'vehicle.year',
            'vehicle.make',
            'vehicle.model',
            'vehicle.vin',
        ];

        foreach ($requiredFields as $field) {
            if (!$this->hasNestedValue($data, $field)) {
                $violations[] = "Missing required field: {$field}";
            }
        }

        // Validate SIN format (9 digits)
        if (isset($data['applicant']['sin'])) {
            $sin = preg_replace('/\D/', '', $data['applicant']['sin']);
            if (strlen($sin) !== 9) {
                $violations[] = 'Invalid SIN format';
            }
        }

        // Validate email format
        if (isset($data['applicant']['email'])) {
            if (!filter_var($data['applicant']['email'], FILTER_VALIDATE_EMAIL)) {
                $violations[] = 'Invalid email format';
            }
        }

        // Validate postal code format (Canadian)
        if (isset($data['applicant']['address']['postal_code'])) {
            $postalCode = strtoupper(str_replace(' ', '', $data['applicant']['address']['postal_code']));
            if (!preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postalCode)) {
                $violations[] = 'Invalid Canadian postal code format';
            }
        }

        // Validate phone number (10 digits)
        if (isset($data['applicant']['phone'])) {
            $phone = preg_replace('/\D/', '', $data['applicant']['phone']);
            if (strlen($phone) !== 10) {
                $violations[] = 'Invalid phone number format';
            }
        }

        // Validate date of birth (must be 18+ years old)
        if (isset($data['applicant']['date_of_birth'])) {
            try {
                $dob = new \DateTime($data['applicant']['date_of_birth']);
                $age = $dob->diff(new \DateTime())->y;
                if ($age < 18) {
                    $violations[] = 'Applicant must be at least 18 years old';
                }
                if ($age > 100) {
                    $violations[] = 'Invalid date of birth - age exceeds 100 years';
                }
            } catch (\Exception $e) {
                $violations[] = 'Invalid date of birth format';
            }
        }

        // Validate loan amount (reasonable range)
        if (isset($data['loan']['amount'])) {
            $amount = floatval($data['loan']['amount']);
            if ($amount < 1000 || $amount > 100000) {
                $violations[] = 'Loan amount must be between $1,000 and $100,000';
            }
        }

        // Validate loan term (reasonable range)
        if (isset($data['loan']['term_months'])) {
            $term = intval($data['loan']['term_months']);
            if ($term < 12 || $term > 84) {
                $violations[] = 'Loan term must be between 12 and 84 months';
            }
        }

        // Validate vehicle year (reasonable range)
        if (isset($data['vehicle']['year'])) {
            $year = intval($data['vehicle']['year']);
            $currentYear = intval(date('Y'));
            if ($year < 1990 || $year > $currentYear + 1) {
                $violations[] = 'Vehicle year must be between 1990 and ' . ($currentYear + 1);
            }
        }

        // Validate VIN format (17 characters)
        if (isset($data['vehicle']['vin'])) {
            $vin = strtoupper(str_replace(' ', '', $data['vehicle']['vin']));
            if (strlen($vin) !== 17 || !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
                $violations[] = 'Invalid VIN format';
            }
        }

        $triggered = count($violations) > 0;

        return [
            'triggered' => $triggered,
            'reason' => $triggered ? 'Invalid or missing data detected' : null,
            'details' => [
                'violations' => $violations,
                'violation_count' => count($violations),
            ],
        ];
    }

    private function hasNestedValue(array $data, string $path): bool
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return !empty($current);
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Invalid Data Rule',
            'type' => 'hard_fail',
            'description' => 'Validates required fields and data formats for application integrity',
            'criteria' => [
                'All required fields present',
                'Valid SIN format (9 digits)',
                'Valid email format',
                'Valid Canadian postal code',
                'Valid phone number (10 digits)',
                'Applicant age 18-100 years',
                'Loan amount $1,000-$100,000',
                'Loan term 12-84 months',
                'Vehicle year 1990-current+1',
                'Valid VIN format (17 characters)',
            ],
            'action' => 'Hard fail - reject application immediately',
        ];
    }
}
