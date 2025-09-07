# Rules Engine Component

## Overview
The rules engine provides deterministic fraud detection through configurable business rules, including hard-fail validations and weighted risk scoring.

## Rule Categories

### Hard-Fail Rules (Short-Circuit)
These rules immediately decline applications without further processing:

1. **SIN Validation**
   - Invalid SIN checksum algorithm
   - Missing or malformed SIN
   - Known invalid SIN patterns

2. **Mandatory Field Validation**
   - Required fields missing
   - Invalid data formats
   - Out-of-range values

3. **Deny/PEP List Checks**
   - Politically Exposed Persons (PEP) list
   - Sanctions list matching
   - Internal deny list hits

### Risk Flag Rules (Weighted Scoring)
These rules contribute to the overall rule score (0-1):

#### Identity & Verification Flags
- **Province-IP Mismatch**: Application province doesn't match IP geolocation
- **Disposable Email**: Email from temporary/disposable email providers
- **Phone Reuse**: Phone number used in multiple recent applications
- **Email Reuse**: Email address used in multiple recent applications

#### Geographic & Address Flags
- **Address-Postal Mismatch**: Street address doesn't match postal code
- **High-Risk Province**: Application from provinces with higher fraud rates
- **IP Risk Score**: Suspicious IP characteristics (VPN, proxy, etc.)

#### Velocity & Pattern Flags
- **VIN Reuse**: Vehicle VIN used in multiple applications
- **Dealer Volume Spike**: Unusual 24-hour application volume from dealer
- **Rapid Application Pattern**: Multiple applications from same identity

#### Financial Risk Flags
- **High LTV**: Loan-to-value ratio above risk thresholds
- **Low Down Payment**: Down payment percentage below minimum
- **Income Inconsistency**: Stated income vs. loan amount mismatch
- **High-Value Vehicle**: Expensive vehicle with low stated income

## Rule Configuration

### Rule Manifest Structure
```json
{
  "rulepack_version": "v1.2.3",
  "created_at": "2024-01-15T10:00:00Z",
  "hard_fail_rules": [
    {
      "rule_id": "sin_validation",
      "name": "SIN Checksum Validation",
      "description": "Validates SIN using checksum algorithm",
      "severity": "critical",
      "enabled": true
    }
  ],
  "risk_flag_rules": [
    {
      "rule_id": "province_ip_mismatch",
      "name": "Province IP Mismatch",
      "description": "Province in application doesn't match IP location",
      "weight": 0.15,
      "severity": "medium",
      "enabled": true,
      "thresholds": {
        "confidence_threshold": 0.8
      }
    }
  ]
}
```

### Rule Weights and Scoring
```json
{
  "scoring_config": {
    "max_rule_score": 1.0,
    "normalization": "weighted_sum",
    "weights": {
      "province_ip_mismatch": 0.15,
      "disposable_email": 0.10,
      "phone_reuse_7d": 0.12,
      "email_reuse_7d": 0.12,
      "vin_reuse": 0.20,
      "dealer_volume_spike": 0.08,
      "high_ltv": 0.10,
      "low_downpayment": 0.08,
      "address_postal_mismatch": 0.05
    }
  }
}
```

## Rule Implementation

### Rule Interface
```php
interface FraudRule
{
    public function evaluate(ApplicationData $data): RuleResult;
    public function getName(): string;
    public function getVersion(): string;
    public function isEnabled(): bool;
}
```

### Hard-Fail Rule Example
```php
class SinValidationRule implements FraudRule
{
    public function evaluate(ApplicationData $data): RuleResult
    {
        $sin = $data->getPersonalInfo()->getSin();
        
        if (!$this->isValidSinFormat($sin)) {
            return RuleResult::hardFail(
                'invalid_sin_format',
                'SIN format is invalid'
            );
        }
        
        if (!$this->validateSinChecksum($sin)) {
            return RuleResult::hardFail(
                'invalid_sin_checksum',
                'SIN checksum validation failed'
            );
        }
        
        return RuleResult::pass();
    }
}
```

### Risk Flag Rule Example
```php
class ProvinceIpMismatchRule implements FraudRule
{
    public function evaluate(ApplicationData $data): RuleResult
    {
        $applicationProvince = $data->getAddress()->getProvince();
        $ipLocation = $this->geoIpService->getLocation($data->getClientIp());
        
        if ($applicationProvince !== $ipLocation->getProvince()) {
            return RuleResult::riskFlag(
                'province_ip_mismatch',
                'Application province differs from IP location',
                0.15 // weight
            );
        }
        
        return RuleResult::pass();
    }
}
```

## Rule Engine Architecture

### RulesProcessor Class
```php
class RulesProcessor
{
    private array $hardFailRules;
    private array $riskFlagRules;
    private RuleConfigManager $configManager;
    
    public function process(ApplicationData $data): RulesOutput
    {
        // 1. Load current rule configuration
        $config = $this->configManager->getCurrentConfig();
        
        // 2. Execute hard-fail rules first
        foreach ($this->hardFailRules as $rule) {
            $result = $rule->evaluate($data);
            if ($result->isHardFail()) {
                return RulesOutput::hardFail($result);
            }
        }
        
        // 3. Execute risk flag rules
        $riskFlags = [];
        $totalScore = 0.0;
        
        foreach ($this->riskFlagRules as $rule) {
            $result = $rule->evaluate($data);
            if ($result->isRiskFlag()) {
                $riskFlags[] = $result;
                $totalScore += $result->getWeight();
            }
        }
        
        // 4. Normalize score
        $normalizedScore = min($totalScore, 1.0);
        
        return new RulesOutput(
            $riskFlags,
            $normalizedScore,
            $config->getVersion()
        );
    }
}
```

## Data Sources & External Services

### IP Geolocation Service
```php
interface GeoIpService
{
    public function getLocation(string $ipAddress): LocationData;
    public function isVpn(string $ipAddress): bool;
    public function getRiskScore(string $ipAddress): float;
}
```

### Deny List Service
```php
interface DenyListService
{
    public function checkPepList(PersonalInfo $person): bool;
    public function checkSanctionsList(PersonalInfo $person): bool;
    public function checkInternalDenyList(string $identifier): bool;
}
```

### Historical Data Service
```php
interface HistoricalDataService
{
    public function getPhoneReuseCount(string $phone, int $days): int;
    public function getEmailReuseCount(string $email, int $days): int;
    public function getVinReuseCount(string $vin): int;
    public function getDealerVolumeSpike(string $dealerId): float;
}
```

## Configuration Management

### Rule Configuration Storage
- **Location**: Database table `rule_configurations`
- **Versioning**: Semantic versioning (v1.2.3)
- **Deployment**: Blue-green deployment for rule updates
- **Rollback**: Ability to revert to previous rule versions

### Configuration Schema
```sql
CREATE TABLE rule_configurations (
    id UUID PRIMARY KEY,
    version VARCHAR(20) NOT NULL,
    config_json JSONB NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    activated_at TIMESTAMP,
    created_by VARCHAR(100)
);
```

### Rule Update Process
1. **Development**: Create new rule configuration
2. **Testing**: Validate against test dataset
3. **Staging**: Deploy to staging environment
4. **Production**: Blue-green deployment
5. **Monitoring**: Track rule performance metrics
6. **Rollback**: Revert if issues detected

## Local Development Setup

### Environment Setup
```bash
# Install dependencies
composer install

# Set up rule configuration
php artisan rules:install-config

# Load test rules
php artisan rules:load-test-config
```

### Testing Rules
```bash
# Run rule engine tests
php artisan test --filter=RulesEngineTest

# Test specific rule
php artisan rules:test sin_validation --input=test_data.json

# Validate rule configuration
php artisan rules:validate-config rules/v1.2.3.json
```

### Rule Development
```bash
# Generate new rule class
php artisan make:rule ProvinceIpMismatchRule

# Test rule against sample data
php artisan rules:test-rule ProvinceIpMismatchRule --sample-size=1000
```

## Performance Considerations

### Caching Strategy
- **Rule Configuration**: Cache for 5 minutes
- **Deny Lists**: Cache for 1 hour, refresh async
- **Historical Counts**: Cache for 10 minutes
- **IP Geolocation**: Cache for 24 hours

### Optimization Techniques
- **Early Exit**: Hard-fail rules execute first
- **Parallel Execution**: Risk flag rules can run concurrently
- **Batch Queries**: Optimize database lookups
- **Connection Pooling**: Reuse database connections

### Monitoring Metrics
- Rule execution times
- Cache hit rates
- Hard-fail frequencies
- Risk flag distribution
- Configuration change impact

## Security & Compliance

### Data Privacy
- No PII in rule logs
- Anonymized rule testing
- Secure configuration storage
- Audit trail for rule changes

### Compliance Requirements
- Rule explainability
- Decision audit trails
- Configuration versioning
- Change approval process

### Access Control
- Role-based rule management
- Configuration change approvals
- Audit logging
- Secure API access

## Testing Strategy

### Unit Tests
- Individual rule logic
- Edge case handling
- Configuration validation
- Error scenarios

### Integration Tests
- Full rule engine pipeline
- External service integration
- Database interactions
- Performance benchmarks

### Regression Tests
- Rule configuration changes
- Historical decision validation
- Performance impact assessment
- Backward compatibility
