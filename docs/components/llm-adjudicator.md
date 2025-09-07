# LLM Adjudicator Component

## Overview

The LLM Adjudicator provides intelligent fraud analysis using Large Language Models for complex cases that require human-like reasoning and contextual understanding. This component serves as the final decision layer in the fraud detection pipeline, triggered for borderline cases or when ML confidence is low.

## Architecture

### Component Structure
```
components/LLMAdjudicator/
├── LLMAdjudicator.php          # Main service class
└── README.md                   # Component documentation
```

### Integration Points
- **Trigger Logic**: Activated for ML fraud probabilities between 0.3-0.7 or confidence < 0.8
- **Input Sources**: Rules engine results, ML predictions, feature engineering output
- **Output**: Comprehensive fraud analysis with probability, confidence, and reasoning

## Configuration

### Service Configuration (`config/services.php`)
```php
'llm_adjudicator' => [
    'enabled' => env('LLM_ADJUDICATOR_ENABLED', true),
    'provider' => env('LLM_PROVIDER', 'openrouter'),
    'api_key' => env('OPENROUTER_API_KEY'),
    'endpoint' => env('LLM_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    'model' => env('LLM_MODEL', 'anthropic/claude-sonnet-4'),
    'max_tokens' => env('LLM_MAX_TOKENS', 2000),
    'temperature' => env('LLM_TEMPERATURE', 0.1),
    'timeout' => env('LLM_TIMEOUT', 30),
    'retry_attempts' => env('LLM_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('LLM_RETRY_DELAY', 1000),
    'trigger_threshold_min' => env('LLM_TRIGGER_MIN', 0.3),
    'trigger_threshold_max' => env('LLM_TRIGGER_MAX', 0.7),
]
```

### Environment Variables
```bash
# LLM Adjudicator Configuration
LLM_ADJUDICATOR_ENABLED=true
LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=your_api_key_here
LLM_ENDPOINT=https://openrouter.ai/api/v1/chat/completions
LLM_MODEL=anthropic/claude-sonnet-4
LLM_MAX_TOKENS=2000
LLM_TEMPERATURE=0.1
LLM_TIMEOUT=30
LLM_RETRY_ATTEMPTS=3
LLM_RETRY_DELAY=1000
LLM_TRIGGER_MIN=0.3
LLM_TRIGGER_MAX=0.7
```

## Features

### Intelligent Triggering
- **Borderline Cases**: Fraud probability between 0.3-0.7
- **Low Confidence**: ML confidence score < 0.8
- **ML Failure**: Fallback when ML service is unavailable
- **Configurable Thresholds**: Adjustable trigger conditions

### Comprehensive Analysis
- **Application Review**: Income, employment, credit profile analysis
- **Risk Assessment**: LTV ratios, debt-to-income calculations
- **Pattern Recognition**: Fraud indicators and anomaly detection
- **Canadian Context**: Compliance with Canadian lending regulations

### Robust Response Handling
- **JSON Parsing**: Handles various response formats from LLM providers
- **Validation**: Ensures all required fields and valid ranges
- **Error Handling**: Graceful fallback with detailed logging
- **Retry Logic**: Automatic retry with exponential backoff

## API Interface

### Main Methods

#### `adjudicate(array $context): array`
Performs comprehensive fraud analysis on application data.

**Parameters:**
- `$context`: Array containing application data, rules results, ML results, and features

**Returns:**
```php
[
    'success' => true,
    'analysis' => [
        'fraud_probability' => 0.32,
        'confidence' => 0.82,
        'risk_tier' => 'medium',
        'recommendation' => 'review',
        'primary_concerns' => [...],
        'red_flags' => [...],
        'mitigating_factors' => [...],
        'reasoning' => 'Detailed analysis...'
    ],
    'processing_time_ms' => 11015.07,
    'model_used' => 'anthropic/claude-sonnet-4',
    'provider' => 'openrouter'
]
```

#### `shouldTriggerAdjudication(?array $mlResults): bool`
Determines if LLM adjudication should be triggered based on ML results.

#### `getHealthStatus(): array`
Returns service health status and configuration details.

## Usage Examples

### Basic Integration
```php
use Components\LLMAdjudicator\LLMAdjudicator;

$adjudicator = new LLMAdjudicator();

// Check if adjudication should be triggered
if ($adjudicator->shouldTriggerAdjudication($mlResults)) {
    $context = [
        'request_id' => $requestId,
        'application_data' => $applicationData,
        'rules_results' => $rulesResults,
        'ml_results' => $mlResults,
        'features' => $features
    ];
    
    $result = $adjudicator->adjudicate($context);
    
    if ($result['success']) {
        $analysis = $result['analysis'];
        // Use analysis for decision making
    }
}
```

### Health Check
```php
$status = $adjudicator->getHealthStatus();
if ($status['status'] === 'healthy') {
    // Service is operational
}
```

## Analysis Output

### Fraud Assessment
- **Fraud Probability**: 0.0-1.0 risk score
- **Confidence**: 0.0-1.0 confidence in assessment
- **Risk Tier**: low, medium, high classification
- **Recommendation**: approve, review, decline

### Detailed Insights
- **Primary Concerns**: Main risk factors identified
- **Red Flags**: Specific fraud indicators
- **Mitigating Factors**: Positive aspects of application
- **Reasoning**: Comprehensive analysis explanation

## Performance

### Typical Response Times
- **Health Check**: ~1.4 seconds
- **Full Analysis**: ~11 seconds
- **End-to-End Pipeline**: <100ms (when LLM not triggered)

### Optimization Features
- **Intelligent Triggering**: Only activates for borderline cases
- **Retry Logic**: Handles temporary API failures
- **Timeout Management**: Prevents hanging requests
- **Caching**: Response caching for identical contexts (future enhancement)

## Error Handling

### Common Error Scenarios
1. **API Key Issues**: Invalid or missing OpenRouter API key
2. **Network Failures**: Timeout or connectivity issues
3. **Rate Limiting**: API quota exceeded
4. **Invalid Responses**: Malformed JSON or missing fields

### Fallback Behavior
- **Graceful Degradation**: Pipeline continues without LLM analysis
- **Detailed Logging**: All errors logged for debugging
- **Retry Mechanism**: Automatic retry with backoff
- **Health Monitoring**: Service status tracking

## Testing

### Test Coverage
- **Health Check**: Service connectivity and configuration
- **Adjudication Logic**: Real analysis with Claude Sonnet 4
- **Trigger Logic**: All trigger condition scenarios
- **End-to-End**: Complete pipeline integration
- **Error Handling**: Failure scenarios and recovery

### Running Tests
```bash
# Comprehensive LLM adjudicator testing
php test_llm_adjudicator.php

# Component-specific testing
php test_components.php
```

## Security

### API Security
- **Secure Headers**: Authorization and referer headers
- **Environment Variables**: API keys stored securely
- **Request Validation**: Input sanitization and validation
- **Response Validation**: Output verification and sanitization

### Data Privacy
- **No Data Storage**: No application data stored by LLM provider
- **Anonymization**: Sensitive data can be masked in prompts
- **Audit Logging**: All requests logged for compliance
- **Encryption**: HTTPS for all API communications

## Monitoring

### Key Metrics
- **Response Times**: Average and percentile response times
- **Success Rates**: API call success/failure rates
- **Trigger Frequency**: How often LLM is activated
- **Analysis Quality**: Fraud detection accuracy metrics

### Logging
```php
// Success logging
Log::info('LLM adjudication completed', [
    'request_id' => $requestId,
    'model' => $model,
    'processing_time_ms' => $processingTime,
    'fraud_probability' => $fraudProbability
]);

// Error logging
Log::error('LLM adjudication failed', [
    'request_id' => $requestId,
    'error' => $errorMessage,
    'processing_time_ms' => $processingTime
]);
```

## Future Enhancements

### Planned Features
1. **Multi-Model Support**: Support for multiple LLM providers
2. **Response Caching**: Cache identical analysis requests
3. **A/B Testing**: Compare different models and prompts
4. **Fine-Tuning**: Custom model training on fraud data
5. **Real-Time Monitoring**: Advanced metrics and alerting

### AWS Migration
When migrating to AWS, the component can be configured to use:
- **Amazon Bedrock**: For Claude, GPT, or other models
- **AWS Lambda**: For serverless LLM processing
- **CloudWatch**: For monitoring and logging
- **Parameter Store**: For secure configuration management

## Troubleshooting

### Common Issues

#### "LLM Adjudicator API key not configured"
- Ensure `OPENROUTER_API_KEY` is set in `.env`
- Verify API key is valid and has sufficient credits

#### "Failed to parse JSON response"
- Check LLM model compatibility with JSON format
- Verify prompt instructions are clear
- Review response format in logs

#### "API request failed: 429"
- Rate limit exceeded - implement backoff
- Consider upgrading API plan
- Check retry configuration

#### High response times
- Monitor OpenRouter service status
- Consider model selection (faster models available)
- Implement timeout handling

### Debug Mode
Enable detailed logging by setting log level to debug in `config/logging.php`.

## Support

For issues related to:
- **Component Integration**: Check Laravel logs and component tests
- **OpenRouter API**: Consult OpenRouter documentation
- **Claude Sonnet 4**: Review Anthropic model documentation
- **Performance**: Monitor response times and adjust timeouts

## Conclusion

The LLM Adjudicator component provides sophisticated fraud analysis capabilities, leveraging state-of-the-art language models to make nuanced decisions on complex cases. With robust error handling, comprehensive testing, and detailed monitoring, it serves as a reliable final decision layer in the fraud detection pipeline.
