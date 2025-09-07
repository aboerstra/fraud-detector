# Bedrock Adjudicator Component

## Overview
The Bedrock adjudicator provides LLM-based risk assessment using AWS Bedrock, analyzing redacted application dossiers to provide additional fraud insights while maintaining strict privacy controls.

## Architecture & Privacy

### Privacy-First Design
The adjudicator operates under strict data minimization principles:

**NEVER SEND:**
- Names, SIN, email addresses, phone numbers
- Full addresses, VIN numbers
- Any personally identifiable information

**SEND ONLY:**
- Age bands (e.g., 25-34, 35-44)
- Province codes (ON, BC, AB, etc.)
- Numeric ratios and percentiles
- Boolean flags and risk indicators
- Anonymized feature importance

### Redacted Dossier Structure
```json
{
  "case_id": "req_abc123",
  "applicant": {
    "age_band": "35-44",
    "province": "ON"
  },
  "financial": {
    "ltv_ratio": 0.85,
    "downpayment_income_ratio": 0.3,
    "purchase_loan_ratio": 1.15
  },
  "risk_indicators": {
    "province_ip_mismatch": true,
    "vin_reuse_detected": false,
    "email_domain_risk": "disposable",
    "dealer_risk_percentile": 0.75
  },
  "ml_assessment": {
    "confidence_score": 0.72,
    "top_risk_factors": [
      "high_dealer_risk",
      "geographic_mismatch", 
      "elevated_ltv"
    ]
  },
  "velocity_flags": {
    "phone_reuse_count": 3,
    "email_reuse_count": 1,
    "dealer_volume_spike": true
  }
}
```

## Bedrock Integration

### Model Selection
**Primary Model: Claude 3 Haiku**
- Fast inference (~1-2 seconds)
- Cost-effective for high volume
- Good reasoning capabilities
- Reliable structured output

**Alternative: Llama 3 8B**
- Open-weights model via Bedrock
- Lower cost option
- Comparable performance

### Service Configuration
```python
import boto3
from botocore.config import Config

class BedrockAdjudicator:
    def __init__(self):
        # Configure Bedrock client with VPC endpoint
        config = Config(
            region_name='ca-central-1',
            retries={'max_attempts': 3},
            read_timeout=30
        )
        
        self.bedrock = boto3.client(
            'bedrock-runtime',
            config=config,
            endpoint_url='https://vpce-xxx.bedrock-runtime.ca-central-1.vpce.amazonaws.com'
        )
        
        self.model_id = "anthropic.claude-3-haiku-20240307-v1:0"
        self.prompt_template_version = "v1.2.0"
```

### Prompt Engineering

#### System Prompt
```
You are a fraud risk analyst for auto loan applications. Analyze the provided case dossier and provide a structured risk assessment.

IMPORTANT CONSTRAINTS:
- The dossier contains NO personally identifiable information
- Provide concise, factual analysis only
- Focus on risk patterns and anomalies
- Limit response to exactly 3 bullet points for rationale
- Score must be between 0.01 and 0.99 (never exactly 0 or 1)

OUTPUT FORMAT (JSON only):
{
  "adjudicator_score": 0.XX,
  "risk_band": "low|medium|high", 
  "rationale": [
    "Bullet point 1",
    "Bullet point 2", 
    "Bullet point 3"
  ]
}
```

#### User Prompt Template
```
Case ID: {case_id}

APPLICANT PROFILE:
- Age: {age_band}
- Province: {province}

FINANCIAL METRICS:
- Loan-to-Value: {ltv_ratio:.2f}
- Down Payment/Income: {downpayment_income_ratio:.2f}
- Purchase/Loan Ratio: {purchase_loan_ratio:.2f}

RISK INDICATORS:
- Geographic Mismatch: {province_ip_mismatch}
- VIN Reuse: {vin_reuse_detected}
- Email Risk: {email_domain_risk}
- Dealer Risk Percentile: {dealer_risk_percentile:.2f}

ML ASSESSMENT:
- Confidence Score: {confidence_score:.2f}
- Key Risk Factors: {top_risk_factors}

VELOCITY PATTERNS:
- Phone Reuse Count: {phone_reuse_count}
- Email Reuse Count: {email_reuse_count}
- Dealer Volume Spike: {dealer_volume_spike}

Provide your risk assessment:
```

### Inference Implementation
```python
async def adjudicate_case(self, dossier: Dict) -> AdjudicatorResult:
    """Get LLM risk assessment for application"""
    
    try:
        # Prepare prompt
        prompt = self.build_prompt(dossier)
        
        # Call Bedrock
        response = await self.call_bedrock(prompt)
        
        # Parse and validate response
        result = self.parse_response(response)
        
        # Log for audit
        self.log_adjudication(dossier['case_id'], prompt, result)
        
        return result
        
    except Exception as e:
        logger.error(f"Adjudication failed for {dossier['case_id']}: {str(e)}")
        raise AdjudicationError(f"LLM adjudication failed: {str(e)}")

async def call_bedrock(self, prompt: str) -> str:
    """Make Bedrock API call with retry logic"""
    
    request_body = {
        "anthropic_version": "bedrock-2023-05-31",
        "max_tokens": 200,
        "temperature": 0.1,
        "messages": [
            {
                "role": "user",
                "content": prompt
            }
        ]
    }
    
    response = await self.bedrock.invoke_model_async(
        modelId=self.model_id,
        body=json.dumps(request_body),
        contentType='application/json'
    )
    
    response_body = json.loads(response['body'].read())
    return response_body['content'][0]['text']
```

### Response Parsing & Validation
```python
def parse_response(self, response_text: str) -> AdjudicatorResult:
    """Parse and validate LLM response"""
    
    try:
        # Extract JSON from response
        json_match = re.search(r'\{.*\}', response_text, re.DOTALL)
        if not json_match:
            raise ValueError("No JSON found in response")
        
        data = json.loads(json_match.group())
        
        # Validate required fields
        score = float(data['adjudicator_score'])
        risk_band = data['risk_band']
        rationale = data['rationale']
        
        # Validate score range
        if not (0.01 <= score <= 0.99):
            score = max(0.01, min(0.99, score))
        
        # Validate risk band
        if risk_band not in ['low', 'medium', 'high']:
            risk_band = self.infer_risk_band(score)
        
        # Validate rationale
        if not isinstance(rationale, list) or len(rationale) != 3:
            rationale = ["Risk assessment completed", "Multiple factors considered", "Score reflects overall risk level"]
        
        return AdjudicatorResult(
            adjudicator_score=score,
            risk_band=risk_band,
            rationale=rationale,
            model_id=self.model_id,
            prompt_template_version=self.prompt_template_version
        )
        
    except Exception as e:
        logger.error(f"Failed to parse LLM response: {str(e)}")
        # Return default safe response
        return AdjudicatorResult(
            adjudicator_score=0.5,
            risk_band="medium",
            rationale=["Unable to complete full assessment", "Default risk score applied", "Manual review recommended"],
            model_id=self.model_id,
            prompt_template_version=self.prompt_template_version
        )
```

## Cost Control & Optimization

### Token Management
```python
class TokenManager:
    def __init__(self):
        self.max_input_tokens = 1000
        self.max_output_tokens = 200
        self.cost_per_1k_input = 0.00025  # Claude 3 Haiku pricing
        self.cost_per_1k_output = 0.00125
    
    def estimate_cost(self, input_tokens: int, output_tokens: int) -> float:
        """Estimate API call cost"""
        input_cost = (input_tokens / 1000) * self.cost_per_1k_input
        output_cost = (output_tokens / 1000) * self.cost_per_1k_output
        return input_cost + output_cost
    
    def compress_dossier(self, dossier: Dict) -> Dict:
        """Compress dossier to minimize tokens"""
        # Use abbreviations and compact format
        compressed = {
            "id": dossier["case_id"][-8:],  # Last 8 chars only
            "age": dossier["applicant"]["age_band"],
            "prov": dossier["applicant"]["province"],
            "ltv": round(dossier["financial"]["ltv_ratio"], 2),
            "flags": self.compress_flags(dossier["risk_indicators"]),
            "ml_score": round(dossier["ml_assessment"]["confidence_score"], 2)
        }
        return compressed
```

### Budget Controls
```python
class BudgetManager:
    def __init__(self, daily_budget: float = 100.0):
        self.daily_budget = daily_budget
        self.current_spend = 0.0
        self.call_count = 0
        
    async def check_budget(self, estimated_cost: float) -> bool:
        """Check if call is within budget"""
        if self.current_spend + estimated_cost > self.daily_budget:
            logger.warning(f"Budget exceeded: {self.current_spend + estimated_cost} > {self.daily_budget}")
            return False
        return True
    
    def record_spend(self, actual_cost: float):
        """Record actual API cost"""
        self.current_spend += actual_cost
        self.call_count += 1
        
        # Alert if approaching budget
        if self.current_spend > self.daily_budget * 0.8:
            self.send_budget_alert()
```

## VPC Endpoint Configuration

### Network Setup
```yaml
# CloudFormation template for VPC endpoint
BedrockVPCEndpoint:
  Type: AWS::EC2::VPCEndpoint
  Properties:
    VpcId: !Ref VPC
    ServiceName: !Sub 'com.amazonaws.${AWS::Region}.bedrock-runtime'
    VpcEndpointType: Interface
    SubnetIds:
      - !Ref PrivateSubnet1
      - !Ref PrivateSubnet2
    SecurityGroupIds:
      - !Ref BedrockEndpointSecurityGroup
    PolicyDocument:
      Statement:
        - Effect: Allow
          Principal: '*'
          Action:
            - bedrock:InvokeModel
          Resource: 
            - !Sub 'arn:aws:bedrock:${AWS::Region}::foundation-model/anthropic.claude-3-haiku-*'
```

### Security Group Rules
```yaml
BedrockEndpointSecurityGroup:
  Type: AWS::EC2::SecurityGroup
  Properties:
    GroupDescription: Security group for Bedrock VPC endpoint
    VpcId: !Ref VPC
    SecurityGroupIngress:
      - IpProtocol: tcp
        FromPort: 443
        ToPort: 443
        SourceSecurityGroupId: !Ref WorkerSecurityGroup
    SecurityGroupEgress:
      - IpProtocol: tcp
        FromPort: 443
        ToPort: 443
        CidrIp: 0.0.0.0/0
```

## Local Development Setup

### Environment Configuration
```bash
# AWS credentials and region
export AWS_REGION=ca-central-1
export AWS_ACCESS_KEY_ID=your_access_key
export AWS_SECRET_ACCESS_KEY=your_secret_key

# Bedrock configuration
export BEDROCK_MODEL_ID=anthropic.claude-3-haiku-20240307-v1:0
export BEDROCK_ENDPOINT_URL=https://bedrock-runtime.ca-central-1.amazonaws.com
export PROMPT_TEMPLATE_VERSION=v1.2.0

# Cost controls
export DAILY_BUDGET_USD=50.0
export MAX_TOKENS_INPUT=1000
export MAX_TOKENS_OUTPUT=200
```

### Testing the Adjudicator
```python
# Test script
async def test_adjudicator():
    adjudicator = BedrockAdjudicator()
    
    test_dossier = {
        "case_id": "test_123",
        "applicant": {"age_band": "35-44", "province": "ON"},
        "financial": {"ltv_ratio": 0.85, "downpayment_income_ratio": 0.3},
        "risk_indicators": {"province_ip_mismatch": True, "dealer_risk_percentile": 0.75},
        "ml_assessment": {"confidence_score": 0.72, "top_risk_factors": ["high_ltv", "geo_mismatch"]}
    }
    
    result = await adjudicator.adjudicate_case(test_dossier)
    print(f"Score: {result.adjudicator_score}")
    print(f"Risk Band: {result.risk_band}")
    print(f"Rationale: {result.rationale}")

# Run test
asyncio.run(test_adjudicator())
```

### Mock Service for Development
```python
class MockBedrockAdjudicator:
    """Mock adjudicator for local development"""
    
    def __init__(self):
        self.model_id = "mock-claude-3-haiku"
        self.prompt_template_version = "v1.2.0"
    
    async def adjudicate_case(self, dossier: Dict) -> AdjudicatorResult:
        """Mock adjudication with realistic responses"""
        
        # Simulate processing time
        await asyncio.sleep(0.5)
        
        # Generate score based on risk indicators
        base_score = 0.3
        
        if dossier.get("risk_indicators", {}).get("province_ip_mismatch"):
            base_score += 0.2
        
        if dossier.get("financial", {}).get("ltv_ratio", 0) > 0.8:
            base_score += 0.15
        
        score = min(0.99, max(0.01, base_score))
        
        return AdjudicatorResult(
            adjudicator_score=score,
            risk_band="medium" if score > 0.5 else "low",
            rationale=[
                "Geographic inconsistency noted",
                "Financial ratios within acceptable range", 
                "Overall risk profile moderate"
            ],
            model_id=self.model_id,
            prompt_template_version=self.prompt_template_version
        )
```

## Monitoring & Observability

### Metrics Collection
```python
from prometheus_client import Counter, Histogram, Gauge

# Bedrock metrics
bedrock_calls_total = Counter('bedrock_calls_total', 'Total Bedrock API calls')
bedrock_latency = Histogram('bedrock_latency_seconds', 'Bedrock API latency')
bedrock_cost_total = Counter('bedrock_cost_usd_total', 'Total Bedrock API cost')
bedrock_errors = Counter('bedrock_errors_total', 'Bedrock API errors', ['error_type'])

# Score distribution
adjudicator_score_gauge = Gauge('adjudicator_score', 'Latest adjudicator score')
risk_band_counter = Counter('risk_band_total', 'Risk band distribution', ['band'])
```

### Audit Logging
```python
def log_adjudication(self, case_id: str, prompt_hash: str, result: AdjudicatorResult):
    """Log adjudication for audit trail"""
    
    audit_log = {
        'event': 'bedrock_adjudication',
        'case_id': case_id,
        'prompt_template_version': self.prompt_template_version,
        'prompt_hash': prompt_hash,  # Hash of prompt, not content
        'model_id': self.model_id,
        'adjudicator_score': result.adjudicator_score,
        'risk_band': result.risk_band,
        'rationale_count': len(result.rationale),
        'timestamp': datetime.utcnow().isoformat(),
        'cost_estimate': self.estimate_cost()
    }
    
    # Log to secure audit system
    audit_logger.info(json.dumps(audit_log))
```

### Error Handling & Fallbacks
```python
class AdjudicatorWithFallback:
    def __init__(self):
        self.primary = BedrockAdjudicator()
        self.fallback_score = 0.5
        
    async def adjudicate_case(self, dossier: Dict) -> AdjudicatorResult:
        """Adjudicate with fallback on failure"""
        
        try:
            return await self.primary.adjudicate_case(dossier)
            
        except BedrockThrottlingError:
            logger.warning("Bedrock throttling, using fallback")
            return self.create_fallback_result("throttling")
            
        except BedrockTimeoutError:
            logger.warning("Bedrock timeout, using fallback")
            return self.create_fallback_result("timeout")
            
        except Exception as e:
            logger.error(f"Bedrock error: {str(e)}")
            return self.create_fallback_result("error")
    
    def create_fallback_result(self, reason: str) -> AdjudicatorResult:
        """Create fallback result when Bedrock unavailable"""
        return AdjudicatorResult(
            adjudicator_score=self.fallback_score,
            risk_band="medium",
            rationale=[
                f"LLM adjudication unavailable ({reason})",
                "Default risk assessment applied",
                "Manual review recommended"
            ],
            model_id="fallback",
            prompt_template_version=self.primary.prompt_template_version
        )
```

## Security & Compliance

### Data Protection
- **No PII**: Strict redaction of all personal information
- **Audit Trail**: Complete logging of all interactions
- **Encryption**: TLS for all API communications
- **Access Control**: IAM roles with minimal permissions

### Compliance Considerations
- **Data Residency**: All processing in ca-central-1
- **Retention**: Audit logs retained per compliance requirements
- **Privacy**: PIPEDA compliance through data minimization
- **Explainability**: Structured rationale for all decisions

### IAM Policy
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "bedrock:InvokeModel"
      ],
      "Resource": [
        "arn:aws:bedrock:ca-central-1::foundation-model/anthropic.claude-3-haiku-*"
      ]
    }
  ]
}
