# Rules Engine Implementation Plan

## Overview

The Rules Engine is Stage 1 of the fraud detection pipeline, responsible for deterministic risk assessment and hard-fail validation. It processes application data through configurable business rules and outputs risk scores and flags.

## Objectives

- **Hard-fail Detection**: Identify applications that should be immediately declined
- **Risk Scoring**: Generate normalized risk scores (0-1) based on business rules
- **Explainability**: Provide clear flags indicating which rules were triggered
- **Performance**: Process rules in <500ms per application
- **Configurability**: Support rule updates without code deployment

## Architecture

### Component Structure
```
app/Services/Rules/
├── RulesEngine.php           # Main orchestrator
├── RuleProcessor.php         # Individual rule execution
├── RuleRegistry.php          # Rule configuration management
├── Rules/                    # Individual rule implementations
│   ├── HardFailRules/
│   │   ├── SinValidationRule.php
│   │   ├── MandatoryFieldsRule.php
│   │   └── DenyListRule.php
│   └── RiskScoringRules/
│       ├── GeographicConsistencyRule.php
│       ├── VelocityRule.php
│       ├── LoanToValueRule.php
│       └── DealerRiskRule.php
├── Contracts/
│   ├── RuleInterface.php
│   ├── HardFailRuleInterface.php
│   └── RiskScoringRuleInterface.php
└── Data/
    ├── RuleResult.php
    ├── RuleContext.php
    └── RuleManifest.php
```

### Database Schema
```sql
-- Rule configuration and versioning
CREATE TABLE rule_packs (
    id UUID PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    rules_config JSONB NOT NULL,
    is_active BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Rule execution results
CREATE TABLE rule_executions (
    id UUID PRIMARY KEY,
    request_id UUID REFERENCES fraud_requests(id),
    rule_pack_version VARCHAR(50) NOT NULL,
    rule_score DECIMAL(5,4) NOT NULL,
    rule_flags JSONB NOT NULL,
    hard_fail_triggered BOOLEAN DEFAULT false,
    execution_time_ms INTEGER,
    executed_at TIMESTAMP,
    created_at TIMESTAMP
);

-- Deny lists for hard-fail rules
CREATE TABLE deny_lists (
    id UUID PRIMARY KEY,
    list_type VARCHAR(50) NOT NULL, -- 'sin', 'email', 'phone', 'vin'
    value_hash VARCHAR(255) NOT NULL, -- Hashed for privacy
    reason VARCHAR(255),
    added_by VARCHAR(255),
    expires_at TIMESTAMP,
    created_at TIMESTAMP,
    INDEX idx_deny_lists_type_hash (list_type, value_hash)
);
```

## Rule Implementation

### 1. Hard-Fail Rules (Short-Circuit)

#### SIN Validation Rule
```php
class SinValidationRule implements HardFailRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $sin = $context->getPersonalInfo()['sin'] ?? null;
        
        if (!$this->isValidSinFormat($sin)) {
            return RuleResult::hardFail('invalid_sin_format');
        }
        
        if (!$this->isValidSinChecksum($sin)) {
            return RuleResult::hardFail('invalid_sin_checksum');
        }
        
        return RuleResult::pass();
    }
    
    private function isValidSinChecksum(string $sin): bool
    {
        // Luhn algorithm implementation for SIN validation
        $digits = str_split($sin);
        $checksum = 0;
        
        for ($i = 0; $i < 8; $i++) {
            $digit = (int)$digits[$i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = $digit % 10 + 1;
                }
            }
            $checksum += $digit;
        }
        
        $checkDigit = (10 - ($checksum % 10)) % 10;
        return $checkDigit === (int)$digits[8];
    }
}
```

#### Mandatory Fields Rule
```php
class MandatoryFieldsRule implements HardFailRuleInterface
{
    private array $requiredFields = [
        'personal_info.date_of_birth',
        'personal_info.sin',
        'personal_info.province',
        'contact_info.email',
        'contact_info.phone',
        'financial_info.annual_income',
        'loan_info.amount',
        'vehicle_info.vin'
    ];
    
    public function evaluate(RuleContext $context): RuleResult
    {
        $missingFields = [];
        
        foreach ($this->requiredFields as $field) {
            if (!$context->hasField($field)) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            return RuleResult::hardFail('missing_mandatory_fields', [
                'missing_fields' => $missingFields
            ]);
        }
        
        return RuleResult::pass();
    }
}
```

#### Deny List Rule
```php
class DenyListRule implements HardFailRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $checks = [
            'sin' => $context->getPersonalInfo()['sin'] ?? null,
            'email' => $context->getContactInfo()['email'] ?? null,
            'phone' => $context->getContactInfo()['phone'] ?? null,
            'vin' => $context->getVehicleInfo()['vin'] ?? null,
        ];
        
        foreach ($checks as $type => $value) {
            if ($value && $this->isOnDenyList($type, $value)) {
                return RuleResult::hardFail('deny_list_hit', [
                    'list_type' => $type,
                    'value_type' => $type
                ]);
            }
        }
        
        return RuleResult::pass();
    }
    
    private function isOnDenyList(string $type, string $value): bool
    {
        $hash = hash('sha256', strtolower(trim($value)));
        
        return DB::table('deny_lists')
            ->where('list_type', $type)
            ->where('value_hash', $hash)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }
}
```

### 2. Risk Scoring Rules

#### Geographic Consistency Rule
```php
class GeographicConsistencyRule implements RiskScoringRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $score = 0.0;
        $flags = [];
        
        // Province-IP mismatch check
        $declaredProvince = $context->getPersonalInfo()['province'] ?? null;
        $ipProvince = $this->getProvinceFromIp($context->getClientIp());
        
        if ($declaredProvince && $ipProvince && $declaredProvince !== $ipProvince) {
            $score += 0.3;
            $flags[] = 'province_ip_mismatch';
        }
        
        // Address-postal code consistency
        $address = $context->getContactInfo()['address'] ?? [];
        if (!$this->isValidPostalCodeForProvince(
            $address['postal_code'] ?? null,
            $address['province'] ?? null
        )) {
            $score += 0.2;
            $flags[] = 'invalid_postal_province_combo';
        }
        
        return RuleResult::scored($score, $flags);
    }
    
    private function getProvinceFromIp(string $ip): ?string
    {
        // IP geolocation service integration
        // Return province code or null if cannot determine
    }
}
```

#### Velocity Rule
```php
class VelocityRule implements RiskScoringRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $score = 0.0;
        $flags = [];
        
        $email = $context->getContactInfo()['email'] ?? null;
        $phone = $context->getContactInfo()['phone'] ?? null;
        $vin = $context->getVehicleInfo()['vin'] ?? null;
        
        // Email reuse in last 30 days
        if ($email) {
            $emailCount = $this->getRecentApplicationCount('email', $email, 30);
            if ($emailCount > 3) {
                $score += 0.4;
                $flags[] = 'high_email_velocity';
            } elseif ($emailCount > 1) {
                $score += 0.2;
                $flags[] = 'moderate_email_velocity';
            }
        }
        
        // Phone reuse in last 7 days
        if ($phone) {
            $phoneCount = $this->getRecentApplicationCount('phone', $phone, 7);
            if ($phoneCount > 1) {
                $score += 0.3;
                $flags[] = 'phone_reuse_detected';
            }
        }
        
        // VIN reuse (should be unique)
        if ($vin) {
            $vinCount = $this->getRecentApplicationCount('vin', $vin, 365);
            if ($vinCount > 0) {
                $score += 0.5;
                $flags[] = 'vin_reuse_detected';
            }
        }
        
        return RuleResult::scored(min($score, 1.0), $flags);
    }
    
    private function getRecentApplicationCount(string $field, string $value, int $days): int
    {
        return DB::table('fraud_requests')
            ->whereRaw("application_data->'contact_info'->>'$field' = ?", [$value])
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }
}
```

#### Loan-to-Value Rule
```php
class LoanToValueRule implements RiskScoringRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $loanAmount = $context->getLoanInfo()['amount'] ?? 0;
        $vehicleValue = $context->getVehicleInfo()['value'] ?? 0;
        $downPayment = $context->getLoanInfo()['down_payment'] ?? 0;
        
        if ($vehicleValue <= 0) {
            return RuleResult::scored(0.3, ['invalid_vehicle_value']);
        }
        
        $ltv = ($loanAmount + $downPayment) / $vehicleValue;
        $score = 0.0;
        $flags = [];
        
        if ($ltv > 1.2) {
            $score = 0.8;
            $flags[] = 'very_high_ltv';
        } elseif ($ltv > 1.0) {
            $score = 0.5;
            $flags[] = 'high_ltv';
        } elseif ($ltv > 0.9) {
            $score = 0.2;
            $flags[] = 'moderate_ltv';
        }
        
        // Down payment ratio check
        $income = $context->getFinancialInfo()['annual_income'] ?? 0;
        if ($income > 0) {
            $dpRatio = $downPayment / $income;
            if ($dpRatio < 0.05) { // Less than 5% of annual income
                $score += 0.2;
                $flags[] = 'low_down_payment_ratio';
            }
        }
        
        return RuleResult::scored(min($score, 1.0), $flags);
    }
}
```

### 3. Dealer Risk Rule
```php
class DealerRiskRule implements RiskScoringRuleInterface
{
    public function evaluate(RuleContext $context): RuleResult
    {
        $dealerId = $context->getDealerInfo()['dealer_id'] ?? null;
        
        if (!$dealerId) {
            return RuleResult::scored(0.2, ['missing_dealer_id']);
        }
        
        $score = 0.0;
        $flags = [];
        
        // 24-hour volume spike
        $recentVolume = $this->getDealerVolume($dealerId, 1);
        $avgVolume = $this->getDealerAverageVolume($dealerId, 30);
        
        if ($avgVolume > 0 && $recentVolume > ($avgVolume * 3)) {
            $score += 0.4;
            $flags[] = 'dealer_volume_spike';
        }
        
        // Historical fraud rate
        $fraudPercentile = $this->getDealerFraudPercentile($dealerId);
        if ($fraudPercentile > 0.8) {
            $score += 0.5;
            $flags[] = 'high_risk_dealer';
        } elseif ($fraudPercentile > 0.6) {
            $score += 0.3;
            $flags[] = 'moderate_risk_dealer';
        }
        
        return RuleResult::scored(min($score, 1.0), $flags);
    }
}
```

## Rule Configuration

### Rule Manifest Structure
```json
{
  "version": "v1.0.0",
  "name": "Auto Loan Rules Pack v1",
  "description": "Initial rule set for Canadian auto loan fraud detection",
  "hard_fail_rules": [
    {
      "name": "sin_validation",
      "class": "SinValidationRule",
      "enabled": true,
      "description": "Validates SIN format and checksum"
    },
    {
      "name": "mandatory_fields",
      "class": "MandatoryFieldsRule",
      "enabled": true,
      "description": "Ensures all required fields are present"
    },
    {
      "name": "deny_list",
      "class": "DenyListRule",
      "enabled": true,
      "description": "Checks against known fraud indicators"
    }
  ],
  "risk_scoring_rules": [
    {
      "name": "geographic_consistency",
      "class": "GeographicConsistencyRule",
      "weight": 0.25,
      "enabled": true,
      "description": "Validates geographic consistency"
    },
    {
      "name": "velocity_checks",
      "class": "VelocityRule",
      "weight": 0.30,
      "enabled": true,
      "description": "Detects suspicious application velocity"
    },
    {
      "name": "loan_to_value",
      "class": "LoanToValueRule",
      "weight": 0.25,
      "enabled": true,
      "description": "Analyzes loan-to-value ratios"
    },
    {
      "name": "dealer_risk",
      "class": "DealerRiskRule",
      "weight": 0.20,
      "enabled": true,
      "description": "Assesses dealer risk factors"
    }
  ],
  "thresholds": {
    "hard_fail_threshold": 1.0,
    "high_risk_threshold": 0.7,
    "medium_risk_threshold": 0.3
  }
}
```

## Implementation Tasks

### Phase 1: Core Infrastructure (Week 1)
1. **Rule Interfaces**: Define contracts for hard-fail and risk-scoring rules
2. **Rule Engine**: Implement main orchestrator and processor
3. **Rule Registry**: Configuration management and rule loading
4. **Database Schema**: Create tables for rule packs and executions
5. **Basic Rules**: Implement SIN validation and mandatory fields rules

### Phase 2: Risk Scoring Rules (Week 2)
1. **Geographic Rules**: Province-IP matching, postal code validation
2. **Velocity Rules**: Email/phone/VIN reuse detection
3. **Financial Rules**: LTV analysis, down payment ratios
4. **Dealer Rules**: Volume spikes, historical fraud rates
5. **Rule Weighting**: Implement weighted scoring system

### Phase 3: Configuration & Testing (Week 3)
1. **Rule Management**: Admin interface for rule configuration
2. **A/B Testing**: Support for multiple rule pack versions
3. **Performance Optimization**: Caching, query optimization
4. **Monitoring**: Rule execution metrics and alerting
5. **Documentation**: Rule descriptions and business logic

## Performance Requirements

- **Execution Time**: <500ms per application
- **Throughput**: 100 applications per minute
- **Memory Usage**: <50MB per worker process
- **Database Queries**: <10 queries per rule execution

## Testing Strategy

### Unit Tests
- Individual rule logic validation
- Edge case handling
- Performance benchmarks

### Integration Tests
- End-to-end rule execution
- Database interaction testing
- Configuration loading

### Load Tests
- Concurrent rule execution
- Memory usage under load
- Database performance

## Monitoring & Alerting

### Metrics
- Rule execution times
- Hard-fail rates by rule
- Risk score distributions
- Rule configuration changes

### Alerts
- Rule execution failures
- Performance degradation
- Unusual hard-fail spikes
- Configuration deployment issues

## Security Considerations

- **PII Protection**: Hash sensitive values in deny lists
- **Access Control**: Restrict rule configuration access
- **Audit Trail**: Log all rule changes and executions
- **Data Retention**: Automatic cleanup of old execution data

## Future Enhancements

- **Machine Learning Rules**: Integrate ML-based risk indicators
- **Real-time Updates**: Dynamic rule configuration without restarts
- **Advanced Analytics**: Rule effectiveness analysis
- **External Data**: Integration with third-party risk data sources
