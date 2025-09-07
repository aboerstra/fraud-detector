# API Gateway Component Plan

## Overview
Laravel-based API gateway that handles incoming fraud detection requests, validates them, and manages the asynchronous processing pipeline.

## Local Development Setup

### Prerequisites
- PHP 8.2+
- Laravel 12.28.1
- PostgreSQL 14+
- Composer

### Installation
```bash
composer install
php artisan migrate
php artisan queue:work --queue=fraud-detection
```

## Component Responsibilities

### 1. Request Handling
- **Endpoint**: `POST /applications`
- **Authentication**: API key + HMAC (timestamp + nonce)
- **Validation**: Input validation, schema compliance
- **Response**: `job_id`, `request_id`, `status="queued"`, `received_at`, `poll_url`

### 2. Status Polling
- **Endpoint**: `GET /decision/{job_id}`
- **Response**: Current status and decision payload when complete

### 3. Security Features
- HMAC signature validation
- Replay attack prevention (nonce tracking)
- Rate limiting
- Input sanitization

## Data Contracts

### Submit Request Headers
```
X-Api-Key: {api_key}
X-Timestamp: {unix_timestamp}
X-Nonce: {unique_nonce}
X-Signature: {hmac_sha256}
```

### Submit Request Body
```json
{
  "payload_version": "1.0",
  "applicant": {
    "age": 35,
    "sin": "123456789",
    "province": "ON",
    "postal_code": "M5V3A8"
  },
  "loan": {
    "amount": 25000,
    "term_months": 60,
    "purpose": "auto"
  },
  "vehicle": {
    "year": 2020,
    "make": "Toyota",
    "model": "Camry",
    "vin": "1HGBH41JXMN109186",
    "mileage": 45000,
    "value": 28000
  }
}
```

### Decision Response
```json
{
  "job_id": "uuid",
  "request_id": "uuid",
  "status": "decided",
  "decision": {
    "final_decision": "approve|review|decline",
    "scores": {
      "rule_score": 0.3,
      "rule_band": "medium",
      "confidence_score": 0.75,
      "confidence_band": "high",
      "adjudicator_score": 0.6,
      "adjudicator_band": "medium"
    },
    "explainability": {
      "rule_flags": ["province_ip_mismatch", "high_ltv"],
      "top_features": ["debt_to_income", "credit_score", "employment_length"],
      "adjudicator_rationale": "Moderate risk due to geographic inconsistency"
    },
    "versions": {
      "rulepack_version": "1.0",
      "feature_set_version": "1.0",
      "model_version": "1.0",
      "policy_version": "1.0"
    },
    "timing": {
      "received_at": "2025-01-01T12:00:00Z",
      "decided_at": "2025-01-01T12:01:30Z",
      "total_ms": 90000
    }
  }
}
```

## Database Schema

### Tables
- `requests` - Raw application data
- `jobs` - Queue job tracking
- `decisions` - Final decisions and metadata
- `api_clients` - API key management
- `replay_nonces` - Replay attack prevention

## Implementation Files

### Controllers
- `app/Http/Controllers/ApplicationController.php` - Main API endpoints
- `app/Http/Controllers/TestUIController.php` - Testing interface

### Models
- `app/Models/FraudRequest.php` - Request data model
- `app/Models/ApiClient.php` - API client management

### Middleware
- `app/Http/Middleware/HmacAuthentication.php` - HMAC validation
- `app/Http/Middleware/RateLimiting.php` - Rate limiting

## AWS Migration Notes

### Target Architecture
- **ALB** (Application Load Balancer) for HTTPS termination
- **EC2** instances in Auto Scaling Group
- **RDS PostgreSQL** Multi-AZ for high availability
- **ElastiCache Redis** for session/cache management
- **CloudWatch** for monitoring and alerting

### Security Considerations
- WAF rules for DDoS protection
- VPC with private subnets
- Security groups with least privilege
- KMS encryption for sensitive data
- Secrets Manager for API keys

### Scaling Strategy
- Horizontal scaling via Auto Scaling Groups
- Database read replicas for read-heavy workloads
- CloudFront CDN for static assets
- SQS for queue management (replace DB queue)

## Monitoring & Alerting

### Key Metrics
- Request rate and response times
- Queue depth and processing times
- Error rates by endpoint
- Authentication failures

### Health Checks
- `/health` endpoint for load balancer
- Database connectivity check
- Queue worker status
- External service dependencies

## Testing Strategy

### Unit Tests
- Request validation logic
- HMAC authentication
- Response formatting

### Integration Tests
- End-to-end API flow
- Database operations
- Queue processing

### Load Testing
- Burst capacity (200 requests in 2 minutes)
- Sustained load testing
- Queue backlog scenarios
