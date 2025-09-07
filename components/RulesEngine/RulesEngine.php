<?php

namespace App\Components\RulesEngine;

use App\Models\FraudRequest;
use Illuminate\Support\Facades\Log;

class RulesEngine
{
    private array $hardFailRules;
    private array $riskScoringRules;

    public function __construct()
    {
        $this->hardFailRules = [
            new Rules\DuplicateApplicationRule(),
            new Rules\InvalidDataRule(),
            new Rules\SanctionsListRule(),
            new Rules\VelocityRule(),
        ];

        $this->riskScoringRules = [
            new Rules\IncomeDebtRatioRule(),
            new Rules\CreditHistoryRule(),
            new Rules\EmploymentStabilityRule(),
            new Rules\GeographicRiskRule(),
            new Rules\VehicleValueRule(),
        ];
    }

    public function evaluate(FraudRequest $request): array
    {
        Log::info('Rules Engine: Starting evaluation', ['request_id' => $request->id]);

        $result = [
            'hard_fail' => false,
            'hard_fail_reasons' => [],
            'risk_score' => 0,
            'risk_factors' => [],
            'rules_applied' => [],
            'processing_time_ms' => 0,
        ];

        $startTime = microtime(true);

        // Apply hard fail rules first
        foreach ($this->hardFailRules as $rule) {
            $ruleResult = $rule->evaluate($request);
            $result['rules_applied'][] = [
                'rule' => get_class($rule),
                'result' => $ruleResult,
                'timestamp' => now()->toISOString(),
            ];

            if ($ruleResult['triggered']) {
                $result['hard_fail'] = true;
                $result['hard_fail_reasons'][] = $ruleResult['reason'];
                Log::warning('Rules Engine: Hard fail triggered', [
                    'request_id' => $request->id,
                    'rule' => get_class($rule),
                    'reason' => $ruleResult['reason'],
                ]);
            }
        }

        // If hard fail, skip risk scoring
        if ($result['hard_fail']) {
            $result['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('Rules Engine: Hard fail detected, skipping risk scoring', [
                'request_id' => $request->id,
                'reasons' => $result['hard_fail_reasons'],
            ]);
            return $result;
        }

        // Apply risk scoring rules
        foreach ($this->riskScoringRules as $rule) {
            $ruleResult = $rule->evaluate($request);
            $result['rules_applied'][] = [
                'rule' => get_class($rule),
                'result' => $ruleResult,
                'timestamp' => now()->toISOString(),
            ];

            if ($ruleResult['triggered']) {
                $result['risk_score'] += $ruleResult['score'];
                $result['risk_factors'][] = [
                    'factor' => $ruleResult['factor'],
                    'score' => $ruleResult['score'],
                    'description' => $ruleResult['description'],
                ];
            }
        }

        // Normalize risk score to 0-100 scale
        $result['risk_score'] = min(100, max(0, $result['risk_score']));

        $result['processing_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Rules Engine: Evaluation completed', [
            'request_id' => $request->id,
            'risk_score' => $result['risk_score'],
            'risk_factors_count' => count($result['risk_factors']),
            'processing_time_ms' => $result['processing_time_ms'],
        ]);

        return $result;
    }

    public function getRuleDefinitions(): array
    {
        $definitions = [];

        foreach (array_merge($this->hardFailRules, $this->riskScoringRules) as $rule) {
            $definitions[] = $rule->getDefinition();
        }

        return $definitions;
    }
}
