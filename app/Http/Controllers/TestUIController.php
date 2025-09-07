<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Components\LLMAdjudicator\LLMAdjudicator;

class TestUIController extends Controller
{
    /**
     * Show the testing UI
     */
    public function index()
    {
        return view('test-ui');
    }

    /**
     * Generate test data using AI
     */
    public function generateTestData(Request $request): JsonResponse
    {
        try {
            $riskLevel = $request->input('risk_level', 'medium');
            $customPrompt = $request->input('custom_prompt');

            $prompt = $this->buildTestDataPrompt($riskLevel, $customPrompt);
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.llm_adjudicator.api_key'),
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url'),
                    'X-Title' => 'Fraud Detection Test Data Generator'
                ])
                ->post(config('services.llm_adjudicator.endpoint'), [
                    'model' => config('services.llm_adjudicator.model'),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => 2000,
                    'temperature' => 0.7,
                    'response_format' => ['type' => 'json_object']
                ]);

            if (!$response->successful()) {
                throw new \Exception('AI service request failed: ' . $response->body());
            }

            $aiResponse = $response->json();
            $content = $aiResponse['choices'][0]['message']['content'];
            
            // Clean up the content to extract JSON
            $content = $this->extractJsonFromContent($content);
            $testData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse AI response: ' . json_last_error_msg());
            }

            return response()->json([
                'success' => true,
                'data' => $testData,
                'risk_level' => $riskLevel
            ]);

        } catch (\Exception $e) {
            Log::error('Test data generation failed', [
                'error' => $e->getMessage(),
                'risk_level' => $riskLevel ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system health status
     */
    public function systemHealth(): JsonResponse
    {
        $health = [
            'laravel_api' => $this->checkLaravelHealth(),
            'ml_service' => $this->checkMLServiceHealth(),
            'llm_adjudicator' => $this->checkLLMHealth(),
            'database' => $this->checkDatabaseHealth(),
            'queue_worker' => $this->checkQueueWorkerHealth()
        ];

        $overallStatus = collect($health)->every(fn($status) => $status['status'] === 'healthy') 
            ? 'healthy' : 'degraded';

        return response()->json([
            'overall_status' => $overallStatus,
            'services' => $health,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Build AI prompt for test data generation
     */
    private function buildTestDataPrompt(string $riskLevel, ?string $customPrompt): string
    {
        if ($customPrompt) {
            return "Generate a realistic Canadian auto loan application based on this description: {$customPrompt}. " .
                   "Return a complete JSON object with all required fields for fraud detection testing. " .
                   "Include personal_info, address, financial_info, loan_info, and vehicle_info sections. " .
                   "Make all data realistic and internally consistent. Use valid Canadian postal codes, " .
                   "realistic SIN numbers (format: 123456789), and appropriate vehicle/financial combinations.";
        }

        $prompts = [
            'low' => "Generate a LOW RISK Canadian auto loan application that should be APPROVED. " .
                     "Include: stable employment (24+ months), good credit score (700+), reasonable " .
                     "loan-to-value ratio (<90%), consistent financial profile, valid Canadian data.",
            
            'medium' => "Generate a MEDIUM RISK Canadian auto loan application that should trigger LLM review. " .
                        "Include: mixed risk factors, borderline credit score (650-699), moderate employment " .
                        "history (12-23 months), loan-to-value ratio (85-95%), some concerning but not " .
                        "disqualifying factors.",
            
            'high' => "Generate a HIGH RISK Canadian auto loan application that should be DECLINED. " .
                      "Include: short employment (<12 months), poor credit indicators, high debt-to-income " .
                      "ratio (>50%), high loan-to-value ratio (>100%), inconsistent or suspicious data patterns.",
            
            'invalid' => "Generate an INVALID Canadian auto loan application with data validation errors. " .
                         "Include: invalid SIN format, underage applicant (<18), invalid postal code, " .
                         "missing required fields, or inconsistent data that should trigger validation failures."
        ];

        $basePrompt = $prompts[$riskLevel] ?? $prompts['medium'];
        
        return $basePrompt . " Return a complete JSON object with these exact sections: " .
               "personal_info (first_name, last_name, date_of_birth, sin, email, phone), " .
               "address (street, city, province, postal_code, country), " .
               "financial_info (annual_income, employment_type, employment_months, employer_name), " .
               "loan_info (amount, term_months, interest_rate, purpose), " .
               "vehicle_info (year, make, model, vin, value, mileage). " .
               "Make all data realistic and internally consistent for a Canadian context.";
    }

    /**
     * Extract JSON from AI response content
     */
    private function extractJsonFromContent(string $content): string
    {
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        
        // Try to find JSON object boundaries
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }
        
        return trim($content);
    }

    /**
     * Check Laravel API health
     */
    private function checkLaravelHealth(): array
    {
        try {
            return [
                'status' => 'healthy',
                'response_time' => 0,
                'version' => app()->version()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check ML Service health
     */
    private function checkMLServiceHealth(): array
    {
        try {
            $startTime = microtime(true);
            $response = Http::timeout(5)->get(config('services.ml_service.url') . '/healthz');
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'status' => 'healthy',
                    'response_time' => round($responseTime, 2),
                    'version' => $data['version'] ?? 'unknown',
                    'models_loaded' => $data['models_loaded'] ?? false
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'error' => 'HTTP ' . $response->status()
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check LLM Adjudicator health
     */
    private function checkLLMHealth(): array
    {
        try {
            $adjudicator = new LLMAdjudicator();
            $health = $adjudicator->getHealthStatus();
            
            return [
                'status' => $health['status'] === 'healthy' ? 'healthy' : 'unhealthy',
                'response_time' => $health['response_time_ms'] ?? null,
                'provider' => $health['provider'] ?? 'unknown',
                'model' => $health['model'] ?? 'unknown'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check database health
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $startTime = microtime(true);
            \DB::connection()->getPdo();
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'status' => 'healthy',
                'response_time' => round($responseTime, 2),
                'driver' => config('database.default')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check queue worker health
     */
    private function checkQueueWorkerHealth(): array
    {
        try {
            // Check if queue workers are running by looking for active processes
            $output = [];
            $returnCode = 0;
            exec('ps aux | grep "queue:work" | grep -v grep', $output, $returnCode);
            
            $activeWorkers = count($output);
            
            // Also check for pending jobs in the queue
            $pendingJobs = \DB::table('jobs')->count();
            $failedJobs = \DB::table('failed_jobs')->count();
            
            if ($activeWorkers > 0) {
                return [
                    'status' => 'healthy',
                    'active_workers' => $activeWorkers,
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs' => $failedJobs,
                    'queue_connection' => config('queue.default')
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'error' => 'No queue workers running',
                    'active_workers' => 0,
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs' => $failedJobs,
                    'queue_connection' => config('queue.default'),
                    'suggestion' => 'Run: php artisan queue:work'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }
}
