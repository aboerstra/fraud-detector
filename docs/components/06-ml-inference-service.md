# ML Inference Service Component

## Overview
The ML inference service provides real-time fraud scoring using a calibrated LightGBM model, serving predictions via a FastAPI-based REST service with model versioning and performance monitoring.

## Service Architecture

### FastAPI Service Structure
```python
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import lightgbm as lgb
import numpy as np
from typing import List, Dict

app = FastAPI(title="Fraud Detection ML Service", version="1.0.0")

class PredictionRequest(BaseModel):
    features: List[float]
    feature_names: List[str]
    request_id: str

class PredictionResponse(BaseModel):
    confidence_score: float
    top_features: List[Dict[str, float]]
    model_version: str
    calibration_version: str
    inference_time_ms: int
```

### Model Loading & Management
```python
class ModelManager:
    def __init__(self):
        self.model = None
        self.calibrator = None
        self.feature_names = None
        self.model_version = None
        self.calibration_version = None
        
    def load_model_from_s3(self, model_path: str):
        """Load LightGBM model and calibrator from S3"""
        # Download model artifacts
        model_artifact = self.s3_client.download_file(model_path)
        
        # Load LightGBM model
        self.model = lgb.Booster(model_file=model_artifact['model_path'])
        
        # Load calibrator
        self.calibrator = joblib.load(model_artifact['calibrator_path'])
        
        # Load metadata
        metadata = json.load(model_artifact['metadata_path'])
        self.model_version = metadata['model_version']
        self.calibration_version = metadata['calibration_version']
        self.feature_names = metadata['feature_names']
```

## Model Training Pipeline

### Training Data Requirements
- **Dataset Size**: Minimum 10,000 samples for initial training
- **Feature Set**: Exactly 15 features matching feature engineering output
- **Labels**: Binary fraud labels (0=legitimate, 1=fraud)
- **Data Quality**: <5% missing values, validated feature ranges
- **Class Balance**: Handle class imbalance through sampling/weighting

### LightGBM Configuration
```python
lgb_params = {
    'objective': 'binary',
    'metric': 'auc',
    'boosting_type': 'gbdt',
    'num_leaves': 31,
    'learning_rate': 0.05,
    'feature_fraction': 0.9,
    'bagging_fraction': 0.8,
    'bagging_freq': 5,
    'verbose': 0,
    'random_state': 42,
    'early_stopping_rounds': 100,
    'num_boost_round': 1000
}
```

### Training Process
```python
def train_model(X_train, y_train, X_val, y_val):
    """Train LightGBM model with cross-validation"""
    
    # Create LightGBM datasets
    train_data = lgb.Dataset(X_train, label=y_train)
    val_data = lgb.Dataset(X_val, label=y_val, reference=train_data)
    
    # Train model
    model = lgb.train(
        lgb_params,
        train_data,
        valid_sets=[val_data],
        callbacks=[lgb.early_stopping(100), lgb.log_evaluation(50)]
    )
    
    # Get feature importance
    feature_importance = model.feature_importance(importance_type='gain')
    
    return model, feature_importance
```

### Model Calibration
```python
from sklearn.calibration import CalibratedClassifierCV
from sklearn.isotonic import IsotonicRegression

def calibrate_model(model, X_cal, y_cal):
    """Calibrate model probabilities using isotonic regression"""
    
    # Get raw predictions
    raw_predictions = model.predict(X_cal)
    
    # Fit isotonic calibrator
    calibrator = IsotonicRegression(out_of_bounds='clip')
    calibrator.fit(raw_predictions, y_cal)
    
    return calibrator

def apply_calibration(raw_score, calibrator):
    """Apply calibration to raw model output"""
    return calibrator.predict([raw_score])[0]
```

## Inference Endpoints

### Health Check Endpoint
```python
@app.get("/healthz")
async def health_check():
    """Health check endpoint"""
    return {
        "status": "healthy",
        "model_loaded": model_manager.model is not None,
        "model_version": model_manager.model_version,
        "calibration_version": model_manager.calibration_version,
        "timestamp": datetime.utcnow().isoformat()
    }
```

### Prediction Endpoint
```python
@app.post("/score", response_model=PredictionResponse)
async def predict_fraud(request: PredictionRequest):
    """Main prediction endpoint"""
    start_time = time.time()
    
    try:
        # Validate input
        if len(request.features) != 15:
            raise HTTPException(400, "Expected 15 features")
        
        # Prepare features
        features_array = np.array(request.features).reshape(1, -1)
        
        # Get raw prediction
        raw_score = model_manager.model.predict(features_array)[0]
        
        # Apply calibration
        calibrated_score = model_manager.calibrator.predict([raw_score])[0]
        
        # Get feature importance for this prediction
        top_features = get_top_features(
            features_array[0], 
            model_manager.model.feature_importance(),
            request.feature_names
        )
        
        inference_time = int((time.time() - start_time) * 1000)
        
        return PredictionResponse(
            confidence_score=float(calibrated_score),
            top_features=top_features,
            model_version=model_manager.model_version,
            calibration_version=model_manager.calibration_version,
            inference_time_ms=inference_time
        )
        
    except Exception as e:
        logger.error(f"Prediction error: {str(e)}")
        raise HTTPException(500, f"Prediction failed: {str(e)}")
```

### Feature Importance Analysis
```python
def get_top_features(feature_values, feature_importance, feature_names, top_k=3):
    """Get top contributing features for this prediction"""
    
    # Calculate feature contributions
    contributions = []
    for i, (value, importance, name) in enumerate(
        zip(feature_values, feature_importance, feature_names)
    ):
        contribution = abs(value * importance)
        contributions.append({
            'feature_name': name,
            'feature_value': float(value),
            'importance': float(importance),
            'contribution': float(contribution)
        })
    
    # Sort by contribution and return top K
    contributions.sort(key=lambda x: x['contribution'], reverse=True)
    return contributions[:top_k]
```

## Model Artifacts & Versioning

### Artifact Structure
```
s3://fraud-models/
├── models/
│   ├── v1.0.0/
│   │   ├── lightgbm_model.txt
│   │   ├── calibrator.pkl
│   │   ├── metadata.json
│   │   └── model_card.md
│   └── v1.1.0/
│       ├── lightgbm_model.txt
│       ├── calibrator.pkl
│       ├── metadata.json
│       └── model_card.md
└── training_data/
    ├── v1.0.0/
    │   ├── train.parquet
    │   ├── validation.parquet
    │   └── test.parquet
```

### Metadata Schema
```json
{
  "model_version": "v1.0.0",
  "calibration_version": "v1.0.0",
  "feature_set_version": "v1.0.0",
  "training_date": "2024-01-15T10:00:00Z",
  "feature_names": [
    "age", "sin_valid_flag", "email_domain_category",
    "phone_reuse_count", "email_reuse_count", "vin_reuse_flag",
    "dealer_app_volume_24h", "dealer_fraud_percentile",
    "province_ip_mismatch", "address_postal_match_flag",
    "ltv_ratio", "purchase_loan_ratio", "downpayment_income_ratio",
    "mileage_plausibility_score", "high_value_low_income_flag"
  ],
  "training_metrics": {
    "auc_roc": 0.85,
    "auc_pr": 0.72,
    "precision_at_10pct_recall": 0.65,
    "calibration_error": 0.03
  },
  "validation_metrics": {
    "auc_roc": 0.83,
    "auc_pr": 0.70,
    "precision_at_10pct_recall": 0.62
  }
}
```

### Model Card Template
```markdown
# Fraud Detection Model v1.0.0

## Model Overview
- **Type**: LightGBM Binary Classifier
- **Purpose**: Auto loan fraud detection
- **Training Date**: 2024-01-15
- **Features**: 15 engineered features

## Performance Metrics
- **AUC-ROC**: 0.85 (validation: 0.83)
- **AUC-PR**: 0.72 (validation: 0.70)
- **Precision @ 10% Recall**: 0.65

## Training Data
- **Size**: 50,000 samples
- **Fraud Rate**: 3.2%
- **Time Period**: 2023-01-01 to 2023-12-31
- **Data Quality**: 99.2% complete features

## Feature Importance
1. dealer_fraud_percentile (0.18)
2. ltv_ratio (0.15)
3. vin_reuse_flag (0.12)
4. age (0.10)
5. phone_reuse_count (0.09)

## Limitations
- Trained on synthetic data
- May not generalize to new fraud patterns
- Requires feature drift monitoring
```

## Local Development Setup

### Environment Setup
```bash
# Create virtual environment
python -m venv venv
source venv/bin/activate  # Linux/Mac
# or
venv\Scripts\activate  # Windows

# Install dependencies
pip install -r requirements.txt

# Set environment variables
export MODEL_S3_BUCKET=fraud-models-dev
export AWS_REGION=ca-central-1
export LOG_LEVEL=INFO
```

### Requirements.txt
```
fastapi==0.104.1
uvicorn==0.24.0
lightgbm==4.1.0
scikit-learn==1.3.2
numpy==1.24.3
pandas==2.0.3
boto3==1.34.0
pydantic==2.5.0
python-multipart==0.0.6
prometheus-client==0.19.0
```

### Running the Service
```bash
# Development server
uvicorn main:app --reload --host 0.0.0.0 --port 8000

# Production server
uvicorn main:app --host 0.0.0.0 --port 8000 --workers 4

# With Docker
docker build -t fraud-ml-service .
docker run -p 8000:8000 fraud-ml-service
```

### Testing the Service
```bash
# Health check
curl http://localhost:8000/healthz

# Prediction test
curl -X POST http://localhost:8000/score \
  -H "Content-Type: application/json" \
  -d '{
    "features": [35, 1, 1, 0, 0, 0, 2, 0.3, 0, 1, 0.8, 1.2, 0.5, 0.0, 0],
    "feature_names": ["age", "sin_valid_flag", ...],
    "request_id": "test-123"
  }'
```

## Performance Optimization

### Model Loading
- **Startup**: Load model once at service startup
- **Memory**: Keep model in memory for fast inference
- **Caching**: Cache feature importance calculations
- **Warm-up**: Pre-load model artifacts during deployment

### Inference Optimization
```python
# Batch prediction support
@app.post("/score_batch")
async def predict_batch(requests: List[PredictionRequest]):
    """Batch prediction for multiple requests"""
    features_batch = np.array([req.features for req in requests])
    raw_scores = model_manager.model.predict(features_batch)
    calibrated_scores = model_manager.calibrator.predict(raw_scores)
    
    return [
        PredictionResponse(
            confidence_score=float(score),
            # ... other fields
        )
        for score in calibrated_scores
    ]
```

### Resource Management
- **Memory**: Monitor memory usage and model size
- **CPU**: Optimize for single-threaded inference
- **Concurrency**: Use async/await for I/O operations
- **Scaling**: Horizontal scaling with load balancer

## Monitoring & Observability

### Metrics Collection
```python
from prometheus_client import Counter, Histogram, Gauge

# Metrics
prediction_counter = Counter('predictions_total', 'Total predictions')
prediction_latency = Histogram('prediction_duration_seconds', 'Prediction latency')
model_score_gauge = Gauge('model_confidence_score', 'Latest confidence score')

@app.middleware("http")
async def metrics_middleware(request, call_next):
    start_time = time.time()
    response = await call_next(request)
    
    if request.url.path == "/score":
        prediction_counter.inc()
        prediction_latency.observe(time.time() - start_time)
    
    return response
```

### Health Monitoring
- **Model Status**: Track model loading and version
- **Prediction Latency**: P95 latency under 100ms
- **Error Rates**: Alert on >1% error rate
- **Resource Usage**: Memory and CPU monitoring

### Logging
```python
import logging
import json

logger = logging.getLogger(__name__)

def log_prediction(request_id, features, score, latency):
    """Structured logging for predictions"""
    logger.info(json.dumps({
        'event': 'prediction',
        'request_id': request_id,
        'confidence_score': score,
        'inference_time_ms': latency,
        'model_version': model_manager.model_version,
        'timestamp': datetime.utcnow().isoformat()
    }))
```

## Security Considerations

### API Security
- **Authentication**: API key validation
- **Rate Limiting**: Prevent abuse
- **Input Validation**: Strict feature validation
- **Error Handling**: No sensitive data in errors

### Model Security
- **Artifact Integrity**: Verify model checksums
- **Access Control**: Restrict S3 bucket access
- **Audit Trail**: Log model loading and updates
- **Encryption**: Encrypt model artifacts at rest

### Network Security
- **TLS**: HTTPS for all communications
- **VPC**: Deploy within private network
- **Firewall**: Restrict inbound connections
- **Monitoring**: Network traffic analysis

## Deployment Strategy

### Container Deployment
```dockerfile
FROM python:3.11-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install -r requirements.txt

COPY . .
EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

### Model Updates
1. **Training**: New model trained offline
2. **Validation**: Model performance validation
3. **Staging**: Deploy to staging environment
4. **A/B Testing**: Compare with current model
5. **Production**: Blue-green deployment
6. **Monitoring**: Track performance metrics
7. **Rollback**: Revert if issues detected

### Scaling Strategy
- **Horizontal**: Multiple service instances
- **Load Balancing**: Distribute requests evenly
- **Auto-scaling**: Scale based on CPU/memory
- **Health Checks**: Remove unhealthy instances
