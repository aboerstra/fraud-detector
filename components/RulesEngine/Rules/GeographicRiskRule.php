<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class GeographicRiskRule implements RuleInterface
{
    private array $highRiskPostalCodes;
    private array $highRiskProvinces;

    public function __construct()
    {
        // High-risk postal code prefixes (first 3 characters)
        $this->highRiskPostalCodes = [
            'H1A', 'H1B', 'H1C', // Montreal high-risk areas
            'M1B', 'M1E', 'M1G', // Toronto high-risk areas
            'V6A', 'V6B',        // Vancouver high-risk areas
            'T5A', 'T5B',        // Edmonton high-risk areas
            'R2W', 'R3A',        // Winnipeg high-risk areas
        ];

        // Province risk scores
        $this->highRiskProvinces = [
            'newfoundland and labrador' => 8,
            'new brunswick' => 6,
            'nova scotia' => 5,
            'prince edward island' => 4,
            'manitoba' => 6,
            'saskatchewan' => 7,
        ];
    }

    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $triggered = false;
        $score = 0;
        $factor = '';
        $description = '';

        // Evaluate postal code risk
        if (isset($data['applicant']['address']['postal_code'])) {
            $postalCode = strtoupper(str_replace(' ', '', $data['applicant']['address']['postal_code']));
            $postalPrefix = substr($postalCode, 0, 3);

            if (in_array($postalPrefix, $this->highRiskPostalCodes)) {
                $triggered = true;
                $score = 12;
                $factor = 'high_risk_postal_code';
                $description = sprintf('High-risk postal code area: %s', $postalPrefix);
            }
        }

        // Evaluate province risk
        if (isset($data['applicant']['address']['province'])) {
            $province = strtolower(trim($data['applicant']['address']['province']));

            if (isset($this->highRiskProvinces[$province])) {
                $triggered = true;
                $score += $this->highRiskProvinces[$province];
                $factor = $factor ? $factor . '_high_risk_province' : 'high_risk_province';
                $description .= $description ? sprintf(' + High-risk province: %s', ucwords($province)) : sprintf('High-risk province: %s', ucwords($province));
            }
        }

        // Evaluate address mismatch with IP geolocation
        if (isset($data['metadata']['ip_geolocation']) && isset($data['applicant']['address']['province'])) {
            $ipProvince = strtolower($data['metadata']['ip_geolocation']['province'] ?? '');
            $addressProvince = strtolower($data['applicant']['address']['province']);

            if ($ipProvince && $ipProvince !== $addressProvince) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_province_mismatch' : 'province_mismatch';
                $description .= $description ? ' + Address/IP province mismatch' : 'Address/IP province mismatch';
            }
        }

        // Evaluate rural vs urban risk
        if (isset($data['applicant']['address']['area_type'])) {
            $areaType = strtolower($data['applicant']['address']['area_type']);

            if ($areaType === 'rural' || $areaType === 'remote') {
                $triggered = true;
                $score += 5;
                $factor = $factor ? $factor . '_rural_area' : 'rural_area';
                $description .= $description ? ' + Rural/remote area' : 'Rural/remote area';
            }
        }

        // Evaluate recent address change
        if (isset($data['applicant']['address_months'])) {
            $addressMonths = intval($data['applicant']['address_months']);

            if ($addressMonths < 3) {
                $triggered = true;
                $score += 10;
                $factor = $factor ? $factor . '_recent_move' : 'recent_move';
                $description .= $description ? sprintf(' + Very recent address change: %d months', $addressMonths) : sprintf('Very recent address change: %d months', $addressMonths);
            } elseif ($addressMonths < 6) {
                $triggered = true;
                $score += 6;
                $factor = $factor ? $factor . '_recent_move' : 'recent_move';
                $description .= $description ? sprintf(' + Recent address change: %d months', $addressMonths) : sprintf('Recent address change: %d months', $addressMonths);
            }
        }

        // Evaluate PO Box usage
        if (isset($data['applicant']['address']['street'])) {
            $street = strtolower($data['applicant']['address']['street']);
            
            if (strpos($street, 'p.o. box') !== false || 
                strpos($street, 'po box') !== false || 
                strpos($street, 'post office box') !== false ||
                preg_match('/\bbox\s+\d+/', $street)) {
                $triggered = true;
                $score += 8;
                $factor = $factor ? $factor . '_po_box' : 'po_box';
                $description .= $description ? ' + PO Box address' : 'PO Box address';
            }
        }

        // Evaluate distance from nearest branch (if provided)
        if (isset($data['metadata']['nearest_branch_km'])) {
            $distanceKm = floatval($data['metadata']['nearest_branch_km']);

            if ($distanceKm > 200) {
                $triggered = true;
                $score += 6;
                $factor = $factor ? $factor . '_remote_location' : 'remote_location';
                $description .= $description ? sprintf(' + Very remote from branch: %.1f km', $distanceKm) : sprintf('Very remote from branch: %.1f km', $distanceKm);
            } elseif ($distanceKm > 100) {
                $triggered = true;
                $score += 3;
                $factor = $factor ? $factor . '_distant_location' : 'distant_location';
                $description .= $description ? sprintf(' + Distant from branch: %.1f km', $distanceKm) : sprintf('Distant from branch: %.1f km', $distanceKm);
            }
        }

        return [
            'triggered' => $triggered,
            'score' => $score,
            'factor' => $factor,
            'description' => $description,
            'details' => [
                'postal_code' => $data['applicant']['address']['postal_code'] ?? null,
                'province' => $data['applicant']['address']['province'] ?? null,
                'ip_geolocation' => $data['metadata']['ip_geolocation'] ?? null,
                'area_type' => $data['applicant']['address']['area_type'] ?? null,
                'address_months' => $data['applicant']['address_months'] ?? null,
                'nearest_branch_km' => $data['metadata']['nearest_branch_km'] ?? null,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Geographic Risk Rule',
            'type' => 'risk_scoring',
            'description' => 'Evaluates geographic and location-based risk factors',
            'scoring' => [
                'High-risk postal code: +12 points',
                'High-risk province: +4-8 points',
                'Address/IP province mismatch: +8 points',
                'Rural/remote area: +5 points',
                'Address change <3 months: +10 points',
                'Address change <6 months: +6 points',
                'PO Box address: +8 points',
                'Distance from branch >200km: +6 points',
                'Distance from branch >100km: +3 points',
            ],
            'rationale' => 'Geographic factors can indicate fraud patterns and collection difficulties',
        ];
    }
}
