# Fraud Detection System - Architecture Overview

## System Goals & Constraints

### Primary Objectives
- **Performance**: Async fraud decision in ≤ 5 minutes P95 (target: 30-120s typical)
- **Explainability**: Return rule_score, ml confidence_score, adjudicator_score, final_decision, and explanations
- **Privacy**: Data minimization to LLM (no PII), Canada residency compliance
- **Cost Control**: Minimal infrastructure, efficient Bedrock usage

### Technical Constraints
- **Region**: AWS ca-central-1 only
- **Framework**: Laravel 12.28.1 with PHP 8.4
- **Queue**: Laravel DB queue driver (no external queue services)
- **Database**: PostgreSQL with UUID primary keys
- **ML**: LightGBM on EC2
- **LLM**: AWS Bedrock (Claude 3 Haiku or Llama 3 8B)

## High-Level Architecture

```
Client/Sample App
       ↓
Laravel API (EC2) ← HMAC Auth
       ↓
PostgreSQL RDS ← Store requests/results
       ↓
DB Queue ← Async processing
       ↓
Worker Process ← 5-stage pipeline
       ↓
┌─────────────────────────────────────┐
│ Stage 1: Rules Engine               │
│ Stage 2: Feature Engineering        │
│ Stage 3: ML Scoring (LightGBM)      │
│ Stage 4: LLM Adjudication (Bedrock) │
│ Stage 5: Decision Assembly          │
└─────────────────────────────────────┘
       ↓
Decision Storage ← Final results
       ↓
GET /decision/{job_id} ← Client polling
```

## Core Components

### 1. Laravel API Layer
- **Endpoints**: 
  - `POST /applications` - Submit fraud detection requests
  - `GET /decision/{job_id}` - Retrieve fraud analysis results
  - `GET /health` - System health monitoring
- **Authentication**: HMAC with timestamp and nonce validation
- **Validation**: Comprehensive input validation for Canadian auto loan applications

### 2. Database Layer (PostgreSQL)
- **Primary Tables**:
  - `fraud_requests` - Application data and final results
  - `jobs` / `failed_jobs` - Queue management
  - `replay_nonces` - HMAC replay attack prevention
- **Features**: UUID primary keys, JSON fields, comprehensive indexing

### 3. Queue Processing System
- **Driver**: Laravel DB queue (no external dependencies)
- **Concurrency**: 2-4 workers for optimal throughput
- **Retry Logic**: 3 attempts with exponential backoff
- **Monitoring**: Queue depth and processing time tracking

### 4. Fraud Detection Pipeline

#### Stage 1: Rules Engine
- **Hard-fail checks**: Invalid SIN, missing mandatory fields, deny list hits
- **Risk scoring**: Province-IP mismatch, email/phone reuse, VIN validation, LTV analysis
- **Output**: `rule_score` (0-1), `rule_flags[]`, `rulepack_version`

#### Stage 2: Feature Engineering
- **Top-15 Features**: Identity, digital footprint, velocity, geo, loan/vehicle ratios
- **Output**: Feature vector, `feature_set_version`
- **Validation**: Range checking, null handling

#### Stage 3: ML Scoring (LightGBM)
- **Model**: Calibrated LightGBM with isotonic/Platt scaling
- **Input**: 15-feature vector from Stage 2
- **Output**: `confidence_score` (0-1), `top_features[]`, `model_version`

#### Stage 4: LLM Adjudication (Bedrock)
- **Privacy**: Redacted dossier (no PII - names, SIN, email, phone, VIN, addresses)
- **Input**: Age bands, province codes, numeric ratios, flags, ML features
- **Output**: `adjudicator_score` (0-1), `risk_band`, `rationale`
- **Models**: Claude 3 Haiku (primary), Llama 3 8B (alternative)

#### Stage 5: Decision Assembly
- **Logic**: Threshold-based decision tree combining all scores
- **Output**: `final_decision` (approve/review/decline), `reasons[]`, `policy_version`

## Data Flow

### Request Submission
1. Client submits HMAC-authenticated request
2. API validates payload and authentication
3. Request stored in `fraud_requests` table
4. Job queued for async processing
5. Client receives `job_id` and polling URL

### Pipeline Processing
1. Worker picks up job from queue
2. Executes 5-stage pipeline sequentially
3. Each stage updates request record with results
4. Final decision assembled and stored
5. Job marked as completed

### Result Retrieval
1. Client polls `GET /decision/{job_id}`
2. API returns current status and results
3. Complete response includes scores, explanations, and timing data

## Security & Privacy

### Authentication
- **HMAC Signing**: Method + path + body + timestamp + nonce
- **Replay Protection**: Nonce tracking with 5-minute timestamp window
- **API Keys**: Per-client authentication credentials

### Data Protection
- **Encryption**: TLS in transit, KMS encryption at rest
- **PII Redaction**: Strict data minimization for LLM processing
- **Access Control**: Security groups and VPC endpoints
- **Audit Trail**: Complete request lineage with version tracking

## Performance & Monitoring

### SLA Targets
- **P95 Latency**: ≤ 5 minutes (target: 30-120 seconds)
- **Queue Depth**: < 10 pending jobs typical
- **Availability**: 99.5% uptime during business hours

### Monitoring Points
- Queue depth and oldest job age
- Stage-by-stage processing times
- Error rates and retry patterns
- Bedrock token usage and costs
- Database performance metrics

## Cost Management

### Bedrock Optimization
- **Token Limits**: ≤200 output tokens per request
- **Model Selection**: Prefer Claude 3 Haiku for cost efficiency
- **Budget Alarms**: $100 monthly ceiling for POC

### Infrastructure Efficiency
- **EC2**: t3.small/medium instances
- **RDS**: db.t3.micro with minimal storage
- **Auto-scaling**: Manual scaling based on queue metrics

## Deployment Architecture

### POC Environment
- **Compute**: 1-2 EC2 instances (API + Worker)
- **Database**: Single-AZ RDS PostgreSQL
- **Storage**: S3 bucket for model artifacts
- **Networking**: VPC with security groups, Bedrock VPC endpoint

### Production Path
- **High Availability**: Multi-AZ RDS, auto-scaling groups
- **Security**: WAF, Shield, Secrets Manager
- **Monitoring**: CloudWatch, centralized logging
- **Disaster Recovery**: Cross-region backups

## Version Management

### Component Versioning
- `rulepack_version` - Rules engine configuration
- `feature_set_version` - Feature engineering schema
- `model_version` - LightGBM model artifact
- `calibration_version` - Probability calibration
- `policy_version` - Decision threshold configuration
- `prompt_template_version` - LLM prompt structure

### Deployment Strategy
- **Blue-Green**: For model updates
- **Feature Flags**: For rules and policy changes
- **Rollback**: Version-aware rollback capabilities
