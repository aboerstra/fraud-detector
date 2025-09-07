"""
Model service for loading and managing LightGBM models
"""

import asyncio
import logging
import pickle
import time
from pathlib import Path
from typing import Dict, List, Optional, Any
import numpy as np
import joblib
from sklearn.calibration import CalibratedClassifierCV

try:
    import lightgbm as lgb
    LIGHTGBM_AVAILABLE = True
except ImportError:
    LIGHTGBM_AVAILABLE = False
    lgb = None

from ..models.responses import RiskTier, FeatureImportance

logger = logging.getLogger(__name__)


class ModelService:
    """Service for managing ML models and predictions"""
    
    def __init__(self, model_path: str = "models/"):
        self.model_path = Path(model_path)
        self.models: Dict[str, Any] = {}
        self.model_metadata: Dict[str, Dict[str, Any]] = {}
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
        self.default_model_version = "v1.0.0"
        self.prediction_count = 0
        self.total_prediction_time = 0.0
        
    async def load_models(self) -> None:
        """Load all available models"""
        logger.info(f"Loading models from {self.model_path}")
        
        # Create model directory if it doesn't exist
        self.model_path.mkdir(parents=True, exist_ok=True)
        
        # Try to load existing models
        model_files = list(self.model_path.glob("*.pkl")) + list(self.model_path.glob("*.joblib"))
        
        if not model_files:
            logger.warning("No model files found, creating mock model")
            await self._create_mock_model()
        else:
            for model_file in model_files:
                try:
                    await self._load_model_file(model_file)
                except Exception as e:
                    logger.error(f"Failed to load model {model_file}: {e}")
        
        if not self.models:
            logger.warning("No models loaded successfully, creating fallback mock model")
            await self._create_mock_model()
            
        logger.info(f"Loaded {len(self.models)} models: {list(self.models.keys())}")
    
    async def _load_model_file(self, model_file: Path) -> None:
        """Load a specific model file"""
        try:
            # Determine model version from filename
            version = model_file.stem
            
            logger.info(f"Loading model {version} from {model_file}")
            
            # Load model based on file extension
            if model_file.suffix == '.pkl':
                with open(model_file, 'rb') as f:
                    model_data = pickle.load(f)
            elif model_file.suffix == '.joblib':
                model_data = joblib.load(model_file)
            else:
                raise ValueError(f"Unsupported model file format: {model_file.suffix}")
            
            # Extract model and metadata
            if isinstance(model_data, dict):
                model = model_data.get('model')
                metadata = model_data.get('metadata', {})
            else:
                model = model_data
                metadata = {}
            
            if model is None:
                raise ValueError("No model found in file")
            
            self.models[version] = model
            self.model_metadata[version] = {
                'version': version,
                'file_path': str(model_file),
                'loaded_at': time.time(),
                'model_type': type(model).__name__,
                **metadata
            }
            
            logger.info(f"Successfully loaded model {version}")
            
        except Exception as e:
            logger.error(f"Failed to load model from {model_file}: {e}")
            raise
    
    async def _create_mock_model(self) -> None:
        """Create a mock model for testing purposes"""
        logger.info("Creating mock model for testing")
        
        class MockModel:
            """Mock LightGBM model for testing"""
            
            def __init__(self):
                self.feature_importances_ = np.array([
                    0.25, 0.20, 0.15, 0.10, 0.08, 0.06, 0.05, 0.04, 0.03, 0.02,
                    0.01, 0.005, 0.003, 0.002, 0.001
                ])
                self.n_features_ = 15
            
            def predict_proba(self, X):
                """Mock prediction with simple logic"""
                if len(X.shape) == 1:
                    X = X.reshape(1, -1)
                
                predictions = []
                for row in X:
                    # Simple fraud scoring based on key features
                    credit_score = row[0] if len(row) > 0 else 650
                    debt_ratio = row[1] if len(row) > 1 else 30
                    ltv_ratio = row[2] if len(row) > 2 else 80
                    
                    # Calculate fraud probability
                    fraud_prob = 0.1  # Base probability
                    
                    if credit_score < 600:
                        fraud_prob += 0.3
                    elif credit_score < 650:
                        fraud_prob += 0.15
                    elif credit_score < 700:
                        fraud_prob += 0.05
                    
                    if debt_ratio > 50:
                        fraud_prob += 0.25
                    elif debt_ratio > 40:
                        fraud_prob += 0.1
                    
                    if ltv_ratio > 100:
                        fraud_prob += 0.2
                    elif ltv_ratio > 90:
                        fraud_prob += 0.1
                    
                    fraud_prob = min(fraud_prob, 0.95)  # Cap at 95%
                    predictions.append([1 - fraud_prob, fraud_prob])
                
                return np.array(predictions)
        
        mock_model = MockModel()
        version = self.default_model_version
        
        self.models[version] = mock_model
        self.model_metadata[version] = {
            'version': version,
            'model_type': 'MockModel',
            'loaded_at': time.time(),
            'is_mock': True,
            'accuracy': 0.85,
            'precision': 0.82,
            'recall': 0.88,
            'f1_score': 0.85,
            'training_date': '2024-01-01',
            'features_count': 15
        }
        
        logger.info(f"Created mock model {version}")
    
    async def predict(
        self, 
        features: List[float], 
        model_version: Optional[str] = None
    ) -> Dict[str, Any]:
        """Make a fraud prediction"""
        start_time = time.time()
        
        try:
            # Select model
            if model_version is None or model_version == "latest":
                model_version = self._get_latest_model_version()
            
            if model_version not in self.models:
                raise ValueError(f"Model version {model_version} not found")
            
            model = self.models[model_version]
            
            # Validate features
            if len(features) != 15:
                raise ValueError(f"Expected 15 features, got {len(features)}")
            
            # Convert to numpy array
            X = np.array(features).reshape(1, -1)
            
            # Make prediction
            if hasattr(model, 'predict_proba'):
                probabilities = model.predict_proba(X)[0]
                fraud_probability = float(probabilities[1])  # Probability of fraud (class 1)
            else:
                # Fallback for models without predict_proba
                prediction = model.predict(X)[0]
                fraud_probability = float(prediction)
            
            # Calculate confidence score
            confidence_score = self._calculate_confidence(fraud_probability, features)
            
            # Determine risk tier
            risk_tier = self._get_risk_tier(fraud_probability)
            
            # Get feature importance
            feature_importance = self._get_feature_importance(model, features)
            
            # Update metrics
            self.prediction_count += 1
            processing_time = (time.time() - start_time) * 1000
            self.total_prediction_time += processing_time
            
            result = {
                'fraud_probability': fraud_probability,
                'confidence_score': confidence_score,
                'risk_tier': risk_tier,
                'feature_importance': feature_importance,
                'model_version': model_version,
                'processing_time_ms': processing_time
            }
            
            logger.debug(f"Prediction completed: {fraud_probability:.3f} (confidence: {confidence_score:.3f})")
            return result
            
        except Exception as e:
            logger.error(f"Prediction failed: {e}")
            raise
    
    def _get_latest_model_version(self) -> str:
        """Get the latest model version"""
        if not self.models:
            raise ValueError("No models loaded")
        
        # Sort versions and return the latest
        versions = sorted(self.models.keys(), reverse=True)
        return versions[0]
    
    def _calculate_confidence(self, fraud_probability: float, features: List[float]) -> float:
        """Calculate confidence score for the prediction"""
        # Simple confidence calculation based on probability distance from 0.5
        # and feature quality
        prob_confidence = 1 - 2 * abs(fraud_probability - 0.5)
        
        # Feature quality assessment (simplified)
        feature_quality = 1.0
        
        # Check for extreme values that might indicate data quality issues
        credit_score = features[0] if len(features) > 0 else 650
        if credit_score < 300 or credit_score > 850:
            feature_quality *= 0.8
        
        annual_income = features[4] if len(features) > 4 else 50000
        if annual_income < 10000 or annual_income > 500000:
            feature_quality *= 0.9
        
        confidence = (prob_confidence * 0.7 + feature_quality * 0.3)
        return max(0.1, min(0.99, confidence))
    
    def _get_risk_tier(self, fraud_probability: float) -> RiskTier:
        """Determine risk tier based on fraud probability"""
        if fraud_probability < 0.3:
            return RiskTier.LOW
        elif fraud_probability < 0.7:
            return RiskTier.MEDIUM
        else:
            return RiskTier.HIGH
    
    def _get_feature_importance(
        self, 
        model: Any, 
        features: List[float]
    ) -> List[FeatureImportance]:
        """Get feature importance explanations"""
        try:
            # Get feature importances from model
            if hasattr(model, 'feature_importances_'):
                importances = model.feature_importances_
            elif hasattr(model, 'coef_'):
                importances = np.abs(model.coef_[0])
            else:
                # Default importances for mock model
                importances = np.array([
                    0.25, 0.20, 0.15, 0.10, 0.08, 0.06, 0.05, 0.04, 0.03, 0.02,
                    0.01, 0.005, 0.003, 0.002, 0.001
                ])
            
            # Normalize importances
            if importances.sum() > 0:
                importances = importances / importances.sum()
            
            # Create feature importance objects
            feature_importance = []
            for i, (name, importance, value) in enumerate(zip(self.feature_names, importances, features)):
                if importance > 0.01:  # Only include significant features
                    feature_importance.append(FeatureImportance(
                        feature_name=name,
                        importance=float(importance),
                        value=float(value)
                    ))
            
            # Sort by importance and return top 10
            feature_importance.sort(key=lambda x: x.importance, reverse=True)
            return feature_importance[:10]
            
        except Exception as e:
            logger.warning(f"Failed to get feature importance: {e}")
            return []
    
    async def health_check(self) -> Dict[str, Any]:
        """Check service health"""
        return {
            'models_loaded': len(self.models),
            'versions': list(self.models.keys()),
            'prediction_count': self.prediction_count,
            'average_prediction_time_ms': (
                self.total_prediction_time / self.prediction_count 
                if self.prediction_count > 0 else 0
            )
        }
    
    async def get_model_info(self) -> Dict[str, Any]:
        """Get detailed model information"""
        return {
            'available_models': list(self.models.keys()),
            'active_model': self._get_latest_model_version() if self.models else None,
            'model_details': self.model_metadata,
            'feature_names': self.feature_names,
            'last_updated': max(
                (meta.get('loaded_at', 0) for meta in self.model_metadata.values()),
                default=0
            )
        }
    
    async def reload_model(self, model_version: Optional[str] = None) -> Dict[str, Any]:
        """Reload specific model or all models"""
        start_time = time.time()
        reloaded = []
        failed = []
        
        try:
            if model_version:
                # Reload specific model
                model_files = list(self.model_path.glob(f"{model_version}.*"))
                if model_files:
                    await self._load_model_file(model_files[0])
                    reloaded.append(model_version)
                else:
                    failed.append(model_version)
            else:
                # Reload all models
                self.models.clear()
                self.model_metadata.clear()
                await self.load_models()
                reloaded = list(self.models.keys())
            
            reload_time = (time.time() - start_time) * 1000
            
            return {
                'success': len(failed) == 0,
                'reloaded_models': reloaded,
                'failed_models': failed,
                'reload_time_ms': reload_time,
                'message': f"Successfully reloaded {len(reloaded)} models"
            }
            
        except Exception as e:
            logger.error(f"Model reload failed: {e}")
            return {
                'success': False,
                'reloaded_models': reloaded,
                'failed_models': failed + ([model_version] if model_version else ['all']),
                'reload_time_ms': (time.time() - start_time) * 1000,
                'message': f"Reload failed: {str(e)}"
            }
    
    async def cleanup(self) -> None:
        """Cleanup resources"""
        logger.info("Cleaning up model service")
        self.models.clear()
        self.model_metadata.clear()
