# Feature Engineering Implementation Plan

## Overview

The Feature Engineering component is Stage 2 of the fraud detection pipeline, responsible for transforming raw application data into a standardized feature vector for machine learning models. It extracts the Top-15 features that provide the most predictive power for fraud detection.

## Objectives

- **Feature Extraction**: Transform raw application data into ML-ready features
- **Standardization**: Ensure consistent feature formats and ranges
- **Performance**: Process features in <200ms per application
- **Versioning**: Support feature schema evolution and backward compatibility
- **Validation**: Detect and handle missing or invalid feature values

## Architecture

### Component Structure
```
app/Services/Features/
├── FeatureEngine.php           # Main orchestrator
├── FeatureExtractor.php        # Feature extraction logic
├── FeatureValidator.php        # Feature validation and cleaning
├── FeatureRegistry.php         # Feature schema management
├── Extractors/                 # Individual feature extractors
│   ├── IdentityFeatures.php
│   ├── DigitalFeatures.php
│   ├── VelocityFeatures.php
│   ├── GeographicFeatures.php
│   ├── FinancialFeatures.php
│   └── VehicleFeatures.php
├── Contracts/
│   ├── FeatureExtractorInterface.php
│   └── FeatureValidatorInterface.php
├── Data/
│   ├── FeatureVector.php
│   ├── FeatureSchema.php
│   └── FeatureContext.php
└── Transformers/
    ├── NumericTransformer.php
    ├── CategoricalTransformer.php
    └── DateTransformer.php
```

### Database Schema
```sql
-- Feature schema versioning
CREATE TABLE feature_schemas (
    id UUID PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    schema_config JSONB NOT NULL,
    is_active BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Feature extraction results
CREATE TABLE feature_extractions (
    id UUID PRIMARY KEY,
    request_id UUID REFERENCES fraud_requests(id),
    feature_schema_version VARCHAR(50) NOT NULL,
    feature_vector JSONB NOT NULL,
    feature_metadata JSONB,
    validation_status VARCHAR(50) DEFAULT 'valid',
    validation_errors JSONB,
    extraction_time_ms INTEGER,
    extracted_at TIMESTAMP,
    created_at TIMESTAMP
);

-- Feature statistics for monitoring
CREATE TABLE feature_statistics (
    id UUID PRIMARY KEY,
    feature_name VARCHAR(255) NOT NULL,
    schema_version VARCHAR(50) NOT NULL,
    date_bucket DATE NOT NULL,
    min_value DECIMAL(10,4),
    max_value DECIMAL(10,4),
    mean_value DECIMAL(10,4),
    std_dev DECIMAL(10,4),
    null_count INTEGER,
    total_count INTEGER,
    created_at TIMESTAMP,
    UNIQUE(feature_name, schema_version, date_bucket)
);
```

## Top-15 Feature Set v1.0

### 1. Identity & Digital Features (5 features)

#### Age (Numeric)
```php
class AgeFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $dob = $context->getPersonalInfo()['date_of_birth'] ?? null;
        
        if (!$dob) {
            return FeatureValue::missing('age');
        }
        
        $birthDate = Carbon::parse($dob);
        $age = $birthDate->diffInYears(now());
        
        // Validate reasonable age range
        if ($age < 18 || $age > 100) {
            return FeatureValue::invalid('age', $age, 'Age outside valid range');
        }
        
        return FeatureValue::numeric('age', $age);
    }
}
```

#### SIN Valid Flag (Boolean)
```php
class SinValidFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $sin = $context->getPersonalInfo()['sin'] ?? null;
        
        if (!$sin) {
            return FeatureValue::boolean('sin_valid', false);
        }
        
        $isValid = $this->validateSinChecksum($sin);
        return FeatureValue::boolean('sin_valid', $isValid);
    }
    
    private function validateSinChecksum(string $sin): bool
    {
        // Luhn algorithm implementation
        if (strlen($sin) !== 9 || !ctype_digit($sin)) {
            return false;
        }
        
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

#### Email Domain Category (Categorical)
```php
class EmailDomainCategoryFeature implements FeatureExtractorInterface
{
    private array $domainCategories = [
        'major_provider' => ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com'],
        'canadian_provider' => ['rogers.com', 'bell.ca', 'telus.net', 'shaw.ca'],
        'business' => [], // Determined by MX record analysis
        'disposable' => [], // Known disposable email providers
        'unknown' => []
    ];
    
    public function extract(FeatureContext $context): FeatureValue
    {
        $email = $context->getContactInfo()['email'] ?? null;
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return FeatureValue::categorical('email_domain_category', 'unknown');
        }
        
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $category = $this->categorizeDomain($domain);
        
        return FeatureValue::categorical('email_domain_category', $category);
    }
    
    private function categorizeDomain(string $domain): string
    {
        foreach ($this->domainCategories as $category => $domains) {
            if (in_array($domain, $domains)) {
                return $category;
            }
        }
        
        // Check if it's a business domain (has MX record, not in major providers)
        if ($this->hasBusinessMxRecord($domain)) {
            return 'business';
        }
        
        return 'unknown';
    }
}
```

#### Phone Reuse Count (Numeric)
```php
class PhoneReuseCountFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $phone = $context->getContactInfo()['phone'] ?? null;
        
        if (!$phone) {
            return FeatureValue::numeric('phone_reuse_count', 0);
        }
        
        $normalizedPhone = $this->normalizePhone($phone);
        $count = $this->getPhoneReuseCount($normalizedPhone, 30); // Last 30 days
        
        return FeatureValue::numeric('phone_reuse_count', $count);
    }
    
    private function normalizePhone(string $phone): string
    {
        // Remove all non-digits
        return preg_replace('/\D/', '', $phone);
    }
    
    private function getPhoneReuseCount(string $phone, int $days): int
    {
        return DB::table('fraud_requests')
            ->whereRaw("application_data->'contact_info'->>'phone' LIKE ?", ["%{$phone}%"])
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }
}
```

#### Email Reuse Count (Numeric)
```php
class EmailReuseCountFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $email = $context->getContactInfo()['email'] ?? null;
        
        if (!$email) {
            return FeatureValue::numeric('email_reuse_count', 0);
        }
        
        $count = $this->getEmailReuseCount(strtolower($email), 30); // Last 30 days
        
        return FeatureValue::numeric('email_reuse_count', $count);
    }
    
    private function getEmailReuseCount(string $email, int $days): int
    {
        return DB::table('fraud_requests')
            ->whereRaw("LOWER(application_data->'contact_info'->>'email') = ?", [$email])
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }
}
```

### 2. Velocity & Dealer Features (3 features)

#### VIN Reuse Flag (Boolean)
```php
class VinReuseFlagFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $vin = $context->getVehicleInfo()['vin'] ?? null;
        
        if (!$vin) {
            return FeatureValue::boolean('vin_reuse_flag', false);
        }
        
        $count = $this->getVinReuseCount(strtoupper($vin), 365); // Last year
        $isReused = $count > 0;
        
        return FeatureValue::boolean('vin_reuse_flag', $isReused);
    }
    
    private function getVinReuseCount(string $vin, int $days): int
    {
        return DB::table('fraud_requests')
            ->whereRaw("UPPER(application_data->'vehicle_info'->>'vin') = ?", [$vin])
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }
}
```

#### Dealer App Volume 24h (Numeric)
```php
class DealerVolumeFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $dealerId = $context->getDealerInfo()['dealer_id'] ?? null;
        
        if (!$dealerId) {
            return FeatureValue::numeric('dealer_volume_24h', 0);
        }
        
        $volume = $this->getDealerVolume($dealerId, 1); // Last 24 hours
        
        return FeatureValue::numeric('dealer_volume_24h', $volume);
    }
    
    private function getDealerVolume(string $dealerId, int $days): int
    {
        return DB::table('fraud_requests')
            ->whereRaw("application_data->'dealer_info'->>'dealer_id' = ?", [$dealerId])
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }
}
```

#### Dealer Fraud Percentile (Numeric)
```php
class DealerFraudPercentileFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $dealerId = $context->getDealerInfo()['dealer_id'] ?? null;
        
        if (!$dealerId) {
            return FeatureValue::numeric('dealer_fraud_percentile', 0.5); // Neutral
        }
        
        $percentile = $this->calculateDealerFraudPercentile($dealerId);
        
        return FeatureValue::numeric('dealer_fraud_percentile', $percentile);
    }
    
    private function calculateDealerFraudPercentile(string $dealerId): float
    {
        // Get dealer's fraud rate
        $totalApps = DB::table('fraud_requests')
            ->whereRaw("application_data->'dealer_info'->>'dealer_id' = ?", [$dealerId])
            ->where('created_at', '>=', now()->subMonths(12))
            ->count();
            
        if ($totalApps < 10) {
            return 0.5; // Insufficient data, return neutral
        }
        
        $fraudApps = DB::table('fraud_requests')
            ->whereRaw("application_data->'dealer_info'->>'dealer_id' = ?", [$dealerId])
            ->where('final_decision', 'decline')
            ->where('created_at', '>=', now()->subMonths(12))
            ->count();
            
        $dealerFraudRate = $fraudApps / $totalApps;
        
        // Calculate percentile among all dealers
        $allDealerRates = $this->getAllDealerFraudRates();
        $rank = 0;
        
        foreach ($allDealerRates as $rate) {
            if ($dealerFraudRate > $rate) {
                $rank++;
            }
        }
        
        return count($allDealerRates) > 0 ? $rank / count($allDealerRates) : 0.5;
    }
}
```

### 3. Geographic Features (2 features)

#### Province IP Mismatch (Boolean)
```php
class ProvinceIpMismatchFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $declaredProvince = $context->getPersonalInfo()['province'] ?? null;
        $clientIp = $context->getClientIp();
        
        if (!$declaredProvince || !$clientIp) {
            return FeatureValue::boolean('province_ip_mismatch', false);
        }
        
        $ipProvince = $this->getProvinceFromIp($clientIp);
        
        if (!$ipProvince) {
            return FeatureValue::boolean('province_ip_mismatch', false);
        }
        
        $mismatch = $declaredProvince !== $ipProvince;
        
        return FeatureValue::boolean('province_ip_mismatch', $mismatch);
    }
    
    private function getProvinceFromIp(string $ip): ?string
    {
        // Integration with IP geolocation service
        // Return province code or null if cannot determine
        try {
            $response = Http::timeout(2)->get("https://ipapi.co/{$ip}/region_code/");
            $regionCode = $response->body();
            
            // Map region codes to Canadian provinces
            return $this->mapRegionToProvince($regionCode);
        } catch (Exception $e) {
            Log::warning('IP geolocation failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
```

#### Address Postal Match Flag (Boolean)
```php
class AddressPostalMatchFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $address = $context->getContactInfo()['address'] ?? [];
        $postalCode = $address['postal_code'] ?? null;
        $province = $address['province'] ?? null;
        
        if (!$postalCode || !$province) {
            return FeatureValue::boolean('address_postal_match', false);
        }
        
        $isValid = $this->validatePostalCodeForProvince($postalCode, $province);
        
        return FeatureValue::boolean('address_postal_match', $isValid);
    }
    
    private function validatePostalCodeForProvince(string $postalCode, string $province): bool
    {
        // Canadian postal code validation by province
        $postalCode = strtoupper(preg_replace('/\s/', '', $postalCode));
        
        if (!preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postalCode)) {
            return false;
        }
        
        $firstChar = $postalCode[0];
        $provinceMapping = [
            'A' => ['NL'],
            'B' => ['NS', 'NB'],
            'C' => ['PE'],
            'E' => ['NB'],
            'G' => ['QC'],
            'H' => ['QC'],
            'J' => ['QC'],
            'K' => ['ON'],
            'L' => ['ON'],
            'M' => ['ON'],
            'N' => ['ON'],
            'P' => ['ON'],
            'R' => ['MB'],
            'S' => ['SK'],
            'T' => ['AB'],
            'V' => ['BC'],
            'X' => ['NU', 'NT'],
            'Y' => ['YT']
        ];
        
        return isset($provinceMapping[$firstChar]) && 
               in_array($province, $provinceMapping[$firstChar]);
    }
}
```

### 4. Financial Features (5 features)

#### Loan to Value Ratio (Numeric)
```php
class LoanToValueRatioFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $loanAmount = $context->getLoanInfo()['amount'] ?? 0;
        $vehicleValue = $context->getVehicleInfo()['value'] ?? 0;
        
        if ($vehicleValue <= 0) {
            return FeatureValue::invalid('loan_to_value_ratio', null, 'Invalid vehicle value');
        }
        
        $ltv = $loanAmount / $vehicleValue;
        
        // Cap at reasonable maximum
        $ltv = min($ltv, 2.0);
        
        return FeatureValue::numeric('loan_to_value_ratio', round($ltv, 4));
    }
}
```

#### Purchase Loan Ratio (Numeric)
```php
class PurchaseLoanRatioFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $loanAmount = $context->getLoanInfo()['amount'] ?? 0;
        $downPayment = $context->getLoanInfo()['down_payment'] ?? 0;
        
        $totalPurchase = $loanAmount + $downPayment;
        
        if ($totalPurchase <= 0) {
            return FeatureValue::invalid('purchase_loan_ratio', null, 'Invalid purchase amount');
        }
        
        $ratio = $loanAmount / $totalPurchase;
        
        return FeatureValue::numeric('purchase_loan_ratio', round($ratio, 4));
    }
}
```

#### Down Payment Income Ratio (Numeric)
```php
class DownPaymentIncomeRatioFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $downPayment = $context->getLoanInfo()['down_payment'] ?? 0;
        $annualIncome = $context->getFinancialInfo()['annual_income'] ?? 0;
        
        if ($annualIncome <= 0) {
            return FeatureValue::invalid('dp_income_ratio', null, 'Invalid income');
        }
        
        $ratio = $downPayment / $annualIncome;
        
        // Cap at reasonable maximum
        $ratio = min($ratio, 1.0);
        
        return FeatureValue::numeric('dp_income_ratio', round($ratio, 4));
    }
}
```

#### Mileage Plausibility Score (Numeric)
```php
class MileagePlausibilityFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $year = $context->getVehicleInfo()['year'] ?? null;
        $mileage = $context->getVehicleInfo()['mileage'] ?? null;
        
        if (!$year || $mileage === null) {
            return FeatureValue::numeric('mileage_plausibility', 0.5); // Neutral
        }
        
        $vehicleAge = date('Y') - $year;
        
        if ($vehicleAge <= 0) {
            return FeatureValue::numeric('mileage_plausibility', 0.0); // Future year
        }
        
        // Expected mileage: 15,000-25,000 km per year
        $expectedMinMileage = $vehicleAge * 10000; // 10k km/year minimum
        $expectedMaxMileage = $vehicleAge * 30000; // 30k km/year maximum
        
        if ($mileage < 0) {
            return FeatureValue::numeric('mileage_plausibility', 0.0);
        }
        
        if ($mileage >= $expectedMinMileage && $mileage <= $expectedMaxMileage) {
            return FeatureValue::numeric('mileage_plausibility', 1.0); // Plausible
        }
        
        // Calculate deviation score
        if ($mileage < $expectedMinMileage) {
            $deviation = ($expectedMinMileage - $mileage) / $expectedMinMileage;
        } else {
            $deviation = ($mileage - $expectedMaxMileage) / $expectedMaxMileage;
        }
        
        $plausibility = max(0.0, 1.0 - $deviation);
        
        return FeatureValue::numeric('mileage_plausibility', round($plausibility, 4));
    }
}
```

#### High Value Low Income Flag (Boolean)
```php
class HighValueLowIncomeFeature implements FeatureExtractorInterface
{
    public function extract(FeatureContext $context): FeatureValue
    {
        $vehicleValue = $context->getVehicleInfo()['value'] ?? 0;
        $annualIncome = $context->getFinancialInfo()['annual_income'] ?? 0;
        
        if ($annualIncome <= 0 || $vehicleValue <= 0) {
            return FeatureValue::boolean('high_value_low_income', false);
        }
        
        $valueIncomeRatio = $vehicleValue / $annualIncome;
        
        // Flag if vehicle value > 80% of annual income
        $isHighValueLowIncome = $valueIncomeRatio > 0.8;
        
        return FeatureValue::boolean('high_value_low_income', $isHighValueLowIncome);
    }
}
```

## Feature Schema Management

### Feature Schema v1.0 Configuration
```json
{
  "version": "v1.0.0",
  "name": "Auto Loan Feature Set v1",
  "description": "Top-15 features for Canadian auto loan fraud detection",
  "features": [
    {
      "name": "age",
      "type": "numeric",
      "description": "Applicant age in years",
      "min_value": 18,
      "max_value": 100,
      "required": true
    },
    {
      "name": "sin_valid",
      "type": "boolean",
      "description": "SIN checksum validation result",
      "required": true
    },
    {
      "name": "email_domain_category",
      "type": "categorical",
      "description": "Email domain classification",
      "categories": ["major_provider", "canadian_provider", "business", "disposable", "unknown"],
      "required": true
    },
    {
      "name": "phone_reuse_count",
      "type": "numeric",
      "description": "Phone number reuse count (30 days)",
      "min_value": 0,
      "max_value": 100,
      "required": true
    },
    {
      "name": "email_reuse_count",
      "type": "numeric",
      "description": "Email address reuse count (30 days)",
      "min_value": 0,
      "max_value": 100,
      "required": true
    },
    {
      "name": "vin_reuse_flag",
      "type": "boolean",
      "description": "VIN previously used in applications",
      "required": true
    },
    {
      "name": "dealer_volume_24h",
      "type": "numeric",
      "description": "Dealer application volume (24 hours)",
      "min_value": 0,
      "max_value": 1000,
      "required": true
    },
    {
      "name": "dealer_fraud_percentile",
      "type": "numeric",
      "description": "Dealer fraud rate percentile",
      "min_value": 0.0,
      "max_value": 1.0,
      "required": true
    },
    {
      "name": "province_ip_mismatch",
      "type": "boolean",
      "description": "Province and IP location mismatch",
      "required": true
    },
    {
      "name": "address_postal_match",
      "type": "boolean",
      "description": "Address and postal code consistency",
      "required": true
    },
    {
      "name": "loan_to_value_ratio",
      "type": "numeric",
      "description": "Loan amount to vehicle value ratio",
      "min_value": 0.0,
      "max_value": 2.0,
      "required": true
    },
    {
      "name": "purchase_loan_ratio",
      "type": "numeric",
      "description": "Loan to total purchase ratio",
      "min_value": 0.0,
      "max_value": 1.0,
      "required": true
    },
    {
      "name": "dp_income_ratio",
      "type": "numeric",
      "description": "Down payment to income ratio",
      "min_value": 0.0,
      "max_value": 1.0,
      "required": true
    },
    {
      "name": "mileage_plausibility",
      "type": "numeric",
      "description": "Vehicle mileage plausibility score",
      "min_value": 0.0,
      "max_value": 1.0,
      "required": true
    },
    {
      "name": "high_value_low_income",
      "type": "boolean",
      "description": "High vehicle value relative to income",
      "required": true
    }
  ],
  "transformations": {
    "numeric_scaling": "min_max",
    "categorical_encoding": "one_hot",
    "missing_value_strategy": "median_mode"
  }
}
```

## Implementation Tasks

### Phase 1: Core Infrastructure (Week 1)
1. **Feature Interfaces**: Define contracts for feature extractors
2. **Feature Engine**: Implement main orchestrator and extraction logic
3. **Feature Registry**: Schema management and versioning
4. **Database Schema**: Create tables for schemas and extractions
5. **Basic Extractors**: Implement age, SIN validation, and LTV features

### Phase 2: Feature Extractors (Week 2)
1. **Identity Features**: Age, SIN validation, email domain classification
2. **Velocity Features**: Phone/email reuse, VIN reuse, dealer volume
3. **Geographic Features**: Province-IP matching, postal validation
4. **Financial Features**: LTV, ratios, mileage plausibility
5. **Validation System**: Feature range checking and error handling

### Phase 3: Optimization & Testing (Week 3)
1. **Performance Optimization**: Caching, query optimization
2. **Feature Statistics**: Monitoring and drift detection
3. **Schema Evolution**: Backward compatibility and migration
4. **Testing Suite**: Unit tests, integration tests, performance tests
5. **Documentation**: Feature descriptions and business logic

## Performance Requirements

- **Extraction Time**: <200ms per application
- **Memory Usage**: <30MB per extraction
- **Database Queries**: <15 queries per extraction
- **Feature Vector Size**: 15 features, <1KB serialized

## Testing Strategy

### Unit Tests
- Individual feature extractor logic
- Edge case handling (missing data, invalid values)
- Performance benchmarks

### Integration Tests
- End-to-end feature extraction
- Schema validation
- Database interaction

### Data Quality Tests
- Feature distribution analysis
- Missing value patterns
- Outlier detection

## Monitoring & Alerting

### Metrics
- Feature extraction times
- Missing value rates by feature
- Feature distribution drift
- Schema version usage

### Alerts
- Feature extraction failures
- High missing value rates
- Significant distribution changes
- Performance degradation

## Security Considerations

- **PII Handling**: Minimize PII exposure in feature values
- **Data Retention**: Automatic cleanup of old feature data
- **Access Control**: Restrict feature schema modification
- **Audit Trail**: Log all feature extractions and schema changes

## Future Enhancements

- **Dynamic Features**: Real-time feature computation
- **Feature Store**: Centralized feature repository
- **Auto-scaling**: Automatic feature importance ranking
- **External Data**: Integration with third-party data sources
