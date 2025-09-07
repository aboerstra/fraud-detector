# Deployment Guide

## Overview
This guide covers deployment strategies for the fraud detection system, from local development to production AWS deployment.

## Deployment Environments

### Environment Types
1. **Local Development** - Individual developer machines
2. **Staging** - Pre-production testing environment
3. **Production** - Live production environment

### Environment Configuration
Each environment has specific configuration requirements:

```bash
# Local Development
APP_ENV=local
APP_DEBUG=true
MOCK_EXTERNAL_SERVICES=true

# Staging
APP_ENV=staging
APP_DEBUG=false
MOCK_EXTERNAL_SERVICES=false

# Production
APP_ENV=production
APP_DEBUG=false
MOCK_EXTERNAL_SERVICES=false
```

## Local Development Deployment

### Prerequisites
- Docker and Docker Compose
- Make (for convenience commands)
- Git
- 8GB+ RAM recommended

### Quick Start
```bash
# Clone repository
git clone <repository-url>
cd fraud-detector

# Initial setup
make setup

# Start all services
make start

# Verify deployment
make health-check

# Test with sample data
make sample-request
```

### Manual Setup (without Docker)
```bash
# Install dependencies
# PHP 8.2+, PostgreSQL 13+, Python 3.11+, Redis

# Database setup
createdb fraud_detector_dev
composer install
php artisan migrate --seed

# ML Service setup
cd ../ml-service
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Start services
php artisan serve --port=8080 &
php artisan queue:work &
uvicorn main:app --port=8000 &
```

### Local Environment Variables
```bash
# Copy and customize environment file
cp .env.example .env.local

# Key local settings
DB_HOST=localhost
REDIS_HOST=localhost
ML_SERVICE_URL=http://localhost:8000
MOCK_BEDROCK=true
MOCK_EXTERNAL_SERVICES=true
```

## Staging Deployment

### Infrastructure Requirements
- **Compute**: 2x t3.medium EC2 instances
- **Database**: db.t3.small RDS PostgreSQL
- **Cache**: ElastiCache Redis (t3.micro)
- **Storage**: S3 bucket for models and logs
- **Network**: VPC with public/private subnets

### Deployment Process
```bash
# 1. Infrastructure provisioning
cd infrastructure/terraform/staging
terraform init
terraform plan -var-file=staging.tfvars
terraform apply

# 2. Application deployment
cd ../../../
docker build -t fraud-api:staging .
docker build -t fraud-ml:staging ml-service/

# 3. Deploy to staging
./infrastructure/scripts/deploy-staging.sh
```

### Staging Configuration
```bash
# Environment variables for staging
APP_ENV=staging
DB_HOST=staging-rds-endpoint
REDIS_HOST=staging-redis-endpoint
ML_SERVICE_URL=http://internal-ml-service:8000
BEDROCK_ENDPOINT_URL=https://vpce-xxx.bedrock-runtime.ca-central-1.vpce.amazonaws.com
AWS_REGION=ca-central-1
```

### Staging Validation
```bash
# Health checks
curl https://staging-api.fraud-detector.com/health

# Integration tests
make test-integration ENVIRONMENT=staging

# Load testing
artillery run tests/performance/staging-load-test.yml
```

## Production Deployment

### Infrastructure Architecture
```
Internet Gateway
    ↓
Application Load Balancer
    ↓
┌─────────────────────────────────────┐
│              Public Subnet          │
│  ┌─────────────┐  ┌─────────────┐   │
│  │   NAT GW    │  │   Bastion   │   │
│  └─────────────┘  └─────────────┘   │
└─────────────────────────────────────┘
    ↓
┌─────────────────────────────────────┐
│             Private Subnet          │
│  ┌─────────────┐  ┌─────────────┐   │
│  │  API Nodes  │  │ Worker Nodes│   │
│  │ (ECS/Fargate│  │(ECS/Fargate)│   │
│  └─────────────┘  └─────────────┘   │
│  ┌─────────────┐  ┌─────────────┐   │
│  │ ML Service  │  │     RDS     │   │
│  │(ECS/Fargate)│  │(Multi-AZ)   │   │
│  └─────────────┘  └─────────────┘   │
└─────────────────────────────────────┘
```

### Production Infrastructure
```hcl
# infrastructure/terraform/production/main.tf
module "vpc" {
  source = "../modules/vpc"
  
  cidr_block = "10.0.0.0/16"
  availability_zones = ["ca-central-1a", "ca-central-1b"]
  
  tags = {
    Environment = "production"
    Project     = "fraud-detector"
  }
}

module "ecs_cluster" {
  source = "../modules/ecs"
  
  cluster_name = "fraud-detector-prod"
  vpc_id       = module.vpc.vpc_id
  subnet_ids   = module.vpc.private_subnet_ids
  
  services = {
    api = {
      image = "fraud-api:latest"
      cpu   = 512
      memory = 1024
      count  = 3
    }
    worker = {
      image = "fraud-worker:latest"
      cpu   = 256
      memory = 512
      count  = 2
    }
    ml_service = {
      image = "fraud-ml:latest"
      cpu   = 1024
      memory = 2048
      count  = 2
    }
  }
}

module "rds" {
  source = "../modules/rds"
  
  instance_class = "db.r5.large"
  multi_az      = true
  backup_retention = 7
  
  vpc_id     = module.vpc.vpc_id
  subnet_ids = module.vpc.database_subnet_ids
}
```

### Container Images

#### API Dockerfile
```dockerfile
# Dockerfile
FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    postgresql-dev \
    redis \
    nginx \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo_pgsql redis

# Copy application
COPY . /var/www/html
WORKDIR /var/www/html

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Copy configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

#### ML Service Dockerfile
```dockerfile
# src/ml-service/Dockerfile
FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy requirements and install
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application
COPY . .

# Create non-root user
RUN useradd -m -u 1000 appuser && chown -R appuser:appuser /app
USER appuser

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

### Deployment Pipeline

#### CI/CD with GitHub Actions
```yaml
# .github/workflows/deploy-production.yml
name: Deploy to Production

on:
  push:
    branches: [main]
    tags: ['v*']

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run tests
        run: make test

  build:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Configure AWS credentials
        uses: aws-actions/configure-aws-credentials@v2
        with:
          aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
          aws-region: ca-central-1
      
      - name: Login to ECR
        uses: aws-actions/amazon-ecr-login@v1
      
      - name: Build and push images
        run: |
          docker build -t $ECR_REGISTRY/fraud-api:$GITHUB_SHA .
          docker build -t $ECR_REGISTRY/fraud-ml:$GITHUB_SHA ml-service/
          docker push $ECR_REGISTRY/fraud-api:$GITHUB_SHA
          docker push $ECR_REGISTRY/fraud-ml:$GITHUB_SHA

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment: production
    steps:
      - name: Deploy to ECS
        run: |
          aws ecs update-service \
            --cluster fraud-detector-prod \
            --service fraud-api \
            --force-new-deployment
```

### Blue-Green Deployment
```bash
#!/bin/bash
# infrastructure/scripts/blue-green-deploy.sh

# 1. Deploy to green environment
aws ecs create-service \
  --cluster fraud-detector-prod \
  --service-name fraud-api-green \
  --task-definition fraud-api:latest \
  --desired-count 3

# 2. Wait for green to be healthy
aws ecs wait services-stable \
  --cluster fraud-detector-prod \
  --services fraud-api-green

# 3. Update load balancer to point to green
aws elbv2 modify-target-group \
  --target-group-arn $GREEN_TARGET_GROUP_ARN \
  --health-check-path /health

# 4. Wait for traffic to switch
sleep 300

# 5. Scale down blue environment
aws ecs update-service \
  --cluster fraud-detector-prod \
  --service fraud-api-blue \
  --desired-count 0
```

### Production Configuration

#### Environment Variables
```bash
# Production environment
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:generated-key

# Database
DB_CONNECTION=pgsql
DB_HOST=prod-rds-cluster.cluster-xxx.ca-central-1.rds.amazonaws.com
DB_DATABASE=fraud_detector_prod
DB_USERNAME=fraud_user
DB_PASSWORD=${DB_PASSWORD_FROM_SECRETS_MANAGER}

# Cache
REDIS_HOST=prod-redis.xxx.cache.amazonaws.com

# AWS Services
AWS_REGION=ca-central-1
AWS_BUCKET=fraud-detector-prod-models
BEDROCK_ENDPOINT_URL=https://vpce-xxx.bedrock-runtime.ca-central-1.vpce.amazonaws.com

# Security
ENCRYPT_SENSITIVE_DATA=true
AUDIT_LOGGING_ENABLED=true
SECURE_HEADERS_ENABLED=true

# Performance
QUEUE_WORKERS=4
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Monitoring
METRICS_ENABLED=true
HEALTH_CHECK_ENABLED=true
```

#### Secrets Management
```bash
# Store secrets in AWS Secrets Manager
aws secretsmanager create-secret \
  --name fraud-detector/prod/database \
  --description "Database credentials" \
  --secret-string '{"username":"fraud_user","password":"secure-password"}'

aws secretsmanager create-secret \
  --name fraud-detector/prod/hmac-keys \
  --description "HMAC signing keys" \
  --secret-string '{"key1":"secret1","key2":"secret2"}'
```

## Monitoring & Observability

### CloudWatch Setup
```yaml
# infrastructure/cloudwatch/dashboards.yml
FraudDetectorDashboard:
  Type: AWS::CloudWatch::Dashboard
  Properties:
    DashboardName: FraudDetector-Production
    DashboardBody: |
      {
        "widgets": [
          {
            "type": "metric",
            "properties": {
              "metrics": [
                ["AWS/ECS", "CPUUtilization", "ServiceName", "fraud-api"],
                ["AWS/ECS", "MemoryUtilization", "ServiceName", "fraud-api"]
              ],
              "period": 300,
              "stat": "Average",
              "region": "ca-central-1",
              "title": "API Service Metrics"
            }
          }
        ]
      }
```

### Alerting
```yaml
# CloudWatch Alarms
HighErrorRateAlarm:
  Type: AWS::CloudWatch::Alarm
  Properties:
    AlarmName: FraudDetector-HighErrorRate
    MetricName: 4XXError
    Namespace: AWS/ApplicationELB
    Statistic: Sum
    Period: 300
    EvaluationPeriods: 2
    Threshold: 10
    ComparisonOperator: GreaterThanThreshold
    AlarmActions:
      - !Ref SNSTopicArn

HighLatencyAlarm:
  Type: AWS::CloudWatch::Alarm
  Properties:
    AlarmName: FraudDetector-HighLatency
    MetricName: TargetResponseTime
    Namespace: AWS/ApplicationELB
    Statistic: Average
    Period: 300
    EvaluationPeriods: 2
    Threshold: 5.0
    ComparisonOperator: GreaterThanThreshold
```

## Security Hardening

### Network Security
```hcl
# Security Groups
resource "aws_security_group" "api" {
  name_prefix = "fraud-api-"
  vpc_id      = var.vpc_id

  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  egress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# VPC Endpoints
resource "aws_vpc_endpoint" "bedrock" {
  vpc_id              = var.vpc_id
  service_name        = "com.amazonaws.ca-central-1.bedrock-runtime"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = var.private_subnet_ids
  security_group_ids  = [aws_security_group.bedrock_endpoint.id]
}
```

### IAM Roles
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "bedrock:InvokeModel"
      ],
      "Resource": [
        "arn:aws:bedrock:ca-central-1::foundation-model/anthropic.claude-3-haiku-*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject"
      ],
      "Resource": [
        "arn:aws:s3:::fraud-detector-prod-models/*"
      ]
    }
  ]
}
```

## Backup & Disaster Recovery

### Database Backups
```bash
# Automated RDS backups
aws rds modify-db-instance \
  --db-instance-identifier fraud-detector-prod \
  --backup-retention-period 7 \
  --preferred-backup-window "03:00-04:00"

# Manual snapshot
aws rds create-db-snapshot \
  --db-instance-identifier fraud-detector-prod \
  --db-snapshot-identifier fraud-detector-$(date +%Y%m%d)
```

### Application Data Backup
```bash
# Model artifacts backup
aws s3 sync s3://fraud-detector-prod-models s3://fraud-detector-backup-models

# Configuration backup
kubectl get configmaps -o yaml > configs-backup-$(date +%Y%m%d).yaml
```

### Disaster Recovery Plan
1. **RTO (Recovery Time Objective)**: 4 hours
2. **RPO (Recovery Point Objective)**: 1 hour
3. **Backup Strategy**: Daily automated backups + real-time replication
4. **Recovery Procedures**: Documented runbooks for various failure scenarios

## Performance Optimization

### Auto Scaling
```hcl
resource "aws_appautoscaling_target" "api" {
  max_capacity       = 10
  min_capacity       = 2
  resource_id        = "service/fraud-detector-prod/fraud-api"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "api_cpu" {
  name               = "fraud-api-cpu-scaling"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.api.resource_id
  scalable_dimension = aws_appautoscaling_target.api.scalable_dimension
  service_namespace  = aws_appautoscaling_target.api.service_namespace

  target_tracking_scaling_policy_configuration {
    target_value = 70.0
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
  }
}
```

### Caching Strategy
```bash
# Redis cluster for production
aws elasticache create-replication-group \
  --replication-group-id fraud-detector-prod \
  --description "Fraud detector cache cluster" \
  --num-cache-clusters 3 \
  --cache-node-type cache.r6g.large \
  --engine redis \
  --engine-version 7.0
```

## Rollback Procedures

### Application Rollback
```bash
# Rollback to previous version
aws ecs update-service \
  --cluster fraud-detector-prod \
  --service fraud-api \
  --task-definition fraud-api:previous

# Database rollback (if needed)
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier fraud-detector-rollback \
  --db-snapshot-identifier fraud-detector-20240115
```

### Configuration Rollback
```bash
# Revert configuration changes
aws ssm put-parameter \
  --name "/fraud-detector/prod/config" \
  --value "$(cat previous-config.json)" \
  --overwrite

# Restart services to pick up changes
aws ecs update-service \
  --cluster fraud-detector-prod \
  --service fraud-api \
  --force-new-deployment
```

## Maintenance Procedures

### Scheduled Maintenance
```bash
# 1. Scale up capacity before maintenance
aws ecs update-service \
  --cluster fraud-detector-prod \
  --service fraud-api \
  --desired-count 6

# 2. Perform rolling updates
for instance in $(aws ecs list-container-instances --cluster fraud-detector-prod --query 'containerInstanceArns[]' --output text); do
  aws ecs update-container-instances-state \
    --cluster fraud-detector-prod \
    --container-instances $instance \
    --status DRAINING
  
  # Wait for tasks to drain
  sleep 300
  
  # Perform maintenance
  # ...
  
  # Bring back online
  aws ecs update-container-instances-state \
    --cluster fraud-detector-prod \
    --container-instances $instance \
    --status ACTIVE
done

# 3. Scale back to normal capacity
aws ecs update-service \
  --cluster fraud-detector-prod \
  --service fraud-api \
  --desired-count 3
```

### Database Maintenance
```bash
# Apply minor version updates
aws rds modify-db-instance \
  --db-instance-identifier fraud-detector-prod \
  --engine-version 15.4 \
  --apply-immediately

# Vacuum and analyze (during maintenance window)
psql -h $DB_HOST -U $DB_USER -d fraud_detector_prod -c "VACUUM ANALYZE;"
```

## Troubleshooting

### Common Issues

#### High Latency
1. Check ECS service metrics
2. Verify database performance
3. Check external service dependencies
4. Review application logs

#### Queue Backlog
1. Scale up worker instances
2. Check for failed jobs
3. Verify ML service availability
4. Monitor Bedrock API limits

#### Database Connection Issues
1. Check RDS instance status
2. Verify security group rules
3. Check connection pool settings
4. Review database logs

### Debugging Tools
```bash
# ECS service logs
aws logs tail /ecs/fraud-api --follow

# Database performance
aws rds describe-db-log-files \
  --db-instance-identifier fraud-detector-prod

# Application metrics
aws cloudwatch get-metric-statistics \
  --namespace "FraudDetector" \
  --metric-name "ProcessingTime" \
  --start-time 2024-01-15T00:00:00Z \
  --end-time 2024-01-15T23:59:59Z \
  --period 3600 \
  --statistics Average
```

## Cost Optimization

### Resource Right-Sizing
- Monitor CPU/memory utilization
- Use Spot instances for non-critical workloads
- Implement auto-scaling policies
- Regular review of instance types

### Cost Monitoring
```bash
# Set up billing alerts
aws budgets create-budget \
  --account-id $ACCOUNT_ID \
  --budget file://budget-config.json

# Cost allocation tags
aws resourcegroupstaggingapi tag-resources \
  --resource-arn-list $RESOURCE_ARN \
  --tags Project=FraudDetector,Environment=Production
```

This deployment guide provides comprehensive coverage of deploying the fraud detection system from local development through production, including security, monitoring, and maintenance procedures.
