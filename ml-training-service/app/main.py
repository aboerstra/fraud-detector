"""
FastAPI ML Training Service for Fraud Detection
Provides machine learning model training capabilities
"""

from fastapi import FastAPI, HTTPException, BackgroundTasks, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import uvicorn
import logging
import time
from typing import Dict, List, Optional
import os
from contextlib import asynccontextmanager

from .models.requests import TrainingJobRequest, DatasetUploadRequest
from .models.responses import TrainingJobResponse, DatasetResponse, TrainingStatusResponse
from .services.training_service import TrainingService
from .services.dataset_service import DatasetService
from .utils.config import get_settings
from .utils.logging_config import setup_logging

# Setup logging
setup_logging()
logger = logging.getLogger(__name__)

# Global service instances
training_service: Optional[TrainingService] = None
dataset_service: Optional[DatasetService] = None

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan manager for startup and shutdown events"""
    global training_service, dataset_service
    
    logger.info("Starting ML Training Service...")
    
    try:
        # Initialize services
        settings = get_settings()
        training_service = TrainingService(settings)
        dataset_service = DatasetService(settings)
        
        # Initialize services
        await training_service.initialize()
        await dataset_service.initialize()
        
        logger.info("ML Training Service initialized successfully")
        
        yield
        
    except Exception as e:
        logger.error(f"Failed to initialize services: {e}")
        raise
    finally:
        logger.info("Shutting down ML Training Service...")
        if training_service:
            await training_service.cleanup()
        if dataset_service:
            await dataset_service.cleanup()

# Create FastAPI app
app = FastAPI(
    title="Fraud Detection ML Training Service",
    description="Machine Learning training service for fraud detection models",
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

def get_training_service() -> TrainingService:
    """Dependency to get training service instance"""
    if training_service is None:
        raise HTTPException(status_code=503, detail="Training service not initialized")
    return training_service

def get_dataset_service() -> DatasetService:
    """Dependency to get dataset service instance"""
    if dataset_service is None:
        raise HTTPException(status_code=503, detail="Dataset service not initialized")
    return dataset_service

@app.get("/", response_model=Dict[str, str])
async def root():
    """Root endpoint"""
    return {
        "service": "Fraud Detection ML Training Service",
        "version": "1.0.0",
        "status": "running"
    }

@app.get("/healthz")
async def health_check():
    """Health check endpoint"""
    try:
        return {
            "status": "healthy",
            "timestamp": time.time(),
            "version": "1.0.0",
            "services": {
                "training": "healthy" if training_service else "unhealthy",
                "dataset": "healthy" if dataset_service else "unhealthy"
            }
        }
    except Exception as e:
        logger.error(f"Health check failed: {e}")
        raise HTTPException(status_code=503, detail=f"Service unhealthy: {str(e)}")

# Dataset Management Endpoints

@app.post("/datasets/upload", response_model=DatasetResponse)
async def upload_dataset(
    background_tasks: BackgroundTasks,
    file: UploadFile = File(...),
    name: str = None,
    description: str = None
):
    """Upload a training dataset"""
    try:
        dataset_svc = get_dataset_service()
        
        # Validate file
        if not file.filename.endswith(('.csv', '.json')):
            raise HTTPException(status_code=400, detail="Only CSV and JSON files are supported")
        
        # Create dataset record
        dataset = await dataset_svc.create_dataset(
            name=name or file.filename,
            description=description,
            file=file
        )
        
        # Process dataset in background
        background_tasks.add_task(dataset_svc.process_dataset, dataset.id)
        
        return DatasetResponse.from_dataset(dataset)
        
    except Exception as e:
        logger.error(f"Dataset upload failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/datasets", response_model=List[DatasetResponse])
async def list_datasets(
    status: Optional[str] = None,
    limit: int = 50,
    offset: int = 0
):
    """List available datasets"""
    try:
        dataset_svc = get_dataset_service()
        datasets = await dataset_svc.list_datasets(status=status, limit=limit, offset=offset)
        return [DatasetResponse.from_dataset(ds) for ds in datasets]
    except Exception as e:
        logger.error(f"Failed to list datasets: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/datasets/{dataset_id}", response_model=DatasetResponse)
async def get_dataset(dataset_id: int):
    """Get dataset details"""
    try:
        dataset_svc = get_dataset_service()
        dataset = await dataset_svc.get_dataset(dataset_id)
        if not dataset:
            raise HTTPException(status_code=404, detail="Dataset not found")
        return DatasetResponse.from_dataset(dataset)
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to get dataset: {e}")
        raise HTTPException(status_code=500, detail=str(e))

# Training Job Endpoints

@app.post("/training/jobs", response_model=TrainingJobResponse)
async def create_training_job(
    request: TrainingJobRequest,
    background_tasks: BackgroundTasks
):
    """Create and start a new training job"""
    try:
        training_svc = get_training_service()
        
        # Create training job
        job = await training_svc.create_training_job(request)
        
        # Start training in background
        background_tasks.add_task(training_svc.run_training_job, job.job_id)
        
        return TrainingJobResponse.from_job(job)
        
    except Exception as e:
        logger.error(f"Training job creation failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/training/jobs", response_model=List[TrainingJobResponse])
async def list_training_jobs(
    status: Optional[str] = None,
    dataset_id: Optional[int] = None,
    limit: int = 50,
    offset: int = 0
):
    """List training jobs"""
    try:
        training_svc = get_training_service()
        jobs = await training_svc.list_training_jobs(
            status=status, 
            dataset_id=dataset_id, 
            limit=limit, 
            offset=offset
        )
        return [TrainingJobResponse.from_job(job) for job in jobs]
    except Exception as e:
        logger.error(f"Failed to list training jobs: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/training/jobs/{job_id}", response_model=TrainingStatusResponse)
async def get_training_job_status(job_id: str):
    """Get training job status and progress"""
    try:
        training_svc = get_training_service()
        job = await training_svc.get_training_job(job_id)
        if not job:
            raise HTTPException(status_code=404, detail="Training job not found")
        return TrainingStatusResponse.from_job(job)
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to get training job status: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/training/jobs/{job_id}/cancel")
async def cancel_training_job(job_id: str):
    """Cancel a running training job"""
    try:
        training_svc = get_training_service()
        success = await training_svc.cancel_training_job(job_id)
        if not success:
            raise HTTPException(status_code=404, detail="Training job not found or cannot be cancelled")
        return {"message": "Training job cancelled successfully"}
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to cancel training job: {e}")
        raise HTTPException(status_code=500, detail=str(e))

# Model Management Endpoints

@app.get("/models")
async def list_models(
    status: Optional[str] = None,
    limit: int = 50,
    offset: int = 0
):
    """List trained models"""
    try:
        training_svc = get_training_service()
        models = await training_svc.list_models(status=status, limit=limit, offset=offset)
        return models
    except Exception as e:
        logger.error(f"Failed to list models: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/models/{model_id}")
async def get_model(model_id: str):
    """Get model details"""
    try:
        training_svc = get_training_service()
        model = await training_svc.get_model(model_id)
        if not model:
            raise HTTPException(status_code=404, detail="Model not found")
        return model
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to get model: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/models/{model_id}/deploy")
async def deploy_model(model_id: str, deployment_config: Dict = None):
    """Deploy a model to production"""
    try:
        training_svc = get_training_service()
        success = await training_svc.deploy_model(model_id, deployment_config or {})
        if not success:
            raise HTTPException(status_code=404, detail="Model not found or cannot be deployed")
        return {"message": "Model deployed successfully"}
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to deploy model: {e}")
        raise HTTPException(status_code=500, detail=str(e))

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
        port=8001,
        reload=True,
        log_level="info"
    )
