"""
Request models for the training service
"""

from pydantic import BaseModel, Field
from typing import Optional, Dict, Any, List


class TrainingJobRequest(BaseModel):
    """Request model for creating a training job"""
    dataset_id: int = Field(..., description="ID of the dataset to use for training")
    name: str = Field(..., description="Name for the training job")
    description: Optional[str] = Field(None, description="Description of the training job")
    preset: Optional[str] = Field("balanced", description="Training preset: fast, balanced, or thorough")
    cv_folds: Optional[int] = Field(5, description="Number of cross-validation folds")
    test_size: Optional[float] = Field(0.2, description="Proportion of data to use for testing")
    random_state: Optional[int] = Field(42, description="Random state for reproducibility")
    hyperparameters: Optional[Dict[str, Any]] = Field({}, description="Custom hyperparameters")
    created_by: Optional[str] = Field(None, description="User who created the job")


class DatasetUploadRequest(BaseModel):
    """Request model for dataset upload metadata"""
    name: str = Field(..., description="Name of the dataset")
    description: Optional[str] = Field(None, description="Description of the dataset")
    uploaded_by: Optional[str] = Field(None, description="User who uploaded the dataset")
