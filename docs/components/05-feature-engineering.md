# Feature Engineering Component

## Overview
The feature engineering component extracts and transforms the Top-15 features from raw application data for machine learning model input, ensuring consistent data quality and versioning.

## Top-15 Feature Set (v1)

### Identity & Digital Features (5)

#### 1. Age (Numeric)
- **Source**: Date of birth from application
- **Calculation**: `(current_date - date_of_birth) / 365.25`
- **Range**: 18-100 years
- **Missing**: Use median age (35)

#### 2. SIN Valid Flag (Boolean)
- **Source**: SIN validation from rules engine
- **Calculation**: `sin_checksum_valid AND sin_format_valid`
- **Values**: 0 (invalid), 1 (valid)
- **Missing**: Default to 0

#### 3. Email Domain Category (Categorical)
- **Source**: Email address domain analysis
- **Categories**: 
  - 0: Unknown/Other
  - 1: Major Provider (gmail, yahoo, hotmail)
  - 2: ISP Provider (rogers, bell, telus)
  - 3: Corporate Domain
  - 4: Disposable/Temporary
- **Missing**: Default to 0

#### 4. Phone Reuse Count (Numeric)
- **Source**: Historical phone number usage
- **Calculation**: Count of applications with same phone in last 30 days
- **Range**: 0-50 (capped)
- **Missing**: Default to 0

#### 5. Email Reuse Count (Numeric)
- **Source**: Historical email usage
- **Calculation**: Count of applications with same email in last 30 days
- **Range**: 0-50 (capped)
- **Missing**: Default to 0

### Velocity & Dealer Features (3)

#### 6. VIN Reuse Flag (Boolean)
- **Source**: Vehicle VIN historical usage
- **Calculation**: `vin_usage_count > 1`
- **Values**: 0 (unique), 1 (reused)
- **Missing**: Default to 0

#### 7. Dealer App Volume 24h (Numeric)
- **Source**: Dealer application volume
- **Calculation**: Count of applications from dealer in last 24 hours
- **Range**: 0-100 (capped)
- **Missing**: Default to 1

#### 8. Dealer Fraud Percentile (Numeric)
- **Source**: Historical dealer fraud rates
- **Calculation**: Percentile rank of dealer's fraud rate vs all dealers
- **Range**: 0.0-1.0
- **Missing**: Default to 0.5 (median)

### Geographic & Address Features (2)

#### 9. Province IP Mismatch (Boolean)
- **Source**: Geographic validation
- **Calculation**: `application_province != ip_geolocation_province`
- **Values**: 0 (match), 1 (mismatch)
- **Missing**: Default to 0

#### 10. Address Postal Match Flag (Boolean)
- **Source**: Address validation service
- **Calculation**: `street_address_postal_code == provided_postal_code`
- **Values**: 0 (mismatch), 1 (match)
- **Missing**: Default to 1

### Financial & Vehicle Features (5)

#### 11. Loan-to-Value Ratio (Numeric)
- **Source**: Loan amount vs vehicle value
- **Calculation**: `loan_amount / vehicle_value`
- **Range**: 0.0-2.0 (capped at 200%)
- **Missing**: Default to 0.8

#### 12. Purchase/Loan Ratio (Numeric)
- **Source**: Purchase price vs loan amount
- **Calculation**: `purchase_price / loan_amount`
- **Range**: 0.5-5.0 (capped)
- **Missing**: Default to 1.2

#### 13. Down Payment/Income Ratio (Numeric)
- **Source**: Down payment vs stated income
- **Calculation**: `down_payment / (annual_income / 12)`
- **Range**: 0.0-10.0 (capped)
- **Missing**: Default to 0.5

#### 14. Mileage Plausibility Score (Numeric)
- **Source**: Vehicle mileage vs age analysis
- **Calculation**: Z-score of mileage given vehicle year
- **Range**: -3.0 to 3.0 (standardized)
- **Missing**: Default to 0.0

#### 15. High Value Low Income Flag (Boolean)
- **Source**: Vehicle value vs income analysis
- **Calculation**: `vehicle_value > (annual_income * 0.8) AND annual_income < 50000`
- **Values**: 0 (normal), 1 (high value/low income)
- **Missing**: Default to 0

## Feature Engineering Pipeline

### FeatureExtractor Class
```php
class FeatureExtractor
{
    private array $extractors;
    private FeatureValidator $validator;
    private FeatureConfigManager $configManager;
    
    public function extract(ApplicationData $data): FeatureVector
    {
        $config = $this->configManager->getCurrentConfig();
        $features = [];
        
        foreach ($config->getFeatureDefinitions() as $featureDef) {
            $extractor = $this->extractors[$featureDef->getName()];
            $value = $extractor->extract($data);
            
            // Validate and normalize
            $normalizedValue = $this->validator->validateAndNormalize(
                $value, 
                $featureDef
            );
            
            $features[$featureDef->getName()] = $normalizedValue;
        }
        
        return new FeatureVector(
            $features,
            $config->getVersion(),
            now()
        );
    }
}
```

### Individual Feature Extractors

#### Age Extractor
```php
class AgeExtractor implements FeatureExtractorInterface
{
    public function extract(ApplicationData $data): float
    {
        $dateOfBirth = $data->getPersonalInfo()->getDateOfBirth();
        
        if (!$dateOfBirth) {
            return 35.0; // Default median age
        }
        
        $age = Carbon::now()->diffInYears($dateOfBirth);
        
        // Validate range
        if ($age < 18 || $age > 100) {
            return 35.0; // Default for invalid ages
        }
        
        return (float) $age;
    }
}
```

#### Email Domain Category Extractor
```php
class EmailDomainCategoryExtractor implements FeatureExtractorInterface
{
    private array $majorProviders = ['gmail.com', 'yahoo.com', 'hotmail.com'];
    private array $ispProviders = ['rogers.com', 'bell.ca', 'telus.net'];
    private array $disposableProviders = ['10minutemail.com', 'tempmail.org'];
    
    public function extract(ApplicationData $data): int
    {
        $email = $data->getContactInfo()->getEmail();
        
        if (!$email) {
            return 0; // Unknown
        }
        
        $domain = strtolower(substr(strrchr($email, "@"), 1));
        
        if (in_array($domain, $this->disposableProviders)) {
            return 4; // Disposable
        }
        
        if (in_array($domain, $this->majorProviders)) {
            return 1; // Major provider
        }
        
        if (in_array($domain, $this->ispProviders)) {
            return 2; // ISP provider
        }
        
        if ($this->isCorporateDomain($domain)) {
            return 3; // Corporate
        }
        
        return 0; // Unknown/Other
    }
}
```

#### LTV Ratio Extractor
```php
class LtvRatioExtractor implements FeatureExtractorInterface
{
    public function extract(ApplicationData $data): float
    {
        $loanAmount = $data->getLoanInfo()->getAmount();
        $vehicleValue = $data->getVehicleInfo()->getValue();
        
        if (!$loanAmount || !$vehicleValue || $vehicleValue <= 0) {
            return 0.8; // Default LTV
        }
        
        $ltv = $loanAmount / $vehicleValue;
        
        // Cap at 200%
        return min($ltv, 2.0);
    }
}
```

## Feature Validation & Normalization

### Validation Rules
```php
class FeatureValidator
{
    public function validateAndNormalize($value, FeatureDefinition $def): float
    {
        // Handle missing values
        if ($value === null || $value === '') {
            return $def->getDefaultValue();
        }
        
        // Type conversion
        $value = $this->convertToNumeric($value, $def->getType());
        
        // Range validation
        if ($def->hasRange()) {
            $value = $this->enforceRange($value, $def->getRange());
        }
        
        // Outlier detection
        if ($def->hasOutlierDetection()) {
            $value = $this->handleOutliers($value, $def->getOutlierConfig());
        }
        
        return $value;
    }
}
```

### Feature Configuration
```json
{
  "feature_set_version": "v1.0.0",
  "features": [
    {
      "name": "age",
      "type": "numeric",
      "range": {"min": 18, "max": 100},
      "default_value": 35.0,
      "description": "Applicant age in years"
    },
    {
      "name": "email_domain_category",
      "type": "categorical",
      "categories": [0, 1, 2, 3, 4],
      "default_value": 0,
      "description": "Email domain category classification"
    }
  ]
}
```

## Data Quality & Monitoring

### Feature Quality Metrics
```php
class FeatureQualityMonitor
{
    public function calculateQualityMetrics(FeatureVector $features): array
    {
        return [
            'completeness' => $this->calculateCompleteness($features),
            'validity' => $this->calculateValidity($features),
            'consistency' => $this->calculateConsistency($features),
            'outlier_rate' => $this->calculateOutlierRate($features),
            'distribution_drift' => $this->calculateDistributionDrift($features)
        ];
    }
}
```

### Missing Value Handling
- **Strategy**: Feature-specific defaults based on business logic
- **Documentation**: Track missing value rates per feature
- **Monitoring**: Alert if missing rates exceed thresholds
- **Imputation**: Use median/mode for numeric/categorical features

### Outlier Detection
- **Method**: IQR-based outlier detection
- **Action**: Cap values at 95th/5th percentiles
- **Logging**: Track outlier frequencies
- **Review**: Regular outlier pattern analysis

## Local Development Setup

### Environment Setup
```bash
# Install dependencies
composer install

# Set up feature configuration
php artisan features:install-config

# Load test feature definitions
php artisan features:load-test-config
```

### Testing Features
```bash
# Run feature extraction tests
php artisan test --filter=FeatureExtractionTest

# Test specific feature extractor
php artisan features:test age --input=test_data.json

# Validate feature configuration
php artisan features:validate-config features/v1.0.0.json
```

### Feature Development
```bash
# Generate new feature extractor
php artisan make:feature-extractor PhoneReuseCountExtractor

# Test feature against sample data
php artisan features:test-extractor PhoneReuseCountExtractor --sample-size=1000
```

## Performance Optimization

### Caching Strategy
- **Historical Counts**: Cache for 10 minutes
- **Dealer Statistics**: Cache for 1 hour
- **Domain Classifications**: Cache for 24 hours
- **Validation Rules**: Cache for 5 minutes

### Batch Processing
- **Database Queries**: Batch historical lookups
- **External APIs**: Minimize API calls through caching
- **Parallel Processing**: Extract independent features concurrently

### Memory Management
- **Streaming**: Process large datasets in chunks
- **Cleanup**: Clear temporary data after processing
- **Monitoring**: Track memory usage patterns

## Versioning & Deployment

### Feature Set Versioning
- **Semantic Versioning**: Major.Minor.Patch
- **Backward Compatibility**: Maintain previous versions
- **Migration Path**: Gradual feature rollout
- **Rollback**: Ability to revert feature changes

### Deployment Strategy
- **Blue-Green**: Deploy new feature versions safely
- **A/B Testing**: Compare feature set performance
- **Monitoring**: Track feature impact on model performance
- **Validation**: Ensure feature consistency across environments

## Security & Privacy

### Data Protection
- **PII Handling**: Minimize PII in feature vectors
- **Anonymization**: Hash sensitive identifiers
- **Encryption**: Encrypt feature data at rest
- **Access Control**: Restrict feature data access

### Audit Trail
- **Feature Lineage**: Track feature derivation
- **Version History**: Maintain feature set changes
- **Processing Logs**: Log feature extraction events
- **Compliance**: Meet regulatory requirements

## Testing Strategy

### Unit Tests
- Individual feature extractors
- Validation logic
- Edge case handling
- Error scenarios

### Integration Tests
- Full feature pipeline
- Database interactions
- External service calls
- Performance benchmarks

### Data Quality Tests
- Feature distribution validation
- Missing value rate checks
- Outlier detection accuracy
- Cross-feature consistency
