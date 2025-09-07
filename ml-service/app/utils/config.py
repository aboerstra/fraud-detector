"""
Configuration management for the ML service
"""

import os
from functools import lru_cache
from typing import Optional
from pydantic import Field
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings"""
    
    # Service configuration
    service_name: str = Field(default="fraud-detection-ml-service", env="SERVICE_NAME")
    service_version: str = Field(default="1.0.0", env="SERVICE_VERSION")
    environment: str = Field(default="development", env="ENVIRONMENT")
    debug: bool = Field(default=False, env="DEBUG")
    
    # Server configuration
    host: str = Field(default="0.0.0.0", env="HOST")
    port: int = Field(default=8000, env="PORT")
    workers: int = Field(default=1, env="WORKERS")
    
    # Model configuration
    model_path: str = Field(default="models/", env="MODEL_PATH")
    default_model_version: str = Field(default="v1.0.0", env="DEFAULT_MODEL_VERSION")
    model_cache_size: int = Field(default=5, env="MODEL_CACHE_SIZE")
    
    # Performance configuration
    max_batch_size: int = Field(default=100, env="MAX_BATCH_SIZE")
    prediction_timeout: float = Field(default=30.0, env="PREDICTION_TIMEOUT")
    feature_preprocessing_timeout: float = Field(default=5.0, env="FEATURE_PREPROCESSING_TIMEOUT")
    
    # Logging configuration
    log_level: str = Field(default="INFO", env="LOG_LEVEL")
    log_format: str = Field(default="json", env="LOG_FORMAT")  # json or text
    log_file: Optional[str] = Field(default=None, env="LOG_FILE")
    
    # Security configuration
    cors_origins: str = Field(default="*", env="CORS_ORIGINS")
    api_key_header: str = Field(default="X-API-Key", env="API_KEY_HEADER")
    require_api_key: bool = Field(default=False, env="REQUIRE_API_KEY")
    api_keys: str = Field(default="", env="API_KEYS")  # Comma-separated list
    
    # Monitoring configuration
    enable_metrics: bool = Field(default=True, env="ENABLE_METRICS")
    metrics_port: int = Field(default=8001, env="METRICS_PORT")
    health_check_interval: int = Field(default=30, env="HEALTH_CHECK_INTERVAL")
    
    # External service configuration
    laravel_api_url: str = Field(default="http://localhost:8080", env="LARAVEL_API_URL")
    laravel_api_timeout: float = Field(default=10.0, env="LARAVEL_API_TIMEOUT")
    
    # Feature engineering configuration
    feature_validation_strict: bool = Field(default=True, env="FEATURE_VALIDATION_STRICT")
    feature_imputation_strategy: str = Field(default="default", env="FEATURE_IMPUTATION_STRATEGY")  # default, mean, median
    
    # Model performance thresholds
    min_confidence_threshold: float = Field(default=0.1, env="MIN_CONFIDENCE_THRESHOLD")
    max_prediction_time_ms: float = Field(default=100.0, env="MAX_PREDICTION_TIME_MS")
    
    # Resource limits
    max_memory_mb: int = Field(default=2048, env="MAX_MEMORY_MB")
    max_cpu_percent: float = Field(default=80.0, env="MAX_CPU_PERCENT")
    
    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = False
    
    def get_cors_origins(self) -> list:
        """Get CORS origins as a list"""
        if self.cors_origins == "*":
            return ["*"]
        return [origin.strip() for origin in self.cors_origins.split(",") if origin.strip()]
    
    def get_api_keys(self) -> set:
        """Get API keys as a set"""
        if not self.api_keys:
            return set()
        return {key.strip() for key in self.api_keys.split(",") if key.strip()}
    
    def is_production(self) -> bool:
        """Check if running in production environment"""
        return self.environment.lower() in ("production", "prod")
    
    def is_development(self) -> bool:
        """Check if running in development environment"""
        return self.environment.lower() in ("development", "dev")
    
    def get_model_path_absolute(self) -> str:
        """Get absolute path to model directory"""
        if os.path.isabs(self.model_path):
            return self.model_path
        return os.path.abspath(self.model_path)


@lru_cache()
def get_settings() -> Settings:
    """Get cached settings instance"""
    return Settings()


# Environment-specific configurations
class DevelopmentSettings(Settings):
    """Development environment settings"""
    debug: bool = True
    log_level: str = "DEBUG"
    workers: int = 1
    require_api_key: bool = False


class ProductionSettings(Settings):
    """Production environment settings"""
    debug: bool = False
    log_level: str = "INFO"
    workers: int = 4
    require_api_key: bool = True
    cors_origins: str = "https://api.example.com"


class TestingSettings(Settings):
    """Testing environment settings"""
    debug: bool = True
    log_level: str = "DEBUG"
    model_path: str = "tests/fixtures/models/"
    require_api_key: bool = False
    enable_metrics: bool = False


def get_environment_settings() -> Settings:
    """Get settings based on environment"""
    env = os.getenv("ENVIRONMENT", "development").lower()
    
    if env in ("production", "prod"):
        return ProductionSettings()
    elif env in ("testing", "test"):
        return TestingSettings()
    else:
        return DevelopmentSettings()


# Configuration validation
def validate_settings(settings: Settings) -> list:
    """Validate settings and return list of issues"""
    issues = []
    
    # Validate model path
    if not os.path.exists(settings.get_model_path_absolute()):
        issues.append(f"Model path does not exist: {settings.model_path}")
    
    # Validate port ranges
    if not (1024 <= settings.port <= 65535):
        issues.append(f"Invalid port number: {settings.port}")
    
    if not (1024 <= settings.metrics_port <= 65535):
        issues.append(f"Invalid metrics port number: {settings.metrics_port}")
    
    # Validate log level
    valid_log_levels = {"DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"}
    if settings.log_level.upper() not in valid_log_levels:
        issues.append(f"Invalid log level: {settings.log_level}")
    
    # Validate thresholds
    if not (0.0 <= settings.min_confidence_threshold <= 1.0):
        issues.append(f"Invalid confidence threshold: {settings.min_confidence_threshold}")
    
    if settings.max_prediction_time_ms <= 0:
        issues.append(f"Invalid max prediction time: {settings.max_prediction_time_ms}")
    
    # Validate batch size
    if settings.max_batch_size <= 0:
        issues.append(f"Invalid max batch size: {settings.max_batch_size}")
    
    # Validate timeouts
    if settings.prediction_timeout <= 0:
        issues.append(f"Invalid prediction timeout: {settings.prediction_timeout}")
    
    if settings.feature_preprocessing_timeout <= 0:
        issues.append(f"Invalid feature preprocessing timeout: {settings.feature_preprocessing_timeout}")
    
    # Production-specific validations
    if settings.is_production():
        if settings.debug:
            issues.append("Debug mode should be disabled in production")
        
        if not settings.require_api_key:
            issues.append("API key authentication should be required in production")
        
        if settings.cors_origins == "*":
            issues.append("CORS should be restricted in production")
    
    return issues


# Configuration helpers
def get_log_config(settings: Settings) -> dict:
    """Get logging configuration dictionary"""
    config = {
        "version": 1,
        "disable_existing_loggers": False,
        "formatters": {
            "json": {
                "class": "pythonjsonlogger.jsonlogger.JsonFormatter",
                "format": "%(asctime)s %(name)s %(levelname)s %(message)s"
            },
            "text": {
                "format": "%(asctime)s - %(name)s - %(levelname)s - %(message)s",
                "datefmt": "%Y-%m-%d %H:%M:%S"
            }
        },
        "handlers": {
            "console": {
                "class": "logging.StreamHandler",
                "level": settings.log_level,
                "formatter": settings.log_format,
                "stream": "ext://sys.stdout"
            }
        },
        "loggers": {
            "": {
                "level": settings.log_level,
                "handlers": ["console"],
                "propagate": False
            },
            "uvicorn": {
                "level": "INFO",
                "handlers": ["console"],
                "propagate": False
            },
            "uvicorn.access": {
                "level": "INFO",
                "handlers": ["console"],
                "propagate": False
            }
        }
    }
    
    # Add file handler if log file is specified
    if settings.log_file:
        config["handlers"]["file"] = {
            "class": "logging.handlers.RotatingFileHandler",
            "level": settings.log_level,
            "formatter": settings.log_format,
            "filename": settings.log_file,
            "maxBytes": 10485760,  # 10MB
            "backupCount": 5
        }
        
        # Add file handler to all loggers
        for logger_config in config["loggers"].values():
            logger_config["handlers"].append("file")
    
    return config


def print_startup_info(settings: Settings) -> None:
    """Print startup information"""
    print(f"🚀 Starting {settings.service_name} v{settings.service_version}")
    print(f"📊 Environment: {settings.environment}")
    print(f"🌐 Server: {settings.host}:{settings.port}")
    print(f"🤖 Model path: {settings.get_model_path_absolute()}")
    print(f"📝 Log level: {settings.log_level}")
    print(f"🔒 API key required: {settings.require_api_key}")
    print(f"📈 Metrics enabled: {settings.enable_metrics}")
    
    # Validate configuration
    issues = validate_settings(settings)
    if issues:
        print("⚠️  Configuration issues:")
        for issue in issues:
            print(f"   - {issue}")
    else:
        print("✅ Configuration validated successfully")
