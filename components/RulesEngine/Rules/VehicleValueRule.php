<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class VehicleValueRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $triggered = false;
        $score = 0;
        $factor = '';
        $description = '';

        $loanAmount = isset($data['loan']['amount']) ? floatval($data['loan']['amount']) : 0;
        $vehicleValue = isset($data['vehicle']['estimated_value']) ? floatval($data['vehicle']['estimated_value']) : 0;
        $vehicleYear = isset($data['vehicle']['year']) ? intval($data['vehicle']['year']) : 0;

        // Calculate loan-to-value ratio
        if ($loanAmount > 0 && $vehicleValue > 0) {
            $ltvRatio = ($loanAmount / $vehicleValue) * 100;

            if ($ltvRatio > 120) {
                $triggered = true;
                $score = 25;
                $factor = 'very_high_ltv';
                $description = sprintf('Very high loan-to-value ratio: %.1f%% (>120%%)', $ltvRatio);
            } elseif ($ltvRatio > 100) {
                $triggered = true;
                $score = 15;
                $factor = 'high_ltv';
                $description = sprintf('High loan-to-value ratio: %.1f%% (>100%%)', $ltvRatio);
            } elseif ($ltvRatio > 90) {
                $triggered = true;
                $score = 8;
                $factor = 'elevated_ltv';
                $description = sprintf('Elevated loan-to-value ratio: %.1f%% (>90%%)', $ltvRatio);
            }
        }

        // Evaluate vehicle age
        if ($vehicleYear > 0) {
            $currentYear = intval(date('Y'));
            $vehicleAge = $currentYear - $vehicleYear;

            if ($vehicleAge > 15) {
                $triggered = true;
                $score += 12;
                $factor = $factor ? $factor . '_very_old_vehicle' : 'very_old_vehicle';
                $description .= $description ? sprintf(' + Very old vehicle: %d years', $vehicleAge) : sprintf('Very old vehicle: %d years', $vehicleAge);
            } elseif ($vehicleAge > 10) {
                $triggered = true;
                $score += 6;
                $factor = $factor ? $factor . '_old_vehicle' : 'old_vehicle';
                $description .= $description ? sprintf(' + Old vehicle: %d years', $vehicleAge) : sprintf('Old vehicle: %d years', $vehicleAge);
            }
        }

        // Evaluate vehicle value vs loan amount mismatch
        if ($vehicleValue > 0 && $loanAmount > 0) {
            if ($vehicleValue < 5000 && $loanAmount > 15000) {
                $triggered = true;
                $score += 20;
                $factor = $factor ? $factor . '_value_loan_mismatch' : 'value_loan_mismatch';
                $description .= $description ? ' + Low vehicle value vs high loan amount' : 'Low vehicle value vs high loan amount';
            }
        }

        // Evaluate high-risk vehicle types
        if (isset($data['vehicle']['type'])) {
            $vehicleType = strtolower($data['vehicle']['type']);
            $highRiskTypes = ['motorcycle', 'atv', 'boat', 'rv', 'recreational vehicle', 'jet ski'];

            foreach ($highRiskTypes as $riskType) {
                if (strpos($vehicleType, $riskType) !== false) {
                    $triggered = true;
                    $score += 8;
                    $factor = $factor ? $factor . '_high_risk_vehicle_type' : 'high_risk_vehicle_type';
                    $description .= $description ? ' + High-risk vehicle type' : 'High-risk vehicle type';
                    break;
                }
            }
        }

        // Evaluate luxury vehicle risk
        if (isset($data['vehicle']['make'])) {
            $make = strtolower($data['vehicle']['make']);
            $luxuryBrands = ['bmw', 'mercedes', 'audi', 'lexus', 'acura', 'infiniti', 'cadillac', 'lincoln', 'jaguar', 'porsche', 'maserati', 'bentley', 'ferrari', 'lamborghini'];

            foreach ($luxuryBrands as $luxuryBrand) {
                if (strpos($make, $luxuryBrand) !== false) {
                    $triggered = true;
                    $score += 6;
                    $factor = $factor ? $factor . '_luxury_vehicle' : 'luxury_vehicle';
                    $description .= $description ? ' + Luxury vehicle brand' : 'Luxury vehicle brand';
                    break;
                }
            }
        }

        // Evaluate vehicle condition
        if (isset($data['vehicle']['condition'])) {
            $condition = strtolower($data['vehicle']['condition']);

            if ($condition === 'poor' || $condition === 'salvage' || $condition === 'damaged') {
                $triggered = true;
                $score += 15;
                $factor = $factor ? $factor . '_poor_condition' : 'poor_condition';
                $description .= $description ? ' + Poor vehicle condition' : 'Poor vehicle condition';
            } elseif ($condition === 'fair') {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_fair_condition' : 'fair_condition';
                $description .= $description ? ' + Fair vehicle condition' : 'Fair vehicle condition';
            }
        }

        // Evaluate high mileage
        if (isset($data['vehicle']['mileage'])) {
            $mileage = intval($data['vehicle']['mileage']);

            if ($mileage > 200000) {
                $triggered = true;
                $score += 10;
                $factor = $factor ? $factor . '_very_high_mileage' : 'very_high_mileage';
                $description .= $description ? sprintf(' + Very high mileage: %d km', $mileage) : sprintf('Very high mileage: %d km', $mileage);
            } elseif ($mileage > 150000) {
                $triggered = true;
                $score += 5;
                $factor = $factor ? $factor . '_high_mileage' : 'high_mileage';
                $description .= $description ? sprintf(' + High mileage: %d km', $mileage) : sprintf('High mileage: %d km', $mileage);
            }
        }

        // Evaluate missing VIN or invalid VIN
        if (!isset($data['vehicle']['vin']) || empty($data['vehicle']['vin'])) {
            $triggered = true;
            $score += 12;
            $factor = $factor ? $factor . '_missing_vin' : 'missing_vin';
            $description .= $description ? ' + Missing VIN' : 'Missing VIN';
        }

        // Evaluate vehicle value estimation confidence
        if (isset($data['vehicle']['value_confidence'])) {
            $confidence = strtolower($data['vehicle']['value_confidence']);

            if ($confidence === 'low' || $confidence === 'poor') {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_low_value_confidence' : 'low_value_confidence';
                $description .= $description ? ' + Low vehicle value confidence' : 'Low vehicle value confidence';
            }
        }

        return [
            'triggered' => $triggered,
            'score' => $score,
            'factor' => $factor,
            'description' => $description,
            'details' => [
                'loan_amount' => $loanAmount,
                'vehicle_value' => $vehicleValue,
                'ltv_ratio' => $loanAmount > 0 && $vehicleValue > 0 ? round(($loanAmount / $vehicleValue) * 100, 1) : null,
                'vehicle_year' => $vehicleYear,
                'vehicle_age' => $vehicleYear > 0 ? (intval(date('Y')) - $vehicleYear) : null,
                'vehicle_type' => $data['vehicle']['type'] ?? null,
                'vehicle_make' => $data['vehicle']['make'] ?? null,
                'vehicle_condition' => $data['vehicle']['condition'] ?? null,
                'vehicle_mileage' => $data['vehicle']['mileage'] ?? null,
                'vehicle_vin' => $data['vehicle']['vin'] ?? null,
                'value_confidence' => $data['vehicle']['value_confidence'] ?? null,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Vehicle Value Rule',
            'type' => 'risk_scoring',
            'description' => 'Evaluates vehicle-related risk factors including value, age, and condition',
            'scoring' => [
                'Loan-to-value >120%: +25 points',
                'Loan-to-value >100%: +15 points',
                'Loan-to-value >90%: +8 points',
                'Vehicle age >15 years: +12 points',
                'Vehicle age >10 years: +6 points',
                'Low value vs high loan: +20 points',
                'High-risk vehicle type: +8 points',
                'Luxury vehicle brand: +6 points',
                'Poor vehicle condition: +15 points',
                'Fair vehicle condition: +8 points',
                'Mileage >200,000km: +10 points',
                'Mileage >150,000km: +5 points',
                'Missing VIN: +12 points',
                'Low value confidence: +8 points',
            ],
            'rationale' => 'Vehicle value and condition affect collateral security and resale potential',
        ];
    }
}
