# Local Development Deployment Plan

## Overview
Complete setup guide for running the fraud detection system locally for development and testing. This covers all components from database setup to testing UI.

## System Requirements

### Hardware
- **CPU**: 4+ cores recommended
- **RAM**: 8GB minimum, 16GB recommended
- **Storage**: 20GB free space
- **Network**: Stable internet connection for API calls

### Software Prerequisites
- **PHP**: 8.2+
- **Composer**: Latest version
- **Node.js**: 18+ (for frontend assets)
- **PostgreSQL**: 14+
- **Python**: 3.9+ (for ML service)
- **Docker**: Latest (optional, for ML service)
- **Git**: Latest version

## Component Setup Order

### 1. Database Setup (PostgreSQL)

#### Installation
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install postgresql postgresql-contrib

# macOS (Homebrew)
brew install postgresql
brew services start postgresql

# Windows
# Download from https://www.postgresql.org/download/windows/
```

#### Database Configuration
```bash
# Create database and user
sudo -u postgres psql

CREATE DATABASE fraud_detector_dev;
CREATE USER fraud_user WITH PASSWORD 'fraud_password';
GRANT ALL PRIVILEGES ON DATABASE fraud_detector_dev TO fraud_user;
\q
```

#### Environment Configuration
```bash
# .env file
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fraud_detector_dev
DB_USERNAME=fraud_user
DB_PASSWORD=fraud_password
```

### 2. Laravel Application Setup

#### Installation
```bash
# Clone repository
git clone <repository-url> fraud-detector
cd fraud-detector

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env (see above)

# Run migrations
php artisan migrate

# Seed test data (optional)
php artisan db:seed
```

#### Queue Configuration
```bash
# .env additions
QUEUE_CONNECTION=database
QUEUE_FAILED_DRIVER=database-uuids

# Create queue tables (if not already migrated)
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

#### API Configuration
```bash
# .env additions for external services
OPENROUTER_API_KEY=your_openrouter_key_here
BEDROCK_REGION=ca-central-1
BEDROCK_ACCESS_KEY=your_aws_access_key
BEDROCK_SECRET_KEY=your_aws_secret_key

# ML Service configuration
ML_SERVICE_URL=http://localhost:8000
ML_SERVICE_TIMEOUT=30

# LLM Adjudicator configuration
LLM_ADJUDICATOR_ENDPOINT=https://bedrock-runtime.ca-central-1.amazonaws.com
LLM_ADJUDICATOR_API_KEY=your_bedrock_key
```

### 3. ML Inference Service Setup

#### Python Environment
```bash
# Navigate to ML service directory
cd ml-service

# Create virtual environment
python -m venv venv

# Activate virtual environment
# Linux/macOS:
source venv/bin/activate
# Windows:
venv\Scripts\activate

# Install dependencies
pip install -r requirements.txt
```

#### Model Setup
```bash
# Create models directory
mkdir -p models/lightgbm_v1.0.0

# For development, create dummy model files
# (In production, these would be downloaded from S3)
python scripts/create_dummy_model.py
```

#### Service Configuration
```bash
# Create .env file in ml-service directory
MODEL_VERSION=v1.0.0
MODEL_PATH=./models/lightgbm_v1.0.0
LOG_LEVEL=INFO
HOST=0.0.0.0
PORT=8000
```

#### Start ML Service
```bash
# From ml-service directory
python main.py

# Or using uvicorn directly
uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
```

### 4. Component Organization

#### Create Component Directories
```bash
# Organize existing components into proper structure
mkdir -p components/{api-gateway,queue-worker,database-layer,ml-inference,testing-ui}

# Move existing component files
mv components/rules-engine components/rules-engine
mv components/feature-engineering components/feature-engineering
mv components/llm-adjudicator components/llm-adjudicator

# Create service directories
mkdir -p app/Services/{Rules,Features,ML,LLM,Decision}
```

#### Update Autoloader
```bash
# Update composer.json autoload section
composer dump-autoload
```

### 5. Start All Services

#### Terminal 1: Laravel Application
```bash
# Start Laravel development server
php artisan serve --host=0.0.0.0 --port=8080
```

#### Terminal 2: Queue Worker
```bash
# Start queue worker
php artisan queue:work --queue=fraud-detection --tries=3 --timeout=300 --verbose
```

#### Terminal 3: ML Service
```bash
# Navigate to ML service and start
cd ml-service
source venv/bin/activate  # Linux/macOS
python main.py
```

#### Terminal 4: Queue Monitor (Optional)
```bash
# Monitor queue status
watch -n 5 'php artisan queue:monitor fraud-detection'
```

## Service URLs

### Local Development URLs
- **Laravel Application**: http://localhost:8080
- **Testing UI**: http://localhost:8080/test-ui
- **API Endpoints**: http://localhost:8080/api/
- **ML Service**: http://localhost:8000
- **ML Service Health**: http://localhost:8000/health
- **ML Service Docs**: http://localhost:8000/docs

### API Testing
```bash
# Test API health
curl http://localhost:8080/api/health

# Test ML service health
curl http://localhost:8000/health

# Submit test application
curl -X POST http://localhost:8080/api/applications \
  -H "Content-Type: application/json" \
  -H "X-API-Key: test-key" \
  -H "X-Timestamp: $(date +%s)" \
  -H "X-Nonce: $(openssl rand -hex 16)" \
  -d '{
    "payload_version": "1.0",
    "applicant": {
      "age": 35,
      "sin": "123456789",
      "province": "ON",
      "postal_code": "M5V 3A8"
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
  }'
```

## Development Workflow

### 1. Daily Development Setup
```bash
# Start all services
./scripts/start-dev.sh

# Or manually:
# Terminal 1: php artisan serve
# Terminal 2: php artisan queue:work
# Terminal 3: cd ml-service && python main.py
```

### 2. Testing Workflow
```bash
# Run PHP tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# Test ML service
cd ml-service
python -m pytest tests/

# Test API endpoints
php artisan test tests/Feature/ApiTest.php
```

### 3. Database Management
```bash
# Reset database
php artisan migrate:fresh --seed

# Create new migration
php artisan make:migration create_new_table

# Run specific migration
php artisan migrate --path=/database/migrations/specific_migration.php
```

### 4. Queue Management
```bash
# Clear all jobs
php artisan queue:clear

# Retry failed jobs
php artisan queue:retry all

# Monitor queue in real-time
php artisan queue:monitor fraud-detection
```

## Development Scripts

### Start Development Environment
```bash
#!/bin/bash
# scripts/start-dev.sh

echo "Starting Fraud Detection Development Environment..."

# Check prerequisites
command -v php >/dev/null 2>&1 || { echo "PHP is required but not installed."; exit 1; }
command -v python >/dev/null 2>&1 || { echo "Python is required but not installed."; exit 1; }

# Start Laravel server
echo "Starting Laravel server..."
php artisan serve --host=0.0.0.0 --port=8080 &
LARAVEL_PID=$!

# Start queue worker
echo "Starting queue worker..."
php artisan queue:work --queue=fraud-detection --tries=3 --timeout=300 &
QUEUE_PID=$!

# Start ML service
echo "Starting ML service..."
cd ml-service
source venv/bin/activate
python main.py &
ML_PID=$!
cd ..

echo "All services started!"
echo "Laravel: http://localhost:8080"
echo "Testing UI: http://localhost:8080/test-ui"
echo "ML Service: http://localhost:8000"
echo ""
echo "Press Ctrl+C to stop all services"

# Trap Ctrl+C and stop all services
trap 'kill $LARAVEL_PID $QUEUE_PID $ML_PID; exit' INT
wait
```

### Stop Development Environment
```bash
#!/bin/bash
# scripts/stop-dev.sh

echo "Stopping all fraud detection services..."

# Kill Laravel server
pkill -f "php artisan serve"

# Kill queue worker
pkill -f "php artisan queue:work"

# Kill ML service
pkill -f "python main.py"

echo "All services stopped."
```

### Health Check Script
```bash
#!/bin/bash
# scripts/health-check.sh

echo "Checking service health..."

# Check Laravel
if curl -s http://localhost:8080/api/health > /dev/null; then
    echo "✓ Laravel API: Healthy"
else
    echo "✗ Laravel API: Not responding"
fi

# Check ML Service
if curl -s http://localhost:8000/health > /dev/null; then
    echo "✓ ML Service: Healthy"
else
    echo "✗ ML Service: Not responding"
fi

# Check Database
if php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database: Connected';" 2>/dev/null; then
    echo "✓ Database: Connected"
else
    echo "✗ Database: Connection failed"
fi

# Check Queue
QUEUE_SIZE=$(php artisan queue:monitor fraud-detection --once | grep -o '[0-9]\+' | head -1)
echo "Queue depth: $QUEUE_SIZE jobs"
```

## Troubleshooting

### Common Issues

#### 1. Database Connection Issues
```bash
# Check PostgreSQL status
sudo systemctl status postgresql

# Restart PostgreSQL
sudo systemctl restart postgresql

# Check connection
psql -h localhost -U fraud_user -d fraud_detector_dev
```

#### 2. Queue Worker Issues
```bash
# Clear failed jobs
php artisan queue:flush

# Restart queue worker
php artisan queue:restart

# Check queue status
php artisan queue:monitor fraud-detection
```

#### 3. ML Service Issues
```bash
# Check Python environment
cd ml-service
source venv/bin/activate
python --version
pip list

# Check model files
ls -la models/lightgbm_v1.0.0/

# Test ML service directly
curl http://localhost:8000/health
```

#### 4. Permission Issues
```bash
# Fix Laravel permissions
sudo chown -R $USER:www-data storage
sudo chown -R $USER:www-data bootstrap/cache
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### Log Locations
- **Laravel Logs**: `storage/logs/laravel.log`
- **Queue Logs**: `storage/logs/queue.log`
- **ML Service Logs**: `ml-service/logs/app.log`
- **PostgreSQL Logs**: `/var/log/postgresql/`

### Performance Monitoring
```bash
# Monitor system resources
htop

# Monitor Laravel performance
php artisan route:cache
php artisan config:cache
php artisan view:cache

# Monitor ML service performance
cd ml-service
python -m cProfile main.py
```

## IDE Configuration

### VS Code Settings
```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "python.defaultInterpreterPath": "./ml-service/venv/bin/python",
    "files.associations": {
        "*.blade.php": "blade"
    },
    "emmet.includeLanguages": {
        "blade": "html"
    }
}
```

### Recommended Extensions
- PHP Intelephense
- Laravel Blade Snippets
- Python
- PostgreSQL
- REST Client
- GitLens

## Next Steps

### 1. Component Integration Testing
```bash
# Run full integration test
php artisan test --testsuite=Integration

# Test specific component integration
php artisan test tests/Integration/FraudDetectionPipelineTest.php
```

### 2. Performance Testing
```bash
# Load test API endpoints
ab -n 100 -c 10 http://localhost:8080/api/applications

# Monitor queue performance
php artisan queue:monitor fraud-detection --watch
```

### 3. Prepare for AWS Migration
- Review AWS deployment documentation
- Set up AWS credentials
- Test Bedrock connectivity
- Prepare S3 bucket for model storage

This local development setup provides a complete environment for testing and developing the fraud detection system before deploying to AWS.
