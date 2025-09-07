"""
Training service for managing ML model training jobs
"""

import asyncio
import logging
import uuid
import time
import os
import joblib
from pathlib import Path
from typing import Dict, List, Optional, Any
import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score, StratifiedKFold
from sklearn.metrics import classification_report, confusion_matrix, roc_auc_score, precision_recall_curve, auc
import lightgbm as lgb

from ..models.requests import TrainingJobRequest
from ..utils.database import get_database_connection

logger = logging.getLogger(__name__)


class TrainingService:
    """Service for managing ML model training"""
    
    def __init__(self, settings):
        self.settings = settings
        self.models_path = Path(settings.models_path)
        self.datasets_path = Path(settings.datasets_path)
        self.running_jobs = {}  # Track running training jobs
        
        # Feature names for fraud detection
        self.feature_names = [
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
        ]
        
        # Training presets for different user levels
        self.training_presets = {
            'fast': {
                'num_leaves': 31,
                'learning_rate': 0.1,
                'feature_fraction': 0.9,
                'bagging_fraction': 0.8,
                'bagging_freq': 5,
                'verbose': 0,
                'num_boost_round': 100,
                'early_stopping_rounds': 10
            },
            'balanced': {
                'num_leaves': 63,
                'learning_rate': 0.05,
                'feature_fraction': 0.8,
                'bagging_fraction': 0.7,
                'bagging_freq': 5,
                'verbose': 0,
                'num_boost_round': 300,
                'early_stopping_rounds': 20
            },
            'thorough': {
                'num_leaves': 127,
                'learning_rate': 0.02,
                'feature_fraction': 0.7,
                'bagging_fraction': 0.6,
                'bagging_freq': 5,
                'verbose': 0,
                'num_boost_round': 1000,
                'early_stopping_rounds': 50
            }
        }
    
    async def initialize(self):
        """Initialize the training service"""
        logger.info("Initializing training service")
        
        # Create directories
        self.models_path.mkdir(parents=True, exist_ok=True)
        self.datasets_path.mkdir(parents=True, exist_ok=True)
        
        logger.info("Training service initialized")
    
    async def create_training_job(self, request: TrainingJobRequest) -> Dict[str, Any]:
        """Create a new training job"""
        job_id = str(uuid.uuid4())
        
        # Get training configuration
        config = self._prepare_training_config(request)
        
        # Create job record in database
        async with get_database_connection() as conn:
            job_data = {
                'job_id': job_id,
                'dataset_id': request.dataset_id,
                'name': request.name,
                'description': request.description,
                'config': config,
                'status': 'queued',
                'created_by': request.created_by
            }
            
            # Insert job record (simplified - would use proper ORM in production)
            query = """
                INSERT INTO training_jobs 
                (job_id, dataset_id, name, description, config, status, created_by, created_at, updated_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
                RETURNING *
            """
            
            result = await conn.fetchrow(
                query, 
                job_id, request.dataset_id, request.name, request.description,
                config, 'queued', request.created_by
            )
            
            return dict(result)
    
    async def run_training_job(self, job_id: str):
        """Run a training job in the background"""
        try:
            logger.info(f"Starting training job {job_id}")
            
            # Mark job as running
            await self._update_job_status(job_id, 'running', 'Initializing training...')
            
            # Load dataset and configuration
            job_data = await self._get_job_data(job_id)
            dataset = await self._load_dataset(job_data['dataset_id'])
            config = job_data['config']
            
            # Prepare training data
            await self._update_job_progress(job_id, 10, 'Preparing training data...')
            X, y = await self._prepare_training_data(dataset)
            
            # Split data
            await self._update_job_progress(job_id, 20, 'Splitting data...')
            X_train, X_test, y_train, y_test = train_test_split(
                X, y, 
                test_size=config.get('test_size', 0.2),
                random_state=config.get('random_state', 42),
                stratify=y
            )
            
            # Train model
            await self._update_job_progress(job_id, 30, 'Training model...')
            model, training_metrics = await self._train_lightgbm_model(
                X_train, y_train, X_test, y_test, config, job_id
            )
            
            # Evaluate model
            await self._update_job_progress(job_id, 80, 'Evaluating model...')
            evaluation_metrics = await self._evaluate_model(model, X_test, y_test)
            
            # Cross-validation
            await self._update_job_progress(job_id, 90, 'Running cross-validation...')
            cv_results = await self._cross_validate_model(model, X, y, config)
            
            # Save model
            await self._update_job_progress(job_id, 95, 'Saving model...')
            model_path = await self._save_model(model, job_id, config)
            
            # Calculate feature importance
            feature_importance = self._calculate_feature_importance(model)
            
            # Combine all metrics
            final_metrics = {
                **training_metrics,
                **evaluation_metrics,
                'cv_results': cv_results,
                'feature_importance': feature_importance
            }
            
            # Mark job as completed
            await self._update_job_status(
                job_id, 'completed', 'Training completed successfully',
                metrics=final_metrics, model_path=model_path
            )
            
            # Create model registry entry
            await self._create_model_registry_entry(job_id, model_path, final_metrics)
            
            logger.info(f"Training job {job_id} completed successfully")
            
        except Exception as e:
            logger.error(f"Training job {job_id} failed: {e}")
            await self._update_job_status(job_id, 'failed', f'Training failed: {str(e)}')
        finally:
            # Clean up
            if job_id in self.running_jobs:
                del self.running_jobs[job_id]
    
    async def _train_lightgbm_model(self, X_train, y_train, X_test, y_test, config, job_id):
        """Train LightGBM model with progress tracking"""
        
        # Get hyperparameters
        hyperparams = config.get('hyperparameters', {})
        preset = config.get('preset', 'balanced')
        
        # Use preset if no custom hyperparameters
        if not hyperparams and preset in self.training_presets:
            hyperparams = self.training_presets[preset].copy()
        
        # Prepare datasets
        train_data = lgb.Dataset(X_train, label=y_train, feature_name=self.feature_names)
        valid_data = lgb.Dataset(X_test, label=y_test, reference=train_data, feature_name=self.feature_names)
        
        # Training parameters
        params = {
            'objective': 'binary',
            'metric': ['binary_logloss', 'auc'],
            'boosting_type': 'gbdt',
            'num_class': 1,
            'verbose': -1,
            **hyperparams
        }
        
        # Extract training-specific parameters
        num_boost_round = params.pop('num_boost_round', 300)
        early_stopping_rounds = params.pop('early_stopping_rounds', 20)
        
        # Progress callback
        progress_callback = self._create_progress_callback(job_id, num_boost_round)
        
        # Train model
        model = lgb.train(
            params,
            train_data,
            num_boost_round=num_boost_round,
            valid_sets=[train_data, valid_data],
            valid_names=['train', 'valid'],
            callbacks=[progress_callback],
            early_stopping_rounds=early_stopping_rounds
        )
        
        # Get training metrics
        training_metrics = {
            'best_iteration': model.best_iteration,
            'best_score': model.best_score,
            'hyperparameters': params
        }
        
        return model, training_metrics
    
    def _create_progress_callback(self, job_id: str, total_rounds: int):
        """Create a callback to track training progress"""
        
        def callback(env):
            if env.iteration % 10 == 0:  # Update every 10 iterations
                progress = 30 + int((env.iteration / total_rounds) * 50)  # 30-80% range
                message = f'Training iteration {env.iteration}/{total_rounds}'
                
                # Update progress asynchronously
                asyncio.create_task(self._update_job_progress(job_id, progress, message))
        
        return callback
    
    async def _evaluate_model(self, model, X_test, y_test):
        """Evaluate trained model"""
        
        # Make predictions
        y_pred_proba = model.predict(X_test)
        y_pred = (y_pred_proba > 0.5).astype(int)
        
        # Calculate metrics
        auc_roc = roc_auc_score(y_test, y_pred_proba)
        
        # Precision-Recall AUC
        precision, recall, _ = precision_recall_curve(y_test, y_pred_proba)
        auc_pr = auc(recall, precision)
        
        # Classification report
        class_report = classification_report(y_test, y_pred, output_dict=True)
        
        # Confusion matrix
        cm = confusion_matrix(y_test, y_pred)
        
        return {
            'test_auc_roc': auc_roc,
            'test_auc_pr': auc_pr,
            'test_precision': class_report['1']['precision'],
            'test_recall': class_report['1']['recall'],
            'test_f1': class_report['1']['f1-score'],
            'test_accuracy': class_report['accuracy'],
            'confusion_matrix': cm.tolist(),
            'classification_report': class_report
        }
    
    async def _cross_validate_model(self, model, X, y, config):
        """Perform cross-validation"""
        
        cv_folds = config.get('cv_folds', 5)
        cv = StratifiedKFold(n_splits=cv_folds, shuffle=True, random_state=42)
        
        # Create a new model with same parameters for CV
        params = model.params.copy()
        
        cv_scores = []
        for train_idx, val_idx in cv.split(X, y):
            X_train_cv, X_val_cv = X.iloc[train_idx], X.iloc[val_idx]
            y_train_cv, y_val_cv = y.iloc[train_idx], y.iloc[val_idx]
            
            # Train fold model
            train_data = lgb.Dataset(X_train_cv, label=y_train_cv)
            fold_model = lgb.train(params, train_data, num_boost_round=100, verbose_eval=False)
            
            # Evaluate fold
            y_pred = fold_model.predict(X_val_cv)
            score = roc_auc_score(y_val_cv, y_pred)
            cv_scores.append(score)
        
        return {
            'cv_scores': cv_scores,
            'cv_mean': np.mean(cv_scores),
            'cv_std': np.std(cv_scores)
        }
    
    def _calculate_feature_importance(self, model):
        """Calculate feature importance"""
        importance = model.feature_importance(importance_type='gain')
        
        feature_importance = []
        for i, (name, imp) in enumerate(zip(self.feature_names, importance)):
            feature_importance.append({
                'feature_name': name,
                'importance': float(imp),
                'rank': i + 1
            })
        
        # Sort by importance
        feature_importance.sort(key=lambda x: x['importance'], reverse=True)
        
        # Update ranks
        for i, item in enumerate(feature_importance):
            item['rank'] = i + 1
        
        return feature_importance
    
    async def _save_model(self, model, job_id: str, config: Dict) -> str:
        """Save trained model to disk"""
        
        model_filename = f"model_{job_id}.joblib"
        model_path = self.models_path / model_filename
        
        # Save model with metadata
        model_data = {
            'model': model,
            'config': config,
            'feature_names': self.feature_names,
            'created_at': time.time(),
            'job_id': job_id
        }
        
        joblib.dump(model_data, model_path)
        
        return str(model_path)
    
    def _prepare_training_config(self, request: TrainingJobRequest) -> Dict:
        """Prepare training configuration from request"""
        
        config = {
            'algorithm': 'lightgbm',
            'preset': request.preset or 'balanced',
            'cv_folds': request.cv_folds or 5,
            'test_size': request.test_size or 0.2,
            'random_state': request.random_state or 42,
            'hyperparameters': request.hyperparameters or {}
        }
        
        return config
    
    async def _load_dataset(self, dataset_id: int) -> pd.DataFrame:
        """Load dataset from file"""
        # Get dataset info from database
        async with get_database_connection() as conn:
            query = "SELECT * FROM training_datasets WHERE id = $1"
            dataset_info = await conn.fetchrow(query, dataset_id)
            
            if not dataset_info:
                raise ValueError(f"Dataset {dataset_id} not found")
            
            file_path = dataset_info['file_path']
            file_type = dataset_info['file_type']
            
            # Load data based on file type
            if file_type == 'csv':
                return pd.read_csv(file_path)
            elif file_type == 'json':
                return pd.read_json(file_path)
            else:
                raise ValueError(f"Unsupported file type: {file_type}")
    
    async def _prepare_training_data(self, dataset: pd.DataFrame):
        """Prepare training data from dataset"""
        
        # Assume the dataset has a 'fraud_label' column and feature columns
        if 'fraud_label' not in dataset.columns:
            raise ValueError("Dataset must contain 'fraud_label' column")
        
        # Extract features and target
        y = dataset['fraud_label']
        
        # Extract features (assume they match our feature names)
        available_features = [col for col in self.feature_names if col in dataset.columns]
        
        if len(available_features) < len(self.feature_names):
            logger.warning(f"Only {len(available_features)} of {len(self.feature_names)} features available")
        
        X = dataset[available_features]
        
        # Fill missing features with defaults
        for feature in self.feature_names:
            if feature not in X.columns:
                X[feature] = 0.0  # Default value
        
        # Ensure correct order
        X = X[self.feature_names]
        
        return X, y
    
    async def _update_job_status(self, job_id: str, status: str, message: str = None, 
                                metrics: Dict = None, model_path: str = None):
        """Update job status in database"""
        async with get_database_connection() as conn:
            query = """
                UPDATE training_jobs 
                SET status = $1, status_message = $2, metrics = $3, model_path = $4, updated_at = NOW()
                WHERE job_id = $5
            """
            await conn.execute(query, status, message, metrics, model_path, job_id)
    
    async def _update_job_progress(self, job_id: str, progress: int, message: str = None):
        """Update job progress in database"""
        async with get_database_connection() as conn:
            query = """
                UPDATE training_jobs 
                SET progress = $1, status_message = $2, updated_at = NOW()
                WHERE job_id = $3
            """
            await conn.execute(query, progress, message, job_id)
    
    async def _get_job_data(self, job_id: str) -> Dict:
        """Get job data from database"""
        async with get_database_connection() as conn:
            query = "SELECT * FROM training_jobs WHERE job_id = $1"
            result = await conn.fetchrow(query, job_id)
            return dict(result) if result else None
    
    async def _create_model_registry_entry(self, job_id: str, model_path: str, metrics: Dict):
        """Create entry in model registry"""
        model_id = str(uuid.uuid4())
        
        async with get_database_connection() as conn:
            # Get job info
            job_query = "SELECT * FROM training_jobs WHERE job_id = $1"
            job_info = await conn.fetchrow(job_query, job_id)
            
            # Create model registry entry
            query = """
                INSERT INTO model_registry 
                (model_id, name, version, training_job_id, model_path, performance_metrics, status, created_at, updated_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
            """
            
            await conn.execute(
                query,
                model_id,
                f"Model_{job_info['name']}",
                "1.0.0",
                job_info['id'],
                model_path,
                metrics,
                'ready'
            )
    
    # Additional methods for job management
    async def get_training_job(self, job_id: str):
        """Get training job by ID"""
        return await self._get_job_data(job_id)
    
    async def list_training_jobs(self, status: str = None, dataset_id: int = None, 
                                limit: int = 50, offset: int = 0):
        """List training jobs with filters"""
        async with get_database_connection() as conn:
            conditions = []
            params = []
            param_count = 0
            
            if status:
                param_count += 1
                conditions.append(f"status = ${param_count}")
                params.append(status)
            
            if dataset_id:
                param_count += 1
                conditions.append(f"dataset_id = ${param_count}")
                params.append(dataset_id)
            
            where_clause = " WHERE " + " AND ".join(conditions) if conditions else ""
            
            param_count += 1
            params.append(limit)
            param_count += 1
            params.append(offset)
            
            query = f"""
                SELECT * FROM training_jobs 
                {where_clause}
                ORDER BY created_at DESC 
                LIMIT ${param_count-1} OFFSET ${param_count}
            """
            
            results = await conn.fetch(query, *params)
            return [dict(row) for row in results]
    
    async def cancel_training_job(self, job_id: str) -> bool:
        """Cancel a running training job"""
        # Mark as cancelled in database
        async with get_database_connection() as conn:
            query = """
                UPDATE training_jobs 
                SET status = 'cancelled', status_message = 'Cancelled by user', updated_at = NOW()
                WHERE job_id = $1 AND status IN ('queued', 'running')
            """
            result = await conn.execute(query, job_id)
            
            # Clean up running job
            if job_id in self.running_jobs:
                del self.running_jobs[job_id]
            
            return result != "UPDATE 0"
    
    async def list_models(self, status: str = None, limit: int = 50, offset: int = 0):
        """List trained models"""
        async with get_database_connection() as conn:
            conditions = []
            params = []
            param_count = 0
            
            if status:
                param_count += 1
                conditions.append(f"status = ${param_count}")
                params.append(status)
            
            where_clause = " WHERE " + " AND ".join(conditions) if conditions else ""
            
            param_count += 1
            params.append(limit)
            param_count += 1
            params.append(offset)
            
            query = f"""
                SELECT * FROM model_registry 
                {where_clause}
                ORDER BY created_at DESC 
                LIMIT ${param_count-1} OFFSET ${param_count}
            """
            
            results = await conn.fetch(query, *params)
            return [dict(row) for row in results]
    
    async def get_model(self, model_id: str):
        """Get model by ID"""
        async with get_database_connection() as conn:
            query = "SELECT * FROM model_registry WHERE model_id = $1"
            result = await conn.fetchrow(query, model_id)
            return dict(result) if result else None
    
    async def deploy_model(self, model_id: str, deployment_config: Dict) -> bool:
        """Deploy a model to production"""
        async with get_database_connection() as conn:
            # Mark current production models as not production
            await conn.execute("UPDATE model_registry SET is_production = FALSE")
            
            # Mark this model as production
            query = """
                UPDATE model_registry 
                SET is_production = TRUE, deployed_at = NOW(), deployment_config = $1, status = 'deployed'
                WHERE model_id = $2
            """
            result = await conn.execute(query, deployment_config, model_id)
            
            return result != "UPDATE 0"
    
    async def cleanup(self):
        """Cleanup resources"""
        logger.info("Cleaning up training service")
        self.running_jobs.clear()
