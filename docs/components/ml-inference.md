# ML Inference Service Implementation Plan

## Overview

The ML Inference Service is Stage 3 of the fraud detection pipeline, responsible for generating confidence scores using a calibrated LightGBM model. It takes the 15-feature vector from the Feature Engineering stage and returns a fraud probability score with explainability.

## Objectives

- **Accurate Scoring**: Generate calibrated fraud probability scores (0-1)
- **Performance**: Process inference in <300ms per request
- **Explainability**: Provide top contributing features for each prediction
- **Scalability**: Handle 100+ concurrent requests
- **Model Management**: Support model versioning and hot-swapping

## Architecture

### Service Structure
```
ml-service/
├── app/
│   ├── main.py                 # FastAPI application entry point
│   ├── models/
│   │   ├── model_manager.py    # Model loading and versioning
│   │   ├── inference.py        # Prediction logic
│   │   ├── calibration.py      # Probability calibration
│   │   └── explainer.py        # Feature importance calculation
│   ├── api/
│   │   ├── routes.py           # API endpoints
│   │   ├── schemas.py          # Request/response models
│   │   └── middleware.py       # Logging, metrics, auth
│   ├── core/
│   │   ├── config.py           # Configuration management
│   │   ├── logging.py          # Structured logging
│   │   └── metrics.py          # Performance metrics
│   └── utils/
│       ├── feature_validation.py
│       ├── model_artifacts.py
│       └── health_checks.py
├── models/                     # Model artifacts storage
│   ├── lightgbm_v1.0.0/
│   │   ├── model.pkl
│   │   ├── calibrator.pkl
│   │   ├── feature_names.json
│   │   └── model_card.json
├── tests/
├── requirements.txt
├── Dockerfile
└── docker-compose.yml
```

### Deployment Architecture
```
┌─────────────────────────────────────┐
│ Laravel Application (EC2)           │
│ ┌─────────────────────────────────┐ │
│ │ Fraud Detection Pipeline        │ │
│ │ Stage 3: ML Inference           │ │
│ └─────────────────────────────────┘ │
└─────────────────┬───────────────────┘
                  │ HTTP Request
                  │ POST /score
                  ▼
┌─────────────────────────────────────┐
│ ML Inference Service (EC2)          │
│ ┌─────────────────────────────────┐ │
│ │ FastAPI Application             │ │
│ │ - Model Loading                 │ │
│ │ - Feature Validation            │ │
│ │ │ - LightGBM Inference          │ │
│ │ - Probability Calibration       │ │
│ │ - Feature Importance            │ │
│ └─────────────────────────────────┘ │
└─────────────────┬───────────────────┘
                  │ Model Artifacts
                  ▼
┌─────────────────────────────────────┐
│ S3 Bucket (ca-central-1)            │
│ /models/lightgbm_v1.0.0/           │
│ - model.pkl                         │
│ - calibrator.pkl                    │
│ - feature_names.json                │
│ - model_card.json                   │
└─────────────────────────────────────┘
```

## FastAPI Application Implementation

### Main Application (main.py)
```python
from fastapi import FastAPI, HTTPException, Depends
from fastapi.middleware.cors import CORSMiddleware
from fastapi.middleware.gzip import GZipMiddleware
import uvicorn
import logging
from contextlib import asynccontextmanager

from app.core.config import settings
from app.core.logging import setup_logging
from app.core.metrics import setup_metrics
from app.models.model_manager import ModelManager
from app.api.routes import router
from app.api.middleware import MetricsMiddleware, LoggingMiddleware

# Global model manager instance
model_manager = None

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan management"""
    global model_manager
    
    # Startup
    setup_logging()
    setup_metrics()
    
    model_manager = ModelManager()
    await model_manager.load_model()
    
    logging.info("ML Inference Service started successfully")
    
    yield
    
    # Shutdown
    logging.info("ML Inference Service shutting down")

app = FastAPI(
    title="Fraud Detection ML Inference Service",
    description="LightGBM-based fraud scoring service",
    version="1.0.0",
    lifespan=lifespan
)

# Middleware
app.add_middleware(GZipMiddleware, minimum_size=1000)
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.ALLOWED_ORIGINS,
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["*"],
)
app.add_middleware(MetricsMiddleware)
app.add_middleware(LoggingMiddleware)

# Routes
app.include_router(router, prefix="/api/v1")

@app.get("/health")
async def health_check():
    """Health check endpoint"""
    global model_manager
    
    if not model_manager or not model_manager.is_ready():
        raise HTTPException(status_code=503, detail="Model not ready")
    
    return {
        "status": "healthy",
        "model_version": model_manager.get_model_version(),
        "model_loaded_at": model_manager.get_load_time(),
        "features_count": len(model_manager.get_feature_names())
    }

if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8000,
        reload=settings.DEBUG,
        log_config=None  # Use our custom logging
    )
```

### Model Manager (models/model_manager.py)
```python
import pickle
import json
import boto3
import logging
from datetime import datetime
from typing import Dict, List, Optional, Tuple
import numpy as np
from lightgbm import LGBMClassifier
from sklearn.calibration import CalibratedClassifierCV

from app.core.config import settings

class ModelManager:
    """Manages model loading, versioning, and inference"""
    
    def __init__(self):
        self.model: Optional[LGBMClassifier] = None
        self.calibrator: Optional[CalibratedClassifierCV] = None
        self.feature_names: List[str] = []
        self.model_metadata: Dict = {}
        self.model_version: str = ""
        self.loaded_at: Optional[datetime] = None
        self.s3_client = boto3.client('s3', region_name='ca-central-1')
        
    async def load_model(self, version: str = None) -> bool:
        """Load model from S3"""
        try:
            version = version or settings.MODEL_VERSION
            model_path = f"models/lightgbm_{version}"
            
            logging.info(f"Loading model version: {version}")
            
            # Download model artifacts from S3
            model_artifacts = await self._download_model_artifacts(model_path)
            
            # Load model components
            self.model = pickle.loads(model_artifacts['model.pkl'])
            self.calibrator = pickle.loads(model_artifacts['calibrator.pkl'])
            self.feature_names = json.loads(model_artifacts['feature_names.json'])
            self.model_metadata = json.loads(model_artifacts['model_card.json'])
            
            self.model_version = version
            self.loaded_at = datetime.utcnow()
            
            logging.info(f"Model {version} loaded successfully")
            return True
            
        except Exception as e:
            logging.error(f"Failed to load model: {str(e)}")
            return False
    
    async def _download_model_artifacts(self, model_path: str) -> Dict[str, bytes]:
        """Download all model artifacts from S3"""
        artifacts = {}
        required_files = ['model.pkl', 'calibrator.pkl', 'feature_names.json', 'model_card.json']
        
        for filename in required_files:
            key = f"{model_path}/{filename}"
            try:
                response = self.s3_client.get_object(
                    Bucket=settings.S3_BUCKET,
                    Key=key
                )
                artifacts[filename] = response['Body'].read()
            except Exception as e:
                raise Exception(f"Failed to download {filename}: {str(e)}")
        
        return artifacts
    
    def predict(self, features: np.ndarray) -> Tuple[float, np.ndarray]:
        """Generate prediction and feature importance"""
        if not self.is_ready():
            raise RuntimeError("Model not loaded")
        
        # Get raw prediction
        raw_prediction = self.model.predict_proba(features)[0, 1]
        
        # Apply calibration
        calibrated_prediction = self.calibrator.predict_proba(features.reshape(1, -1))[0, 1]
        
        # Get feature importance for this prediction
        feature_importance = self.model.predict_proba(features, pred_contrib=True)[0, :-1]
        
        return calibrated_prediction, feature_importance
    
    def get_top_features(self, feature_importance: np.ndarray, top_k: int = 5) -> List[Dict]:
        """Get top contributing features"""
        feature_contrib = list(zip(self.feature_names, feature_importance))
        feature_contrib.sort(key=lambda x: abs(x[1]), reverse=True)
        
        return [
            {
                "feature": name,
                "importance": float(importance),
                "direction": "increases_risk" if importance > 0 else "decreases_risk"
            }
            for name, importance in feature_contrib[:top_k]
        ]
    
    def is_ready(self) -> bool:
        """Check if model is ready for inference"""
        return all([
            self.model is not None,
            self.calibrator is not None,
            len(self.feature_names) > 0
        ])
    
    def get_model_version(self) -> str:
        return self.model_version
    
    def get_load_time(self) -> Optional[str]:
        return self.loaded_at.isoformat() if self.loaded_at else None
    
    def get_feature_names(self) -> List[str]:
        return self.feature_names.copy()
    
    def get_model_metadata(self) -> Dict:
        return self.model_metadata.copy()
```

### API Routes (api/routes.py)
```python
from fastapi import APIRouter, HTTPException, Depends
from typing import List, Dict, Any
import numpy as np
import logging
import time

from app.api.schemas import ScoreRequest, ScoreResponse, FeatureImportance
from app.models.model_manager import ModelManager
from app.utils.feature_validation import validate_features
from app.core.metrics import record_inference_metrics

router = APIRouter()

async def get_model_manager() -> ModelManager:
    """Dependency to get model manager instance"""
    from main import model_manager
    if not model_manager or not model_manager.is_ready():
        raise HTTPException(status_code=503, detail="Model not available")
    return model_manager

@router.post("/score", response_model=ScoreResponse)
async def score_application(
    request: ScoreRequest,
    model_manager: ModelManager = Depends(get_model_manager)
):
    """Generate fraud score for application features"""
    start_time = time.time()
    
    try:
        # Validate feature vector
        feature_vector = validate_features(request.features, model_manager.get_feature_names())
        
        # Convert to numpy array
        features = np.array(feature_vector).reshape(1, -1)
        
        # Generate prediction
        confidence_score, feature_importance = model_manager.predict(features)
        
        # Get top contributing features
        top_features = model_manager.get_top_features(feature_importance, top_k=5)
        
        # Record metrics
        inference_time = (time.time() - start_time) * 1000
        record_inference_metrics(inference_time, confidence_score)
        
        response = ScoreResponse(
            confidence_score=float(confidence_score),
            confidence_band=_get_confidence_band(confidence_score),
            top_features=top_features,
            model_version=model_manager.get_model_version(),
            calibration_version=model_manager.get_model_metadata().get('calibration_version'),
            inference_time_ms=int(inference_time)
        )
        
        logging.info(f"Inference completed: score={confidence_score:.4f}, time={inference_time:.1f}ms")
        
        return response
        
    except ValueError as e:
        logging.warning(f"Invalid request: {str(e)}")
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        logging.error(f"Inference failed: {str(e)}")
        raise HTTPException(status_code=500, detail="Inference failed")

@router.get("/model/info")
async def get_model_info(model_manager: ModelManager = Depends(get_model_manager)):
    """Get model information and metadata"""
    return {
        "model_version": model_manager.get_model_version(),
        "loaded_at": model_manager.get_load_time(),
        "feature_names": model_manager.get_feature_names(),
        "metadata": model_manager.get_model_metadata()
    }

@router.post("/model/reload")
async def reload_model(
    version: str = None,
    model_manager: ModelManager = Depends(get_model_manager)
):
    """Reload model with specified version"""
    try:
        success = await model_manager.load_model(version)
        if success:
            return {"status": "success", "version": model_manager.get_model_version()}
        else:
            raise HTTPException(status_code=500, detail="Failed to reload model")
    except Exception as e:
        logging.error(f"Model reload failed: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

def _get_confidence_band(score: float) -> str:
    """Convert confidence score to risk band"""
    if score < 0.3:
        return "low"
    elif score < 0.7:
        return "medium"
    else:
        return "high"
```

### API Schemas (api/schemas.py)
```python
from pydantic import BaseModel, Field, validator
from typing import List, Dict, Any, Optional

class ScoreRequest(BaseModel):
    """Request schema for fraud scoring"""
    features: Dict[str, Any] = Field(..., description="Feature vector as key-value pairs")
    request_id: Optional[str] = Field(None, description="Optional request identifier")
    
    @validator('features')
    def validate_features_not_empty(cls, v):
        if not v:
            raise ValueError("Features cannot be empty")
        return v

class FeatureImportance(BaseModel):
    """Feature importance information"""
    feature: str = Field(..., description="Feature name")
    importance: float = Field(..., description="Importance score")
    direction: str = Field(..., description="Risk direction (increases_risk/decreases_risk)")

class ScoreResponse(BaseModel):
    """Response schema for fraud scoring"""
    confidence_score: float = Field(..., description="Calibrated fraud probability (0-1)")
    confidence_band: str = Field(..., description="Risk band (low/medium/high)")
    top_features: List[FeatureImportance] = Field(..., description="Top contributing features")
    model_version: str = Field(..., description="Model version used")
    calibration_version: str = Field(..., description="Calibration version used")
    inference_time_ms: int = Field(..., description="Inference time in milliseconds")

class ModelInfo(BaseModel):
    """Model information schema"""
    model_version: str
    loaded_at: str
    feature_names: List[str]
    metadata: Dict[str, Any]

class HealthResponse(BaseModel):
    """Health check response schema"""
    status: str
    model_version: str
    model_loaded_at: str
    features_count: int
```

### Feature Validation (utils/feature_validation.py)
```python
import logging
from typing import Dict, List, Any
import numpy as np

def validate_features(features: Dict[str, Any], expected_features: List[str]) -> List[float]:
    """Validate and convert features to numpy array"""
    
    # Check for missing features
    missing_features = set(expected_features) - set(features.keys())
    if missing_features:
        raise ValueError(f"Missing features: {list(missing_features)}")
    
    # Check for extra features
    extra_features = set(features.keys()) - set(expected_features)
    if extra_features:
        logging.warning(f"Extra features ignored: {list(extra_features)}")
    
    # Convert to ordered list matching model expectations
    feature_vector = []
    for feature_name in expected_features:
        value = features[feature_name]
        
        # Handle different data types
        if isinstance(value, bool):
            feature_vector.append(float(value))
        elif isinstance(value, (int, float)):
            if np.isnan(value) or np.isinf(value):
                raise ValueError(f"Invalid value for feature {feature_name}: {value}")
            feature_vector.append(float(value))
        elif isinstance(value, str):
            # Handle categorical features encoded as strings
            feature_vector.append(_encode_categorical(feature_name, value))
        else:
            raise ValueError(f"Unsupported data type for feature {feature_name}: {type(value)}")
    
    return feature_vector

def _encode_categorical(feature_name: str, value: str) -> float:
    """Encode categorical features"""
    # This would typically use a pre-trained encoder
    # For now, implement basic encoding for known categorical features
    
    if feature_name == "email_domain_category":
        encoding_map = {
            "major_provider": 0.0,
            "canadian_provider": 1.0,
            "business": 2.0,
            "disposable": 3.0,
            "unknown": 4.0
        }
        return encoding_map.get(value, 4.0)  # Default to unknown
    
    # For unknown categorical features, try to convert to float
    try:
        return float(value)
    except ValueError:
        raise ValueError(f"Cannot encode categorical feature {feature_name} with value {value}")
```

## Model Training Pipeline

### Training Script (training/train_model.py)
```python
import pandas as pd
import numpy as np
from lightgbm import LGBMClassifier
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.calibration import CalibratedClassifierCV
from sklearn.metrics import roc_auc_score, precision_recall_curve, auc
import pickle
import json
import boto3
from datetime import datetime

class ModelTrainer:
    """LightGBM model training and calibration"""
    
    def __init__(self, config: dict):
        self.config = config
        self.model = None
        self.calibrator = None
        self.feature_names = []
        self.s3_client = boto3.client('s3', region_name='ca-central-1')
    
    def train(self, X: pd.DataFrame, y: pd.Series) -> dict:
        """Train and calibrate LightGBM model"""
        
        # Store feature names
        self.feature_names = list(X.columns)
        
        # Split data
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42, stratify=y
        )
        
        # Train LightGBM model
        self.model = LGBMClassifier(
            objective='binary',
            metric='auc',
            boosting_type='gbdt',
            num_leaves=31,
            learning_rate=0.05,
            feature_fraction=0.9,
            bagging_fraction=0.8,
            bagging_freq=5,
            verbose=0,
            random_state=42
        )
        
        self.model.fit(
            X_train, y_train,
            eval_set=[(X_test, y_test)],
            eval_metric='auc',
            early_stopping_rounds=50,
            verbose=False
        )
        
        # Calibrate probabilities
        self.calibrator = CalibratedClassifierCV(
            self.model, method='isotonic', cv=5
        )
        self.calibrator.fit(X_train, y_train)
        
        # Evaluate model
        metrics = self._evaluate_model(X_test, y_test)
        
        return metrics
    
    def _evaluate_model(self, X_test: pd.DataFrame, y_test: pd.Series) -> dict:
        """Evaluate model performance"""
        
        # Raw predictions
        raw_probs = self.model.predict_proba(X_test)[:, 1]
        
        # Calibrated predictions
        cal_probs = self.calibrator.predict_proba(X_test)[:, 1]
        
        # Calculate metrics
        raw_auc = roc_auc_score(y_test, raw_probs)
        cal_auc = roc_auc_score(y_test, cal_probs)
        
        precision, recall, _ = precision_recall_curve(y_test, cal_probs)
        pr_auc = auc(recall, precision)
        
        # Cross-validation score
        cv_scores = cross_val_score(self.model, X_test, y_test, cv=5, scoring='roc_auc')
        
        return {
            'raw_auc': raw_auc,
            'calibrated_auc': cal_auc,
            'pr_auc': pr_auc,
            'cv_mean': cv_scores.mean(),
            'cv_std': cv_scores.std(),
            'feature_importance': dict(zip(
                self.feature_names,
                self.model.feature_importances_
            ))
        }
    
    def save_model(self, version: str, metrics: dict) -> bool:
        """Save model artifacts to S3"""
        try:
            model_path = f"models/lightgbm_{version}"
            
            # Create model card
            model_card = {
                'version': version,
                'created_at': datetime.utcnow().isoformat(),
                'model_type': 'LightGBM',
                'calibration_method': 'isotonic',
                'calibration_version': f"{version}_isotonic",
                'feature_count': len(self.feature_names),
                'metrics': metrics,
                'hyperparameters': self.model.get_params()
            }
            
            # Upload artifacts
            artifacts = {
                'model.pkl': pickle.dumps(self.model),
                'calibrator.pkl': pickle.dumps(self.calibrator),
                'feature_names.json': json.dumps(self.feature_names),
                'model_card.json': json.dumps(model_card, indent=2)
            }
            
            for filename, content in artifacts.items():
                self.s3_client.put_object(
                    Bucket=self.config['s3_bucket'],
                    Key=f"{model_path}/{filename}",
                    Body=content
                )
            
            print(f"Model {version} saved successfully to S3")
            return True
            
        except Exception as e:
            print(f"Failed to save model: {str(e)}")
            return False

# Example usage
if __name__ == "__main__":
    # Load training data (synthetic for POC)
    X, y = generate_synthetic_data()  # Implementation not shown
    
    config = {
        's3_bucket': 'fraud-detection-models-ca-central-1'
    }
    
    trainer = ModelTrainer(config)
    metrics = trainer.train(X, y)
    
    print("Training completed:")
    print(f"Calibrated AUC: {metrics['calibrated_auc']:.4f}")
    print(f"PR AUC: {metrics['pr_auc']:.4f}")
    
    # Save model
    version = "v1.0.0"
    trainer.save_model(version, metrics)
```

## Integration with Laravel

### Laravel Service Client (app/Services/MlInferenceClient.php)
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MlInferenceClient
{
    private string $baseUrl;
    private int $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('services.ml_inference.url', 'http://localhost:8000');
        $this->timeout = config('services.ml_inference.timeout', 5);
    }
    
    public function score(array $features, string $requestId = null): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/api/v1/score", [
                    'features' => $features,
                    'request_id' => $requestId
                ]);
            
            if (!$response->successful()) {
                throw new Exception("ML service returned {$response->status()}: {$response->body()}");
            }
            
            $data = $response->json();
            
            Log::info('ML inference completed', [
                'request_id' => $requestId,
                'confidence_score' => $data['confidence_score'],
                'inference_time_ms' => $data['inference_time_ms']
            ]);
            
            return $data;
            
        } catch (Exception $e) {
            Log::error('ML inference failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/health");
            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getModelInfo(): array
    {
        $response = Http::timeout(5)->get("{$this->baseUrl}/api/v1/model/info");
        
        if (!$response->successful()) {
            throw new Exception("Failed to get model info: {$response->status()}");
        }
        
        return $response->json();
    }
}
```

## Deployment Configuration

### Dockerfile
```dockerfile
FROM python:3.11-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements and install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app/ ./app/
COPY main.py .

# Create non-root user
RUN useradd -m -u 1000 mluser && chown -R mluser:mluser /app
USER mluser

# Expose port
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

# Start application
CMD ["python", "main.py"]
```

### Requirements.txt
```
fastapi==0.104.1
uvicorn[standard]==0.24.0
lightgbm==4.1.0
scikit-learn==1.3.2
numpy==1.24.3
pandas==2.0.3
boto3==1.34.0
pydantic==2.5.0
python-multipart==0.0.6
prometheus-client==0.19.0
structlog==23.2.0
```

## Performance Requirements

- **Inference Time**: <300ms per request
- **Throughput**: 100+ requests per second
- **Memory Usage**: <2GB per instance
- **Model Loading**: <30 seconds on startup
- **Availability**: 99.9% uptime

## Monitoring & Alerting

### Metrics
- Inference latency (P50, P95, P99)
- Request rate and error rate
- Model prediction distribution
- Feature importance stability
- Memory and CPU usage

### Alerts
- High inference latency (>500ms)
- Error rate >1%
- Model prediction drift
- Service unavailability
- Memory usage >80%

## Security Considerations

- **Network Security**: Private subnet deployment, security groups
- **Model Security**: S3 bucket encryption, IAM roles
- **API Security**: Rate limiting, input validation
- **Audit Trail**: Request logging, model access logs

## Future Enhancements

- **A/B Testing**: Multiple model versions
- **Auto-scaling**: Dynamic instance scaling
- **Model Monitoring**: Drift detection and alerting
- **Feature Store**: Centralized feature management
- **GPU Support**: For larger models
