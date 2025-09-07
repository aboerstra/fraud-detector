"""
Feature preprocessing service for converting raw data to model features
"""

import logging
import time
from typing import Dict, List, Optional, Any
import numpy as np
from datetime import datetime, date

logger = logging.getLogger(__name__)


class FeatureService:
    """Service for preprocessing raw features into model-ready format"""
    
    def __init__(self):
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
        
        # Feature bounds for validation and normalization
        self.feature_bounds = {
            "credit_score": (300, 850),
            "debt_to_income_ratio": (0, 100),
            "loan_to_value_ratio": (0, 150),
            "employment_months": (0, 600),
            "annual_income": (0, 1000000),
            "vehicle_age": (0, 50),
            "credit_history_years": (0, 50),
            "delinquencies_24m": (0, 20),
            "loan_amount": (1000, 200000),
            "vehicle_value": (1000, 500000),
            "credit_utilization": (0, 100),
            "recent_inquiries_6m": (0, 20),
            "address_months": (0, 600),
            "loan_term_months": (12, 120),
            "applicant_age": (18, 100)
        }
    
    async def preprocess_features(self, raw_features: Dict[str, Any]) -> List[float]:
        """
        Convert raw feature data to model-ready feature vector
        
        Args:
            raw_features: Dictionary of raw feature data
            
        Returns:
            List of 15 preprocessed features
        """
        try:
            logger.debug("Preprocessing raw features")
            
            # Extract and calculate features
            features = {}
            
            # 1. Credit Score
            features["credit_score"] = self._extract_credit_score(raw_features)
            
            # 2. Debt-to-Income Ratio
            features["debt_to_income_ratio"] = self._calculate_debt_to_income_ratio(raw_features)
            
            # 3. Loan-to-Value Ratio
            features["loan_to_value_ratio"] = self._calculate_loan_to_value_ratio(raw_features)
            
            # 4. Employment Months
            features["employment_months"] = self._extract_employment_months(raw_features)
            
            # 5. Annual Income
            features["annual_income"] = self._extract_annual_income(raw_features)
            
            # 6. Vehicle Age
            features["vehicle_age"] = self._calculate_vehicle_age(raw_features)
            
            # 7. Credit History Years
            features["credit_history_years"] = self._extract_credit_history_years(raw_features)
            
            # 8. Delinquencies (24 months)
            features["delinquencies_24m"] = self._extract_delinquencies(raw_features)
            
            # 9. Loan Amount
            features["loan_amount"] = self._extract_loan_amount(raw_features)
            
            # 10. Vehicle Value
            features["vehicle_value"] = self._extract_vehicle_value(raw_features)
            
            # 11. Credit Utilization
            features["credit_utilization"] = self._extract_credit_utilization(raw_features)
            
            # 12. Recent Inquiries (6 months)
            features["recent_inquiries_6m"] = self._extract_recent_inquiries(raw_features)
            
            # 13. Address Months
            features["address_months"] = self._extract_address_months(raw_features)
            
            # 14. Loan Term Months
            features["loan_term_months"] = self._extract_loan_term_months(raw_features)
            
            # 15. Applicant Age
            features["applicant_age"] = self._calculate_applicant_age(raw_features)
            
            # Validate and normalize features
            feature_vector = []
            for feature_name in self.feature_names:
                value = features.get(feature_name, 0.0)
                normalized_value = self._normalize_feature(feature_name, value)
                feature_vector.append(normalized_value)
            
            logger.debug(f"Preprocessed {len(feature_vector)} features")
            return feature_vector
            
        except Exception as e:
            logger.error(f"Feature preprocessing failed: {e}")
            raise ValueError(f"Feature preprocessing failed: {str(e)}")
    
    def _extract_credit_score(self, data: Dict[str, Any]) -> float:
        """Extract credit score from raw data"""
        # Try multiple possible paths
        paths = [
            ["credit_score"],
            ["applicant", "credit_score"],
            ["personal_info", "credit_score"],
            ["financial_info", "credit_score"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        # Default credit score if not found
        return 650.0
    
    def _calculate_debt_to_income_ratio(self, data: Dict[str, Any]) -> float:
        """Calculate debt-to-income ratio"""
        try:
            # Get loan amount and term
            loan_amount = self._extract_loan_amount(data)
            loan_term = self._extract_loan_term_months(data)
            annual_income = self._extract_annual_income(data)
            
            if annual_income <= 0 or loan_term <= 0:
                return 35.0  # Default ratio
            
            # Calculate monthly payment (simplified)
            monthly_payment = loan_amount / loan_term
            monthly_income = annual_income / 12
            
            ratio = (monthly_payment / monthly_income) * 100
            return min(ratio, 100.0)  # Cap at 100%
            
        except Exception:
            return 35.0  # Default ratio
    
    def _calculate_loan_to_value_ratio(self, data: Dict[str, Any]) -> float:
        """Calculate loan-to-value ratio"""
        try:
            loan_amount = self._extract_loan_amount(data)
            vehicle_value = self._extract_vehicle_value(data)
            
            if vehicle_value <= 0:
                return 85.0  # Default ratio
            
            ratio = (loan_amount / vehicle_value) * 100
            return min(ratio, 150.0)  # Cap at 150%
            
        except Exception:
            return 85.0  # Default ratio
    
    def _extract_employment_months(self, data: Dict[str, Any]) -> float:
        """Extract employment months"""
        paths = [
            ["employment_months"],
            ["applicant", "employment_months"],
            ["financial_info", "employment_months"],
            ["personal_info", "employment_months"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 24.0  # Default 2 years
    
    def _extract_annual_income(self, data: Dict[str, Any]) -> float:
        """Extract annual income"""
        paths = [
            ["annual_income"],
            ["applicant", "annual_income"],
            ["financial_info", "annual_income"],
            ["personal_info", "annual_income"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 50000.0  # Default income
    
    def _calculate_vehicle_age(self, data: Dict[str, Any]) -> float:
        """Calculate vehicle age"""
        try:
            # Try to get vehicle year
            paths = [
                ["vehicle_year"],
                ["vehicle", "year"],
                ["vehicle_info", "year"]
            ]
            
            vehicle_year = None
            for path in paths:
                value = self._get_nested_value(data, path)
                if value is not None:
                    vehicle_year = int(value)
                    break
            
            if vehicle_year:
                current_year = datetime.now().year
                age = current_year - vehicle_year
                return max(0.0, float(age))
            
            return 5.0  # Default age
            
        except Exception:
            return 5.0  # Default age
    
    def _extract_credit_history_years(self, data: Dict[str, Any]) -> float:
        """Extract credit history years"""
        paths = [
            ["credit_history_years"],
            ["applicant", "credit_history_years"],
            ["financial_info", "credit_history_years"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 7.0  # Default history
    
    def _extract_delinquencies(self, data: Dict[str, Any]) -> float:
        """Extract delinquencies in last 24 months"""
        paths = [
            ["delinquencies_24m"],
            ["applicant", "delinquencies_24m"],
            ["financial_info", "delinquencies_24m"],
            ["credit_info", "delinquencies_24m"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 1.0  # Default delinquencies
    
    def _extract_loan_amount(self, data: Dict[str, Any]) -> float:
        """Extract loan amount"""
        paths = [
            ["loan_amount"],
            ["loan", "amount"],
            ["loan_info", "amount"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 25000.0  # Default loan amount
    
    def _extract_vehicle_value(self, data: Dict[str, Any]) -> float:
        """Extract vehicle value"""
        paths = [
            ["vehicle_value"],
            ["vehicle", "value"],
            ["vehicle", "estimated_value"],
            ["vehicle_info", "value"],
            ["vehicle_info", "estimated_value"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 30000.0  # Default vehicle value
    
    def _extract_credit_utilization(self, data: Dict[str, Any]) -> float:
        """Extract credit utilization percentage"""
        paths = [
            ["credit_utilization"],
            ["applicant", "credit_utilization"],
            ["financial_info", "credit_utilization"],
            ["credit_info", "utilization"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 30.0  # Default utilization
    
    def _extract_recent_inquiries(self, data: Dict[str, Any]) -> float:
        """Extract recent credit inquiries (6 months)"""
        paths = [
            ["recent_inquiries_6m"],
            ["applicant", "recent_inquiries_6m"],
            ["financial_info", "recent_inquiries_6m"],
            ["credit_info", "recent_inquiries_6m"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 1.0  # Default inquiries
    
    def _extract_address_months(self, data: Dict[str, Any]) -> float:
        """Extract months at current address"""
        paths = [
            ["address_months"],
            ["applicant", "address_months"],
            ["personal_info", "address_months"],
            ["address", "months"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 24.0  # Default 2 years
    
    def _extract_loan_term_months(self, data: Dict[str, Any]) -> float:
        """Extract loan term in months"""
        paths = [
            ["loan_term_months"],
            ["loan", "term_months"],
            ["loan_info", "term_months"]
        ]
        
        for path in paths:
            value = self._get_nested_value(data, path)
            if value is not None:
                return float(value)
        
        return 60.0  # Default 5 years
    
    def _calculate_applicant_age(self, data: Dict[str, Any]) -> float:
        """Calculate applicant age from date of birth"""
        try:
            # Try to get date of birth
            paths = [
                ["age"],
                ["applicant", "age"],
                ["personal_info", "age"],
                ["date_of_birth"],
                ["applicant", "date_of_birth"],
                ["personal_info", "date_of_birth"]
            ]
            
            # First try direct age
            for path in paths[:3]:
                value = self._get_nested_value(data, path)
                if value is not None:
                    return float(value)
            
            # Try to calculate from date of birth
            for path in paths[3:]:
                dob = self._get_nested_value(data, path)
                if dob is not None:
                    if isinstance(dob, str):
                        try:
                            # Parse date string
                            if 'T' in dob:
                                dob_date = datetime.fromisoformat(dob.replace('Z', '+00:00')).date()
                            else:
                                dob_date = datetime.strptime(dob, '%Y-%m-%d').date()
                            
                            today = date.today()
                            age = today.year - dob_date.year - ((today.month, today.day) < (dob_date.month, dob_date.day))
                            return float(age)
                        except Exception:
                            continue
            
            return 35.0  # Default age
            
        except Exception:
            return 35.0  # Default age
    
    def _get_nested_value(self, data: Dict[str, Any], path: List[str]) -> Any:
        """Get value from nested dictionary using path"""
        try:
            current = data
            for key in path:
                if isinstance(current, dict) and key in current:
                    current = current[key]
                else:
                    return None
            return current
        except Exception:
            return None
    
    def _normalize_feature(self, feature_name: str, value: float) -> float:
        """Normalize feature value to reasonable bounds"""
        if feature_name in self.feature_bounds:
            min_val, max_val = self.feature_bounds[feature_name]
            # Clamp to bounds
            value = max(min_val, min(max_val, value))
        
        return value
    
    def validate_feature_vector(self, features: List[float]) -> bool:
        """Validate that feature vector is complete and reasonable"""
        try:
            if len(features) != 15:
                return False
            
            for i, (feature_name, value) in enumerate(zip(self.feature_names, features)):
                if not isinstance(value, (int, float)):
                    return False
                
                if np.isnan(value) or np.isinf(value):
                    return False
                
                # Check bounds
                if feature_name in self.feature_bounds:
                    min_val, max_val = self.feature_bounds[feature_name]
                    if value < min_val or value > max_val:
                        return False
            
            return True
            
        except Exception:
            return False
    
    def get_feature_info(self) -> Dict[str, Any]:
        """Get information about expected features"""
        return {
            "feature_names": self.feature_names,
            "feature_count": len(self.feature_names),
            "feature_bounds": self.feature_bounds,
            "description": "Top-15 features for Canadian auto loan fraud detection"
        }
