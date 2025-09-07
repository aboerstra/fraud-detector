"""
Logging configuration for the ML service
"""

import logging
import logging.config
import sys
from typing import Dict, Any
import json
from datetime import datetime

from .config import get_settings


class JSONFormatter(logging.Formatter):
    """Custom JSON formatter for structured logging"""
    
    def format(self, record: logging.LogRecord) -> str:
        """Format log record as JSON"""
        log_entry = {
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "module": record.module,
            "function": record.funcName,
            "line": record.lineno,
        }
        
        # Add exception info if present
        if record.exc_info:
            log_entry["exception"] = self.formatException(record.exc_info)
        
        # Add extra fields from record
        for key, value in record.__dict__.items():
            if key not in {
                "name", "msg", "args", "levelname", "levelno", "pathname",
                "filename", "module", "lineno", "funcName", "created",
                "msecs", "relativeCreated", "thread", "threadName",
                "processName", "process", "getMessage", "exc_info",
                "exc_text", "stack_info"
            }:
                log_entry[key] = value
        
        return json.dumps(log_entry, default=str)


class ContextFilter(logging.Filter):
    """Filter to add context information to log records"""
    
    def __init__(self, service_name: str, service_version: str):
        super().__init__()
        self.service_name = service_name
        self.service_version = service_version
    
    def filter(self, record: logging.LogRecord) -> bool:
        """Add context fields to log record"""
        record.service_name = self.service_name
        record.service_version = self.service_version
        return True


def setup_logging() -> None:
    """Setup logging configuration"""
    settings = get_settings()
    
    # Create formatters
    formatters = {
        "json": {
            "()": JSONFormatter,
        },
        "text": {
            "format": "%(asctime)s - %(name)s - %(levelname)s - %(message)s",
            "datefmt": "%Y-%m-%d %H:%M:%S"
        }
    }
    
    # Create handlers
    handlers = {
        "console": {
            "class": "logging.StreamHandler",
            "level": settings.log_level,
            "formatter": settings.log_format,
            "stream": sys.stdout,
            "filters": ["context"]
        }
    }
    
    # Add file handler if specified
    if settings.log_file:
        handlers["file"] = {
            "class": "logging.handlers.RotatingFileHandler",
            "level": settings.log_level,
            "formatter": settings.log_format,
            "filename": settings.log_file,
            "maxBytes": 10 * 1024 * 1024,  # 10MB
            "backupCount": 5,
            "filters": ["context"]
        }
    
    # Create filters
    filters = {
        "context": {
            "()": ContextFilter,
            "service_name": settings.service_name,
            "service_version": settings.service_version
        }
    }
    
    # Create loggers
    loggers = {
        "": {  # Root logger
            "level": settings.log_level,
            "handlers": list(handlers.keys()),
            "propagate": False
        },
        "uvicorn": {
            "level": "INFO",
            "handlers": ["console"],
            "propagate": False
        },
        "uvicorn.access": {
            "level": "INFO" if not settings.debug else "DEBUG",
            "handlers": ["console"],
            "propagate": False
        },
        "uvicorn.error": {
            "level": "INFO",
            "handlers": ["console"],
            "propagate": False
        },
        "fastapi": {
            "level": "INFO",
            "handlers": ["console"],
            "propagate": False
        }
    }
    
    # Configure logging
    config = {
        "version": 1,
        "disable_existing_loggers": False,
        "formatters": formatters,
        "filters": filters,
        "handlers": handlers,
        "loggers": loggers
    }
    
    logging.config.dictConfig(config)
    
    # Set specific log levels for noisy libraries
    logging.getLogger("urllib3").setLevel(logging.WARNING)
    logging.getLogger("requests").setLevel(logging.WARNING)
    logging.getLogger("httpx").setLevel(logging.WARNING)
    
    # Log startup message
    logger = logging.getLogger(__name__)
    logger.info(
        "Logging configured",
        extra={
            "log_level": settings.log_level,
            "log_format": settings.log_format,
            "log_file": settings.log_file
        }
    )


def get_logger(name: str) -> logging.Logger:
    """Get a logger with the specified name"""
    return logging.getLogger(name)


class RequestLogger:
    """Logger for HTTP requests"""
    
    def __init__(self):
        self.logger = get_logger("request")
    
    def log_request(
        self,
        method: str,
        path: str,
        status_code: int,
        duration_ms: float,
        request_id: str = None,
        user_agent: str = None,
        client_ip: str = None
    ) -> None:
        """Log HTTP request"""
        self.logger.info(
            f"{method} {path} - {status_code}",
            extra={
                "request_method": method,
                "request_path": path,
                "response_status": status_code,
                "duration_ms": duration_ms,
                "request_id": request_id,
                "user_agent": user_agent,
                "client_ip": client_ip,
                "event_type": "http_request"
            }
        )


class ModelLogger:
    """Logger for model operations"""
    
    def __init__(self):
        self.logger = get_logger("model")
    
    def log_prediction(
        self,
        request_id: str,
        model_version: str,
        fraud_probability: float,
        confidence_score: float,
        processing_time_ms: float,
        feature_count: int = None
    ) -> None:
        """Log model prediction"""
        self.logger.info(
            f"Prediction completed: {fraud_probability:.3f}",
            extra={
                "request_id": request_id,
                "model_version": model_version,
                "fraud_probability": fraud_probability,
                "confidence_score": confidence_score,
                "processing_time_ms": processing_time_ms,
                "feature_count": feature_count,
                "event_type": "model_prediction"
            }
        )
    
    def log_model_load(
        self,
        model_version: str,
        model_type: str,
        load_time_ms: float,
        success: bool = True,
        error: str = None
    ) -> None:
        """Log model loading"""
        level = logging.INFO if success else logging.ERROR
        message = f"Model {model_version} loaded" if success else f"Failed to load model {model_version}"
        
        self.logger.log(
            level,
            message,
            extra={
                "model_version": model_version,
                "model_type": model_type,
                "load_time_ms": load_time_ms,
                "success": success,
                "error": error,
                "event_type": "model_load"
            }
        )
    
    def log_feature_preprocessing(
        self,
        request_id: str,
        feature_count: int,
        processing_time_ms: float,
        success: bool = True,
        error: str = None
    ) -> None:
        """Log feature preprocessing"""
        level = logging.INFO if success else logging.ERROR
        message = f"Features preprocessed: {feature_count}" if success else "Feature preprocessing failed"
        
        self.logger.log(
            level,
            message,
            extra={
                "request_id": request_id,
                "feature_count": feature_count,
                "processing_time_ms": processing_time_ms,
                "success": success,
                "error": error,
                "event_type": "feature_preprocessing"
            }
        )


class PerformanceLogger:
    """Logger for performance metrics"""
    
    def __init__(self):
        self.logger = get_logger("performance")
    
    def log_metrics(
        self,
        total_predictions: int,
        average_response_time_ms: float,
        predictions_per_second: float,
        error_rate: float,
        memory_usage_mb: float,
        cpu_usage_percent: float
    ) -> None:
        """Log performance metrics"""
        self.logger.info(
            "Performance metrics",
            extra={
                "total_predictions": total_predictions,
                "average_response_time_ms": average_response_time_ms,
                "predictions_per_second": predictions_per_second,
                "error_rate": error_rate,
                "memory_usage_mb": memory_usage_mb,
                "cpu_usage_percent": cpu_usage_percent,
                "event_type": "performance_metrics"
            }
        )
    
    def log_slow_request(
        self,
        request_id: str,
        endpoint: str,
        duration_ms: float,
        threshold_ms: float
    ) -> None:
        """Log slow requests"""
        self.logger.warning(
            f"Slow request detected: {endpoint}",
            extra={
                "request_id": request_id,
                "endpoint": endpoint,
                "duration_ms": duration_ms,
                "threshold_ms": threshold_ms,
                "event_type": "slow_request"
            }
        )


class SecurityLogger:
    """Logger for security events"""
    
    def __init__(self):
        self.logger = get_logger("security")
    
    def log_authentication_failure(
        self,
        client_ip: str,
        user_agent: str = None,
        reason: str = None
    ) -> None:
        """Log authentication failures"""
        self.logger.warning(
            "Authentication failed",
            extra={
                "client_ip": client_ip,
                "user_agent": user_agent,
                "reason": reason,
                "event_type": "auth_failure"
            }
        )
    
    def log_rate_limit_exceeded(
        self,
        client_ip: str,
        endpoint: str,
        limit: int,
        window_seconds: int
    ) -> None:
        """Log rate limit violations"""
        self.logger.warning(
            "Rate limit exceeded",
            extra={
                "client_ip": client_ip,
                "endpoint": endpoint,
                "limit": limit,
                "window_seconds": window_seconds,
                "event_type": "rate_limit_exceeded"
            }
        )


# Global logger instances
request_logger = RequestLogger()
model_logger = ModelLogger()
performance_logger = PerformanceLogger()
security_logger = SecurityLogger()
