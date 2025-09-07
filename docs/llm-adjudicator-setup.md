# LLM Adjudicator Setup Guide

## Overview
The LLM Adjudicator is showing as "unhealthy" because the required environment variables are not configured. This guide will help you set up the LLM adjudicator properly.

## Required Configuration

### 1. Environment Variables
Copy the following variables from `.env.example` to your `.env` file and update the values:

```bash
# LLM Adjudicator Configuration
LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=your-actual-openrouter-api-key
LLM_ENDPOINT=https://openrouter.ai/api/v1/chat/completions
LLM_MODEL=anthropic/claude-sonnet-4
LLM_TIMEOUT=30
LLM_MAX_TOKENS=2000
LLM_TEMPERATURE=0.1
LLM_ADJUDICATOR_ENABLED=true
LLM_TRIGGER_MIN=0.3
LLM_TRIGGER_MAX=0.7
LLM_RETRY_ATTEMPTS=3
LLM_RETRY_DELAY=1000
```

### 2. Get an OpenRouter API Key

1. Visit [OpenRouter.ai](https://openrouter.ai/)
2. Sign up for an account
3. Navigate to the API Keys section
4. Create a new API key
5. Copy the key and replace `your-actual-openrouter-api-key` in your `.env` file

### 3. Alternative LLM Providers

If you prefer to use a different LLM provider, update these variables:

#### For OpenAI:
```bash
LLM_PROVIDER=openai
OPENROUTER_API_KEY=your-openai-api-key
LLM_ENDPOINT=https://api.openai.com/v1/chat/completions
LLM_MODEL=gpt-4
```

#### For AWS Bedrock:
```bash
LLM_PROVIDER=bedrock
LLM_ENDPOINT=https://bedrock-runtime.ca-central-1.amazonaws.com
LLM_MODEL=anthropic.claude-3-sonnet-20240229-v1:0
# Also ensure AWS credentials are configured
```

### 4. Testing the Configuration

After updating your `.env` file:

1. Restart your Laravel application
2. Visit the test UI at `/test-ui`
3. Check the "System Health" section
4. The LLM Adjudicator should now show as "healthy"
5. Use the "LLM Adjudicator Controls" to test:
   - Run Canary Test
   - Run Migration Test
   - Reset Circuit Breaker (if needed)

### 5. Troubleshooting

#### LLM Adjudicator still shows as unhealthy:

1. **Check API Key**: Ensure your API key is valid and has sufficient credits
2. **Check Network**: Ensure your server can reach the LLM endpoint
3. **Check Logs**: Look at Laravel logs for specific error messages:
   ```bash
   tail -f storage/logs/laravel.log
   ```

#### Common Error Messages:

- **"Invalid API key"**: Your API key is incorrect or expired
- **"Rate limit exceeded"**: You've hit the API rate limit, wait and try again
- **"Insufficient credits"**: Your account needs more credits
- **"Network timeout"**: Check your internet connection and firewall settings

#### Circuit Breaker is OPEN:

If the circuit breaker shows as "OPEN", it means there have been multiple failures:

1. Fix the underlying issue (usually API key or network)
2. Use the "Reset Circuit Breaker" button in the test UI
3. The system will automatically test and close the circuit breaker if successful

### 6. Enhanced Features

Once the LLM adjudicator is healthy, you can use these enhanced features:

- **Four-Outcome Decision Model**: APPROVE, CONDITIONAL, DECLINE, REVIEW
- **Auto-Stipulations**: Automatic generation of loan conditions
- **PII Redaction**: Secure handling of sensitive data
- **Circuit Breaker**: Automatic failure handling and recovery
- **Canary Testing**: Continuous health monitoring
- **Migration Testing**: Validation of all decision outcomes

### 7. Cost Management

Monitor your LLM usage costs:

- OpenRouter provides usage dashboards
- Set up billing alerts in your provider account
- Consider using cheaper models for development/testing
- The system includes exponential backoff to reduce unnecessary API calls

### 8. Development Mode

For development without an API key, you can temporarily disable the LLM adjudicator:

```bash
LLM_ADJUDICATOR_ENABLED=false
```

This will skip LLM adjudication and use only rules and ML scoring.

## Support

If you continue to have issues:

1. Check the Laravel logs for detailed error messages
2. Verify your network can reach the LLM endpoint
3. Test your API key with a simple curl request
4. Ensure all environment variables are properly set

The enhanced LLM adjudicator provides significant improvements in fraud detection accuracy and automation once properly configured.
