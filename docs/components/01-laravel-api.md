# Laravel API Component

## Overview
The Laravel API serves as the main entry point for fraud detection requests. It handles authentication, validation, and job queuing for asynchronous processing.

## Responsibilities
- Accept fraud detection requests via REST API
- Validate incoming requests and authenticate clients
- Persist raw application data
- Enqueue jobs for background processing
- Provide status polling endpoints

## Endpoints

### POST /applications
- **Purpose**: Submit new fraud detection request
- **Authentication**: API key + HMAC (timestamp + nonce)
- **Input**: Application JSON payload
- **Output**: Job ID and polling URL
- **Process**:
  1. Validate HMAC signature
  2. Check for replay attacks (nonce validation)
  3. Validate application payload
  4. Persist raw request to database
  5. Enqueue job via DB queue driver
  6. Return job_id and status

### GET /decision/{job_id}
- **Purpose**: Poll for fraud detection decision
- **Authentication**: API key
- **Output**: Decision status and results
- **Statuses**: `queued | processing | decided | failed`

## Authentication
- **Method**: API key + HMAC signature
- **Headers**:
  - `X-Api-Key`: Client API key
  - `X-Timestamp`: Unix timestamp
  - `X-Nonce`: Unique request identifier
  - `X-Signature`: HMAC-SHA256 over (method+path+body+timestamp+nonce)

## Data Flow
1. Client submits application → API validates → Persists to `requests` table
2. Job enqueued to `jobs` table via Laravel DB queue driver
3. Worker picks up job and processes through pipeline
4. Client polls for results via job_id

## Database Tables
- `requests`: Raw application data and metadata
- `jobs`: Laravel queue jobs table
- `failed_jobs`: Failed job tracking
- `api_clients`: Client credentials and configuration
- `replay_nonces`: Replay attack prevention

## Local Development Setup
- Laravel 12.x
- PHP 8.2+
- PostgreSQL database
- Queue worker process
- Environment variables for database and AWS credentials

## Configuration
- Database connection settings
- Queue configuration (DB driver)
- API rate limiting
- HMAC secret management
- Logging configuration

## Security Considerations
- HMAC signature validation
- Replay attack prevention via nonces
- Rate limiting per API key
- Input validation and sanitization
- Secure headers (CORS, CSP)
