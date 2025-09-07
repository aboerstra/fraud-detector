<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Jobs\FraudDetectionJob;
use App\Models\FraudRequest;

class ApplicationController extends Controller
{
    /**
     * Submit a new fraud detection request
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Skip HMAC authentication for test UI requests
            if (!$request->hasHeader('X-Api-Key')) {
                // This is likely a test UI request, use simplified validation
                $validatedData = $this->validateTestUIData($request);
            } else {
                // Validate HMAC authentication for API requests
                $this->validateHmacAuthentication($request);
                
                // Validate application payload
                $validatedData = $this->validateApplicationData($request);
            }
            
            // Create fraud request record
            $fraudRequest = FraudRequest::create([
                'application_data' => $validatedData,
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'queued',
                'submitted_at' => now(),
            ]);
            
            // Enqueue fraud detection job
            FraudDetectionJob::dispatch($fraudRequest->id);
            
            Log::info('Fraud detection request submitted', [
                'job_id' => $fraudRequest->id,
                'client_ip' => $request->ip(),
            ]);
            
            return response()->json([
                'job_id' => $fraudRequest->id,
                'status' => 'queued',
                'polling_url' => route('applications.decision', ['job_id' => $fraudRequest->id]),
                'estimated_completion' => now()->addMinutes(2)->toISOString(),
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Fraud detection request failed', [
                'error' => $e->getMessage(),
                'client_ip' => $request->ip(),
            ]);
            
            return response()->json([
                'error' => 'Request processing failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Get fraud detection decision by job ID
     * 
     * @param string $jobId
     * @return JsonResponse
     */
    public function decision(string $jobId): JsonResponse
    {
        try {
            $fraudRequest = FraudRequest::findOrFail($jobId);
            
            $response = [
                'job_id' => $fraudRequest->id,
                'status' => $fraudRequest->status,
                'submitted_at' => $fraudRequest->submitted_at->toISOString(),
            ];
            
            if ($fraudRequest->status === 'decided') {
                $response['decision'] = [
                    'final_decision' => $fraudRequest->final_decision,
                    'reasons' => $fraudRequest->decision_reasons ?? [],
                ];
                
                $response['scores'] = [
                    'rule_score' => $fraudRequest->rule_score,
                    'rule_band' => $this->getScoreBand($fraudRequest->rule_score),
                    'confidence_score' => $fraudRequest->confidence_score,
                    'confidence_band' => $this->getScoreBand($fraudRequest->confidence_score),
                    'adjudicator_score' => $fraudRequest->adjudicator_score,
                    'adjudicator_band' => $this->getScoreBand($fraudRequest->adjudicator_score),
                ];
                
                $response['explainability'] = [
                    'rule_flags' => $fraudRequest->rule_flags ?? [],
                    'top_features' => $fraudRequest->top_features ?? [],
                    'adjudicator_rationale' => $fraudRequest->adjudicator_rationale ?? [],
                ];
                
                $response['timing'] = [
                    'received_at' => $fraudRequest->submitted_at->toISOString(),
                    'decided_at' => $fraudRequest->decided_at?->toISOString(),
                    'total_ms' => $fraudRequest->decided_at ? 
                        $fraudRequest->submitted_at->diffInMilliseconds($fraudRequest->decided_at) : null,
                ];
            } elseif ($fraudRequest->status === 'failed') {
                $response['error'] = $fraudRequest->error_message;
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Job not found',
                'message' => $e->getMessage(),
            ], 404);
        }
    }
    
    /**
     * Health check endpoint
     * 
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        try {
            // Check database connectivity
            \DB::connection()->getPdo();
            
            // Check queue status
            $queueStatus = $this->checkQueueHealth();
            
            return response()->json([
                'status' => 'healthy',
                'timestamp' => now()->toISOString(),
                'version' => config('app.version', '1.0.0'),
                'services' => [
                    'database' => 'healthy',
                    'queue' => $queueStatus,
                    'ml_service' => $this->checkMlServiceHealth(),
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'unhealthy',
                'timestamp' => now()->toISOString(),
                'error' => $e->getMessage(),
            ], 503);
        }
    }
    
    /**
     * Validate HMAC authentication
     * 
     * @param Request $request
     * @throws \Exception
     */
    private function validateHmacAuthentication(Request $request): void
    {
        $apiKey = $request->header('X-Api-Key');
        $timestamp = $request->header('X-Timestamp');
        $nonce = $request->header('X-Nonce');
        $signature = $request->header('X-Signature');
        
        if (!$apiKey || !$timestamp || !$nonce || !$signature) {
            throw new \Exception('Missing authentication headers');
        }
        
        // Check timestamp (within 5 minutes)
        if (abs(time() - $timestamp) > 300) {
            throw new \Exception('Request timestamp expired');
        }
        
        // Check for replay attacks (nonce should be unique)
        if (\DB::table('replay_nonces')->where('nonce', $nonce)->exists()) {
            throw new \Exception('Nonce already used');
        }
        
        // Validate HMAC signature
        $payload = $request->method() . $request->path() . $request->getContent() . $timestamp . $nonce;
        $expectedSignature = hash_hmac('sha256', $payload, config('app.hmac_secret'));
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new \Exception('Invalid signature');
        }
        
        // Store nonce to prevent replay
        \DB::table('replay_nonces')->insert([
            'nonce' => $nonce,
            'created_at' => now(),
        ]);
    }
    
    /**
     * Validate application data
     * 
     * @param Request $request
     * @return array
     */
    private function validateApplicationData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'personal_info.date_of_birth' => 'required|date|before:today',
            'personal_info.sin' => 'required|string|size:9',
            'personal_info.province' => 'required|string|size:2',
            
            'contact_info.email' => 'required|email',
            'contact_info.phone' => 'required|string',
            'contact_info.address.street' => 'required|string',
            'contact_info.address.city' => 'required|string',
            'contact_info.address.province' => 'required|string|size:2',
            'contact_info.address.postal_code' => 'required|string',
            
            'financial_info.annual_income' => 'required|numeric|min:0',
            'financial_info.employment_status' => 'required|string|in:employed,self_employed,unemployed,retired',
            
            'loan_info.amount' => 'required|numeric|min:1000|max:100000',
            'loan_info.term_months' => 'required|integer|min:12|max:84',
            'loan_info.down_payment' => 'required|numeric|min:0',
            
            'vehicle_info.vin' => 'required|string|size:17',
            'vehicle_info.year' => 'required|integer|min:1990|max:' . (date('Y') + 1),
            'vehicle_info.make' => 'required|string',
            'vehicle_info.model' => 'required|string',
            'vehicle_info.mileage' => 'required|integer|min:0',
            'vehicle_info.value' => 'required|numeric|min:1000',
            
            'dealer_info.dealer_id' => 'required|string',
            'dealer_info.location' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            throw new \Exception('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }
        
        return $validator->validated();
    }
    
    /**
     * Validate test UI data (simplified validation for test interface)
     * 
     * @param Request $request
     * @return array
     */
    private function validateTestUIData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            // Personal Information (very relaxed validation for testing)
            'personal_info.first_name' => 'nullable|string|max:255',
            'personal_info.last_name' => 'nullable|string|max:255',
            'personal_info.date_of_birth' => 'nullable', // Accept any date format
            'personal_info.sin' => 'nullable|string|max:50', // Accept any SIN format (with/without dashes)
            'personal_info.email' => 'nullable|string|max:255', // Accept any email-like string
            'personal_info.phone' => 'nullable|string|max:50', // Accept any phone format
            
            // Address (very relaxed validation)
            'address.street' => 'nullable|string|max:500',
            'address.city' => 'nullable|string|max:255',
            'address.province' => 'nullable|string|max:50', // Accept full province names or codes
            'address.postal_code' => 'nullable|string|max:20', // Accept any postal code format
            'address.country' => 'nullable|string|max:50',
            
            // Financial Information (very relaxed validation)
            'financial_info.annual_income' => 'nullable', // Accept any income value
            'financial_info.employment_type' => 'nullable|string|max:100',
            'financial_info.employment_months' => 'nullable', // Accept any employment duration
            'financial_info.employer_name' => 'nullable|string|max:255',
            
            // Loan Information (very relaxed validation)
            'loan_info.amount' => 'nullable', // Accept any loan amount
            'loan_info.term_months' => 'nullable', // Accept any term
            'loan_info.interest_rate' => 'nullable', // Accept any interest rate
            'loan_info.purpose' => 'nullable|string|max:255',
            
            // Vehicle Information (very relaxed validation)
            'vehicle_info.year' => 'nullable', // Accept any year
            'vehicle_info.make' => 'nullable|string|max:100',
            'vehicle_info.model' => 'nullable|string|max:100',
            'vehicle_info.vin' => 'nullable|string|max:50', // Accept any VIN format
            'vehicle_info.mileage' => 'nullable', // Accept any mileage
            'vehicle_info.value' => 'nullable', // Accept any vehicle value
        ]);
        
        if ($validator->fails()) {
            Log::warning('Test UI validation failed', [
                'errors' => $validator->errors()->all(),
                'data' => $request->all()
            ]);
            throw new \Exception('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }
        
        // Transform test UI data to match expected API format
        $validated = $validator->validated();
        
        // Helper function to safely get nested array values
        $get = function($array, $key, $default = null) {
            return $array[$key] ?? $default;
        };
        
        $personalInfo = $get($validated, 'personal_info', []);
        $address = $get($validated, 'address', []);
        $financialInfo = $get($validated, 'financial_info', []);
        $loanInfo = $get($validated, 'loan_info', []);
        $vehicleInfo = $get($validated, 'vehicle_info', []);
        
        return [
            'personal_info' => [
                'first_name' => $get($personalInfo, 'first_name', ''),
                'last_name' => $get($personalInfo, 'last_name', ''),
                'date_of_birth' => $get($personalInfo, 'date_of_birth', ''),
                'sin' => $get($personalInfo, 'sin', ''),
                'province' => $get($address, 'province', ''),
            ],
            'contact_info' => [
                'email' => $get($personalInfo, 'email', ''),
                'phone' => $get($personalInfo, 'phone', ''),
                'address' => [
                    'street' => $get($address, 'street', ''),
                    'city' => $get($address, 'city', ''),
                    'province' => $get($address, 'province', ''),
                    'postal_code' => $get($address, 'postal_code', ''),
                    'country' => $get($address, 'country', 'CA'),
                ],
            ],
            'financial_info' => [
                'annual_income' => $get($financialInfo, 'annual_income', 0),
                'employment_status' => $this->mapEmploymentType($get($financialInfo, 'employment_type', '')),
                'employment_months' => $get($financialInfo, 'employment_months', 0),
                'employer_name' => $get($financialInfo, 'employer_name', ''),
            ],
            'loan_info' => [
                'amount' => $get($loanInfo, 'amount', 0),
                'term_months' => $get($loanInfo, 'term_months', 0),
                'interest_rate' => $get($loanInfo, 'interest_rate', 0),
                'purpose' => $get($loanInfo, 'purpose', ''),
                'down_payment' => 0, // Default for test UI
            ],
            'vehicle_info' => [
                'year' => $get($vehicleInfo, 'year', 0),
                'make' => $get($vehicleInfo, 'make', ''),
                'model' => $get($vehicleInfo, 'model', ''),
                'vin' => $get($vehicleInfo, 'vin', ''),
                'mileage' => $get($vehicleInfo, 'mileage', 0),
                'value' => $get($vehicleInfo, 'value', 0),
            ],
            'dealer_info' => [
                'dealer_id' => 'TEST_UI_DEALER',
                'location' => ($get($address, 'city', '') ?: 'Unknown') . ', ' . ($get($address, 'province', '') ?: 'Unknown'),
            ],
        ];
    }
    
    /**
     * Map test UI employment type to API employment status
     * 
     * @param string $employmentType
     * @return string
     */
    private function mapEmploymentType(string $employmentType): string
    {
        $mapping = [
            'full_time' => 'employed',
            'part_time' => 'employed',
            'contract' => 'employed',
            'self_employed' => 'self_employed',
            'unemployed' => 'unemployed',
        ];
        
        return $mapping[$employmentType] ?? 'employed';
    }

    /**
     * Get score band based on numeric score
     * 
     * @param float|null $score
     * @return string
     */
    private function getScoreBand(?float $score): string
    {
        if ($score === null) return 'unknown';
        
        if ($score < 0.3) return 'low';
        if ($score < 0.7) return 'medium';
        return 'high';
    }
    
    /**
     * Check queue health
     * 
     * @return string
     */
    private function checkQueueHealth(): string
    {
        try {
            $pendingJobs = \DB::table('jobs')->count();
            $failedJobs = \DB::table('failed_jobs')->count();
            
            if ($pendingJobs > 100) return 'overloaded';
            if ($failedJobs > 10) return 'degraded';
            
            return 'healthy';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }
    
    /**
     * Check ML service health
     * 
     * @return string
     */
    private function checkMlServiceHealth(): string
    {
        try {
            $mlServiceUrl = config('services.ml_service.url', 'http://localhost:8000');
            $response = \Http::timeout(5)->get($mlServiceUrl . '/healthz');
            
            return $response->successful() ? 'healthy' : 'unhealthy';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }
}
