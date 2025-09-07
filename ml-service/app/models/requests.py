"""
Pydantic models for API requests
"""

from pydantic import BaseModel, Field, field_validator
from typing import List, Optional, Dict, Any
import uuid


class FraudPredictionRequest(BaseModel):
    """Request model for fraud prediction"""
    
    request_id: str = Field(
        default_factory=lambda: str(uuid.uuid4()),
        description="Unique identifier for the request"
    )
    
    feature_vector: Optional[List[float]] = Field(
        None,
        description="Pre-computed feature vector (15 features)",
        min_items=15,
        max_items=15
    )
    
    raw_features: Optional[Dict[str, Any]] = Field(
        None,
        description="Raw feature data to be preprocessed"
    )
    
    model_version: Optional[str] = Field(
        "latest",
        description="Specific model version to use for prediction"
    )
    
    include_explanations: bool = Field(
        True,
        description="Whether to include feature importance explanations"
    )
    
    @field_validator('feature_vector')
    @classmethod
    def validate_feature_vector(cls, v):
        """Validate feature vector values"""
        if v is not None:
            if len(v) != 15:
                raise ValueError("Feature vector must contain exactly 15 features")
            
            # Check for invalid values
            for i, feature in enumerate(v):
                if not isinstance(feature, (int, float)):
                    raise ValueError(f"Feature {i} must be numeric")
                if feature < 0 or feature > 1000000:  # Reasonable bounds
                    raise ValueError(f"Feature {i} value {feature} is out of reasonable bounds")
        
        return v

    model_config = {
        "json_schema_extra": {
            "example": {
                "request_id": "123e4567-e89b-12d3-a456-426614174000",
                "feature_vector": [
                    680.0,    # credit_score
                    8.48,     # debt_to_income_ratio
                    87.50,    # loan_to_value_ratio
                    18.0,     # employment_months
                    55000.0,  # annual_income
                    5.0,      # vehicle_age
                    7.0,      # credit_history_years
                    1.0,      # delinquencies_24m
                    28000.0,  # loan_amount
                    32000.0,  # vehicle_value
                    65.0,     # credit_utilization
                    2.0,      # recent_inquiries_6m
                    24.0,     # address_months
                    72.0,     # loan_term_months
                    40.0      # applicant_age
                ],
                "model_version": "v1.0.0",
                "include_explanations": True
            }
        }
    }


class HealthCheckResponse(BaseModel):
    """Response model for health check"""
    
    status: str = Field(description="Service health status")
    timestamp: float = Field(description="Unix timestamp of health check")
    version: str = Field(description="Service version")
    models_loaded: int = Field(description="Number of models loaded")
    model_versions: List[str] = Field(description="Available model versions")
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "status": "healthy",
                "timestamp": 1641024000.0,
                "version": "1.0.0",
                "models_loaded": 2,
                "model_versions": ["v1.0.0", "v1.1.0"]
            }
        }
    }


class BatchPredictionRequest(BaseModel):
    """Request model for batch predictions"""
    
    requests: List[FraudPredictionRequest] = Field(
        description="List of fraud prediction requests",
        min_items=1,
        max_items=100
    )
    
    parallel_processing: bool = Field(
        True,
        description="Whether to process requests in parallel"
    )
    
    @field_validator('requests')
    @classmethod
    def validate_batch_size(cls, v):
        """Validate batch size"""
        if len(v) > 100:
            raise ValueError("Batch size cannot exceed 100 requests")
        return v
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "requests": [
                    {
                        "request_id": "req-001",
                        "feature_vector": [680.0, 8.48, 87.50, 18.0, 55000.0, 5.0, 7.0, 1.0, 28000.0, 32000.0, 65.0, 2.0, 24.0, 72.0, 40.0]
                    },
                    {
                        "request_id": "req-002", 
                        "feature_vector": [720.0, 6.20, 75.00, 36.0, 75000.0, 3.0, 10.0, 0.0, 35000.0, 45000.0, 45.0, 1.0, 48.0, 60.0, 35.0]
                    }
                ],
                "parallel_processing": True
            }
        }
    }


class ModelReloadRequest(BaseModel):
    """Request model for reloading models"""
    
    model_version: Optional[str] = Field(
        None,
        description="Specific model version to reload, or None for all models"
    )
    
    force_reload: bool = Field(
        False,
        description="Force reload even if model is already loaded"
    )
    
    model_config = {
        "json_schema_extra": {
            "example": {
                "model_version": "v1.1.0",
                "force_reload": False
            }
        }
    }
