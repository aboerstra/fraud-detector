<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class GeographicFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Province risk encoding
        $province = isset($data['applicant']['address']['province']) 
            ? strtolower($data['applicant']['address']['province']) 
            : 'ontario';
        
        $features['province_risk_score'] = $this->encodeProvinceRisk($province);

        // Postal code risk (simplified)
        $postalCode = isset($data['applicant']['address']['postal_code']) 
            ? strtoupper(str_replace(' ', '', $data['applicant']['address']['postal_code'])) 
            : 'M5V3A8';
        
        $features['postal_code_risk'] = $this->encodePostalCodeRisk($postalCode);

        // Urban vs rural
        $areaType = isset($data['applicant']['address']['area_type']) 
            ? strtolower($data['applicant']['address']['area_type']) 
            : 'urban';
        
        $features['area_type_encoded'] = $this->encodeAreaType($areaType);

        // IP geolocation mismatch
        $features['ip_address_mismatch'] = $this->checkIPMismatch($data);

        return $features;
    }

    private function encodeProvinceRisk(string $province): float
    {
        $riskScores = [
            'ontario' => 2.0,
            'quebec' => 2.5,
            'british columbia' => 2.2,
            'alberta' => 2.8,
            'manitoba' => 3.2,
            'saskatchewan' => 3.5,
            'nova scotia' => 3.0,
            'new brunswick' => 3.2,
            'newfoundland and labrador' => 3.8,
            'prince edward island' => 3.0,
            'northwest territories' => 4.0,
            'nunavut' => 4.0,
            'yukon' => 4.0,
        ];

        return $riskScores[$province] ?? 2.5;
    }

    private function encodePostalCodeRisk(string $postalCode): float
    {
        $prefix = substr($postalCode, 0, 1);
        
        $riskByPrefix = [
            'M' => 2.0, // Toronto
            'H' => 2.5, // Montreal
            'V' => 2.2, // Vancouver
            'T' => 2.8, // Calgary/Edmonton
            'R' => 3.2, // Winnipeg
            'S' => 3.5, // Saskatchewan
            'K' => 2.3, // Ottawa
            'N' => 2.8, // Hamilton/Niagara
            'L' => 2.5, // London/Kitchener
        ];

        return $riskByPrefix[$prefix] ?? 3.0;
    }

    private function encodeAreaType(string $areaType): float
    {
        $encoding = [
            'urban' => 1.0,
            'suburban' => 1.5,
            'rural' => 2.5,
            'remote' => 3.5,
        ];

        return $encoding[$areaType] ?? 1.5;
    }

    private function checkIPMismatch(array $data): float
    {
        if (!isset($data['metadata']['ip_geolocation']) || !isset($data['applicant']['address']['province'])) {
            return 0.0;
        }

        $ipProvince = strtolower($data['metadata']['ip_geolocation']['province'] ?? '');
        $addressProvince = strtolower($data['applicant']['address']['province']);

        return ($ipProvince && $ipProvince !== $addressProvince) ? 1.0 : 0.0;
    }

    public function getDefinitions(): array
    {
        return [
            'province_risk_score' => 'Province-based risk score (2.0=ON, 4.0=territories)',
            'postal_code_risk' => 'Postal code prefix risk score',
            'area_type_encoded' => 'Area type risk score (1=urban, 3.5=remote)',
            'ip_address_mismatch' => 'IP/address province mismatch flag (1=mismatch, 0=match)',
        ];
    }
}
