<?php

namespace App\Components\FeatureEngineering;

use App\Models\FraudRequest;
use Illuminate\Support\Facades\Log;

class FeatureExtractor
{
    private array $extractors;

    public function __construct()
    {
        $this->extractors = [
            new Extractors\ApplicantFeatureExtractor(),
            new Extractors\LoanFeatureExtractor(),
            new Extractors\VehicleFeatureExtractor(),
            new Extractors\CreditFeatureExtractor(),
            new Extractors\GeographicFeatureExtractor(),
            new Extractors\BehavioralFeatureExtractor(),
            new Extractors\TemporalFeatureExtractor(),
        ];
    }

    public function extractFeatures(FraudRequest $request): array
    {
        Log::info('Feature Engineering: Starting feature extraction', ['request_id' => $request->id]);

        $startTime = microtime(true);
        $features = [];
        $extractorResults = [];

        foreach ($this->extractors as $extractor) {
            $extractorStartTime = microtime(true);
            
            try {
                $extractorFeatures = $extractor->extract($request);
                $features = array_merge($features, $extractorFeatures);
                
                $extractorResults[] = [
                    'extractor' => get_class($extractor),
                    'feature_count' => count($extractorFeatures),
                    'processing_time_ms' => round((microtime(true) - $extractorStartTime) * 1000, 2),
                    'status' => 'success',
                ];

                Log::debug('Feature Engineering: Extractor completed', [
                    'request_id' => $request->id,
                    'extractor' => get_class($extractor),
                    'feature_count' => count($extractorFeatures),
                ]);

            } catch (\Exception $e) {
                $extractorResults[] = [
                    'extractor' => get_class($extractor),
                    'feature_count' => 0,
                    'processing_time_ms' => round((microtime(true) - $extractorStartTime) * 1000, 2),
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                Log::error('Feature Engineering: Extractor failed', [
                    'request_id' => $request->id,
                    'extractor' => get_class($extractor),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Ensure we have exactly 15 features for the ML model
        $features = $this->selectTop15Features($features);

        $processingTime = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Feature Engineering: Feature extraction completed', [
            'request_id' => $request->id,
            'total_features' => count($features),
            'processing_time_ms' => $processingTime,
        ]);

        return [
            'features' => $features,
            'feature_vector' => array_values($features),
            'feature_names' => array_keys($features),
            'extractor_results' => $extractorResults,
            'processing_time_ms' => $processingTime,
            'timestamp' => now()->toISOString(),
        ];
    }

    private function selectTop15Features(array $features): array
    {
        // Define the top 15 features based on importance for Canadian auto loans
        $top15FeatureNames = [
            'credit_score',
            'debt_to_income_ratio',
            'loan_to_value_ratio',
            'employment_months',
            'annual_income',
            'vehicle_age',
            'credit_history_years',
            'delinquencies_24m',
            'loan_amount',
            'vehicle_value',
            'credit_utilization',
            'recent_inquiries_6m',
            'address_months',
            'loan_term_months',
            'applicant_age',
        ];

        $selectedFeatures = [];

        // Select features in order of importance
        foreach ($top15FeatureNames as $featureName) {
            if (isset($features[$featureName])) {
                $selectedFeatures[$featureName] = $features[$featureName];
            } else {
                // Use default value if feature is missing
                $selectedFeatures[$featureName] = $this->getDefaultFeatureValue($featureName);
            }
        }

        return $selectedFeatures;
    }

    private function getDefaultFeatureValue(string $featureName): float
    {
        // Default values for missing features
        $defaults = [
            'credit_score' => 650.0,
            'debt_to_income_ratio' => 35.0,
            'loan_to_value_ratio' => 85.0,
            'employment_months' => 24.0,
            'annual_income' => 50000.0,
            'vehicle_age' => 5.0,
            'credit_history_years' => 5.0,
            'delinquencies_24m' => 0.0,
            'loan_amount' => 25000.0,
            'vehicle_value' => 30000.0,
            'credit_utilization' => 30.0,
            'recent_inquiries_6m' => 1.0,
            'address_months' => 24.0,
            'loan_term_months' => 60.0,
            'applicant_age' => 35.0,
        ];

        return $defaults[$featureName] ?? 0.0;
    }

    public function getFeatureDefinitions(): array
    {
        $definitions = [];

        foreach ($this->extractors as $extractor) {
            $definitions[get_class($extractor)] = $extractor->getDefinitions();
        }

        return $definitions;
    }
}
