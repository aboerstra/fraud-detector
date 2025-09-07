<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;
use Illuminate\Support\Facades\DB;

class BehavioralFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        // Application velocity features
        $features['applications_24h'] = $this->countRecentApplications($data, 24);
        $features['applications_7d'] = $this->countRecentApplications($data, 24 * 7);

        // Device fingerprinting
        $features['device_risk_score'] = $this->calculateDeviceRisk($data);

        // Session behavior
        $features['session_duration'] = isset($data['metadata']['session_duration']) 
            ? floatval($data['metadata']['session_duration']) 
            : 300.0; // 5 minutes default

        $features['page_views'] = isset($data['metadata']['page_views']) 
            ? floatval($data['metadata']['page_views']) 
            : 5.0;

        // Form completion behavior
        $features['form_completion_time'] = isset($data['metadata']['form_completion_time']) 
            ? floatval($data['metadata']['form_completion_time']) 
            : 600.0; // 10 minutes default

        $features['form_corrections'] = isset($data['metadata']['form_corrections']) 
            ? floatval($data['metadata']['form_corrections']) 
            : 2.0;

        // Referral source risk
        $referralSource = isset($data['metadata']['referral_source']) 
            ? strtolower($data['metadata']['referral_source']) 
            : 'direct';
        
        $features['referral_risk_score'] = $this->encodeReferralRisk($referralSource);

        return $features;
    }

    private function countRecentApplications(array $data, int $hours): float
    {
        if (!isset($data['applicant']['email'])) {
            return 0.0;
        }

        $count = DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereJsonContains('application_data->applicant->email', $data['applicant']['email'])
            ->count();

        return floatval($count);
    }

    private function calculateDeviceRisk(array $data): float
    {
        $score = 1.0; // Base score

        // Check for suspicious user agent
        $userAgent = $data['metadata']['user_agent'] ?? '';
        if (empty($userAgent) || strpos($userAgent, 'bot') !== false) {
            $score += 2.0;
        }

        // Check for VPN/proxy indicators
        if (isset($data['metadata']['is_vpn']) && $data['metadata']['is_vpn']) {
            $score += 1.5;
        }

        // Check for mobile vs desktop consistency
        $isMobile = isset($data['metadata']['is_mobile']) ? $data['metadata']['is_mobile'] : false;
        $screenSize = $data['metadata']['screen_resolution'] ?? '';
        
        if ($isMobile && strpos($screenSize, '1920x1080') !== false) {
            $score += 1.0; // Mobile claiming desktop resolution
        }

        return min(5.0, $score);
    }

    private function encodeReferralRisk(string $source): float
    {
        $riskScores = [
            'direct' => 1.0,
            'organic search' => 1.2,
            'paid search' => 1.5,
            'social media' => 2.0,
            'email' => 1.8,
            'affiliate' => 3.0,
            'unknown' => 2.5,
        ];

        return $riskScores[$source] ?? 2.5;
    }

    public function getDefinitions(): array
    {
        return [
            'applications_24h' => 'Number of applications from same email in 24 hours',
            'applications_7d' => 'Number of applications from same email in 7 days',
            'device_risk_score' => 'Device fingerprint risk score (1-5)',
            'session_duration' => 'Session duration in seconds',
            'page_views' => 'Number of page views in session',
            'form_completion_time' => 'Time to complete form in seconds',
            'form_corrections' => 'Number of form field corrections',
            'referral_risk_score' => 'Referral source risk score (1-3)',
        ];
    }
}
