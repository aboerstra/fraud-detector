# Quick Start Checklist - Fix Current Issues

## Current System Status
✅ Laravel API - healthy  
✅ ML Service - healthy (15.93ms)  
❌ LLM Adjudicator - unhealthy  
✅ Database - healthy (91.33ms)  
❌ Queue Worker - unhealthy (9 pending jobs)  

## Immediate Actions Required

### 1. Start Queue Worker (Critical - 9 jobs pending)
```bash
php artisan queue:work
```
**Why**: There are 9 pending jobs that need processing. The queue worker processes fraud detection requests.

### 2. Configure LLM Adjudicator
Add these lines to your `.env` file:
```bash
# LLM Adjudicator Configuration
LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=your-actual-api-key-here
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

### 3. Get OpenRouter API Key
1. Visit https://openrouter.ai/
2. Sign up for an account
3. Go to API Keys section
4. Create a new API key
5. Replace `your-actual-api-key-here` with the real key

### 4. Alternative: Disable LLM for Now
If you want to test without an API key, add this to `.env`:
```bash
LLM_ADJUDICATOR_ENABLED=false
```

## Quick Commands

```bash
# Start queue worker (run this first!)
php artisan queue:work

# Check queue status
php artisan queue:monitor

# Clear failed jobs if needed
php artisan queue:flush

# Restart application after .env changes
php artisan config:clear
php artisan cache:clear
```

## Verification Steps

1. **Start queue worker** - This will immediately process the 9 pending jobs
2. **Refresh test UI** at `/test-ui` 
3. **Check system health** - Queue worker should show as healthy
4. **Configure LLM** - Follow steps above to get LLM adjudicator healthy
5. **Test the system** - Submit a test application to verify everything works

## Expected Result After Fixes
✅ Laravel API - healthy  
✅ ML Service - healthy  
✅ LLM Adjudicator - healthy (after API key)  
✅ Database - healthy  
✅ Queue Worker - healthy (0 pending jobs)  

The system will then be fully operational with all enhanced features available.
