# Testing Guide

## Overview
This guide covers the testing strategy and procedures for the fraud detection system, including unit tests, integration tests, performance tests, and end-to-end testing.

## Testing Strategy

### Test Pyramid
```
    E2E Tests (Few)
   ┌─────────────────┐
   │  Integration    │
   │     Tests       │
   │   (Some)        │
   ├─────────────────┤
   │   Unit Tests    │
   │    (Many)       │
   └─────────────────┘
```

### Testing Levels

#### 1. Unit Tests
- **Scope**: Individual functions, classes, and methods
- **Tools**: PHPUnit (Laravel), pytest (Python)
- **Coverage Target**: >90%
- **Run Frequency**: Every commit

#### 2. Integration Tests
- **Scope**: Component interactions, database operations, API endpoints
- **Tools**: Laravel Feature Tests, pytest with test database
- **Coverage Target**: >80%
- **Run Frequency**: Every pull request

#### 3. End-to-End Tests
- **Scope**: Complete fraud detection pipeline
- **Tools**: Custom test scripts, API testing tools
- **Coverage Target**: Critical user journeys
- **Run Frequency**: Before releases

#### 4. Performance Tests
- **Scope**: Load testing, stress testing, latency testing
- **Tools**: Artillery, Apache Bench, custom scripts
- **Metrics**: P95 latency, throughput, error rates
- **Run Frequency**: Weekly, before releases

## Test Data Management

### Sample Data Structure
```
tests/data/
├── legitimate_applications.json    # Low-risk test cases
├── fraudulent_applications.json    # High-risk test cases
├── edge_cases.json                # Boundary conditions
├── invalid_requests.json          # Malformed data
├── performance_dataset.json       # Load testing data
└── regression_suite.json          # Historical test cases
```

### Data Categories

#### Legitimate Applications
- Standard demographic profiles
- Normal financial ratios
- Valid contact information
- Established dealers
- Reasonable vehicle values

#### Fraudulent Applications
- Suspicious patterns (email/phone reuse)
- High-risk financial ratios
- Geographic inconsistencies
- Known fraudulent dealers
- Unrealistic vehicle valuations

#### Edge Cases
- Minimum/maximum age boundaries
- Extreme LTV ratios
- Missing optional fields
- International phone numbers
- Special characters in names

## Testing Procedures

### Unit Testing

#### Laravel API Tests
```bash
# Run all API tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# Run with coverage
php artisan test --coverage

# Run specific test class
php artisan test tests/Feature/ApplicationControllerTest.php
```

#### Python ML Service Tests
```bash
# Run all ML tests
cd ml-service
pytest tests/ -v

# Run with coverage
pytest tests/ --cov=. --cov-report=html

# Run specific test file
pytest tests/test_model_inference.py -v

# Run performance tests
pytest tests/test_performance.py -v --benchmark-only
```

### Integration Testing

#### Database Integration
```bash
# Test database migrations
php artisan migrate:fresh --env=testing

# Test seeders
php artisan db:seed --env=testing

# Test queue operations
php artisan test tests/Feature/QueueIntegrationTest.php
```

#### API Integration
```bash
# Test complete API workflow
make test-integration

# Test with Docker environment
docker-compose --profile testing run --rm test-client python -m pytest
```

### End-to-End Testing

#### Complete Pipeline Test
```bash
# Submit application and verify decision
curl -X POST http://localhost:8080/applications \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: test-key" \
  -d @tests/data/sample_application.json

# Poll for decision
curl http://localhost:8080/decision/{job_id}
```

#### Automated E2E Suite
```bash
# Run full end-to-end test suite
make test-e2e

# Run specific scenario
python tests/e2e/test_fraud_detection_flow.py
```

### Performance Testing

#### Load Testing
```bash
# Basic load test
make test-load

# Custom load test
artillery run tests/performance/load_test.yml

# Stress test
artillery run tests/performance/stress_test.yml
```

#### Latency Testing
```bash
# Measure API response times
ab -n 1000 -c 10 http://localhost:8080/health

# Measure ML service latency
python tests/performance/ml_latency_test.py

# Measure end-to-end pipeline latency
python tests/performance/pipeline_latency_test.py
```

## Test Configuration

### Environment Setup
```bash
# Testing environment variables
TESTING_DATABASE=fraud_detector_test
TESTING_REDIS_DATABASE=1
TESTING_MOCK_EXTERNAL_SERVICES=true
TESTING_SEED_DATA=true
MOCK_BEDROCK=true
MOCK_ML_SERVICE=false
```

### Test Database
```sql
-- Create test database
CREATE DATABASE fraud_detector_test;

-- Grant permissions
GRANT ALL PRIVILEGES ON DATABASE fraud_detector_test TO fraud_user;
```

### Mock Services

#### Mock Bedrock Adjudicator
```python
class MockBedrockAdjudicator:
    def adjudicate_case(self, dossier):
        # Return predictable test responses
        return AdjudicatorResult(
            adjudicator_score=0.3,
            risk_band="low",
            rationale=["Test rationale"]
        )
```

#### Mock External Services
```php
class MockGeoIpService implements GeoIpService
{
    public function getLocation(string $ip): LocationData
    {
        // Return test location data
        return new LocationData('ON', 'Toronto');
    }
}
```

## Test Scenarios

### Functional Test Cases

#### Happy Path
1. Submit valid application
2. Verify job creation
3. Check pipeline processing
4. Validate decision response
5. Confirm audit trail

#### Error Handling
1. Invalid authentication
2. Malformed request data
3. Database connection failure
4. External service timeout
5. Queue processing failure

#### Business Logic
1. Hard-fail rule triggers
2. Risk threshold boundaries
3. Score combination logic
4. Explanation generation
5. Policy version handling

### Security Test Cases

#### Authentication
1. Missing API key
2. Invalid HMAC signature
3. Replay attack prevention
4. Timestamp validation
5. Rate limiting

#### Data Protection
1. PII redaction verification
2. Audit log completeness
3. Secure data transmission
4. Access control validation
5. Data retention compliance

## Continuous Integration

### Test Pipeline
```yaml
# .github/workflows/test.yml
name: Test Suite
on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: php artisan test --coverage

  integration-tests:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:15
        env:
          POSTGRES_PASSWORD: postgres
    steps:
      - uses: actions/checkout@v2
      - name: Run integration tests
        run: make test-integration
```

### Quality Gates
- Unit test coverage >90%
- Integration test coverage >80%
- No critical security vulnerabilities
- Performance benchmarks met
- Code quality standards passed

## Test Reporting

### Coverage Reports
```bash
# Generate coverage report
php artisan test --coverage-html coverage/

# View coverage
open coverage/index.html
```

### Performance Reports
```bash
# Generate performance report
python tests/performance/generate_report.py

# View metrics dashboard
open tests/performance/reports/dashboard.html
```

### Test Results
```bash
# JUnit XML output
php artisan test --log-junit test-results.xml

# JSON output for CI/CD
pytest tests/ --json-report --json-report-file=test-results.json
```

## Debugging Tests

### Common Issues

#### Test Database Issues
```bash
# Reset test database
php artisan migrate:fresh --env=testing --seed

# Check database connection
php artisan tinker --env=testing
```

#### Queue Testing Issues
```bash
# Clear test queues
php artisan queue:clear --env=testing

# Process jobs synchronously
QUEUE_CONNECTION=sync php artisan test
```

#### Mock Service Issues
```bash
# Verify mock configuration
php artisan config:show --env=testing

# Check service bindings
php artisan container:bindings
```

### Test Debugging Tools
- Laravel Telescope (disabled in production)
- Xdebug for step-through debugging
- Query logging for database issues
- Custom test helpers and assertions

## Best Practices

### Test Writing
1. **Arrange-Act-Assert** pattern
2. **Descriptive test names** that explain the scenario
3. **Independent tests** that don't rely on each other
4. **Minimal test data** focused on the specific scenario
5. **Clear assertions** with meaningful error messages

### Test Maintenance
1. **Regular test review** and cleanup
2. **Update tests** when requirements change
3. **Monitor test performance** and optimize slow tests
4. **Keep test data current** and realistic
5. **Document complex test scenarios**

### Test Environment
1. **Isolated test environment** separate from development
2. **Consistent test data** across all environments
3. **Fast test execution** for quick feedback
4. **Reliable test infrastructure** with minimal flakiness
5. **Easy test setup** for new developers
