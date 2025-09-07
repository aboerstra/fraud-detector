Awesome — here’s a **complete, no-code implementation plan** for the **Fraud Detection POC** that we’ve been shaping: Laravel + DB queue + LightGBM scoring + Bedrock adjudicator, all in **AWS ca-central-1**. It’s organized so you can hand sections directly to engineering, security, and stakeholders.

---

# 0) POC Goals & Guardrails

* **Outcome:** Async fraud decision in ≤ **5 minutes P95** (target typical: 30–120s), returning
  **rule\_score**, **ml confidence\_score**, **adjudicator\_score** (Bedrock), a **final\_decision**, and **explanations**.
* **Constraints:**

  * Canada residency (AWS **ca-central-1**).
  * Minimal infra (no Kubernetes).
  * Laravel **DB queue driver**.
  * Keep **adjudicator LLM costs low** (Bedrock; short outputs; redacted inputs).
* **Not in scope (POC):** HA/multi-AZ, WAF/DDoS, DR/BCP, SIEM/SOC, fine-grained IAM rotation (we’ll note the prod path).

---

# 1) High-Level Architecture (POC Cut)

**Client / Sample Data App** → **Laravel API (EC2)** → **DB (RDS)** & **DB Queue**
→ **Worker (same EC2 or 2nd EC2)** → **Rules → Feature Eng → ML Scoring (LightGBM on EC2) → Bedrock (adjudicator)**
→ **Decision Assembly** → **Persist to RDS** → **GET /decision/{job\_id}** (or webhook)

**Core AWS services:** EC2 (2 small nodes), RDS Postgres (single-AZ), S3 (models/audit), Bedrock (ca-central-1), VPC endpoints.

---

# 2) Environments & Provisioning

**Regions:** ca-central-1 only
**Envs:** `dev`, `poc`

**Networking**

* VPC (default or simple custom).
* **Security Groups:**

  * API/Worker EC2: HTTPS from tester IPs; egress to RDS, Bedrock VPCE, ML EC2.
  * ML EC2: allow only from API/Worker SG.
  * RDS: allow only from API/Worker SG.
* **PrivateLink (Interface VPC Endpoint)** for **Bedrock** so adjudicator calls never hit public Internet.

**Compute & Storage (suggested)**

* EC2 **API/Worker**: t3.small / t3.medium.
* EC2 **ML Scoring** (FastAPI): t3.micro (can co-locate on API box if preferred).
* RDS **Postgres**: db.t3.micro, 20–50 GB, encryption on.
* **S3**: one bucket with versioning (`/models`, `/audit`).

**Secrets**

* POC: `.env` + instance profile role (least privilege to S3 + Bedrock Invoke).
* Note: adopt Secrets Manager and tighter IAM in prod.

**“Ready” criteria:** Instances reachable per SG design; RDS reachable from API; S3 bucket exists with versioning; Bedrock enabled in region.

---

# 3) Components & Responsibilities

1. **Laravel API (EC2)**

   * Endpoints:

     * `POST /applications` → validate, persist raw, enqueue via DB queue → return `job_id`.
     * `GET /decision/{job_id}` → return status/decision payload.
   * Auth: API key + HMAC (timestamp + nonce).
   * Timestamps: `received_at`, `queued_at`.

2. **DB Queue Driver (Laravel)**

   * Tables: `jobs`, `failed_jobs`.
   * Retries/backoff tuned (e.g., 3 attempts, exponential).
   * Concurrency: 2–4 workers to keep pickup delay low.

3. **Worker (queue consumer)**
   Pipeline per job:

   1. Load raw request.
   2. **Rules v1** → `rule_flags[]`, `rule_score`, `rulepack_version`; short-circuit on hard-fails.
   3. **Feature Eng v1 (Top-15)** → feature vector + `feature_set_version`.
   4. **ML Scoring (LightGBM)** → `confidence_score`, `top_features[]`, `model_version`, `calibration_version`.
   5. **Adjudicator (Bedrock)** → send redacted dossier → `adjudicator_score`, `rationale`, `model_id`.
   6. **Decision Assembly** → `final_decision`, `reasons[]`, `policy_version` (thresholds).
   7. Persist outputs + stage timestamps; mark job done.

4. **ML Inference Server (EC2)**

   * FastAPI or similar; loads **LightGBM artifact** from S3 at start.
   * Endpoint `/score` (private): input features → output `confidence_score`, `top_features`, versions.
   * Calibrated probabilities (record `calibration_version`).

5. **Bedrock Adjudicator (managed)**

   * Models: start with **Claude 3 Haiku** (cheap/fast) or **Llama 3 8B**.
   * Access via **VPC endpoint**; set max tokens \~200; low temperature.
   * Redacted prompt only (no PII).

6. **RDS Postgres (system of record)**

   * Entities: requests, jobs, rules\_outputs, features, ml\_outputs, adjudicator\_outputs, decisions, tenants/api\_clients, replay\_nonces.
   * Indices on `job_id`, `request_id`, `created_at`.

7. **S3**

   * Store model artifacts (LightGBM), optional audit snapshots.

---

# 4) Data Contracts (no code)

## 4.1 Submit (client → API)

* **Headers:** `X-Api-Key`, `X-Timestamp`, `X-Nonce`, `X-Signature` (HMAC over method+path+body+ts+nonce).
* **Body:** Application JSON (payload versioned).
* **ACK Response:** `job_id`, `request_id`, `status="queued"`, `received_at`, `poll_url`.

## 4.2 Decision Resource (poll/webhook)

* **Statuses:** `queued | processing | decided | failed`.
* **Final (`status=decided`)** returns:

  * `decision.final_decision`: `approve | review | decline`
  * `scores`:

    * `rule_score` (0–1), `rule_band`
    * `confidence_score` (0–1), `confidence_band`
    * `adjudicator_score` (0–1), `adjudicator_band`
  * `explainability`: `rule_flags[]`, `top_features[]`, `adjudicator_rationale`
  * `versions`: `rulepack_version`, `feature_set_version`, `model_version`, `calibration_version`, `policy_version`, `adjudicator_model_id`, `prompt_template_version`
  * `timing`: `received_at`, `queued_at`, `started_at`, `ml_scored_at`, `adjudicated_at`, `decided_at`, `total_ms`.

---

# 5) Rules v1 (deterministic) & Rule Score

**Hard-fail (short-circuit before ML):** invalid/missing SIN checksum, mandatory fields missing, (if available) deny/PEP list hit.

**Risk flags (weighted into `rule_score`):**

* province–IP mismatch, disposable email domain, phone/email reuse counts (last 7/30d), VIN reuse, high LTV, low downpayment vs income, address–postal mismatch, dealer 24h volume spike, dealer historical fraud percentile.

**Governance:**

* Rule manifest with names, descriptions, weights, severities; stamped as `rulepack_version`.
* `rule_score` normalized 0–1; `rule_flags[]` always persisted.

---

# 6) Feature Set v1 (Top-15)

* **Identity & Digital:** age, SIN valid flag, email domain category, phone reuse count, email reuse count.
* **Velocity & Dealer:** VIN reuse flag, dealer app volume (24h), dealer fraud percentile.
* **Geo & Address:** province–IP mismatch, address–postal match flag.
* **Loan/Vehicle Sanity:** LTV, purchase/loan ratio, downpayment/income ratio, mileage plausibility (year-adjusted), high-value vehicle with low income flag.

**Outputs:** ordered vector + `feature_set_version`; validate ranges/nulls; store snapshot.

---

# 7) LightGBM Model Lifecycle (offline → serve)

* **Training:** use synthetic set with same 15 features + label `fraud` (0/1). 5-fold CV; metrics: AUC/PR-AUC; tune for recall at fixed FPR.
* **Calibration:** isotonic or Platt; record `calibration_version`.
* **Packaging:** artifact + model card (data description, metrics, features) → S3 `/models`.
* **Serving:** ML EC2 loads artifact at boot; `/healthz` returns active `model_version`.

---

# 8) Bedrock Adjudicator (prompting & privacy)

**Privacy/Redaction (strict):**

* **Never** send: names, SIN, email, phone, VIN, full address.
* **Send** only a **dossier**: age band (e.g., 35–44), province code, numeric ratios (LTV, DP/income), flags (e.g., `province_ip_mismatch`), dealer risk percentile, ML top features (short labels), ML confidence (0–1).

**Prompt contract (inputs → outputs):**

* Inputs: `case_id`, concise dossier fields, `confidence_score`, `rule_flags`, `key_features`.
* Outputs:

  * `adjudicator_score` (0–1, clamp \[0.01, 0.99])
  * `risk_band` (`low|medium|high`)
  * `rationale` (≤ 3 bullet points)

**Model & runtime choices (POC):**

* Primary: **Claude 3 Haiku** (fast, inexpensive).
* Alt: **Llama 3 8B** (if you want open-weights flavor via Bedrock).
* Bedrock via **PrivateLink**; token limits: input compact; output ≤200 tokens.

---

# 9) Decision Policy (assembly layer)

* **Inputs:** `rule_score` (+ flags), `confidence_score`, optional `adjudicator_score`.
* **Logic:**

  * If **any hard-fail** → `decline`.
  * Else if `confidence_score ≥ τ1` **or** `rule_score ≥ τ2` → `review` or `decline` per policy.
  * Else if `adjudicator_score ≥ τ3` → escalate to `review`.
  * Else → `approve`.
* **Outputs:** `final_decision`, `reasons[]` (mix of rule flags + ML top drivers + adjudicator bullets), `policy_version`.
* **Note:** adjudicator is **advisory** in POC; avoid hard overrides unless agreed.

---

# 10) Persistence Model (tables only; no DDL)

* **requests** — `request_id`, `tenant_id`, raw JSON, `payload_version`, auth meta, `received_at`.
* **jobs** — `job_id`, `request_id`, status, attempts, `queued_at`, `started_at`, `decided_at`, error.
* **rules\_outputs** — `request_id`, `rule_flags[]`, `rule_score`, `rulepack_version`.
* **features** — `request_id`, `feature_set_version`, `vector_json`, validation status.
* **ml\_outputs** — `request_id`, `confidence_score`, `top_features[]`, `model_version`, `calibration_version`, ml latency.
* **adjudicator\_outputs** — `request_id`, `adjudicator_score`, `risk_band`, rationale, `adjudicator_model_id`, `prompt_template_version`.
* **decisions** — `job_id`, `request_id`, `final_decision`, `reasons[]`, `policy_version`, lifecycle timestamps, `total_ms`.
* **tenants / api\_clients** — API keys, HMAC secrets, toggles (e.g., mask raw scores).
* **replay\_nonces** — recent nonces to block replay attacks.

---

# 11) Monitoring, SLA & Cost

**SLA metrics** (persist and/or simple CloudWatch alarms):

* Queue depth / oldest job age.
* Stage timers: received → queued → started → ml\_scored → adjudicated → decided.
* P95 total latency target ≤ 5 min (goal 30–120s typical).

**Cost control (POC):**

* Bedrock: cap output tokens (≤200), prefer Haiku; set a **budget alarm** (e.g., \$100 ceiling).
* EC2/RDS: t-class; turn off dev env when idle.
* Optional: batch adjudicator calls to reduce token sprawl.

---

# 12) Security, Privacy & Residency

* **TLS** everywhere; API HMAC auth; SG allow-listing; PrivateLink to Bedrock.
* **Data minimization** to LLM (no PII).
* **Encryption at rest:** RDS & S3 with KMS.
* **Auditability:** store prompt hash and (optionally) full prompt/output, model IDs, versions.
* **Idempotency:** dedupe on `client_request_id` → return same `job_id`.

---

# 13) Test Plan (POC)

**Functional**

1. Valid submit → ACK with `job_id`; decision polling returns full payload with 3 scores.
2. Invalid submit (missing required) → 4xx; no job created.
3. Hard-fail rule → immediate `decline` (no ML/LLM call).
4. Edge: ML down → worker retries; if exhausted → `failed` with error; DLQ (failed\_jobs) entry.

**Performance**

* Burst **200 requests in 2 minutes** → **P95 ≤ 2 min** (goal), **≤ 5 min** (SLA).
* Worker concurrency tuned until queue pickup delay < 1s typical.

**Explainability**

* Each decision includes:

  * at least **2 rule flags** (if triggered)
  * **3 ML top features** (if meaningful)
  * adjudicator rationale (≤ 3 bullets).

**Auditability**

* Randomly sample 5 decisions → replay lineage from DB (raw → rules → features → ML → LLM → decision) with version stamps.

---

# 14) Timeline & Ownership (3-week plan)

**Week 1 — Foundations**

* Infra (EC2/RDS/S3), Bedrock enable + PrivateLink, API endpoints skeleton, DB schemas, queue tables, health checks.

**Week 2 — Core Flow**

* Rules v1 + Feature Set v1 (Top-15), ML server + model\_v1 (calibrated), Bedrock adjudicator call (redacted dossier), Decision Assembly + response shape.

**Week 3 — Quality & Demo**

* Reason codes mapping; SLA tuning (worker concurrency), budget alarms; sample data app; runbook; stakeholder dry-run.

**RACI (simple)**

* **Backend:** Laravel API/Worker, queue, RDS schemas, decision policy.
* **ML:** LightGBM training, packaging, serving, calibration.
* **LLM:** Bedrock setup, prompt template, redaction policy.
* **Infra/Sec:** VPC/SG/PrivateLink/KMS/budgets, IAM.
* **PM/QA:** Acceptance tests, demo script.

---

# 15) Deliverables (Definition of Done)

1. **Running POC** in ca-central-1 with endpoints live.
2. **API contract** (ACK + final decision schemas).
3. **Rules pack v1** (list, weights, hard-fails) + `rulepack_version`.
4. **Feature spec v1** (15 features: names, derivations, types, ranges) + `feature_set_version`.
5. **Model card v1** (LightGBM): metrics, `model_version`, `calibration_version`.
6. **LLM prompt template + redaction policy** + `prompt_template_version`.
7. **Runbook** (start/stop, model rotate, rule update, thresholds update).
8. **Demo script** with 3–4 archetype cases and expected outputs.

---

# 16) Risks & Mitigations

* **Queue lag breaches SLA** → add 1–3 workers; reduce queue poll interval; keep adjudicator short (≤200 tokens).
* **Model drift** (synthetic vs real) → lock feature schema; calibrate; plan re-train with early real samples.
* **Explainability gaps** → always include rule flags + ML top features + concise LLM rationale.
* **Cost sprawl (LLM)** → enforce token caps; budgets/alarms; compress dossier.
* **Compliance audit** → document data minimization to LLM, PrivateLink, KMS, version stamps.

---

# 17) Production Path (after POC)

* Multi-AZ RDS; instance auto-scaling; WAF/Shield; Secrets Manager; CloudWatch/Loki logging; SIEM; DR/backup policies; model registry; human-in-the-loop UI; fine-grained tenant configs; optional **self-hosted** LLM (Mixtral/Llama) to eliminate external dependencies if desired.

---

This plan keeps the POC **lean, auditable, explainable, and Canada-resident**, while leaving a clean runway to production-grade controls. If you want, I can condense this into a **one-page checklist** or a **slide** you can drop into your internal review deck.
