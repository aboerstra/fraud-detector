# Decision Engine Implementation Plan

## Overview

The Decision Engine is Stage 5 (final stage) of the fraud detection pipeline, responsible for combining all previous stage outputs into a final decision. It implements configurable business logic to determine whether an application should be approved, reviewed, or declined based on rule scores, ML confidence, and adjudicator assessment.

## Objectives

- **Final Decision**: Combine all scoring inputs into approve/review/decline decision
- **Configurable Logic**: Support flexible decision thresholds and business rules
- **Explainability**: Provide clear reasoning for each decision
- **Auditability**: Maintain complete decision trail with version tracking
- **Performance**: Complete decision assembly in <100ms

## Architecture

### Component Structure
```
app/Services/Decision/
├── DecisionEngine.php          # Main orchestrator
├── PolicyManager.php           # Decision policy management
├── ThresholdEvaluator.php      # Threshold-based decision logic
├── ReasonAssembler.php         # Decision reasoning compilation
├── Contracts/
│   ├── DecisionEngineInterface.php
│   ├── PolicyManagerInterface.php
│   └── ThresholdEvaluatorInterface.php
├── Data/
│   ├── DecisionRequest.php
│   ├── DecisionResponse.php
│   ├── DecisionPolicy.php
│   └── ScoreInputs.php
├── Policies/
│   ├── StandardPolicy.php
│   ├── ConservativePolicy.php
│   └── AggressivePolicy.php
└── Exceptions/
    ├── DecisionException.php
    └── PolicyException.php
```

### Decision Flow Architecture
```
┌─────────────────────────────────────┐
│ Stage Inputs                        │
│ ┌─────────────────────────────────┐ │
│ │ Rules Engine                    │ │
│ │ - rule_score: 0.25             │ │
│ │ - rule_flags: [...]            │ │
│ │ - hard_fail: false             │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ Feature Engineering             │ │
│ │ - feature_vector: [...]        │ │
│ │ - validation_status: valid     │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ ML Inference                    │ │
│ │ - confidence_score: 0.18       │ │
│ │ - top_features: [...]          │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ Bedrock Adjudicator             │ │
│ │ - adjudicator_score: 0.22      │ │
│ │ - rationale: [...]             │ │
│ └─────────────────────────────────┘ │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ Decision Engine                     │
│ ┌─────────────────────────────────┐ │
│ │ Policy Evaluation               │ │
│ │ - Load active policy            │ │
│ │ - Apply decision thresholds     │ │
│ │ - Check hard-fail conditions    │ │
│ └─────────────────────────────────┘ │
│ ┌─────────────────────────────────┐ │
│ │ Reason Assembly                 │ │
│ │ - Combine rule flags            │ │
│ │ - Include ML top features       │ │
│ │ - Add adjudicator rationale     │ │
│ └─────────────────────────────────┘ │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ Final Decision                      │
│ - final_decision: "approve"         │
│ - reasons: [...]                    │
│ - policy_version: "v1.0.0"         │
│ - confidence_level: "high"          │
└─────────────────────────────────────┘
```

### Database Schema
```sql
-- Decision policies for versioning
CREATE TABLE decision_policies (
    id UUID PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    policy_config JSONB NOT NULL,
    is_active BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Decision execution results
CREATE TABLE decision_executions (
    id UUID PRIMARY KEY,
    request_id UUID REFERENCES fraud_requests(id),
    final_decision VARCHAR(20) NOT NULL,
    decision_reasons JSONB NOT NULL,
    policy_version VARCHAR(50) NOT NULL,
    confidence_level VARCHAR(20),
    rule_score DECIMAL(5,4),
    ml_confidence_score DECIMAL(5,4),
    adjudicator_score DECIMAL(5,4),
    combined_score DECIMAL(5,4),
    execution_time_ms INTEGER,
    decided_at TIMESTAMP,
    created_at TIMESTAMP
);

-- Decision metrics for monitoring
CREATE TABLE decision_metrics (
    id UUID PRIMARY KEY,
    date_bucket DATE NOT NULL,
    policy_version VARCHAR(50) NOT NULL,
    total_decisions INTEGER DEFAULT 0,
    approve_count INTEGER DEFAULT 0,
    review_count INTEGER DEFAULT 0,
    decline_count INTEGER DEFAULT 0,
    avg_rule_score DECIMAL(5,4),
    avg_ml_score DECIMAL(5,4),
    avg_adjudicator_score DECIMAL(5,4),
    avg_execution_time_ms INTEGER,
    created_at TIMESTAMP,
    UNIQUE(date_bucket, policy_version)
);
```

## Decision Engine Implementation

### Main Decision Engine
```php
<?php

namespace App\Services\Decision;

use App\Services\Decision\Data\DecisionRequest;
use App\Services\Decision\Data\DecisionResponse;
use App\Services\Decision\Data\ScoreInputs;
use App\Services\Decision\Contracts\DecisionEngineInterface;
use App\Services\Decision\Contracts\PolicyManagerInterface;
use App\Services\Decision\Contracts\ThresholdEvaluatorInterface;
use Illuminate\Support\Facades\Log;

class DecisionEngine implements DecisionEngineInterface
{
    public function __construct(
        private PolicyManagerInterface $policyManager,
        private ThresholdEvaluatorInterface $thresholdEvaluator,
        private ReasonAssembler $reasonAssembler
    ) {}
    
    public function makeDecision(DecisionRequest $request): DecisionResponse
    {
        $startTime = microtime(true);
        
        try {
            // Load active decision policy
            $policy = $this->policyManager->getActivePolicy();
            
            // Extract score inputs
            $scoreInputs = $this->extractScoreInputs($request);
            
            // Check for hard-fail conditions first
            if ($this->hasHardFailConditions($request)) {
                return $this->createHardFailDecision($request, $policy, $startTime);
            }
            
            // Evaluate decision thresholds
            $decision = $this->thresholdEvaluator->evaluate($scoreInputs, $policy);
            
            // Assemble decision reasons
            $reasons = $this->reasonAssembler->assembleReasons(
                $decision,
                $request,
                $scoreInputs
            );
            
            // Calculate confidence level
            $confidenceLevel = $this->calculateConfidenceLevel($scoreInputs, $decision);
            
            // Calculate combined score for analytics
            $combinedScore = $this->calculateCombinedScore($scoreInputs, $policy);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            Log::info('Decision completed', [
                'request_id' => $request->requestId,
                'final_decision' => $decision,
                'policy_version' => $policy->version,
                'execution_time_ms' => round($executionTime)
            ]);
            
            return new DecisionResponse(
                finalDecision: $decision,
                reasons: $reasons,
                policyVersion: $policy->version,
                confidenceLevel: $confidenceLevel,
                combinedScore: $combinedScore,
                executionTimeMs: round($executionTime)
            );
            
        } catch (\Exception $e) {
            Log::error('Decision engine failed', [
                'request_id' => $request->requestId,
                'error' => $e->getMessage()
            ]);
            
            // Return safe fallback decision
            return $this->createFallbackDecision($request, $startTime);
        }
    }
    
    private function extractScoreInputs(DecisionRequest $request): ScoreInputs
    {
        return new ScoreInputs(
            ruleScore: $request->ruleScore,
            ruleFlags: $request->ruleFlags,
            mlConfidenceScore: $request->mlConfidenceScore,
            mlTopFeatures: $request->mlTopFeatures,
            adjudicatorScore: $request->adjudicatorScore,
            adjudicatorRationale: $request->adjudicatorRationale
        );
    }
    
    private function hasHardFailConditions(DecisionRequest $request): bool
    {
        // Check for hard-fail rule flags
        $hardFailFlags = [
            'invalid_sin_checksum',
            'missing_mandatory_fields',
            'deny_list_hit'
        ];
        
        foreach ($hardFailFlags as $flag) {
            if (in_array($flag, $request->ruleFlags)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function createHardFailDecision(
        DecisionRequest $request,
        $policy,
        float $startTime
    ): DecisionResponse {
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        $reasons = $this->reasonAssembler->assembleHardFailReasons($request->ruleFlags);
        
        return new DecisionResponse(
            finalDecision: 'decline',
            reasons: $reasons,
            policyVersion: $policy->version,
            confidenceLevel: 'high',
            combinedScore: 1.0,
            executionTimeMs: round($executionTime)
        );
    }
    
    private function createFallbackDecision(
        DecisionRequest $request,
        float $startTime
    ): DecisionResponse {
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        return new DecisionResponse(
            finalDecision: 'review',
            reasons: ['System error occurred - manual review required'],
            policyVersion: 'fallback',
            confidenceLevel: 'low',
            combinedScore: 0.5,
            executionTimeMs: round($executionTime)
        );
    }
    
    private function calculateConfidenceLevel(ScoreInputs $inputs, string $decision): string
    {
        // Calculate confidence based on score consistency
        $scores = array_filter([
            $inputs->ruleScore,
            $inputs->mlConfidenceScore,
            $inputs->adjudicatorScore
        ]);
        
        if (empty($scores)) {
            return 'low';
        }
        
        $avgScore = array_sum($scores) / count($scores);
        $variance = $this->calculateVariance($scores, $avgScore);
        
        // High confidence if scores are consistent and clear
        if ($variance < 0.05) {
            if (($decision === 'approve' && $avgScore < 0.3) ||
                ($decision === 'decline' && $avgScore > 0.7)) {
                return 'high';
            }
        }
        
        // Medium confidence for moderate consistency
        if ($variance < 0.15) {
            return 'medium';
        }
        
        return 'low';
    }
    
    private function calculateCombinedScore(ScoreInputs $inputs, $policy): float
    {
        $weights = $policy->config['score_weights'] ?? [
            'rule_score' => 0.3,
            'ml_confidence' => 0.5,
            'adjudicator_score' => 0.2
        ];
        
        $weightedSum = 0;
        $totalWeight = 0;
        
        if ($inputs->ruleScore !== null) {
            $weightedSum += $inputs->ruleScore * $weights['rule_score'];
            $totalWeight += $weights['rule_score'];
        }
        
        if ($inputs->mlConfidenceScore !== null) {
            $weightedSum += $inputs->mlConfidenceScore * $weights['ml_confidence'];
            $totalWeight += $weights['ml_confidence'];
        }
        
        if ($inputs->adjudicatorScore !== null) {
            $weightedSum += $inputs->adjudicatorScore * $weights['adjudicator_score'];
            $totalWeight += $weights['adjudicator_score'];
        }
        
        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.5;
    }
    
    private function calculateVariance(array $scores, float $mean): float
    {
        $squaredDiffs = array_map(function($score) use ($mean) {
            return pow($score - $mean, 2);
        }, $scores);
        
        return array_sum($squaredDiffs) / count($scores);
    }
}
```

### Threshold Evaluator
```php
<?php

namespace App\Services\Decision;

use App\Services\Decision\Data\ScoreInputs;
use App\Services\Decision\Data\DecisionPolicy;
use App\Services\Decision\Contracts\ThresholdEvaluatorInterface;

class ThresholdEvaluator implements ThresholdEvaluatorInterface
{
    public function evaluate(ScoreInputs $inputs, DecisionPolicy $policy): string
    {
        $thresholds = $policy->config['thresholds'];
        
        // Check individual score thresholds
        if ($this->exceedsDeclineThreshold($inputs, $thresholds)) {
            return 'decline';
        }
        
        if ($this->exceedsReviewThreshold($inputs, $thresholds)) {
            return 'review';
        }
        
        // Check combined score threshold
        $combinedScore = $this->calculateWeightedScore($inputs, $policy);
        
        if ($combinedScore >= $thresholds['combined_decline_threshold']) {
            return 'decline';
        }
        
        if ($combinedScore >= $thresholds['combined_review_threshold']) {
            return 'review';
        }
        
        return 'approve';
    }
    
    private function exceedsDeclineThreshold(ScoreInputs $inputs, array $thresholds): bool
    {
        // Any single score above decline threshold
        if ($inputs->ruleScore !== null && 
            $inputs->ruleScore >= $thresholds['rule_decline_threshold']) {
            return true;
        }
        
        if ($inputs->mlConfidenceScore !== null && 
            $inputs->mlConfidenceScore >= $thresholds['ml_decline_threshold']) {
            return true;
        }
        
        if ($inputs->adjudicatorScore !== null && 
            $inputs->adjudicatorScore >= $thresholds['adjudicator_decline_threshold']) {
            return true;
        }
        
        return false;
    }
    
    private function exceedsReviewThreshold(ScoreInputs $inputs, array $thresholds): bool
    {
        // Any single score above review threshold
        if ($inputs->ruleScore !== null && 
            $inputs->ruleScore >= $thresholds['rule_review_threshold']) {
            return true;
        }
        
        if ($inputs->mlConfidenceScore !== null && 
            $inputs->mlConfidenceScore >= $thresholds['ml_review_threshold']) {
            return true;
        }
        
        if ($inputs->adjudicatorScore !== null && 
            $inputs->adjudicatorScore >= $thresholds['adjudicator_review_threshold']) {
            return true;
        }
        
        return false;
    }
    
    private function calculateWeightedScore(ScoreInputs $inputs, DecisionPolicy $policy): float
    {
        $weights = $policy->config['score_weights'];
        
        $weightedSum = 0;
        $totalWeight = 0;
        
        if ($inputs->ruleScore !== null) {
            $weightedSum += $inputs->ruleScore * $weights['rule_score'];
            $totalWeight += $weights['rule_score'];
        }
        
        if ($inputs->mlConfidenceScore !== null) {
            $weightedSum += $inputs->mlConfidenceScore * $weights['ml_confidence'];
            $totalWeight += $weights['ml_confidence'];
        }
        
        if ($inputs->adjudicatorScore !== null) {
            $weightedSum += $inputs->adjudicatorScore * $weights['adjudicator_score'];
            $totalWeight += $weights['adjudicator_score'];
        }
        
        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }
}
```

### Reason Assembler
```php
<?php

namespace App\Services\Decision;

use App\Services\Decision\Data\DecisionRequest;
use App\Services\Decision\Data\ScoreInputs;

class ReasonAssembler
{
    public function assembleReasons(
        string $decision,
        DecisionRequest $request,
        ScoreInputs $inputs
    ): array {
        $reasons = [];
        
        // Add rule-based reasons
        $reasons = array_merge($reasons, $this->getRuleReasons($inputs, $decision));
        
        // Add ML-based reasons
        $reasons = array_merge($reasons, $this->getMlReasons($inputs, $decision));
        
        // Add adjudicator reasons
        $reasons = array_merge($reasons, $this->getAdjudicatorReasons($inputs, $decision));
        
        // Add decision-specific summary
        $reasons[] = $this->getDecisionSummary($decision, $inputs);
        
        // Limit to top 5 reasons and remove duplicates
        return array_slice(array_unique($reasons), 0, 5);
    }
    
    public function assembleHardFailReasons(array $ruleFlags): array
    {
        $reasons = [];
        
        if (in_array('invalid_sin_checksum', $ruleFlags)) {
            $reasons[] = 'Invalid Social Insurance Number provided';
        }
        
        if (in_array('missing_mandatory_fields', $ruleFlags)) {
            $reasons[] = 'Required application information is missing';
        }
        
        if (in_array('deny_list_hit', $ruleFlags)) {
            $reasons[] = 'Application contains information on security watch list';
        }
        
        $reasons[] = 'Application declined due to policy violations';
        
        return $reasons;
    }
    
    private function getRuleReasons(ScoreInputs $inputs, string $decision): array
    {
        $reasons = [];
        
        if (empty($inputs->ruleFlags)) {
            if ($decision === 'approve') {
                $reasons[] = 'No rule violations detected';
            }
            return $reasons;
        }
        
        // Map rule flags to human-readable reasons
        $flagReasons = [
            'province_ip_mismatch' => 'Geographic location inconsistency detected',
            'high_email_velocity' => 'Email address used frequently in recent applications',
            'phone_reuse_detected' => 'Phone number associated with multiple applications',
            'vin_reuse_detected' => 'Vehicle identification number previously used',
            'very_high_ltv' => 'Loan amount significantly exceeds vehicle value',
            'dealer_volume_spike' => 'Unusual application volume from dealer',
            'high_risk_dealer' => 'Application from high-risk dealer location'
        ];
        
        foreach ($inputs->ruleFlags as $flag) {
            if (isset($flagReasons[$flag])) {
                $reasons[] = $flagReasons[$flag];
            }
        }
        
        return array_slice($reasons, 0, 3); // Limit rule reasons
    }
    
    private function getMlReasons(ScoreInputs $inputs, string $decision): array
    {
        $reasons = [];
        
        if ($inputs->mlConfidenceScore === null) {
            return $reasons;
        }
        
        // Add ML confidence assessment
        if ($inputs->mlConfidenceScore < 0.3) {
            $reasons[] = 'Machine learning analysis indicates low fraud risk';
        } elseif ($inputs->mlConfidenceScore > 0.7) {
            $reasons[] = 'Machine learning analysis indicates elevated fraud risk';
        }
        
        // Add top ML features
        if (!empty($inputs->mlTopFeatures)) {
            $topFeature = $inputs->mlTopFeatures[0];
            $direction = $topFeature['direction'] === 'increases_risk' ? 'increases' : 'decreases';
            $reasons[] = "Primary risk factor: {$topFeature['feature']} {$direction} fraud likelihood";
        }
        
        return $reasons;
    }
    
    private function getAdjudicatorReasons(ScoreInputs $inputs, string $decision): array
    {
        $reasons = [];
        
        if (empty($inputs->adjudicatorRationale)) {
            return $reasons;
        }
        
        // Add adjudicator rationale (already human-readable)
        foreach ($inputs->adjudicatorRationale as $rationale) {
            $reasons[] = "Expert assessment: " . $rationale;
        }
        
        return array_slice($reasons, 0, 2); // Limit adjudicator reasons
    }
    
    private function getDecisionSummary(string $decision, ScoreInputs $inputs): string
    {
        switch ($decision) {
            case 'approve':
                return 'Application approved based on comprehensive risk assessment';
            case 'review':
                return 'Application requires manual review due to elevated risk indicators';
            case 'decline':
                return 'Application declined due to high fraud risk assessment';
            default:
                return 'Decision made based on available risk information';
        }
    }
}
```

## Decision Policy Management

### Standard Decision Policy v1.0
```php
<?php

namespace App\Services\Decision\Policies;

use App\Services\Decision\Data\DecisionPolicy;

class StandardPolicy
{
    public static function getPolicy(): DecisionPolicy
    {
        return new DecisionPolicy(
            version: 'v1.0.0',
            name: 'Standard Auto Loan Policy',
            description: 'Balanced approach for auto loan fraud detection',
            config: [
                'thresholds' => [
                    // Individual score thresholds
                    'rule_review_threshold' => 0.6,
                    'rule_decline_threshold' => 0.8,
                    'ml_review_threshold' => 0.5,
                    'ml_decline_threshold' => 0.75,
                    'adjudicator_review_threshold' => 0.6,
                    'adjudicator_decline_threshold' => 0.8,
                    
                    // Combined score thresholds
                    'combined_review_threshold' => 0.4,
                    'combined_decline_threshold' => 0.7
                ],
                'score_weights' => [
                    'rule_score' => 0.3,
                    'ml_confidence' => 0.5,
                    'adjudicator_score' => 0.2
                ],
                'hard_fail_rules' => [
                    'invalid_sin_checksum',
                    'missing_mandatory_fields',
                    'deny_list_hit'
                ],
                'review_triggers' => [
                    'min_scores_for_review' => 2, // At least 2 scores above review threshold
                    'adjudicator_override' => true, // Adjudicator can trigger review
                    'rule_flag_count_threshold' => 3 // 3+ rule flags trigger review
                ]
            ]
        );
    }
}
```

### Conservative Policy
```php
<?php

namespace App\Services\Decision\Policies;

use App\Services\Decision\Data\DecisionPolicy;

class ConservativePolicy
{
    public static function getPolicy(): DecisionPolicy
    {
        return new DecisionPolicy(
            version: 'v1.0.0-conservative',
            name: 'Conservative Auto Loan Policy',
            description: 'Risk-averse approach with lower thresholds',
            config: [
                'thresholds' => [
                    // Lower thresholds for conservative approach
                    'rule_review_threshold' => 0.4,
                    'rule_decline_threshold' => 0.6,
                    'ml_review_threshold' => 0.3,
                    'ml_decline_threshold' => 0.5,
                    'adjudicator_review_threshold' => 0.4,
                    'adjudicator_decline_threshold' => 0.6,
                    
                    'combined_review_threshold' => 0.25,
                    'combined_decline_threshold' => 0.5
                ],
                'score_weights' => [
                    'rule_score' => 0.4,      // Higher weight on rules
                    'ml_confidence' => 0.4,
                    'adjudicator_score' => 0.2
                ],
                'hard_fail_rules' => [
                    'invalid_sin_checksum',
                    'missing_mandatory_fields',
                    'deny_list_hit'
                ],
                'review_triggers' => [
                    'min_scores_for_review' => 1, // Single score above threshold
                    'adjudicator_override' => true,
                    'rule_flag_count_threshold' => 2 // 2+ rule flags trigger review
                ]
            ]
        );
    }
}
```

## Policy Manager Implementation

```php
<?php

namespace App\Services\Decision;

use App\Services\Decision\Data\DecisionPolicy;
use App\Services\Decision\Contracts\PolicyManagerInterface;
use App\Services\Decision\Policies\StandardPolicy;
use App\Services\Decision\Policies\ConservativePolicy;
use App\Services\Decision\Policies\AggressivePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PolicyManager implements PolicyManagerInterface
{
    public function getActivePolicy(): DecisionPolicy
    {
        // Try to get from cache first
        $policy = Cache::remember('active_decision_policy', 300, function () {
            return $this->loadActivePolicyFromDatabase();
        });
        
        return $policy ?? $this->getDefaultPolicy();
    }
    
    public function setActivePolicy(string $version): bool
    {
        try {
            DB::transaction(function () use ($version) {
                // Deactivate all policies
                DB::table('decision_policies')->update(['is_active' => false]);
                
                // Activate specified policy
                DB::table('decision_policies')
                    ->where('version', $version)
                    ->update(['is_active' => true]);
            });
            
            // Clear cache
            Cache::forget('active_decision_policy');
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function createPolicy(DecisionPolicy $policy): bool
    {
        try {
            DB::table('decision_policies')->insert([
                'id' => \Str::uuid(),
                'version' => $policy->version,
                'name' => $policy->name,
                'description' => $policy->description,
                'policy_config' => json_encode($policy->config),
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getAllPolicies(): array
    {
        $policies = DB::table('decision_policies')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return $policies->map(function ($policy) {
            return new DecisionPolicy(
                version: $policy->version,
                name: $policy->name,
                description: $policy->description,
                config: json_decode($policy->policy_config, true)
            );
        })->toArray();
    }
    
    private function loadActivePolicyFromDatabase(): ?DecisionPolicy
    {
        $policy = DB::table('decision_policies')
            ->where('is_active', true)
            ->first();
            
        if (!$policy) {
            return null;
        }
        
        return new DecisionPolicy(
            version: $policy->version,
            name: $policy->name,
            description: $policy->description,
            config: json_decode($policy->policy_config, true)
        );
    }
    
    private function getDefaultPolicy(): DecisionPolicy
    {
        return StandardPolicy::getPolicy();
    }
    
    public function initializeDefaultPolicies(): void
    {
        $policies = [
            StandardPolicy::getPolicy(),
            ConservativePolicy::getPolicy(),
            // AggressivePolicy::getPolicy() // Uncomment when implemented
        ];
        
        foreach ($policies as $policy) {
            $exists = DB::table('decision_policies')
                ->where('version', $policy->version)
                ->exists();
                
            if (!$exists) {
                $this->createPolicy($policy);
            }
        }
        
        // Set standard policy as active if no active policy exists
        $hasActivePolicy = DB::table('decision_policies')
            ->where('is_active', true)
            ->exists();
            
        if (!$hasActivePolicy) {
            $this->setActivePolicy('v1.0.0');
        }
    }
}
```

## Implementation Tasks

### Phase 1: Core Infrastructure (Week 1)
1. **Decision Interfaces**: Define contracts for decision engine components
2. **Decision Engine**: Implement main orchestrator and decision logic
3. **Policy Manager**: Configuration management and policy loading
4. **Database Schema**: Create tables for policies and executions
5. **Basic Policies**: Implement standard decision policy

### Phase 2: Advanced Logic (Week 2)
1. **Threshold Evaluator**: Complex threshold evaluation logic
2. **Reason Assembler**: Human-readable reason generation
3. **Policy Variants**: Conservative and aggressive policies
4. **Confidence Calculation**: Decision confidence assessment
5. **Fallback Handling**: Error recovery and safe defaults

### Phase 3: Management & Testing (Week 3)
1. **Policy Management**: Admin interface for policy configuration
2. **A/B Testing**: Support for multiple policy versions
3. **Decision Analytics**: Metrics and performance tracking
4. **Testing Suite**: Unit tests, integration tests, policy tests
5. **Documentation**: Decision logic and policy descriptions

## Performance Requirements

- **Decision Time**: <100ms per decision
- **Throughput**: 200+ decisions per minute
- **Memory Usage**: <20MB per decision process
- **Policy Loading**: <50ms policy retrieval

## Monitoring & Alerting

### Metrics
- Decision distribution (approve/review/decline rates)
- Decision execution times
- Policy effectiveness metrics
- Score consistency analysis
- Confidence level distribution

### Alerts
- Decision rate anomalies
- High execution times
- Policy configuration errors
- Score inconsistencies
- Fallback decision spikes

## Security Considerations

- **Policy Access Control**: Restrict policy modification access
- **Audit Trail**: Log all policy changes and decisions
- **Data Retention**: Automatic cleanup
