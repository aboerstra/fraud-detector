"""
Pydantic models for API responses
"""

from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any
from enum import Enum


class RiskTier(str, Enum):
    """Risk tier enumeration"""
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    UNKNOWN = "unknown"
    ERROR = "error"


class FeatureImportance(BaseModel):
    """Feature importance information"""
    
    feature_name: str = Field(description="Name of the feature")
    importance: float = Field(description="Importance score (0-1)")
    value: Optional[float] = Field(None, description="Feature value used in prediction")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "feature_name": "credit_score",
                "importance": 0.25,
                "value": 680.0
            }
        }
    }


class FraudPredictionResponse(BaseModel):
    """Response model for fraud prediction"""
    
    request_id: str = Field(description="Unique identifier for the request")
    
    fraud_probability: float = Field(
        description="Probability of fraud (0-1)",
        ge=0.0,
        le=1.0
    )
    
    confidence_score: float = Field(
        description="Model confidence in the prediction (0-1)",
        ge=0.0,
        le=1.0
    )
    
    risk_tier: RiskTier = Field(description="Risk tier classification")
    
    feature_importance: List[FeatureImportance] = Field(
        description="Feature importance explanations",
        default_factory=list
    )
    
    model_version: str = Field(description="Version of the model used")
    
    processing_time_ms: float = Field(description="Processing time in milliseconds")
    
    timestamp: float = Field(description="Unix timestamp of prediction")
    
    metadata: Optional[Dict[str, Any]] = Field(
        None,
        description="Additional metadata about the prediction"
    )
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "request_id": "123e4567-e89b-12d3-a456-426614174000",
                "fraud_probability": 0.23,
                "confidence_score": 0.87,
                "risk_tier": "low",
                "feature_importance": [
                    {
                        "feature_name": "credit_score",
                        "importance": 0.25,
                        "value": 680.0
                    },
                    {
                        "feature_name": "debt_to_income_ratio",
                        "importance": 0.20,
                        "value": 8.48
                    }
                ],
                "model_version": "lightgbm_v1.0.0",
                "processing_time_ms": 45.2,
                "timestamp": 1641024000.0
            }
        }
    }


class ModelInfoResponse(BaseModel):
    """Response model for model information"""
    
    available_models: List[str] = Field(description="List of available model versions")
    
    active_model: str = Field(description="Currently active model version")
    
    model_details: Dict[str, Dict[str, Any]] = Field(
        description="Detailed information about each model"
    )
    
    feature_names: List[str] = Field(description="Expected feature names in order")
    
    last_updated: float = Field(description="Unix timestamp of last model update")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "available_models": ["v1.0.0", "v1.1.0"],
                "active_model": "v1.1.0",
                "model_details": {
                    "v1.0.0": {
                        "accuracy": 0.92,
                        "precision": 0.89,
                        "recall": 0.94,
                        "f1_score": 0.91,
                        "training_date": "2024-01-15",
                        "features_count": 15
                    },
                    "v1.1.0": {
                        "accuracy": 0.94,
                        "precision": 0.91,
                        "recall": 0.96,
                        "f1_score": 0.93,
                        "training_date": "2024-02-01",
                        "features_count": 15
                    }
                },
                "feature_names": [
                    "credit_score",
                    "debt_to_income_ratio",
                    "loan_to_value_ratio",
                    "employment_months",
                    "annual_income",
                    "vehicle_age",
                    "credit_history_years",
                    "delinquencies_24m",
                    "loan_amount",
                    "vehicle_value",
                    "credit_utilization",
                    "recent_inquiries_6m",
                    "address_months",
                    "loan_term_months",
                    "applicant_age"
                ],
                "last_updated": 1641024000.0
            }
        }
    }


class BatchPredictionResponse(BaseModel):
    """Response model for batch predictions"""
    
    batch_id: str = Field(description="Unique identifier for the batch")
    
    total_requests: int = Field(description="Total number of requests processed")
    
    successful_predictions: int = Field(description="Number of successful predictions")
    
    failed_predictions: int = Field(description="Number of failed predictions")
    
    predictions: List[FraudPredictionResponse] = Field(
        description="Individual prediction responses"
    )
    
    total_processing_time_ms: float = Field(
        description="Total processing time for the batch"
    )
    
    average_processing_time_ms: float = Field(
        description="Average processing time per request"
    )
    
    timestamp: float = Field(description="Unix timestamp of batch completion")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "batch_id": "batch-123e4567-e89b-12d3-a456-426614174000",
                "total_requests": 10,
                "successful_predictions": 9,
                "failed_predictions": 1,
                "predictions": [
                    {
                        "request_id": "req-001",
                        "fraud_probability": 0.23,
                        "confidence_score": 0.87,
                        "risk_tier": "low",
                        "feature_importance": [],
                        "model_version": "lightgbm_v1.0.0",
                        "processing_time_ms": 45.2,
                        "timestamp": 1641024000.0
                    }
                ],
                "total_processing_time_ms": 452.0,
                "average_processing_time_ms": 45.2,
                "timestamp": 1641024000.0
            }
        }
    }


class ErrorResponse(BaseModel):
    """Response model for errors"""
    
    error_code: str = Field(description="Error code")
    
    error_message: str = Field(description="Human-readable error message")
    
    details: Optional[Dict[str, Any]] = Field(
        None,
        description="Additional error details"
    )
    
    timestamp: float = Field(description="Unix timestamp of error")
    
    request_id: Optional[str] = Field(
        None,
        description="Request ID if available"
    )
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "error_code": "INVALID_FEATURES",
                "error_message": "Feature vector must contain exactly 15 features",
                "details": {
                    "provided_features": 12,
                    "expected_features": 15
                },
                "timestamp": 1641024000.0,
                "request_id": "123e4567-e89b-12d3-a456-426614174000"
            }
        }
    }


class ModelReloadResponse(BaseModel):
    """Response model for model reload operations"""
    
    success: bool = Field(description="Whether the reload was successful")
    
    reloaded_models: List[str] = Field(description="List of models that were reloaded")
    
    failed_models: List[str] = Field(
        description="List of models that failed to reload",
        default_factory=list
    )
    
    reload_time_ms: float = Field(description="Time taken to reload models")
    
    message: str = Field(description="Status message")
    
    timestamp: float = Field(description="Unix timestamp of reload operation")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "success": True,
                "reloaded_models": ["v1.1.0"],
                "failed_models": [],
                "reload_time_ms": 1250.5,
                "message": "Successfully reloaded 1 model",
                "timestamp": 1641024000.0
            }
        }
    }


class ServiceMetrics(BaseModel):
    """Service performance metrics"""
    
    total_predictions: int = Field(description="Total predictions made")
    
    average_response_time_ms: float = Field(description="Average response time")
    
    predictions_per_second: float = Field(description="Current throughput")
    
    error_rate: float = Field(description="Error rate (0-1)")
    
    uptime_seconds: float = Field(description="Service uptime in seconds")
    
    memory_usage_mb: float = Field(description="Current memory usage")
    
    cpu_usage_percent: float = Field(description="Current CPU usage")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "total_predictions": 15420,
                "average_response_time_ms": 47.3,
                "predictions_per_second": 23.5,
                "error_rate": 0.002,
                "uptime_seconds": 86400,
                "memory_usage_mb": 512.3,
                "cpu_usage_percent": 15.7
            }
        }
    }
