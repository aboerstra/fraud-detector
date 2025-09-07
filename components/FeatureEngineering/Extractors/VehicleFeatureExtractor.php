<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class VehicleFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Vehicle value
        $features['vehicle_value'] = isset($data['vehicle']['estimated_value']) 
            ? floatval($data['vehicle']['estimated_value']) 
            : 30000.0;

        // Vehicle year and age
        $vehicleYear = isset($data['vehicle']['year']) 
            ? intval($data['vehicle']['year']) 
            : 2020;

        $features['vehicle_year'] = floatval($vehicleYear);

        $currentYear = intval(date('Y'));
        $features['vehicle_age'] = floatval($currentYear - $vehicleYear);

        // Vehicle mileage
        $features['vehicle_mileage'] = isset($data['vehicle']['mileage']) 
            ? floatval($data['vehicle']['mileage']) 
            : 80000.0;

        // Vehicle make encoding
        $make = isset($data['vehicle']['make']) 
            ? strtolower($data['vehicle']['make']) 
            : 'other';
        
        $features['vehicle_make_encoded'] = $this->encodeVehicleMake($make);

        // Vehicle type encoding
        $type = isset($data['vehicle']['type']) 
            ? strtolower($data['vehicle']['type']) 
            : 'sedan';
        
        $features['vehicle_type_encoded'] = $this->encodeVehicleType($type);

        // Vehicle condition encoding
        $condition = isset($data['vehicle']['condition']) 
            ? strtolower($data['vehicle']['condition']) 
            : 'good';
        
        $features['vehicle_condition_encoded'] = $this->encodeVehicleCondition($condition);

        // Mileage per year
        if ($features['vehicle_age'] > 0) {
            $features['mileage_per_year'] = $features['vehicle_mileage'] / $features['vehicle_age'];
        } else {
            $features['mileage_per_year'] = 15000.0; // Average
        }

        // Vehicle value tier
        $features['vehicle_value_tier'] = $this->encodeVehicleValueTier($features['vehicle_value']);

        // Vehicle age tier
        $features['vehicle_age_tier'] = $this->encodeVehicleAgeTier($features['vehicle_age']);

        // VIN present flag
        $features['vin_present'] = isset($data['vehicle']['vin']) && !empty($data['vehicle']['vin']) ? 1.0 : 0.0;

        // Vehicle depreciation rate
        $features['depreciation_rate'] = $this->calculateDepreciationRate($features['vehicle_age'], $make);

        // Vehicle risk composite score
        $features['vehicle_risk_composite'] = $this->calculateVehicleRiskComposite($features);

        // High-risk vehicle flag
        $features['high_risk_vehicle'] = $this->isHighRiskVehicle($make, $type) ? 1.0 : 0.0;

        // Luxury vehicle flag
        $features['luxury_vehicle'] = $this->isLuxuryVehicle($make) ? 1.0 : 0.0;

        // Value confidence score
        $valueConfidence = isset($data['vehicle']['value_confidence']) 
            ? strtolower($data['vehicle']['value_confidence']) 
            : 'medium';
        
        $features['value_confidence_encoded'] = $this->encodeValueConfidence($valueConfidence);

        return $features;
    }

    private function encodeVehicleMake(string $make): float
    {
        $luxuryBrands = ['bmw', 'mercedes', 'audi', 'lexus', 'acura', 'infiniti', 'cadillac', 'lincoln'];
        $reliableBrands = ['toyota', 'honda', 'mazda', 'subaru', 'hyundai', 'kia'];
        $domesticBrands = ['ford', 'chevrolet', 'gmc', 'dodge', 'chrysler', 'jeep'];

        foreach ($luxuryBrands as $brand) {
            if (strpos($make, $brand) !== false) return 3.0;
        }

        foreach ($reliableBrands as $brand) {
            if (strpos($make, $brand) !== false) return 1.0;
        }

        foreach ($domesticBrands as $brand) {
            if (strpos($make, $brand) !== false) return 2.0;
        }

        return 2.5; // Other/unknown brands
    }

    private function encodeVehicleType(string $type): float
    {
        $encoding = [
            'sedan' => 1.0,
            'suv' => 1.5,
            'truck' => 2.0,
            'coupe' => 2.5,
            'convertible' => 3.0,
            'motorcycle' => 4.0,
            'atv' => 4.5,
            'boat' => 5.0,
            'rv' => 4.5,
        ];

        foreach ($encoding as $vehicleType => $score) {
            if (strpos($type, $vehicleType) !== false) {
                return $score;
            }
        }

        return 2.0; // Default
    }

    private function encodeVehicleCondition(string $condition): float
    {
        $encoding = [
            'excellent' => 1.0,
            'very good' => 1.5,
            'good' => 2.0,
            'fair' => 3.0,
            'poor' => 4.0,
            'salvage' => 5.0,
            'damaged' => 5.0,
        ];

        return $encoding[$condition] ?? 2.0;
    }

    private function encodeVehicleValueTier(float $value): float
    {
        if ($value < 10000) return 1.0;
        if ($value < 20000) return 2.0;
        if ($value < 35000) return 3.0;
        if ($value < 50000) return 4.0;
        return 5.0;
    }

    private function encodeVehicleAgeTier(float $age): float
    {
        if ($age <= 2) return 1.0;
        if ($age <= 5) return 2.0;
        if ($age <= 10) return 3.0;
        if ($age <= 15) return 4.0;
        return 5.0;
    }

    private function calculateDepreciationRate(float $age, string $make): float
    {
        $baseDep = 15.0; // Base depreciation rate per year

        // Luxury vehicles depreciate faster
        $luxuryBrands = ['bmw', 'mercedes', 'audi', 'lexus', 'acura', 'infiniti'];
        foreach ($luxuryBrands as $brand) {
            if (strpos($make, $brand) !== false) {
                $baseDep = 20.0;
                break;
            }
        }

        // Reliable brands depreciate slower
        $reliableBrands = ['toyota', 'honda', 'mazda'];
        foreach ($reliableBrands as $brand) {
            if (strpos($make, $brand) !== false) {
                $baseDep = 12.0;
                break;
            }
        }

        return min(80.0, $baseDep * $age); // Cap at 80% depreciation
    }

    private function calculateVehicleRiskComposite(array $features): float
    {
        $score = 0.0;

        // Age component (25% weight)
        $agePenalty = max(0, $features['vehicle_age'] - 5) * 3;
        $score += min(25, $agePenalty) * 0.25;

        // Mileage component (20% weight)
        $mileagePenalty = max(0, $features['vehicle_mileage'] - 100000) / 10000 * 5;
        $score += min(20, $mileagePenalty) * 0.2;

        // Condition component (20% weight)
        $conditionPenalty = ($features['vehicle_condition_encoded'] - 1) * 5;
        $score += min(20, $conditionPenalty) * 0.2;

        // Type component (15% weight)
        $typePenalty = ($features['vehicle_type_encoded'] - 1) * 3.75;
        $score += min(15, $typePenalty) * 0.15;

        // Value component (10% weight)
        if ($features['vehicle_value'] < 5000) {
            $score += 10 * 0.1;
        } elseif ($features['vehicle_value'] > 75000) {
            $score += 5 * 0.1;
        }

        // VIN component (10% weight)
        if ($features['vin_present'] == 0) {
            $score += 10 * 0.1;
        }

        return round($score, 2);
    }

    private function isHighRiskVehicle(string $make, string $type): bool
    {
        $highRiskTypes = ['motorcycle', 'atv', 'boat', 'rv', 'jet ski'];
        
        foreach ($highRiskTypes as $riskType) {
            if (strpos($type, $riskType) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isLuxuryVehicle(string $make): bool
    {
        $luxuryBrands = ['bmw', 'mercedes', 'audi', 'lexus', 'acura', 'infiniti', 'cadillac', 'lincoln', 'porsche', 'jaguar'];
        
        foreach ($luxuryBrands as $brand) {
            if (strpos($make, $brand) !== false) {
                return true;
            }
        }

        return false;
    }

    private function encodeValueConfidence(string $confidence): float
    {
        $encoding = [
            'high' => 1.0,
            'medium' => 2.0,
            'low' => 3.0,
            'poor' => 4.0,
        ];

        return $encoding[$confidence] ?? 2.0;
    }

    public function getDefinitions(): array
    {
        return [
            'vehicle_value' => 'Estimated vehicle value in CAD',
            'vehicle_year' => 'Vehicle model year',
            'vehicle_age' => 'Vehicle age in years',
            'vehicle_mileage' => 'Vehicle mileage in kilometers',
            'vehicle_make_encoded' => 'Vehicle make risk score (1=reliable, 3=luxury)',
            'vehicle_type_encoded' => 'Vehicle type risk score (1=sedan, 5=boat)',
            'vehicle_condition_encoded' => 'Vehicle condition score (1=excellent, 5=salvage)',
            'mileage_per_year' => 'Average mileage per year',
            'vehicle_value_tier' => 'Vehicle value tier (1=<10k, 5=>50k)',
            'vehicle_age_tier' => 'Vehicle age tier (1=≤2y, 5=>15y)',
            'vin_present' => 'VIN present flag (1=yes, 0=no)',
            'depreciation_rate' => 'Estimated depreciation rate percentage',
            'vehicle_risk_composite' => 'Composite vehicle risk score (0-100)',
            'high_risk_vehicle' => 'High-risk vehicle type flag (1=yes, 0=no)',
            'luxury_vehicle' => 'Luxury vehicle flag (1=yes, 0=no)',
            'value_confidence_encoded' => 'Value confidence score (1=high, 4=poor)',
        ];
    }
}
