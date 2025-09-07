# API Specification

## Overview
This document provides detailed API specifications for the fraud detection system, including request/response schemas, authentication, error handling, and usage examples.

## Base Information

- **Base URL**: `http://localhost:8080` (development)
- **API Version**: v1
- **Content Type**: `application/json`
- **Authentication**: HMAC-SHA256 with API keys

## Authentication

### HMAC Authentication
All API requests must include HMAC authentication headers:

```http
X-Api-Key: your-api-key
X-Timestamp: 1640995200
X-Nonce: unique-request-identifier
X-Signature: hmac-sha256-signature
```

### Signature Generation
```javascript
// Signature calculation
const message = `${method}${path}${body}${timestamp}${nonce}`;
const signature = crypto.createHmac('sha256', secretKey).update(message).digest('hex');
```

### Example (JavaScript)
```javascript
function generateSignature(method, path, body, timestamp, nonce, secretKey) {
    const message = method + path + body + timestamp + nonce;
    return crypto.createHmac('sha256', secretKey).update(message).digest('hex');
}

const headers = {
    'Content-Type': 'application/json',
    'X-Api-Key': 'your-api-key',
    'X-Timestamp': Math.floor(Date.now() / 1000).toString(),
    'X-Nonce': crypto.randomUUID(),
    'X-Signature': generateSignature('POST', '/applications', JSON.stringify(body), timestamp, nonce, secretKey)
};
```

## Endpoints

### 1. Submit Application

Submit a new fraud detection application for processing.

**Endpoint**: `POST /applications`

#### Request Headers
```http
Content-Type: application/json
X-Api-Key: string (required)
X-Timestamp: integer (required)
X-Nonce: string (required)
X-Signature: string (required)
```

#### Request Body Schema
```json
{
  "personal_info": {
    "date_of_birth": "string (YYYY-MM-DD, required)",
    "sin": "string (9 digits, required)",
    "province": "string (2 chars, required)"
  },
  "contact_info": {
    "email": "string (email format, required)",
    "phone": "string (E.164 format, required)",
    "address": {
      "street": "string (required)",
      "city": "string (required)",
      "province": "string (2 chars, required)",
      "postal_code": "string (Canadian format, required)"
    }
  },
  "financial_info": {
    "annual_income": "number (positive, required)",
    "employment_status": "string (enum: employed|self_employed|unemployed|retired, required)",
    "employer": "string (optional)",
    "employment_duration_months": "number (optional)"
  },
  "loan_info": {
    "amount": "number (positive, required)",
    "term_months": "number (12-84, required)",
    "down_payment": "number (non-negative, required)",
    "purpose": "string (enum: vehicle_purchase|refinance, required)"
  },
  "vehicle_info": {
    "vin": "string (17 chars, required)",
    "year": "number (1900-current+1, required)",
    "make": "string (required)",
    "model": "string (required)",
    "trim": "string (optional)",
    "mileage": "number (non-negative, required)",
    "value": "number (positive, required)",
    "condition": "string (enum: new|used|certified, required)"
  },
  "dealer_info": {
    "dealer_id": "string (required)",
    "dealer_name": "string (required)",
    "location": "string (required)",
    "license_number": "string (optional)"
  },
  "application_metadata": {
    "application_source": "string (enum: web|mobile|api, optional)",
    "referral_code": "string (optional)",
    "marketing_campaign": "string (optional)",
    "user_agent": "string (optional)",
    "session_id": "string (optional)"
  }
}
```

#### Response Schema (Success - 202 Accepted)
```json
{
  "job_id": "string (UUID)",
  "request_id": "string (UUID)",
  "status": "queued",
  "received_at": "string (ISO 8601)",
  "poll_url": "string (URL)",
  "estimated_completion": "string (ISO 8601)"
}
```

#### Example Request
```bash
curl -X POST http://localhost:8080/applications \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: test-key-123" \
  -H "X-Timestamp: 1640995200" \
  -H "X-Nonce: req-abc123" \
  -H "X-Signature: a1b2c3d4e5f6..." \
  -d '{
    "personal_info": {
      "date_of_birth": "1985-06-15",
      "sin": "123456782",
      "province": "ON"
    },
    "contact_info": {
      "email": "john.doe@gmail.com",
      "phone": "+1-416-555-0123",
      "address": {
        "street": "123 Main Street",
        "city": "Toronto",
        "province": "ON",
        "postal_code": "M5V 3A8"
      }
    },
    "financial_info": {
      "annual_income": 75000,
      "employment_status": "employed",
      "employer": "Tech Corp Inc",
      "employment_duration_months": 36
    },
    "loan_info": {
      "amount": 25000,
      "term_months": 60,
      "down_payment": 5000,
      "purpose": "vehicle_purchase"
    },
    "vehicle_info": {
      "vin": "1HGBH41JXMN109186",
      "year": 2020,
      "make": "Honda",
      "model": "Civic",
      "trim": "LX",
      "mileage": 15000,
      "value": 30000,
      "condition": "used"
    },
    "dealer_info": {
      "dealer_id": "DEALER123",
      "dealer_name": "Toronto Auto Sales",
      "location": "Toronto, ON",
      "license_number": "D12345"
    }
  }'
```

#### Example Response
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "request_id": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "status": "queued",
  "received_at": "2024-01-15T10:00:00Z",
  "poll_url": "/decision/550e8400-e29b-41d4-a716-446655440000",
  "estimated_completion": "2024-01-15T10:02:00Z"
}
```

### 2. Get Decision

Retrieve the fraud detection decision for a submitted application.

**Endpoint**: `GET /decision/{job_id}`

#### Request Headers
```http
X-Api-Key: string (required)
```

#### Path Parameters
- `job_id`: UUID of the job returned from application submission

#### Response Schema (Processing - 202 Accepted)
```json
{
  "job_id": "string (UUID)",
  "status": "processing",
  "current_stage": "string (rules|features|ml_scoring|adjudication|decision)",
  "progress_percentage": "number (0-100)",
  "estimated_completion": "string (ISO 8601)",
  "started_at": "string (ISO 8601)"
}
```

#### Response Schema (Completed - 200 OK)
```json
{
  "job_id": "string (UUID)",
  "request_id": "string (UUID)",
  "status": "decided",
  "decision": {
    "final_decision": "string (approve|review|decline)",
    "reasons": ["string"]
  },
  "scores": {
    "rule_score": "number (0-1)",
    "rule_band": "string (low|medium|high)",
    "confidence_score": "number (0-1)",
    "confidence_band": "string (low|medium|high)",
    "adjudicator_score": "number (0-1, optional)",
    "adjudicator_band": "string (low|medium|high, optional)"
  },
  "explainability": {
    "rule_flags": ["string"],
    "top_features": [
      {
        "feature_name": "string",
        "feature_value": "number",
        "importance": "number",
        "contribution": "number"
      }
    ],
    "adjudicator_rationale": ["string"]
  },
  "versions": {
    "rulepack_version": "string",
    "feature_set_version": "string",
    "model_version": "string",
    "calibration_version": "string",
    "policy_version": "string",
    "adjudicator_model_id": "string",
    "prompt_template_version": "string"
  },
  "timing": {
    "received_at": "string (ISO 8601)",
    "queued_at": "string (ISO 8601)",
    "started_at": "string (ISO 8601)",
    "ml_scored_at": "string (ISO 8601)",
    "adjudicated_at": "string (ISO 8601, optional)",
    "decided_at": "string (ISO 8601)",
    "total_ms": "number"
  }
}
```

#### Example Request
```bash
curl -H "X-Api-Key: test-key-123" \
  http://localhost:8080/decision/550e8400-e29b-41d4-a716-446655440000
```

#### Example Response (Completed)
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "request_id": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "status": "decided",
  "decision": {
    "final_decision": "approve",
    "reasons": [
      "Risk assessment within acceptable parameters",
      "No significant rule violations detected",
      "ML confidence score indicates low fraud risk"
    ]
  },
  "scores": {
    "rule_score": 0.25,
    "rule_band": "low",
    "confidence_score": 0.15,
    "confidence_band": "low",
    "adjudicator_score": 0.30,
    "adjudicator_band": "low"
  },
  "explainability": {
    "rule_flags": [],
    "top_features": [
      {
        "feature_name": "age",
        "feature_value": 38.5,
        "importance": 0.12,
        "contribution": 4.62
      },
      {
        "feature_name": "ltv_ratio",
        "feature_value": 0.83,
        "importance": 0.08,
        "contribution": 6.64
      }
    ],
    "adjudicator_rationale": [
      "Standard risk profile for demographic",
      "Financial ratios within normal ranges",
      "No concerning patterns detected"
    ]
  },
  "versions": {
    "rulepack_version": "v1.2.0",
    "feature_set_version": "v1.0.0",
    "model_version": "v1.0.0",
    "calibration_version": "v1.0.0",
    "policy_version": "v1.3.0",
    "adjudicator_model_id": "anthropic.claude-3-haiku-20240307-v1:0",
    "prompt_template_version": "v1.2.0"
  },
  "timing": {
    "received_at": "2024-01-15T10:00:00Z",
    "queued_at": "2024-01-15T10:00:01Z",
    "started_at": "2024-01-15T10:00:05Z",
    "ml_scored_at": "2024-01-15T10:00:45Z",
    "adjudicated_at": "2024-01-15T10:01:15Z",
    "decided_at": "2024-01-15T10:01:30Z",
    "total_ms": 90000
  }
}
```

### 3. Health Check

Check the health status of the API service.

**Endpoint**: `GET /health`

#### Response Schema (200 OK)
```json
{
  "status": "healthy",
  "timestamp": "string (ISO 8601)",
  "version": "string",
  "services": {
    "database": "string (healthy|unhealthy)",
    "queue": "string (healthy|unhealthy)",
    "ml_service": "string (healthy|unhealthy)",
    "redis": "string (healthy|unhealthy)"
  },
  "metrics": {
    "queue_depth": "number",
    "active_workers": "number",
    "avg_processing_time_ms": "number"
  }
}
```

#### Example Request
```bash
curl http://localhost:8080/health
```

#### Example Response
```json
{
  "status": "healthy",
  "timestamp": "2024-01-15T10:00:00Z",
  "version": "1.0.0",
  "services": {
    "database": "healthy",
    "queue": "healthy",
    "ml_service": "healthy",
    "redis": "healthy"
  },
  "metrics": {
    "queue_depth": 3,
    "active_workers": 2,
    "avg_processing_time_ms": 45000
  }
}
```

## Error Responses

### Error Schema
```json
{
  "error": {
    "code": "string",
    "message": "string",
    "details": "object (optional)",
    "timestamp": "string (ISO 8601)",
    "request_id": "string (UUID)"
  }
}
```

### HTTP Status Codes

#### 400 Bad Request
Invalid request data or malformed JSON.

```json
{
  "error": {
    "code": "INVALID_REQUEST",
    "message": "Validation failed",
    "details": {
      "field_errors": {
        "personal_info.sin": ["SIN must be 9 digits"],
        "loan_info.amount": ["Amount must be positive"]
      }
    },
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 401 Unauthorized
Missing or invalid authentication.

```json
{
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Invalid API key or signature",
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 403 Forbidden
Valid authentication but insufficient permissions.

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "API key does not have permission for this operation",
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 404 Not Found
Requested resource not found.

```json
{
  "error": {
    "code": "NOT_FOUND",
    "message": "Job not found",
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 409 Conflict
Duplicate request (replay attack prevention).

```json
{
  "error": {
    "code": "DUPLICATE_REQUEST",
    "message": "Nonce has already been used",
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 422 Unprocessable Entity
Valid JSON but business logic validation failed.

```json
{
  "error": {
    "code": "BUSINESS_VALIDATION_FAILED",
    "message": "Application failed business validation",
    "details": {
      "violations": [
        "Applicant age below minimum requirement",
        "Vehicle value exceeds loan amount limits"
      ]
    },
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 429 Too Many Requests
Rate limit exceeded.

```json
{
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "message": "Too many requests",
    "details": {
      "limit": 60,
      "window_seconds": 60,
      "retry_after_seconds": 30
    },
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 500 Internal Server Error
Unexpected server error.

```json
{
  "error": {
    "code": "INTERNAL_ERROR",
    "message": "An unexpected error occurred",
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

#### 503 Service Unavailable
Service temporarily unavailable.

```json
{
  "error": {
    "code": "SERVICE_UNAVAILABLE",
    "message": "Service temporarily unavailable",
    "details": {
      "retry_after_seconds": 60
    },
    "timestamp": "2024-01-15T10:00:00Z",
    "request_id": "req-123"
  }
}
```

## Rate Limiting

### Default Limits
- **60 requests per minute** per API key
- **1000 requests per hour** per API key
- **10000 requests per day** per API key

### Rate Limit Headers
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1640995260
X-RateLimit-Window: 60
```

## Webhooks (Future Enhancement)

### Webhook Configuration
```json
{
  "webhook_url": "https://your-domain.com/fraud-webhook",
  "events": ["decision.completed", "decision.failed"],
  "secret": "webhook-secret-key"
}
```

### Webhook Payload
```json
{
  "event": "decision.completed",
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2024-01-15T10:01:30Z",
  "data": {
    "final_decision": "approve",
    "confidence_score": 0.15,
    "processing_time_ms": 90000
  }
}
```

## SDK Examples

### Python SDK
```python
import requests
import hashlib
import hmac
import time
import uuid

class FraudDetectionClient:
    def __init__(self, api_key, secret_key, base_url):
        self.api_key = api_key
        self.secret_key = secret_key
        self.base_url = base_url
    
    def _generate_signature(self, method, path, body, timestamp, nonce):
        message = f"{method}{path}{body}{timestamp}{nonce}"
        return hmac.new(
            self.secret_key.encode(),
            message.encode(),
            hashlib.sha256
        ).hexdigest()
    
    def submit_application(self, application_data):
        path = "/applications"
        body = json.dumps(application_data)
        timestamp = str(int(time.time()))
        nonce = str(uuid.uuid4())
        
        headers = {
            'Content-Type': 'application/json',
            'X-Api-Key': self.api_key,
            'X-Timestamp': timestamp,
            'X-Nonce': nonce,
            'X-Signature': self._generate_signature('POST', path, body, timestamp, nonce)
        }
        
        response = requests.post(f"{self.base_url}{path}", headers=headers, data=body)
        return response.json()
    
    def get_decision(self, job_id):
        path = f"/decision/{job_id}"
        headers = {'X-Api-Key': self.api_key}
        
        response = requests.get(f"{self.base_url}{path}", headers=headers)
        return response.json()

# Usage
client = FraudDetectionClient('your-api-key', 'your-secret', 'http://localhost:8080')
result = client.submit_application(application_data)
decision = client.get_decision(result['job_id'])
```

### Node.js SDK
```javascript
const crypto = require('crypto');
const axios = require('axios');

class FraudDetectionClient {
    constructor(apiKey, secretKey, baseUrl) {
        this.apiKey = apiKey;
        this.secretKey = secretKey;
        this.baseUrl = baseUrl;
    }
    
    generateSignature(method, path, body, timestamp, nonce) {
        const message = method + path + body + timestamp + nonce;
        return crypto.createHmac('sha256', this.secretKey).update(message).digest('hex');
    }
    
    async submitApplication(applicationData) {
        const path = '/applications';
        const body = JSON.stringify(applicationData);
        const timestamp = Math.floor(Date.now() / 1000).toString();
        const nonce = crypto.randomUUID();
        
        const headers = {
            'Content-Type': 'application/json',
            'X-Api-Key': this.apiKey,
            'X-Timestamp': timestamp,
            'X-Nonce': nonce,
            'X-Signature': this.generateSignature('POST', path, body, timestamp, nonce)
        };
        
        const response = await axios.post(`${this.baseUrl}${path}`, applicationData, { headers });
        return response.data;
    }
    
    async getDecision(jobId) {
        const path = `/decision/${jobId}`;
        const headers = { 'X-Api-Key': this.apiKey };
        
        const response = await axios.get(`${this.baseUrl}${path}`, { headers });
        return response.data;
    }
}

// Usage
const client = new FraudDetectionClient('your-api-key', 'your-secret', 'http://localhost:8080');
const result = await client.submitApplication(applicationData);
const decision = await client.getDecision(result.job_id);
```

## Testing

### Postman Collection
A Postman collection is available with pre-configured requests and environment variables for easy API testing.

### Test API Keys
For development and testing:
- **API Key**: `test-key-123`
- **Secret Key**: `test-secret-456`

### Mock Responses
The API supports mock mode for testing without processing:
- Add header `X-Mock-Response: true` to receive immediate mock responses
- Useful for integration testing and development
