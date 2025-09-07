# Decision Engine Component

## Overview
The decision engine combines outputs from rules, ML scoring, and LLM adjudication to make final fraud decisions using configurable policy thresholds and explainable reasoning.

## Decision Logic Architecture

### Input Sources
The decision engine receives three primary inputs:

1. **Rules Output**
   - `rule_score` (0-1): Weighted risk score from business rules
   - `rule_flags[]`: Array of triggered rule identifiers
   - `rulepack_version`: Version of rules configuration

2. **ML Output**
   - `confidence_score` (0-1): Calibrated fraud probability
   - `top_features[]`: Most important features for this prediction
   - `model_version`: ML model version used

3. **Adjudicator Output**
   - `adjudicator_score` (0-1): LLM risk assessment
   - `risk_band`: Low/medium/high risk classification
   - `rationale[]`: LLM explanation bullets

### Decision Flow
```python
class DecisionEngine:
    def __init__(self, policy_config: PolicyConfig):
        self.policy = policy_config
        self.version = policy_config.version
    
    def make_decision(self, inputs: DecisionInputs) -> DecisionResult:
        """Main decision logic"""
        
        # 1. Check for hard-fail rules first
        if self.has_hard_fail_rules(inputs.rules_output):
            return self.create_decline_decision(
                reason="hard_fail_rule",
                details=inputs.rules_output.hard_fail_reasons
            )
        
        # 2. Apply decision policy thresholds
        decision = self.apply_policy_thresholds(inputs)
        
        # 3. Generate explanations
        explanations = self.generate_explanations(inputs, decision)
        
        # 4. Assemble final result
        return DecisionResult(
            final_decision=decision,
            reasons=explanations,
            scores=self.extract_scores(inputs),
            policy_version=self.version,
            timestamp=datetime.utcnow()
        )
```

## Policy Configuration

### Threshold Configuration
```json
{
  "policy_version": "v1.3.0",
  "created_at": "2024-01-15T10:00:00Z",
  "thresholds": {
    "rule_score": {
      "decline_threshold": 0.8,
      "review_threshold": 0.6
    },
    "ml_confidence": {
      "decline_threshold": 0.85,
      "review_threshold": 0.7
    },
    "adjudicator_score": {
      "review_threshold": 0.75
    },
    "combined_logic": {
      "high_confidence_auto_approve": 0.3,
      "any_high_score_review": true,
      "adjudicator_override_enabled": false
    }
  },
  "hard_fail_rules": [
    "invalid_sin_checksum",
    "pep_list_hit",
    "sanctions_list_hit",
    "mandatory_field_missing"
  ]
}
```

### Decision Matrix
```python
def apply_policy_thresholds(self, inputs: DecisionInputs) -> str:
    """Apply policy thresholds to determine decision"""
    
    rule_score = inputs.rules_output.rule_score
    ml_score = inputs.ml_output.confidence_score
    adj_score = inputs.adjudicator_output.adjudicator_score if inputs.adjudicator_output else None
    
    # High confidence auto-approve (low risk)
    if (rule_score < self.policy.thresholds.rule_score.review_threshold and 
        ml_score < self.policy.thresholds.ml_confidence.review_threshold):
        return "approve"
    
    # Any score above decline threshold
    if (rule_score >= self.policy.thresholds.rule_score.decline_threshold or
        ml_score >= self.policy.thresholds.ml_confidence.decline_threshold):
        return "decline"
    
    # Any score above review threshold
    if (rule_score >= self.policy.thresholds.rule_score.review_threshold or
        ml_score >= self.policy.thresholds.ml_confidence.review_threshold):
        return "review"
    
    # Adjudicator escalation (if enabled and available)
    if (adj_score and 
        adj_score >= self.policy.thresholds.adjudicator_score.review_threshold):
        return "review"
    
    # Default to approve for low scores
    return "approve"
```

## Explanation Generation

### Reason Code Mapping
```python
class ExplanationGenerator:
    def __init__(self):
        self.rule_explanations = {
            "province_ip_mismatch": "Geographic inconsistency detected",
            "disposable_email": "Temporary email address used",
            "vin_reuse": "Vehicle VIN previously seen",
            "high_ltv": "Loan-to-value ratio exceeds limits",
            "dealer_volume_spike": "Unusual dealer activity pattern"
        }
        
        self.ml_feature_explanations = {
            "dealer_fraud_percentile": "Dealer risk profile",
            "ltv_ratio": "Loan-to-value assessment",
            "age": "Applicant age factor",
            "phone_reuse_count": "Contact information reuse",
            "email_reuse_count": "Email address reuse"
        }
    
    def generate_explanations(self, inputs: DecisionInputs, decision: str) -> List[str]:
        """Generate human-readable explanations"""
        
        explanations = []
        
        # Add rule-based explanations
        for rule_flag in inputs.rules_output.rule_flags:
            if rule_flag in self.rule_explanations:
                explanations.append(self.rule_explanations[rule_flag])
        
        # Add ML feature explanations
        for feature in inputs.ml_output.top_features[:3]:
            feature_name = feature['feature_name']
            if feature_name in self.ml_feature_explanations:
                explanations.append(
                    f"{self.ml_feature_explanations[feature_name]} "
                    f"(importance: {feature['importance']:.2f})"
                )
        
        # Add adjudicator rationale
        if inputs.adjudicator_output and inputs.adjudicator_output.rationale:
            explanations.extend(inputs.adjudicator_output.rationale)
        
        # Add decision-specific context
        if decision == "decline":
            explanations.append("Risk level exceeds acceptable thresholds")
        elif decision == "review":
            explanations.append("Manual review recommended due to elevated risk")
        elif decision == "approve":
            explanations.append("Risk assessment within acceptable parameters")
        
        return explanations[:5]  # Limit to top 5 explanations
```

### Score Banding
```python
def calculate_score_bands(self, scores: Dict[str, float]) -> Dict[str, str]:
    """Convert numeric scores to risk bands"""
    
    bands = {}
    
    # Rule score banding
    rule_score = scores.get('rule_score', 0)
    if rule_score >= 0.8:
        bands['rule_band'] = 'high'
    elif rule_score >= 0.6:
        bands['rule_band'] = 'medium'
    else:
        bands['rule_band'] = 'low'
    
    # ML confidence banding
    ml_score = scores.get('confidence_score', 0)
    if ml_score >= 0.85:
        bands['confidence_band'] = 'high'
    elif ml_score >= 0.7:
        bands['confidence_band'] = 'medium'
    else:
        bands['confidence_band'] = 'low'
    
    # Adjudicator banding (if available)
    adj_score = scores.get('adjudicator_score')
    if adj_score is not None:
        if adj_score >= 0.75:
            bands['adjudicator_band'] = 'high'
        elif adj_score >= 0.5:
            bands['adjudicator_band'] = 'medium'
        else:
            bands['adjudicator_band'] = 'low'
    
    return bands
```

## Decision Result Structure

### DecisionResult Class
```python
@dataclass
class DecisionResult:
    final_decision: str  # approve|review|decline
    reasons: List[str]
    scores: Dict[str, float]
    score_bands: Dict[str, str]
    policy_version: str
    processing_stages: Dict[str, datetime]
    total_processing_time_ms: int
    versions: Dict[str, str]
    
    def to_api_response(self) -> Dict:
        """Convert to API response format"""
        return {
            "decision": {
                "final_decision": self.final_decision,
                "reasons": self.reasons
            },
            "scores": {
                "rule_score": self.scores.get("rule_score"),
                "rule_band": self.score_bands.get("rule_band"),
                "confidence_score": self.scores.get("confidence_score"),
                "confidence_band": self.score_bands.get("confidence_band"),
                "adjudicator_score": self.scores.get("adjudicator_score"),
                "adjudicator_band": self.score_bands.get("adjudicator_band")
            },
            "explainability": {
                "rule_flags": self.get_rule_flags(),
                "top_features": self.get_top_features(),
                "adjudicator_rationale": self.get_adjudicator_rationale()
            },
            "versions": self.versions,
            "timing": {
                "received_at": self.processing_stages.get("received_at"),
                "queued_at": self.processing_stages.get("queued_at"),
                "started_at": self.processing_stages.get("started_at"),
                "ml_scored_at": self.processing_stages.get("ml_scored_at"),
                "adjudicated_at": self.processing_stages.get("adjudicated_at"),
                "decided_at": self.processing_stages.get("decided_at"),
                "total_ms": self.total_processing_time_ms
            }
        }
```

## Policy Management

### Policy Versioning
```python
class PolicyManager:
    def __init__(self, db_connection):
        self.db = db_connection
        self.current_policy = None
        self.cache_ttl = 300  # 5 minutes
        
    def get_current_policy(self) -> PolicyConfig:
        """Get current active policy configuration"""
        
        if self.is_cache_valid():
            return self.current_policy
        
        # Load from database
        policy_data = self.db.query("""
            SELECT config_json, version 
            FROM decision_policies 
            WHERE is_active = true 
            ORDER BY created_at DESC 
            LIMIT 1
        """).fetchone()
        
        if not policy_data:
            raise PolicyError("No active policy configuration found")
        
        self.current_policy = PolicyConfig.from_json(
            policy_data['config_json'],
            policy_data['version']
        )
        
        return self.current_policy
    
    def update_policy(self, new_policy: PolicyConfig) -> bool:
        """Deploy new policy configuration"""
        
        try:
            # Validate policy
            self.validate_policy(new_policy)
            
            # Deactivate current policy
            self.db.execute("""
                UPDATE decision_policies 
                SET is_active = false 
                WHERE is_active = true
            """)
            
            # Insert new policy
            self.db.execute("""
                INSERT INTO decision_policies 
                (version, config_json, is_active, created_at)
                VALUES (?, ?, true, ?)
            """, (new_policy.version, new_policy.to_json(), datetime.utcnow()))
            
            # Clear cache
            self.current_policy = None
            
            return True
            
        except Exception as e:
            logger.error(f"Policy update failed: {str(e)}")
            self.db.rollback()
            return False
```

### Policy Validation
```python
def validate_policy(self, policy: PolicyConfig) -> None:
    """Validate policy configuration"""
    
    # Check threshold ranges
    thresholds = policy.thresholds
    
    if not (0 <= thresholds.rule_score.review_threshold <= 1):
        raise PolicyValidationError("Rule review threshold must be 0-1")
    
    if not (0 <= thresholds.ml_confidence.decline_threshold <= 1):
        raise PolicyValidationError("ML decline threshold must be 0-1")
    
    # Check threshold ordering
    if thresholds.rule_score.review_threshold >= thresholds.rule_score.decline_threshold:
        raise PolicyValidationError("Review threshold must be less than decline threshold")
    
    # Validate hard-fail rules exist
    for rule_id in policy.hard_fail_rules:
        if not self.rule_exists(rule_id):
            raise PolicyValidationError(f"Hard-fail rule '{rule_id}' not found")
    
    # Check version format
    if not re.match(r'^v\d+\.\d+\.\d+$', policy.version):
        raise PolicyValidationError("Policy version must follow semantic versioning (vX.Y.Z)")
```

## A/B Testing Support

### Policy Experiments
```python
class PolicyExperiment:
    def __init__(self, experiment_config):
        self.experiment_id = experiment_config['experiment_id']
        self.control_policy = experiment_config['control_policy']
        self.treatment_policy = experiment_config['treatment_policy']
        self.traffic_split = experiment_config['traffic_split']  # 0.0-1.0
        self.start_date = experiment_config['start_date']
        self.end_date = experiment_config['end_date']
    
    def should_use_treatment(self, request_id: str) -> bool:
        """Determine if request should use treatment policy"""
        
        # Hash-based consistent assignment
        hash_value = hashlib.md5(request_id.encode()).hexdigest()
        hash_int = int(hash_value[:8], 16)
        assignment_value = (hash_int % 10000) / 10000.0
        
        return assignment_value < self.traffic_split
    
    def get_policy_for_request(self, request_id: str) -> PolicyConfig:
        """Get appropriate policy for this request"""
        
        if not self.is_active():
            return self.control_policy
        
        if self.should_use_treatment(request_id):
            return self.treatment_policy
        else:
            return self.control_policy
    
    def is_active(self) -> bool:
        """Check if experiment is currently active"""
        now = datetime.utcnow()
        return self.start_date <= now <= self.end_date
```

## Local Development Setup

### Environment Configuration
```bash
# Policy configuration
export POLICY_VERSION=v1.3.0
export POLICY_CONFIG_PATH=config/decision_policy.json
export POLICY_CACHE_TTL=300

# Decision thresholds (for testing)
export RULE_DECLINE_THRESHOLD=0.8
export ML_DECLINE_THRESHOLD=0.85
export ADJUDICATOR_REVIEW_THRESHOLD=0.75

# Explanation settings
export MAX_EXPLANATIONS=5
export INCLUDE_FEATURE_IMPORTANCE=true
```

### Testing Decision Logic
```python
# Test script
def test_decision_engine():
    policy = PolicyConfig.load_from_file('config/test_policy.json')
    engine = DecisionEngine(policy)
    
    # Test case 1: High rule score -> decline
    inputs = DecisionInputs(
        rules_output=RulesOutput(rule_score=0.9, rule_flags=['high_ltv', 'vin_reuse']),
        ml_output=MLOutput(confidence_score=0.6, top_features=[...]),
        adjudicator_output=None
    )
    
    result = engine.make_decision(inputs)
    assert result.final_decision == "decline"
    
    # Test case 2: Low scores -> approve
    inputs = DecisionInputs(
        rules_output=RulesOutput(rule_score=0.2, rule_flags=[]),
        ml_output=MLOutput(confidence_score=0.3, top_features=[...]),
        adjudicator_output=AdjudicatorOutput(adjudicator_score=0.4)
    )
    
    result = engine.make_decision(inputs)
    assert result.final_decision == "approve"
    
    print("All decision tests passed!")

test_decision_engine()
```

### Mock Decision Engine
```python
class MockDecisionEngine:
    """Mock decision engine for testing"""
    
    def __init__(self):
        self.policy_version = "v1.3.0-mock"
    
    def make_decision(self, inputs: DecisionInputs) -> DecisionResult:
        """Mock decision logic"""
        
        # Simple logic for testing
        max_score = max(
            inputs.rules_output.rule_score,
            inputs.ml_output.confidence_score,
            inputs.adjudicator_output.adjudicator_score if inputs.adjudicator_output else 0
        )
        
        if max_score >= 0.8:
            decision = "decline"
        elif max_score >= 0.6:
            decision = "review"
        else:
            decision = "approve"
        
        return DecisionResult(
            final_decision=decision,
            reasons=[f"Mock decision based on max score: {max_score:.2f}"],
            scores={
                "rule_score": inputs.rules_output.rule_score,
                "confidence_score": inputs.ml_output.confidence_score
            },
            policy_version=self.policy_version,
            total_processing_time_ms=50
        )
```

## Monitoring & Analytics

### Decision Metrics
```python
from prometheus_client import Counter, Histogram, Gauge

# Decision distribution
decision_counter = Counter('decisions_total', 'Total decisions', ['decision_type'])
decision_latency = Histogram('decision_duration_seconds', 'Decision processing time')

# Score distributions
rule_score_gauge = Gauge('rule_score', 'Latest rule score')
ml_score_gauge = Gauge('ml_confidence_score', 'Latest ML confidence score')
adjudicator_score_gauge = Gauge('adjudicator_score', 'Latest adjudicator score')

# Policy tracking
policy_version_gauge = Gauge('policy_version_info', 'Current policy version', ['version'])

def record_decision_metrics(self, result: DecisionResult):
    """Record decision metrics"""
    decision_counter.labels(decision_type=result.final_decision).inc()
    decision_latency.observe(result.total_processing_time_ms / 1000.0)
    
    rule_score_gauge.set(result.scores.get('rule_score', 0))
    ml_score_gauge.set(result.scores.get('confidence_score', 0))
    
    if 'adjudicator_score' in result.scores:
        adjudicator_score_gauge.set(result.scores['adjudicator_score'])
```

### Decision Analytics
```python
class DecisionAnalytics:
    def __init__(self, db_connection):
        self.db = db_connection
    
    def get_decision_distribution(self, days: int = 7) -> Dict[str, int]:
        """Get decision distribution over time period"""
        
        result = self.db.query("""
            SELECT final_decision, COUNT(*) as count
            FROM decisions 
            WHERE decided_at >= NOW() - INTERVAL ? DAY
            GROUP BY final_decision
        """, (days,)).fetchall()
        
        return {row['final_decision']: row['count'] for row in result}
    
    def get_score_correlations(self) -> Dict[str, float]:
        """Analyze correlations between different scores"""
        
        data = self.db.query("""
            SELECT 
                r.rule_score,
                m.confidence_score,
                a.adjudicator_score,
                d.final_decision
            FROM decisions d
            JOIN rules_outputs r ON d.request_id = r.request_id
            JOIN ml_outputs m ON d.request_id = m.request_id
            LEFT JOIN adjudicator_outputs a ON d.request_id = a.request_id
            WHERE d.decided_at >= NOW() - INTERVAL 30 DAY
        """).fetchall()
        
        # Calculate correlations
        return self.calculate_correlations(data)
    
    def get_policy_performance(self, policy_version: str) -> Dict:
        """Analyze performance of specific policy version"""
        
        metrics = self.db.query("""
            SELECT 
                final_decision,
                AVG(total_ms) as avg_processing_time,
                COUNT(*) as decision_count,
                AVG(CASE WHEN final_decision = 'approve' THEN 1 ELSE 0 END) as approval_rate
            FROM decisions 
            WHERE policy_version = ?
            GROUP BY final_decision
        """, (policy_version,)).fetchall()
        
        return {
            'metrics': metrics,
            'policy_version': policy_version,
            'analysis_date': datetime.utcnow().isoformat()
        }
```

## Security & Audit

### Decision Audit Trail
```python
def log_decision_audit(self, inputs: DecisionInputs, result: DecisionResult):
    """Log decision for audit trail"""
    
    audit_record = {
        'event': 'fraud_decision',
        'request_id': inputs.request_id,
        'final_decision': result.final_decision,
        'policy_version': result.policy_version,
        'scores': {
            'rule_score': result.scores.get('rule_score'),
            'confidence_score': result.scores.get('confidence_score'),
            'adjudicator_score': result.scores.get('adjudicator_score')
        },
        'processing_time_ms': result.total_processing_time_ms,
        'explanation_count': len(result.reasons),
        'timestamp': datetime.utcnow().isoformat(),
        'versions': result.versions
    }
    
    # Log to secure audit system
    audit_logger.info(json.dumps(audit_record))
```

### Decision Integrity
- **Immutable Decisions**: Once made, decisions cannot be modified
- **Version Tracking**: All component versions recorded
- **Audit Logging**: Complete decision trail maintained
- **Policy Governance**: Controlled policy update process
- **Explainability**: Clear reasoning for all decisions
