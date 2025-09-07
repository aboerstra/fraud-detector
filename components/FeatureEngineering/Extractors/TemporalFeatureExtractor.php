<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

class TemporalFeatureExtractor implements ExtractorInterface
{
    public function extract(FraudRequest $request): array
    {
        $data = $request->application_data;
        $features = [];

        $now = new \DateTime();

        // Time-based features
        $features['hour_of_day'] = floatval($now->format('H'));
        $features['day_of_week'] = floatval($now->format('N')); // 1=Monday, 7=Sunday
        $features['day_of_month'] = floatval($now->format('j'));
        $features['month_of_year'] = floatval($now->format('n'));

        // Business hours flag
        $features['business_hours'] = $this->isBusinessHours($now) ? 1.0 : 0.0;

        // Weekend flag
        $features['is_weekend'] = ($features['day_of_week'] >= 6) ? 1.0 : 0.0;

        // Holiday proximity (simplified)
        $features['near_holiday'] = $this->isNearHoliday($now) ? 1.0 : 0.0;

        // End of month flag
        $features['end_of_month'] = ($features['day_of_month'] >= 25) ? 1.0 : 0.0;

        // Time risk score
        $features['time_risk_score'] = $this->calculateTimeRisk($features);

        return $features;
    }

    private function isBusinessHours(\DateTime $dateTime): bool
    {
        $hour = intval($dateTime->format('H'));
        $dayOfWeek = intval($dateTime->format('N'));

        // Monday to Friday, 9 AM to 5 PM
        return ($dayOfWeek >= 1 && $dayOfWeek <= 5) && ($hour >= 9 && $hour <= 17);
    }

    private function isNearHoliday(\DateTime $dateTime): bool
    {
        $month = intval($dateTime->format('n'));
        $day = intval($dateTime->format('j'));

        // Major Canadian holidays (simplified)
        $holidays = [
            [1, 1],   // New Year's Day
            [7, 1],   // Canada Day
            [12, 25], // Christmas
            [12, 26], // Boxing Day
        ];

        foreach ($holidays as $holiday) {
            if ($month === $holiday[0] && abs($day - $holiday[1]) <= 3) {
                return true;
            }
        }

        return false;
    }

    private function calculateTimeRisk(array $features): float
    {
        $score = 1.0;

        // Higher risk outside business hours
        if ($features['business_hours'] == 0) {
            $score += 1.0;
        }

        // Higher risk on weekends
        if ($features['is_weekend'] == 1) {
            $score += 0.5;
        }

        // Higher risk very late at night or very early morning
        if ($features['hour_of_day'] >= 23 || $features['hour_of_day'] <= 5) {
            $score += 1.5;
        }

        // Higher risk near holidays
        if ($features['near_holiday'] == 1) {
            $score += 0.5;
        }

        return min(5.0, $score);
    }

    public function getDefinitions(): array
    {
        return [
            'hour_of_day' => 'Hour of day (0-23)',
            'day_of_week' => 'Day of week (1=Monday, 7=Sunday)',
            'day_of_month' => 'Day of month (1-31)',
            'month_of_year' => 'Month of year (1-12)',
            'business_hours' => 'Business hours flag (1=yes, 0=no)',
            'is_weekend' => 'Weekend flag (1=yes, 0=no)',
            'near_holiday' => 'Near holiday flag (1=yes, 0=no)',
            'end_of_month' => 'End of month flag (1=yes, 0=no)',
            'time_risk_score' => 'Time-based risk score (1-5)',
        ];
    }
}
