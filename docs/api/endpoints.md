# Fraud Detection API - Endpoint Documentation

## Base URL
- **Local Development**: `http://localhost:8080`
- **POC Environment**: `https://fraud-api.poc.ca-central-1.amazonaws.com`

## Authentication

All API endpoints (except health check) require HMAC authentication with the following headers:

- `X-Api-Key`: Client API key
- `X-Timestamp`: Unix timestamp (within 5 minutes)
- `X-Nonce`: Unique request identifier (32-character hex)
- `X-Signature`: HMAC-SHA256 signature

### HMAC Signature Generation

```
payload = method + path + body + timestamp + nonce
signature = HMAC-SHA256(payload, secret_key)
```

**Example (PHP)**:
```php
$method = 'POST';
$path = 'api/applications';
$body = json_encode($data);
$timestamp = time();
$nonce = bin2hex(random_bytes(16));

$payload = $method . $path . $body . $timestamp . $nonce;
$signature = hash_hmac('sha256', $payload, $secret_key);
```

## Endpoints

### 1. Health Check

**GET** `/api/health`

Returns system health status and service availability.

**Authentication**: None required

**Response** (200 OK):
```json
{
  "status": "healthy",
  "timestamp": "2025-09-07T00:22:22.000000Z",
  "version": "1.0.0",
  "services": {
    "database": "healthy",
    "queue": "healthy",
    "ml_service": "healthy"
  }
}
```

**Error Response** (503 Service Unavailable):
```json
{
  "status": "unhealthy",
  "timestamp": "2025-09-07T00:22:22.000000Z",
  "error": "Database connection failed"
}
```

### 2. Submit Fraud Detection Request

**POST** `/api/applications`

Submits a new fraud detection request for processing.

**Authentication**: Required (HMAC)

**Content-Type**: `application/json`

**Request Body**:
```json
{
  "personal_info": {
    "date_of_birth": "1990-05-15",
    "sin": "123456789",
    "province": "ON"
  },
  "contact_info": {
    "email": "john.doe@example.com",
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
    "employment_status": "employed"
  },
  "loan_info": {
    "amount": 25000,
    "term_months": 60,
    "down_payment": 5000
  },
  "vehicle_info": {
    "vin": "1HGBH41JXMN109186",
    "year": 2020,
    "make": "Honda",
    "model": "Civic",
    "mileage": 45000,
    "value": 22000
  },
  "dealer_info": {
    "dealer_id": "DEALER001",
    "location": "Toronto, ON"
  }
}
```

**Validation Rules**:
- `personal_info.date_of_birth`: Required, valid date, before today
- `personal_info.sin`: Required, exactly 9 digits
- `personal_info.province`: Required, 2-character province code
- `contact_info.email`: Required, valid email format
- `contact_info.phone`: Required, valid phone number
- `contact_info.address.*`: All address fields required
- `financial_info.annual_income`: Required, numeric, ≥ 0
- `financial_info.employment_status`: Required, one of: employed, self_employed, unemployed, retired
- `loan_info.amount`: Required, numeric, $1,000 - $100,000
- `loan_info.term_months`: Required, integer, 12-84 months
- `loan_info.down_payment`: Required, numeric, ≥ 0
- `vehicle_info.vin`: Required, exactly 17 characters
- `vehicle_info.year`: Required, integer, 1990 - current year + 1
- `vehicle_info.mileage`: Required, integer, ≥ 0
- `vehicle_info.value`: Required, numeric, ≥ $1,000
- `dealer_info.dealer_id`: Required, string
- `dealer_info.location`: Required, string

**Success Response** (201 Created):
```json
{
  "job_id": "01992188-d632-73b7-a51d-3708c0aee439",
  "status": "queued",
  "polling_url": "http://localhost:8080/api/v1/decision/01992188-d632-73b7-a51d-3708c0aee439",
  "estimated_completion": "2025-09-07T00:24:22.935134Z"
}
```

**Error Responses**:

**400 Bad Request** (Validation Error):
```json
{
  "error": "Request processing failed",
  "message": "Validation failed: The personal info.sin field must be exactly 9 characters."
}
```

**401 Unauthorized** (Authentication Error):
```json
{
  "error": "Request processing failed",
  "message": "Invalid signature"
}
```

### 3. Get Fraud Detection Decision

**GET** `/api/decision/{job_id}`

Retrieves the fraud detection decision and analysis for a specific job.

**Authentication**: None required

**Path Parameters**:
- `job_id`: UUID of the fraud detection job

**Response - Queued** (200 OK):
```json
{
  "job_id": "01992188-d632-73b7-a51d-3708c0aee439",
  "status": "queued",
  "submitted_at": "2025-09-07T00:17:19.000000Z"
}
```

**Response - Processing** (200 OK):
```json
{
  "job_id": "01992188-d632-73b7-a51d-3708c0aee439",
  "status": "processing",
  "submitted_at": "2025-09-07T00:17:19.000000Z",
  "current_stage": "ml_scoring"
}
```

**Response - Completed** (200 OK):
```json
{
  "job_id": "01992188-d632-73b7-a51d-3708c0aee439",
  "status": "decided",
  "submitted_at": "2025-09-07T00:17:19.000000Z",
  "decision": {
    "final_decision": "approve",
    "reasons": [
      "Low risk profile based on rules analysis",
      "ML confidence score indicates low fraud probability",
      "Adjudicator assessment supports approval"
    ]
  },
  "scores": {
    "rule_score": 0.15,
    "rule_band": "low",
    "confidence_score": 0.23,
    "confidence_band": "low",
    "adjudicator_score": 0.18,
    "adjudicator_band": "low"
  },
  "explainability": {
    "rule_flags": [
      "province_ip_match",
      "valid_sin_checksum"
    ],
    "top_features": [
      "loan_to_value_ratio: 0.68",
      "employment_status: employed",
      "province_consistency: true"
    ],
    "adjudicator_rationale": [
      "Consistent employment history and reasonable loan terms",
      "No significant risk indicators in application data",
      "Geographic and identity verification checks passed"
    ]
  },
  "versions": {
    "rulepack_version": "v1.0.0",
    "feature_set_version": "v1.0.0",
    "model_version": "v1.0.0",
    "calibration_version": "v1.0.0",
    "policy_version": "v1.0.0",
    "adjudicator_model_id": "claude-3-haiku",
    "prompt_template_version": "v1.0.0"
  },
  "timing": {
    "received_at": "2025-09-07T00:17:19.000000Z",
    "decided_at": "2025-09-07T00:18:45.000000Z",
    "total_ms": 86000
  }
}
```

**Response - Failed** (200 OK):
```json
{
  "job_id": "01992188-d632-73b7-a51d-3708c0aee439",
  "status": "failed",
  "submitted_at": "2025-09-07T00:17:19.000000Z",
  "error": "ML service unavailable after 3 retry attempts"
}
```

**Error Response** (404 Not Found):
```json
{
  "error": "Job not found",
  "message": "No job found with ID: invalid-job-id"
}
```

## Status Codes

- **200 OK**: Request successful
- **201 Created**: Resource created successfully
- **400 Bad Request**: Invalid request data or validation error
- **401 Unauthorized**: Authentication failed
- **404 Not Found**: Resource not found
- **503 Service Unavailable**: System health check failed

## Rate Limiting

- **POC Environment**: 100 requests per minute per API key
- **Burst Allowance**: Up to 200 requests in 2-minute window
- **Headers**: Rate limit information included in response headers
  - `X-RateLimit-Limit`: Requests per minute limit
  - `X-RateLimit-Remaining`: Remaining requests in current window
  - `X-RateLimit-Reset`: Timestamp when limit resets

## Error Handling

All error responses follow a consistent format:

```json
{
  "error": "Brief error description",
  "message": "Detailed error message",
  "timestamp": "2025-09-07T00:22:22.000000Z",
  "request_id": "req_01992188d63273b7a51d3708c0aee439"
}
```

## Decision Values

### Final Decision
- `approve`: Application approved for processing
- `review`: Application requires manual review
- `decline`: Application declined

### Score Bands
- `low`: Score 0.0 - 0.3
- `medium`: Score 0.3 - 0.7
- `high`: Score 0.7 - 1.0

### Employment Status Values
- `employed`: Full-time or part-time employment
- `self_employed`: Self-employed or contractor
- `unemployed`: Currently unemployed
- `retired`: Retired

### Province Codes
Valid 2-character Canadian province/territory codes:
- `AB`, `BC`, `MB`, `NB`, `NL`, `NS`, `NT`, `NU`, `ON`, `PE`, `QC`, `SK`, `YT`

## Example Integration

### Complete Request Flow (PHP)

```php
<?php
// 1. Submit fraud detection request
$client = new FraudDetectionClient($apiKey, $secretKey, $baseUrl);
$response = $client->submitApplication($applicationData);
$jobId = $response['job_id'];

// 2. Poll for results
do {
    sleep(5); // Wait 5 seconds between polls
    $decision = $client->getDecision($jobId);
} while ($decision['status'] === 'queued' || $decision['status'] === 'processing');

// 3. Process final decision
if ($decision['status'] === 'decided') {
    $finalDecision = $decision['decision']['final_decision'];
    $scores = $decision['scores'];
    $explanations = $decision['explainability'];
    
    // Handle business logic based on decision
    switch ($finalDecision) {
        case 'approve':
            // Process approval
            break;
        case 'review':
            // Queue for manual review
            break;
        case 'decline':
            // Process decline
            break;
    }
}
?>
