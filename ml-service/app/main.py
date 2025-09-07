"""
FastAPI ML Inference Service for Fraud Detection
Provides machine learning model inference for the fraud detection pipeline
"""

from fastapi import FastAPI, HTTPException, Depends
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import uvicorn
import logging
import time
from typing import Dict, List, Optional
import os
from contextlib import asynccontextmanager

from .models.requests import FraudPredictionRequest, HealthCheckResponse
from .models.responses import FraudPredictionResponse, ModelInfoResponse
from .services.model_service import ModelService
from .services.feature_service import FeatureService
from .utils.logging_config import setup_logging
from .utils.config import get_settings

# Setup logging
setup_logging()
logger = logging.getLogger(__name__)

# Global model service instance
model_service: Optional[ModelService] = None
feature_service: Optional[FeatureService] = None

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan manager for startup and shutdown events"""
    global model_service, feature_service
    
    logger.info("Starting ML Inference Service...")
    
    try:
        # Initialize services
        settings = get_settings()
        model_service = ModelService(settings.model_path)
        feature_service = FeatureService()
        
        # Load models
        await model_service.load_models()
        logger.info("Models loaded successfully")
        
        yield
        
    except Exception as e:
        logger.error(f"Failed to initialize services: {e}")
        raise
    finally:
        logger.info("Shutting down ML Inference Service...")
        if model_service:
            await model_service.cleanup()

# Create FastAPI app
app = FastAPI(
    title="Fraud Detection ML Service",
    description="Machine Learning inference service for fraud detection",
    version="1.0.0",
    lifespan=lifespan
)

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Configure appropriately for production
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

def get_model_service() -> ModelService:
    """Dependency to get model service instance"""
    if model_service is None:
        raise HTTPException(status_code=503, detail="Model service not initialized")
    return model_service

def get_feature_service() -> FeatureService:
    """Dependency to get feature service instance"""
    if feature_service is None:
        raise HTTPException(status_code=503, detail="Feature service not initialized")
    return feature_service

@app.get("/", response_model=Dict[str, str])
async def root():
    """Root endpoint"""
    return {
        "service": "Fraud Detection ML Service",
        "version": "1.0.0",
        "status": "running"
    }

@app.get("/healthz", response_model=HealthCheckResponse)
async def health_check(
    model_svc: ModelService = Depends(get_model_service)
):
    """Health check endpoint"""
    try:
        # Check model availability
        model_status = await model_svc.health_check()
        
        return HealthCheckResponse(
            status="healthy",
            timestamp=time.time(),
            version="1.0.0",
            models_loaded=model_status["models_loaded"],
            model_versions=model_status["versions"]
        )
    except Exception as e:
        logger.error(f"Health check failed: {e}")
        raise HTTPException(status_code=503, detail=f"Service unhealthy: {str(e)}")

@app.get("/models", response_model=ModelInfoResponse)
async def get_model_info(
    model_svc: ModelService = Depends(get_model_service)
):
    """Get information about loaded models"""
    try:
        model_info = await model_svc.get_model_info()
        return ModelInfoResponse(**model_info)
    except Exception as e:
        logger.error(f"Failed to get model info: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/predict", response_model=FraudPredictionResponse)
async def predict_fraud(
    request: FraudPredictionRequest,
    model_svc: ModelService = Depends(get_model_service),
    feature_svc: FeatureService = Depends(get_feature_service)
):
    """
    Predict fraud probability for a given application
    
    Args:
        request: Fraud prediction request with feature vector or raw data
        
    Returns:
        Fraud prediction response with probability, confidence, and explanations
    """
    start_time = time.time()
    
    try:
        logger.info(f"Processing fraud prediction request: {request.request_id}")
        
        # Preprocess features if raw data provided
        if request.raw_features:
            features = await feature_svc.preprocess_features(request.raw_features)
        else:
            features = request.feature_vector
        
        if not features:
            raise HTTPException(status_code=400, detail="No features provided")
        
        # Get prediction from model
        prediction_result = await model_svc.predict(
            features=features,
            model_version=request.model_version
        )
        
        processing_time = (time.time() - start_time) * 1000
        
        response = FraudPredictionResponse(
            request_id=request.request_id,
            fraud_probability=prediction_result["fraud_probability"],
            confidence_score=prediction_result["confidence_score"],
            risk_tier=prediction_result["risk_tier"],
            feature_importance=prediction_result["feature_importance"],
            model_version=prediction_result["model_version"],
            processing_time_ms=processing_time,
            timestamp=time.time()
        )
        
        logger.info(f"Prediction completed in {processing_time:.2f}ms: {response.fraud_probability:.3f}")
        return response
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Prediction failed: {e}")
        raise HTTPException(status_code=500, detail=f"Prediction failed: {str(e)}")

@app.post("/predict/batch", response_model=List[FraudPredictionResponse])
async def predict_fraud_batch(
    requests: List[FraudPredictionRequest],
    model_svc: ModelService = Depends(get_model_service),
    feature_svc: FeatureService = Depends(get_feature_service)
):
    """
    Batch prediction endpoint for multiple fraud assessments
    
    Args:
        requests: List of fraud prediction requests
        
    Returns:
        List of fraud prediction responses
    """
    start_time = time.time()
    
    try:
        logger.info(f"Processing batch prediction with {len(requests)} requests")
        
        if len(requests) > 100:  # Limit batch size
            raise HTTPException(status_code=400, detail="Batch size too large (max 100)")
        
        responses = []
        
        for request in requests:
            try:
                # Process each request
                if request.raw_features:
                    features = await feature_svc.preprocess_features(request.raw_features)
                else:
                    features = request.feature_vector
                
                if features:
                    prediction_result = await model_svc.predict(
                        features=features,
                        model_version=request.model_version
                    )
                    
                    response = FraudPredictionResponse(
                        request_id=request.request_id,
                        fraud_probability=prediction_result["fraud_probability"],
                        confidence_score=prediction_result["confidence_score"],
                        risk_tier=prediction_result["risk_tier"],
                        feature_importance=prediction_result["feature_importance"],
                        model_version=prediction_result["model_version"],
                        processing_time_ms=0,  # Will be set after batch processing
                        timestamp=time.time()
                    )
                    responses.append(response)
                else:
                    # Add error response for invalid request
                    error_response = FraudPredictionResponse(
                        request_id=request.request_id,
                        fraud_probability=0.5,  # Default neutral score
                        confidence_score=0.0,
                        risk_tier="unknown",
                        feature_importance=[],
                        model_version="error",
                        processing_time_ms=0,
                        timestamp=time.time()
                    )
                    responses.append(error_response)
                    
            except Exception as e:
                logger.error(f"Failed to process request {request.request_id}: {e}")
                # Add error response
                error_response = FraudPredictionResponse(
                    request_id=request.request_id,
                    fraud_probability=0.5,
                    confidence_score=0.0,
                    risk_tier="error",
                    feature_importance=[],
                    model_version="error",
                    processing_time_ms=0,
                    timestamp=time.time()
                )
                responses.append(error_response)
        
        total_time = (time.time() - start_time) * 1000
        avg_time = total_time / len(responses) if responses else 0
        
        # Update processing times
        for response in responses:
            response.processing_time_ms = avg_time
        
        logger.info(f"Batch prediction completed in {total_time:.2f}ms ({avg_time:.2f}ms avg)")
        return responses
        
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Batch prediction failed: {e}")
        raise HTTPException(status_code=500, detail=f"Batch prediction failed: {str(e)}")

@app.exception_handler(Exception)
async def global_exception_handler(request, exc):
    """Global exception handler"""
    logger.error(f"Unhandled exception: {exc}")
    return JSONResponse(
        status_code=500,
        content={"detail": "Internal server error"}
    )

if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8000,
        reload=True,
        log_level="info"
    )
