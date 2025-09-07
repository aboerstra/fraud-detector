<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;
use Illuminate\Support\Facades\DB;

class VelocityRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $violations = [];

        // Check application velocity by SIN
        if (isset($data['applicant']['sin'])) {
            $sin = $data['applicant']['sin'];
            
            // Count applications in last 24 hours
            $applications24h = $this->countApplicationsBySin($sin, 24);
            if ($applications24h > 3) {
                $violations[] = [
                    'type' => 'sin_velocity_24h',
                    'count' => $applications24h,
                    'threshold' => 3,
                    'description' => 'Too many applications from same SIN in 24 hours',
                ];
            }

            // Count applications in last 7 days
            $applications7d = $this->countApplicationsBySin($sin, 24 * 7);
            if ($applications7d > 10) {
                $violations[] = [
                    'type' => 'sin_velocity_7d',
                    'count' => $applications7d,
                    'threshold' => 10,
                    'description' => 'Too many applications from same SIN in 7 days',
                ];
            }
        }

        // Check application velocity by IP address
        if (isset($data['metadata']['ip_address'])) {
            $ipAddress = $data['metadata']['ip_address'];
            
            // Count applications from same IP in last hour
            $applicationsIp1h = $this->countApplicationsByIp($ipAddress, 1);
            if ($applicationsIp1h > 5) {
                $violations[] = [
                    'type' => 'ip_velocity_1h',
                    'count' => $applicationsIp1h,
                    'threshold' => 5,
                    'description' => 'Too many applications from same IP in 1 hour',
                ];
            }

            // Count applications from same IP in last 24 hours
            $applicationsIp24h = $this->countApplicationsByIp($ipAddress, 24);
            if ($applicationsIp24h > 20) {
                $violations[] = [
                    'type' => 'ip_velocity_24h',
                    'count' => $applicationsIp24h,
                    'threshold' => 20,
                    'description' => 'Too many applications from same IP in 24 hours',
                ];
            }
        }

        // Check application velocity by email domain
        if (isset($data['applicant']['email'])) {
            $email = $data['applicant']['email'];
            $domain = substr(strrchr($email, '@'), 1);
            
            // Count applications from same email domain in last 24 hours
            $applicationsDomain24h = $this->countApplicationsByEmailDomain($domain, 24);
            if ($applicationsDomain24h > 50) {
                $violations[] = [
                    'type' => 'email_domain_velocity_24h',
                    'count' => $applicationsDomain24h,
                    'threshold' => 50,
                    'description' => 'Too many applications from same email domain in 24 hours',
                ];
            }
        }

        // Check for rapid-fire applications (same applicant within minutes)
        if (isset($data['applicant']['sin'])) {
            $sin = $data['applicant']['sin'];
            $recentApplications = $this->getRecentApplicationsBySin($sin, 30); // Last 30 minutes
            
            if (count($recentApplications) > 1) {
                $violations[] = [
                    'type' => 'rapid_fire',
                    'count' => count($recentApplications),
                    'threshold' => 1,
                    'description' => 'Multiple applications from same SIN within 30 minutes',
                ];
            }
        }

        $triggered = count($violations) > 0;

        return [
            'triggered' => $triggered,
            'reason' => $triggered ? 'Application velocity limits exceeded' : null,
            'details' => [
                'violations' => $violations,
                'violation_count' => count($violations),
            ],
        ];
    }

    private function countApplicationsBySin(string $sin, int $hours): int
    {
        return DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereJsonContains('application_data->applicant->sin', $sin)
            ->count();
    }

    private function countApplicationsByIp(string $ipAddress, int $hours): int
    {
        return DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereJsonContains('application_data->metadata->ip_address', $ipAddress)
            ->count();
    }

    private function countApplicationsByEmailDomain(string $domain, int $hours): int
    {
        return DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subHours($hours))
            ->where('application_data->applicant->email', 'LIKE', '%@' . $domain)
            ->count();
    }

    private function getRecentApplicationsBySin(string $sin, int $minutes): array
    {
        return DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->whereJsonContains('application_data->applicant->sin', $sin)
            ->select('id', 'created_at')
            ->get()
            ->toArray();
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Velocity Rule',
            'type' => 'hard_fail',
            'description' => 'Detects suspicious application velocity patterns indicating potential fraud',
            'criteria' => [
                'Max 3 applications per SIN in 24 hours',
                'Max 10 applications per SIN in 7 days',
                'Max 5 applications per IP in 1 hour',
                'Max 20 applications per IP in 24 hours',
                'Max 50 applications per email domain in 24 hours',
                'No multiple applications per SIN within 30 minutes',
            ],
            'action' => 'Hard fail - reject application immediately',
            'rationale' => 'High velocity patterns indicate automated fraud attempts or identity theft',
        ];
    }
}
