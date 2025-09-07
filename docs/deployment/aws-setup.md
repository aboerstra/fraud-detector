# AWS Deployment Guide

## Overview

This guide covers the complete AWS deployment setup for the Fraud Detection System in the ca-central-1 region, following the POC architecture with a clear path to production scaling.

## Prerequisites

- AWS CLI configured with appropriate permissions
- Terraform installed (optional, for infrastructure as code)
- Docker installed for container builds
- Access to ca-central-1 region

## Infrastructure Components

### 1. VPC and Networking

```bash
# Create VPC
aws ec2 create-vpc \
    --cidr-block 10.0.0.0/16 \
    --tag-specifications 'ResourceType=vpc,Tags=[{Key=Name,Value=fraud-detection-vpc}]' \
    --region ca-central-1

# Create subnets
aws ec2 create-subnet \
    --vpc-id vpc-xxxxxxxxx \
    --cidr-block 10.0.1.0/24 \
    --availability-zone ca-central-1a \
    --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=fraud-detection-public-1a}]'

aws ec2 create-subnet \
    --vpc-id vpc-xxxxxxxxx \
    --cidr-block 10.0.2.0/24 \
    --availability-zone ca-central-1b \
    --tag-specifications 'ResourceType=subnet,Tags=[{Key=Name,Value=fraud-detection-private-1b}]'

# Create Internet Gateway
aws ec2 create-internet-gateway \
    --tag-specifications 'ResourceType=internet-gateway,Tags=[{Key=Name,Value=fraud-detection-igw}]'

aws ec2 attach-internet-gateway \
    --vpc-id vpc-xxxxxxxxx \
    --internet-gateway-id igw-xxxxxxxxx
```

### 2. Security Groups

```bash
# API/Worker Security Group
aws ec2 create-security-group \
    --group-name fraud-detection-api \
    --description "Security group for fraud detection API and workers" \
    --vpc-id vpc-xxxxxxxxx

# Allow HTTPS from specific IPs
aws ec2 authorize-security-group-ingress \
    --group-id sg-xxxxxxxxx \
    --protocol tcp \
    --port 443 \
    --cidr 0.0.0.0/0  # Restrict to specific IPs in production

# Allow HTTP for health checks
aws ec2 authorize-security-group-ingress \
    --group-id sg-xxxxxxxxx \
    --protocol tcp \
    --port 80 \
    --cidr 10.0.0.0/16

# ML Service Security Group
aws ec2 create-security-group \
    --group-name fraud-detection-ml \
    --description "Security group for ML inference service" \
    --vpc-id vpc-xxxxxxxxx

# Allow access from API security group only
aws ec2 authorize-security-group-ingress \
    --group-id sg-yyyyyyyyy \
    --protocol tcp \
    --port 8000 \
    --source-group sg-xxxxxxxxx

# Database Security Group
aws ec2 create-security-group \
    --group-name fraud-detection-db \
    --description "Security group for PostgreSQL database" \
    --vpc-id vpc-xxxxxxxxx

# Allow PostgreSQL from API and ML security groups
aws ec2 authorize-security-group-ingress \
    --group-id sg-zzzzzzzzz \
    --protocol tcp \
    --port 5432 \
    --source-group sg-xxxxxxxxx
```

### 3. RDS PostgreSQL Setup

```bash
# Create DB subnet group
aws rds create-db-subnet-group \
    --db-subnet-group-name fraud-detection-db-subnet \
    --db-subnet-group-description "Subnet group for fraud detection database" \
    --subnet-ids subnet-xxxxxxxxx subnet-yyyyyyyyy

# Create RDS instance
aws rds create-db-instance \
    --db-instance-identifier fraud-detection-db \
    --db-instance-class db.t3.micro \
    --engine postgres \
    --engine-version 15.4 \
    --master-username fraudadmin \
    --master-user-password 'YourSecurePassword123!' \
    --allocated-storage 20 \
    --storage-type gp2 \
    --storage-encrypted \
    --vpc-security-group-ids sg-zzzzzzzzz \
    --db-subnet-group-name fraud-detection-db-subnet \
    --backup-retention-period 7 \
    --multi-az false \
    --publicly-accessible false \
    --auto-minor-version-upgrade true \
    --tags Key=Name,Value=fraud-detection-database
```

### 4. S3 Bucket for Model Artifacts

```bash
# Create S3 bucket
aws s3 mb s3://fraud-detection-models-ca-central-1 --region ca-central-1

# Enable versioning
aws s3api put-bucket-versioning \
    --bucket fraud-detection-models-ca-central-1 \
    --versioning-configuration Status=Enabled

# Enable encryption
aws s3api put-bucket-encryption \
    --bucket fraud-detection-models-ca-central-1 \
    --server-side-encryption-configuration '{
        "Rules": [
            {
                "ApplyServerSideEncryptionByDefault": {
                    "SSEAlgorithm": "AES256"
                }
            }
        ]
    }'

# Set bucket policy for restricted access
aws s3api put-bucket-policy \
    --bucket fraud-detection-models-ca-central-1 \
    --policy '{
        "Version": "2012-10-17",
        "Statement": [
            {
                "Sid": "RestrictToFraudDetectionRole",
                "Effect": "Allow",
                "Principal": {
                    "AWS": "arn:aws:iam::ACCOUNT-ID:role/fraud-detection-ec2-role"
                },
                "Action": [
                    "s3:GetObject",
                    "s3:PutObject",
                    "s3:DeleteObject"
                ],
                "Resource": "arn:aws:s3:::fraud-detection-models-ca-central-1/*"
            }
        ]
    }'
```

### 5. Bedrock VPC Endpoint

```bash
# Create VPC endpoint for Bedrock
aws ec2 create-vpc-endpoint \
    --vpc-id vpc-xxxxxxxxx \
    --service-name com.amazonaws.ca-central-1.bedrock-runtime \
    --vpc-endpoint-type Interface \
    --subnet-ids subnet-yyyyyyyyy \
    --security-group-ids sg-xxxxxxxxx \
    --policy-document '{
        "Version": "2012-10-17",
        "Statement": [
            {
                "Effect": "Allow",
                "Principal": "*",
                "Action": [
                    "bedrock:InvokeModel"
                ],
                "Resource": "*"
            }
        ]
    }'
```

### 6. IAM Roles and Policies

```bash
# Create EC2 role for fraud detection
aws iam create-role \
    --role-name fraud-detection-ec2-role \
    --assume-role-policy-document '{
        "Version": "2012-10-17",
        "Statement": [
            {
                "Effect": "Allow",
                "Principal": {
                    "Service": "ec2.amazonaws.com"
                },
                "Action": "sts:AssumeRole"
            }
        ]
    }'

# Create policy for S3 and Bedrock access
aws iam create-policy \
    --policy-name fraud-detection-policy \
    --policy-document '{
        "Version": "2012-10-17",
        "Statement": [
            {
                "Effect": "Allow",
                "Action": [
                    "s3:GetObject",
                    "s3:PutObject",
                    "s3:DeleteObject"
                ],
                "Resource": "arn:aws:s3:::fraud-detection-models-ca-central-1/*"
            },
            {
                "Effect": "Allow",
                "Action": [
                    "bedrock:InvokeModel"
                ],
                "Resource": "*"
            },
            {
                "Effect": "Allow",
                "Action": [
                    "logs:CreateLogGroup",
                    "logs:CreateLogStream",
                    "logs:PutLogEvents"
                ],
                "Resource": "*"
            }
        ]
    }'

# Attach policy to role
aws iam attach-role-policy \
    --role-name fraud-detection-ec2-role \
    --policy-arn arn:aws:iam::ACCOUNT-ID:policy/fraud-detection-policy

# Create instance profile
aws iam create-instance-profile \
    --instance-profile-name fraud-detection-instance-profile

aws iam add-role-to-instance-profile \
    --instance-profile-name fraud-detection-instance-profile \
    --role-name fraud-detection-ec2-role
```

## EC2 Instance Setup

### 1. Launch API/Worker Instance

```bash
# Create key pair
aws ec2 create-key-pair \
    --key-name fraud-detection-key \
    --query 'KeyMaterial' \
    --output text > fraud-detection-key.pem

chmod 400 fraud-detection-key.pem

# Launch EC2 instance
aws ec2 run-instances \
    --image-id ami-0c02fb55956c7d316 \
    --count 1 \
    --instance-type t3.medium \
    --key-name fraud-detection-key \
    --security-group-ids sg-xxxxxxxxx \
    --subnet-id subnet-xxxxxxxxx \
    --iam-instance-profile Name=fraud-detection-instance-profile \
    --user-data file://user-data-api.sh \
    --tag-specifications 'ResourceType=instance,Tags=[{Key=Name,Value=fraud-detection-api}]'
```

### 2. User Data Script for API Instance

```bash
# user-data-api.sh
#!/bin/bash
yum update -y
yum install -y docker git

# Start Docker
systemctl start docker
systemctl enable docker
usermod -a -G docker ec2-user

# Install Docker Compose
curl -L "https://github.com/docker/compose/releases/download/v2.21.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose

# Install PHP 8.4 and Composer
amazon-linux-extras install -y php8.4
yum install -y php-cli php-fpm php-json php-pdo php-pgsql php-mbstring php-xml php-zip
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Clone application repository
cd /home/ec2-user
git clone https://github.com/your-org/fraud-detector.git
chown -R ec2-user:ec2-user fraud-detector

# Install application dependencies
cd fraud-detector
sudo -u ec2-user composer install --no-dev --optimize-autoloader

# Set up environment
cp .env.example .env
php artisan key:generate

# Configure database connection
sed -i 's/DB_HOST=127.0.0.1/DB_HOST=fraud-detection-db.xxxxxxxxx.ca-central-1.rds.amazonaws.com/' .env
sed -i 's/DB_DATABASE=laravel/DB_DATABASE=fraud_detection/' .env
sed -i 's/DB_USERNAME=root/DB_USERNAME=fraudadmin/' .env
sed -i 's/DB_PASSWORD=/DB_PASSWORD=YourSecurePassword123!/' .env

# Run migrations
php artisan migrate --force

# Set up queue worker service
cat > /etc/systemd/system/fraud-queue-worker.service << EOF
[Unit]
Description=Fraud Detection Queue Worker
After=network.target

[Service]
Type=simple
User=ec2-user
WorkingDirectory=/home/ec2-user/fraud-detector
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=60
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl enable fraud-queue-worker
systemctl start fraud-queue-worker

# Set up web server (nginx)
yum install -y nginx
systemctl start nginx
systemctl enable nginx

# Configure nginx
cat > /etc/nginx/conf.d/fraud-detection.conf << EOF
server {
    listen 80;
    server_name _;
    root /home/ec2-user/fraud-detector/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

systemctl restart nginx
```

### 3. Launch ML Inference Instance

```bash
# Launch ML instance
aws ec2 run-instances \
    --image-id ami-0c02fb55956c7d316 \
    --count 1 \
    --instance-type t3.medium \
    --key-name fraud-detection-key \
    --security-group-ids sg-yyyyyyyyy \
    --subnet-id subnet-yyyyyyyyy \
    --iam-instance-profile Name=fraud-detection-instance-profile \
    --user-data file://user-data-ml.sh \
    --tag-specifications 'ResourceType=instance,Tags=[{Key=Name,Value=fraud-detection-ml}]'
```

### 4. User Data Script for ML Instance

```bash
# user-data-ml.sh
#!/bin/bash
yum update -y
yum install -y docker git python3 python3-pip

# Start Docker
systemctl start docker
systemctl enable docker

# Install Python dependencies
pip3 install fastapi uvicorn lightgbm scikit-learn boto3 pandas numpy

# Clone ML service repository
cd /home/ec2-user
git clone https://github.com/your-org/fraud-detector-ml.git
chown -R ec2-user:ec2-user fraud-detector-ml

# Set up ML service
cd fraud-detector-ml
pip3 install -r requirements.txt

# Create systemd service
cat > /etc/systemd/system/fraud-ml-service.service << EOF
[Unit]
Description=Fraud Detection ML Service
After=network.target

[Service]
Type=simple
User=ec2-user
WorkingDirectory=/home/ec2-user/fraud-detector-ml
ExecStart=/usr/bin/python3 main.py
Restart=always
RestartSec=5
Environment=MODEL_VERSION=v1.0.0
Environment=S3_BUCKET=fraud-detection-models-ca-central-1

[Install]
WantedBy=multi-user.target
EOF

systemctl enable fraud-ml-service
systemctl start fraud-ml-service
```

## Application Configuration

### 1. Environment Variables

```bash
# .env configuration for Laravel application
APP_NAME="Fraud Detection API"
APP_ENV=production
APP_KEY=base64:generated-key-here
APP_DEBUG=false
APP_URL=https://fraud-api.your-domain.com

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=fraud-detection-db.xxxxxxxxx.ca-central-1.rds.amazonaws.com
DB_PORT=5432
DB_DATABASE=fraud_detection
DB_USERNAME=fraudadmin
DB_PASSWORD=YourSecurePassword123!

QUEUE_CONNECTION=database

# HMAC Configuration
HMAC_SECRET_KEY=your-secure-hmac-secret-key-here
HMAC_ALGORITHM=sha256

# ML Service Configuration
ML_INFERENCE_URL=http://10.0.2.100:8000
ML_INFERENCE_TIMEOUT=5

# Bedrock Configuration
BEDROCK_REGION=ca-central-1
BEDROCK_PRIMARY_MODEL=anthropic.claude-3-haiku-20240307-v1:0
BEDROCK_FALLBACK_MODEL=meta.llama3-8b-instruct-v1:0
BEDROCK_MAX_TOKENS=200
BEDROCK_TEMPERATURE=0.1

# S3 Configuration
AWS_DEFAULT_REGION=ca-central-1
AWS_BUCKET=fraud-detection-models-ca-central-1
```

### 2. Database Setup

```sql
-- Connect to PostgreSQL and create database
CREATE DATABASE fraud_detection;
CREATE USER fraudapp WITH PASSWORD 'secure-app-password';
GRANT ALL PRIVILEGES ON DATABASE fraud_detection TO fraudapp;

-- Switch to fraud_detection database
\c fraud_detection;

-- Grant schema permissions
GRANT ALL ON SCHEMA public TO fraudapp;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO fraudapp;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO fraudapp;
```

## Load Balancer Setup (Production)

```bash
# Create Application Load Balancer
aws elbv2 create-load-balancer \
    --name fraud-detection-alb \
    --subnets subnet-xxxxxxxxx subnet-yyyyyyyyy \
    --security-groups sg-xxxxxxxxx \
    --scheme internet-facing \
    --type application \
    --ip-address-type ipv4

# Create target group
aws elbv2 create-target-group \
    --name fraud-detection-targets \
    --protocol HTTP \
    --port 80 \
    --vpc-id vpc-xxxxxxxxx \
    --health-check-path /api/health \
    --health-check-interval-seconds 30 \
    --health-check-timeout-seconds 5 \
    --healthy-threshold-count 2 \
    --unhealthy-threshold-count 3

# Register targets
aws elbv2 register-targets \
    --target-group-arn arn:aws:elasticloadbalancing:ca-central-1:ACCOUNT:targetgroup/fraud-detection-targets/xxxxxxxxx \
    --targets Id=i-xxxxxxxxx,Port=80

# Create listener
aws elbv2 create-listener \
    --load-balancer-arn arn:aws:elasticloadbalancing:ca-central-1:ACCOUNT:loadbalancer/app/fraud-detection-alb/xxxxxxxxx \
    --protocol HTTPS \
    --port 443 \
    --certificates CertificateArn=arn:aws:acm:ca-central-1:ACCOUNT:certificate/xxxxxxxxx \
    --default-actions Type=forward,TargetGroupArn=arn:aws:elasticloadbalancing:ca-central-1:ACCOUNT:targetgroup/fraud-detection-targets/xxxxxxxxx
```

## SSL Certificate Setup

```bash
# Request SSL certificate
aws acm request-certificate \
    --domain-name fraud-api.your-domain.com \
    --validation-method DNS \
    --region ca-central-1

# Note: Follow DNS validation process in your domain registrar
```

## Monitoring and Logging

### 1. CloudWatch Setup

```bash
# Create log groups
aws logs create-log-group \
    --log-group-name /aws/ec2/fraud-detection/api \
    --region ca-central-1

aws logs create-log-group \
    --log-group-name /aws/ec2/fraud-detection/ml \
    --region ca-central-1

aws logs create-log-group \
    --log-group-name /aws/ec2/fraud-detection/queue \
    --region ca-central-1
```

### 2. CloudWatch Alarms

```bash
# High error rate alarm
aws cloudwatch put-metric-alarm \
    --alarm-name "FraudDetection-HighErrorRate" \
    --alarm-description "High error rate in fraud detection API" \
    --metric-name ErrorRate \
    --namespace AWS/ApplicationELB \
    --statistic Average \
    --period 300 \
    --threshold 5.0 \
    --comparison-operator GreaterThanThreshold \
    --evaluation-periods 2

# High latency alarm
aws cloudwatch put-metric-alarm \
    --alarm-name "FraudDetection-HighLatency" \
    --alarm-description "High latency in fraud detection API" \
    --metric-name TargetResponseTime \
    --namespace AWS/ApplicationELB \
    --statistic Average \
    --period 300 \
    --threshold 2.0 \
    --comparison-operator GreaterThanThreshold \
    --evaluation-periods 2
```

## Security Hardening

### 1. Security Group Rules (Restrictive)

```bash
# Remove broad access and add specific IP ranges
aws ec2 revoke-security-group-ingress \
    --group-id sg-xxxxxxxxx \
    --protocol tcp \
    --port 443 \
    --cidr 0.0.0.0/0

# Add specific IP ranges for your organization
aws ec2 authorize-security-group-ingress \
    --group-id sg-xxxxxxxxx \
    --protocol tcp \
    --port 443 \
    --cidr 203.0.113.0/24  # Replace with your office IP range
```

### 2. Enable VPC Flow Logs

```bash
aws ec2 create-flow-logs \
    --resource-type VPC \
    --resource-ids vpc-xxxxxxxxx \
    --traffic-type ALL \
    --log-destination-type cloud-watch-logs \
    --log-group-name VPCFlowLogs \
    --deliver-logs-permission-arn arn:aws:iam::ACCOUNT:role/flowlogsRole
```

## Backup and Disaster Recovery

### 1. RDS Automated Backups

```bash
# Enable automated backups (already configured in RDS creation)
aws rds modify-db-instance \
    --db-instance-identifier fraud-detection-db \
    --backup-retention-period 7 \
    --preferred-backup-window "03:00-04:00" \
    --preferred-maintenance-window "sun:04:00-sun:05:00"
```

### 2. S3 Cross-Region Replication

```bash
# Create replication bucket in different region
aws s3 mb s3://fraud-detection-models-backup-us-east-1 --region us-east-1

# Set up replication (requires additional IAM role configuration)
aws s3api put-bucket-replication \
    --bucket fraud-detection-models-ca-central-1 \
    --replication-configuration file://replication-config.json
```

## Cost Optimization

### 1. Instance Scheduling

```bash
# Create Lambda function to stop/start instances during off-hours
# This is particularly useful for development environments

# Example: Stop instances at 8 PM EST
aws events put-rule \
    --name stop-fraud-detection-instances \
    --schedule-expression "cron(0 1 * * ? *)" \
    --state ENABLED

# Example: Start instances at 8 AM EST
aws events put-rule \
    --name start-fraud-detection-instances \
    --schedule-expression "cron(0 13 * * ? *)" \
    --state ENABLED
```

### 2. Reserved Instances (Production)

```bash
# Purchase reserved instances for production workloads
aws ec2 purchase-reserved-instances-offering \
    --reserved-instances-offering-id xxxxxxxxx \
    --instance-count 2
```

## Deployment Checklist

### Pre-Deployment
- [ ] AWS CLI configured with appropriate permissions
- [ ] Domain name registered and DNS configured
- [ ] SSL certificate requested and validated
- [ ] Security groups configured with minimal required access
- [ ] IAM roles and policies created with least privilege

### Infrastructure Deployment
- [ ] VPC and subnets created
- [ ] Security groups configured
- [ ] RDS instance launched and accessible
- [ ] S3 bucket created with proper permissions
- [ ] Bedrock VPC endpoint configured
- [ ] EC2 instances launched with proper IAM roles

### Application Deployment
- [ ] Laravel application deployed and configured
- [ ] Database migrations completed
- [ ] Queue workers running
- [ ] ML inference service deployed and accessible
- [ ] Health checks passing

### Security Verification
- [ ] Security groups allow only necessary traffic
- [ ] Database not publicly accessible
- [ ] SSL/TLS encryption enabled
- [ ] VPC Flow Logs enabled
- [ ] CloudWatch monitoring configured

### Testing
- [ ] API endpoints responding correctly
- [ ] HMAC authentication working
- [ ] End-to-end fraud detection pipeline functional
- [ ] Load testing completed
- [ ] Failover scenarios tested

### Production Readiness
- [ ] Load balancer configured
- [ ] Auto-scaling groups set up (if needed)
- [ ] Backup and recovery procedures tested
- [ ] Monitoring and alerting configured
- [ ] Documentation updated
- [ ] Team trained on operations procedures

## Troubleshooting

### Common Issues

1. **Database Connection Issues**
   ```bash
   # Check security group rules
   aws ec2 describe-security-groups --group-ids sg-zzzzzzzzz
   
   # Test database connectivity
   telnet fraud-detection-db.xxxxxxxxx.ca-central-1.rds.amazonaws.com 5432
   ```

2. **Bedrock Access Issues**
   ```bash
   # Check VPC endpoint status
   aws ec2 describe-vpc-endpoints --filters Name=service-name,Values=com.amazonaws.ca-central-1.bedrock-runtime
   
   # Verify IAM permissions
   aws iam simulate-principal-policy \
       --policy-source-arn arn:aws:iam::ACCOUNT:role/fraud-detection-ec2-role \
       --action-names bedrock:InvokeModel \
       --resource-arns "*"
   ```

3. **Queue Worker Issues**
   ```bash
   # Check queue worker status
   systemctl status fraud-queue-worker
   
   # View queue worker logs
   journalctl -u fraud-queue-worker -f
   ```

4. **ML Service Connectivity**
   ```bash
   # Test ML service health
   curl http://10.0.2.100:8000/health
   
   # Check ML service logs
   journalctl -u fraud-ml-service -f
   ```

This deployment guide provides a comprehensive setup for the fraud detection system in AWS ca-central-1, with security best practices and monitoring in place.
