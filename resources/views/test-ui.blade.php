<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Fraud Detection Testing Interface</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), #1e40af);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }

        .btn-ai {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-ai:hover {
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            transform: translateY(-1px);
            color: white;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .status-healthy { background-color: var(--success-color); }
        .status-unhealthy { background-color: var(--danger-color); }
        .status-degraded { background-color: var(--warning-color); }

        .risk-meter {
            height: 20px;
            border-radius: 10px;
            background: linear-gradient(90deg, #059669 0%, #d97706 50%, #dc2626 100%);
            position: relative;
            overflow: hidden;
        }

        .risk-indicator {
            position: absolute;
            top: -5px;
            width: 4px;
            height: 30px;
            background: white;
            border: 2px solid #1f2937;
            border-radius: 2px;
            transition: left 0.5s ease;
        }

        .form-section {
            border-left: 4px solid var(--primary-color);
            padding-left: 1rem;
            margin-bottom: 1.5rem;
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f4f6;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .result-card {
            border-left: 5px solid;
            transition: all 0.3s ease;
        }

        .result-approve { border-left-color: var(--success-color); }
        .result-review { border-left-color: var(--warning-color); }
        .result-decline { border-left-color: var(--danger-color); }

        .json-viewer {
            background: #1f2937;
            color: #f9fafb;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            max-height: 400px;
            overflow-y: auto;
        }

        .test-scenario-btn {
            border: 2px dashed #d1d5db;
            background: transparent;
            transition: all 0.3s ease;
        }

        .test-scenario-btn:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-shield-check me-2"></i>
                Fraud Detection Testing Interface
            </span>
            <div class="d-flex align-items-center">
                <span class="text-white-50 me-3">AI-Powered Testing</span>
                <button class="btn btn-outline-light btn-sm" onclick="refreshSystemHealth()">
                    <i class="bi bi-arrow-clockwise me-1"></i>
                    Refresh Status
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="row">
            <!-- Left Panel: Form and Controls -->
            <div class="col-lg-8">
                <!-- AI Test Data Generation -->
                <div class="card mb-4">
                    <div class="card-header bg-gradient text-white" style="background: linear-gradient(135deg, #8b5cf6, #a855f7);">
                        <h5 class="mb-0">
                            <i class="bi bi-robot me-2"></i>
                            AI Test Data Generator
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Risk Level</label>
                                <select class="form-select" id="riskLevel">
                                    <option value="low">Low Risk (Should Approve)</option>
                                    <option value="medium" selected>Medium Risk (LLM Review)</option>
                                    <option value="high">High Risk (Should Decline)</option>
                                    <option value="invalid">Invalid Data (Validation Errors)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Custom Scenario</label>
                                <input type="text" class="form-control" id="customPrompt" 
                                       placeholder="e.g., 'A nurse from Calgary buying her first car'">
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-ai" onclick="generateTestData()">
                                <div class="loading-spinner me-2" id="generateSpinner"></div>
                                <i class="bi bi-magic me-1"></i>
                                Generate Test Data
                            </button>
                            <button class="btn btn-outline-secondary" onclick="clearForm()">
                                <i class="bi bi-trash me-1"></i>
                                Clear Form
                            </button>
                            <button class="btn btn-outline-info" onclick="fillSampleData()">
                                <i class="bi bi-file-text me-1"></i>
                                Sample Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Application Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-person-fill me-2"></i>
                            Loan Application Form
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="fraudTestForm">
                            <!-- Personal Information -->
                            <div class="form-section">
                                <h6 class="text-primary mb-3">Personal Information</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">First Name</label>
                                        <input type="text" class="form-control" name="personal_info[first_name]">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" class="form-control" name="personal_info[last_name]">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" name="personal_info[date_of_birth]">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">SIN</label>
                                        <input type="text" class="form-control" name="personal_info[sin]" 
                                               placeholder="123456789">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="tel" class="form-control" name="personal_info[phone]" 
                                               placeholder="416-555-0123">
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="personal_info[email]">
                                    </div>
                                </div>
                            </div>

                            <!-- Address -->
                            <div class="form-section">
                                <h6 class="text-primary mb-3">Address</h6>
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Street Address</label>
                                        <input type="text" class="form-control" name="address[street]">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" class="form-control" name="address[city]">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Province</label>
                                        <select class="form-select" name="address[province]">
                                            <option value="">Select Province</option>
                                            <option value="AB">Alberta</option>
                                            <option value="BC">British Columbia</option>
                                            <option value="MB">Manitoba</option>
                                            <option value="NB">New Brunswick</option>
                                            <option value="NL">Newfoundland and Labrador</option>
                                            <option value="NS">Nova Scotia</option>
                                            <option value="ON">Ontario</option>
                                            <option value="PE">Prince Edward Island</option>
                                            <option value="QC">Quebec</option>
                                            <option value="SK">Saskatchewan</option>
                                            <option value="NT">Northwest Territories</option>
                                            <option value="NU">Nunavut</option>
                                            <option value="YT">Yukon</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Postal Code</label>
                                        <input type="text" class="form-control" name="address[postal_code]" 
                                               placeholder="M5V 3A8">
                                    </div>
                                    <input type="hidden" name="address[country]" value="CA">
                                </div>
                            </div>

                            <!-- Financial Information -->
                            <div class="form-section">
                                <h6 class="text-primary mb-3">Financial Information</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Annual Income</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="financial_info[annual_income]" 
                                                   min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Employment Type</label>
                                        <select class="form-select" name="financial_info[employment_type]">
                                            <option value="">Select Type</option>
                                            <option value="full_time">Full Time</option>
                                            <option value="part_time">Part Time</option>
                                            <option value="contract">Contract</option>
                                            <option value="self_employed">Self Employed</option>
                                            <option value="unemployed">Unemployed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Employment Duration (months)</label>
                                        <input type="number" class="form-control" name="financial_info[employment_months]" 
                                               min="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Employer Name</label>
                                        <input type="text" class="form-control" name="financial_info[employer_name]">
                                    </div>
                                </div>
                            </div>

                            <!-- Loan Information -->
                            <div class="form-section">
                                <h6 class="text-primary mb-3">Loan Information</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Loan Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="loan_info[amount]" 
                                                   min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Term (months)</label>
                                        <select class="form-select" name="loan_info[term_months]">
                                            <option value="">Select Term</option>
                                            <option value="12">12 months</option>
                                            <option value="24">24 months</option>
                                            <option value="36">36 months</option>
                                            <option value="48">48 months</option>
                                            <option value="60">60 months</option>
                                            <option value="72">72 months</option>
                                            <option value="84">84 months</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Interest Rate (%)</label>
                                        <input type="number" class="form-control" name="loan_info[interest_rate]" 
                                               min="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Purpose</label>
                                        <select class="form-select" name="loan_info[purpose]">
                                            <option value="">Select Purpose</option>
                                            <option value="vehicle_purchase">Vehicle Purchase</option>
                                            <option value="refinancing">Refinancing</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle Information -->
                            <div class="form-section">
                                <h6 class="text-primary mb-3">Vehicle Information</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Year</label>
                                        <input type="number" class="form-control" name="vehicle_info[year]" 
                                               min="1900">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Make</label>
                                        <input type="text" class="form-control" name="vehicle_info[make]">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Model</label>
                                        <input type="text" class="form-control" name="vehicle_info[model]">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">VIN</label>
                                        <input type="text" class="form-control" name="vehicle_info[vin]" 
                                               placeholder="1HGBH41JXMN109186">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mileage</label>
                                        <input type="number" class="form-control" name="vehicle_info[mileage]">
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Estimated Value</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="vehicle_info[value]" 
                                                   min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <div class="loading-spinner me-2" id="submitSpinner"></div>
                                    <i class="bi bi-shield-check me-2"></i>
                                    Run Fraud Detection
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Results and Status -->
            <div class="col-lg-4">
                <!-- System Health -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-heart-pulse me-2"></i>
                            System Health
                        </h5>
                    </div>
                    <div class="card-body" id="systemHealth">
                        <div class="text-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Checking system status...</p>
                        </div>
                    </div>
                </div>

                <!-- Results -->
                <div class="card mb-4" id="resultsCard" style="display: none;">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clipboard-data me-2"></i>
                            Detection Results
                        </h5>
                    </div>
                    <div class="card-body" id="resultsContent">
                        <!-- Results will be populated here -->
                    </div>
                </div>

                <!-- Quick Test Scenarios -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-lightning me-2"></i>
                            Quick Test Scenarios
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button class="btn test-scenario-btn" onclick="generateQuickTest('low')">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Low Risk Applicant
                            </button>
                            <button class="btn test-scenario-btn" onclick="generateQuickTest('medium')">
                                <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                                Borderline Case
                            </button>
                            <button class="btn test-scenario-btn" onclick="generateQuickTest('high')">
                                <i class="bi bi-x-circle text-danger me-2"></i>
                                High Risk Applicant
                            </button>
                            <button class="btn test-scenario-btn" onclick="generateQuickTest('invalid')">
                                <i class="bi bi-bug text-info me-2"></i>
                                Invalid Data Test
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Global variables
        let currentJobId = null;
        let pollInterval = null;

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            refreshSystemHealth();
            setupFormValidation();
        });

        // CSRF token setup
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Generate test data using AI
        async function generateTestData() {
            const spinner = document.getElementById('generateSpinner');
            const riskLevel = document.getElementById('riskLevel').value;
            const customPrompt = document.getElementById('customPrompt').value;

            try {
                spinner.style.display = 'inline-block';
                
                const response = await fetch('/test-ui/generate-data', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        risk_level: riskLevel,
                        custom_prompt: customPrompt
                    })
                });

                const result = await response.json();

                if (result.success) {
                    populateForm(result.data);
                    showToast('Test data generated successfully!', 'success');
                } else {
                    showToast('Failed to generate test data: ' + result.error, 'error');
                }
            } catch (error) {
                showToast('Error generating test data: ' + error.message, 'error');
            } finally {
                spinner.style.display = 'none';
            }
        }

        // Quick test scenario generation
        async function generateQuickTest(riskLevel) {
            document.getElementById('riskLevel').value = riskLevel;
            document.getElementById('customPrompt').value = '';
            await generateTestData();
        }

        // Populate form with generated data
        function populateForm(data) {
            const form = document.getElementById('fraudTestForm');
            
            // Helper function to set form field value with better handling
            function setFieldValue(name, value) {
                const field = form.querySelector(`[name="${name}"]`);
                if (field && value !== undefined && value !== null && value !== '') {
                    field.value = value;
                    // Trigger change event for select fields
                    if (field.tagName === 'SELECT') {
                        field.dispatchEvent(new Event('change'));
                    }
                }
            }

            // Helper function to map employment types
            function mapEmploymentType(employmentType) {
                if (!employmentType) return '';
                
                const mapping = {
                    'full-time': 'full_time',
                    'full time': 'full_time',
                    'fulltime': 'full_time',
                    'employed': 'full_time',
                    'part-time': 'part_time',
                    'part time': 'part_time',
                    'parttime': 'part_time',
                    'contract': 'contract',
                    'contractor': 'contract',
                    'self-employed': 'self_employed',
                    'self employed': 'self_employed',
                    'selfemployed': 'self_employed',
                    'unemployed': 'unemployed',
                    'retired': 'unemployed'
                };
                
                return mapping[employmentType.toLowerCase()] || employmentType.toLowerCase().replace(/[^a-z]/g, '_');
            }

            // Helper function to map loan purposes
            function mapLoanPurpose(purpose) {
                if (!purpose) return '';
                
                const mapping = {
                    'vehicle purchase': 'vehicle_purchase',
                    'car purchase': 'vehicle_purchase',
                    'auto purchase': 'vehicle_purchase',
                    'vehicle': 'vehicle_purchase',
                    'car': 'vehicle_purchase',
                    'auto': 'vehicle_purchase',
                    'refinancing': 'refinancing',
                    'refinance': 'refinancing',
                    'other': 'other'
                };
                
                return mapping[purpose.toLowerCase()] || 'vehicle_purchase';
            }

            // Helper function to map province names to codes
            function mapProvince(province) {
                if (!province) return '';
                
                const mapping = {
                    'alberta': 'AB',
                    'british columbia': 'BC',
                    'manitoba': 'MB',
                    'new brunswick': 'NB',
                    'newfoundland and labrador': 'NL',
                    'nova scotia': 'NS',
                    'ontario': 'ON',
                    'prince edward island': 'PE',
                    'quebec': 'QC',
                    'saskatchewan': 'SK',
                    'northwest territories': 'NT',
                    'nunavut': 'NU',
                    'yukon': 'YT'
                };
                
                // If it's already a code, return as is
                if (province.length === 2) return province.toUpperCase();
                
                return mapping[province.toLowerCase()] || province;
            }

            console.log('Populating form with data:', data);

            // Personal Information
            if (data.personal_info) {
                setFieldValue('personal_info[first_name]', data.personal_info.first_name);
                setFieldValue('personal_info[last_name]', data.personal_info.last_name);
                setFieldValue('personal_info[date_of_birth]', data.personal_info.date_of_birth);
                setFieldValue('personal_info[sin]', data.personal_info.sin);
                setFieldValue('personal_info[email]', data.personal_info.email);
                setFieldValue('personal_info[phone]', data.personal_info.phone);
            }

            // Address
            if (data.address) {
                setFieldValue('address[street]', data.address.street);
                setFieldValue('address[city]', data.address.city);
                setFieldValue('address[province]', mapProvince(data.address.province));
                setFieldValue('address[postal_code]', data.address.postal_code);
            }

            // Financial Information
            if (data.financial_info) {
                setFieldValue('financial_info[annual_income]', data.financial_info.annual_income);
                
                // Map employment type with better handling
                const employmentType = mapEmploymentType(data.financial_info.employment_type);
                console.log('Mapping employment type:', data.financial_info.employment_type, '->', employmentType);
                setFieldValue('financial_info[employment_type]', employmentType);
                
                setFieldValue('financial_info[employment_months]', data.financial_info.employment_months);
                setFieldValue('financial_info[employer_name]', data.financial_info.employer_name);
            }

            // Loan Information
            if (data.loan_info) {
                setFieldValue('loan_info[amount]', data.loan_info.amount);
                setFieldValue('loan_info[term_months]', data.loan_info.term_months);
                setFieldValue('loan_info[interest_rate]', data.loan_info.interest_rate);
                
                // Map loan purpose with better handling
                const purpose = mapLoanPurpose(data.loan_info.purpose);
                console.log('Mapping loan purpose:', data.loan_info.purpose, '->', purpose);
                setFieldValue('loan_info[purpose]', purpose);
            }

            // Vehicle Information
            if (data.vehicle_info) {
                setFieldValue('vehicle_info[year]', data.vehicle_info.year);
                setFieldValue('vehicle_info[make]', data.vehicle_info.make);
                setFieldValue('vehicle_info[model]', data.vehicle_info.model);
                setFieldValue('vehicle_info[vin]', data.vehicle_info.vin);
                setFieldValue('vehicle_info[value]', data.vehicle_info.value);
                setFieldValue('vehicle_info[mileage]', data.vehicle_info.mileage);
            }
        }

        // Submit fraud detection form
        document.getElementById('fraudTestForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const spinner = document.getElementById('submitSpinner');
            const formData = new FormData(this);
            const data = {};

            // Convert FormData to nested object
            for (let [key, value] of formData.entries()) {
                const keys = key.match(/([^[\]]+)/g);
                let current = data;
                
                for (let i = 0; i < keys.length - 1; i++) {
                    if (!current[keys[i]]) current[keys[i]] = {};
                    current = current[keys[i]];
                }
                
                current[keys[keys.length - 1]] = value;
            }

            try {
                spinner.style.display = 'inline-block';
                
                const response = await fetch('/api/applications', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (response.ok) {
                    currentJobId = result.job_id;
                    showToast('Fraud detection started! Job ID: ' + currentJobId, 'info');
                    startPolling();
                } else {
                    showToast('Error: ' + (result.message || 'Unknown error'), 'error');
                }
            } catch (error) {
                showToast('Error submitting form: ' + error.message, 'error');
            } finally {
                spinner.style.display = 'none';
            }
        });

        // Start polling for results
        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            
            pollInterval = setInterval(async () => {
                try {
                    const response = await fetch(`/api/decision/${currentJobId}`);
                    const result = await response.json();

                    // Handle different status values
                    if (result.status === 'decided' || result.status === 'completed' || 
                        result.status === 'failed' || result.status === 'error') {
                        clearInterval(pollInterval);
                        displayResults(result);
                    } else if (result.status === 'processing' || result.status === 'queued') {
                        // Continue polling, optionally show progress
                        showToast(`Status: ${result.status}`, 'info');
                    }
                } catch (error) {
                    console.error('Polling error:', error);
                    clearInterval(pollInterval);
                    showToast('Error checking results. Please try again.', 'error');
                }
            }, 2000); // Poll every 2 seconds instead of 1
        }

        // Display results
        function displayResults(result) {
            const resultsCard = document.getElementById('resultsCard');
            const resultsContent = document.getElementById('resultsContent');
            
            let statusClass = '';
            let statusIcon = '';
            let statusText = '';

            // Handle different response formats
            const decision = result.decision?.final_decision || result.final_decision;
            const scores = result.scores || {};
            const explainability = result.explainability || {};
            const timing = result.timing || {};

            if (decision === 'approve') {
                statusClass = 'result-approve';
                statusIcon = 'bi-check-circle-fill text-success';
                statusText = 'APPROVED';
            } else if (decision === 'review') {
                statusClass = 'result-review';
                statusIcon = 'bi-exclamation-triangle-fill text-warning';
                statusText = 'REVIEW REQUIRED';
            } else if (decision === 'decline') {
                statusClass = 'result-decline';
                statusIcon = 'bi-x-circle-fill text-danger';
                statusText = 'DECLINED';
            } else {
                statusClass = 'result-review';
                statusIcon = 'bi-clock-fill text-info';
                statusText = result.status?.toUpperCase() || 'PROCESSING';
            }

            resultsCard.className = `card mb-4 result-card ${statusClass}`;

            // Extract scores with fallbacks
            const ruleScore = scores.rule_score || result.rule_score || 0;
            const mlScore = scores.confidence_score || result.ml_confidence || result.confidence_score || 0;
            const llmScore = scores.adjudicator_score || result.llm_score || result.adjudicator_score || 0;

            // Extract reasons with fallbacks
            const reasons = result.decision?.reasons || explainability.adjudicator_rationale || result.reasons || [];
            const ruleFlags = explainability.rule_flags || [];
            const topFeatures = explainability.top_features || [];

            const html = `
                <div class="text-center mb-4">
                    <i class="${statusIcon}" style="font-size: 3rem;"></i>
                    <h3 class="mt-2">${statusText}</h3>
                    <p class="text-muted">Processing Time: ${timing.total_ms || result.processing_time_ms || 'N/A'}ms</p>
                    ${result.job_id ? `<small class="text-muted">Job ID: ${result.job_id}</small>` : ''}
                </div>

                <div class="row text-center mb-4">
                    <div class="col-4">
                        <div class="border rounded p-2">
                            <small class="text-muted">Rules Score</small>
                            <div class="h5 mb-0">${Math.round(ruleScore * 100)}%</div>
                            <small class="text-muted">${scores.rule_band || 'N/A'}</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2">
                            <small class="text-muted">ML Confidence</small>
                            <div class="h5 mb-0">${Math.round(mlScore * 100)}%</div>
                            <small class="text-muted">${scores.confidence_band || 'N/A'}</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2">
                            <small class="text-muted">LLM Score</small>
                            <div class="h5 mb-0">${Math.round(llmScore * 100)}%</div>
                            <small class="text-muted">${scores.adjudicator_band || 'N/A'}</small>
                        </div>
                    </div>
                </div>

                ${reasons.length > 0 ? `
                <div class="mb-3">
                    <h6>Decision Reasons:</h6>
                    <ul class="list-unstyled">
                        ${reasons.map(reason => `<li><i class="bi bi-arrow-right me-2"></i>${reason}</li>`).join('')}
                    </ul>
                </div>
                ` : ''}

                ${ruleFlags.length > 0 ? `
                <div class="mb-3">
                    <h6>Rule Flags:</h6>
                    <div class="d-flex flex-wrap gap-1">
                        ${ruleFlags.map(flag => `<span class="badge bg-warning text-dark">${flag}</span>`).join('')}
                    </div>
                </div>
                ` : ''}

                ${topFeatures.length > 0 ? `
                <div class="mb-3">
                    <h6>Top Risk Features:</h6>
                    <ul class="list-unstyled">
                        ${topFeatures.slice(0, 5).map(feature => {
                            // Handle both string and object formats
                            const featureName = typeof feature === 'string' ? feature : 
                                               (feature.feature_name || feature.name || feature.feature || JSON.stringify(feature));
                            const importance = typeof feature === 'object' && feature.importance ? 
                                             ` (${Math.round(feature.importance * 100)}%)` : '';
                            return `<li><small><i class="bi bi-dot me-1"></i>${featureName}${importance}</small></li>`;
                        }).join('')}
                    </ul>
                </div>
                ` : ''}

                ${timing.received_at ? `
                <div class="mb-3">
                    <h6>Timeline:</h6>
                    <small class="text-muted">
                        Received: ${new Date(timing.received_at).toLocaleString()}<br>
                        ${timing.decided_at ? `Decided: ${new Date(timing.decided_at).toLocaleString()}` : 'Still processing...'}
                    </small>
                </div>
                ` : ''}

                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-primary btn-sm" onclick="showRawData('${result.job_id}')">
                        <i class="bi bi-code me-1"></i>
                        View Raw Data
                    </button>
                    <button class="btn btn-outline-success btn-sm" onclick="exportResults('${result.job_id}')">
                        <i class="bi bi-download me-1"></i>
                        Export Results
                    </button>
                    ${result.status === 'decided' ? `
                    <button class="btn btn-outline-info btn-sm" onclick="runNewTest()">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Run New Test
                    </button>
                    ` : ''}
                </div>
            `;

            resultsContent.innerHTML = html;
            resultsCard.style.display = 'block';
        }

        // Refresh system health
        async function refreshSystemHealth() {
            try {
                const response = await fetch('/test-ui/system-health');
                const health = await response.json();
                
                displaySystemHealth(health);
            } catch (error) {
                console.error('Health check failed:', error);
                document.getElementById('systemHealth').innerHTML = `
                    <div class="text-center text-danger">
                        <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                        <p class="mt-2">Health check failed</p>
                    </div>
                `;
            }
        }

        // Display system health
        function displaySystemHealth(health) {
            const container = document.getElementById('systemHealth');
            
            const services = Object.entries(health.services).map(([name, status]) => {
                const statusClass = status.status === 'healthy' ? 'status-healthy' : 'status-unhealthy';
                const responseTime = status.response_time ? ` (${status.response_time}ms)` : '';
                
                // Special handling for queue worker to show additional info
                let extraInfo = '';
                if (name === 'queue_worker') {
                    if (status.status === 'healthy') {
                        extraInfo = ` (${status.active_workers} workers, ${status.pending_jobs} pending)`;
                    } else {
                        extraInfo = ` (${status.pending_jobs || 0} pending jobs)`;
                    }
                }
                
                return `
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <span class="status-indicator ${statusClass}"></span>
                            ${name.replace('_', ' ').toUpperCase()}
                            ${status.status === 'unhealthy' && status.suggestion ? 
                                `<br><small class="text-muted ms-3">${status.suggestion}</small>` : ''}
                        </div>
                        <small class="text-muted">${status.status}${responseTime}${extraInfo}</small>
                    </div>
                `;
            }).join('');

            const overallClass = health.overall_status === 'healthy' ? 'text-success' : 'text-warning';
            
            container.innerHTML = `
                <div class="text-center mb-3">
                    <div class="h4 ${overallClass}">
                        <i class="bi bi-${health.overall_status === 'healthy' ? 'check-circle' : 'exclamation-triangle'}"></i>
                        ${health.overall_status.toUpperCase()}
                    </div>
                    <small class="text-muted">Last checked: ${new Date(health.timestamp).toLocaleTimeString()}</small>
                </div>
                ${services}
            `;
        }

        // Clear form
        function clearForm() {
            document.getElementById('fraudTestForm').reset();
            document.getElementById('resultsCard').style.display = 'none';
        }

        // Fill sample data
        function fillSampleData() {
            const sampleData = {
                personal_info: {
                    first_name: 'John',
                    last_name: 'Doe',
                    date_of_birth: '1985-03-15',
                    sin: '123456789',
                    email: 'john.doe@example.com',
                    phone: '416-555-0123'
                },
                address: {
                    street: '123 Main Street',
                    city: 'Toronto',
                    province: 'ON',
                    postal_code: 'M5V 3A8'
                },
                financial_info: {
                    annual_income: 75000,
                    employment_type: 'full_time',
                    employment_months: 24,
                    employer_name: 'Tech Corp Inc'
                },
                loan_info: {
                    amount: 25000,
                    term_months: 60,
                    interest_rate: 5.99,
                    purpose: 'vehicle_purchase'
                },
                vehicle_info: {
                    year: 2020,
                    make: 'Toyota',
                    model: 'Camry',
                    vin: '1HGBH41JXMN109186',
                    value: 28000,
                    mileage: 45000
                }
            };
            
            populateForm(sampleData);
        }

        // Setup form validation
        function setupFormValidation() {
            const form = document.getElementById('fraudTestForm');
            const inputs = form.querySelectorAll('input, select');
            
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    validateField(this);
                });
            });
        }

        // Validate individual field
        function validateField(field) {
            const isValid = field.checkValidity();
            
            if (isValid) {
                field.classList.remove('is-invalid');
                field.classList.add('is-valid');
            } else {
                field.classList.remove('is-valid');
                field.classList.add('is-invalid');
            }
        }

        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            
            const toastId = 'toast-' + Date.now();
            const bgClass = {
                'success': 'bg-success',
                'error': 'bg-danger',
                'warning': 'bg-warning',
                'info': 'bg-info'
            }[type] || 'bg-info';

            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = `toast align-items-center text-white ${bgClass} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;

            toastContainer.appendChild(toast);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Remove toast after it's hidden
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }

        // Create toast container
        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
            return container;
        }

        // Show raw data modal
        function showRawData(jobId) {
            // This would open a modal with raw JSON data
            showToast('Raw data viewer coming soon!', 'info');
        }

        // Export results
        function exportResults(jobId) {
            // This would export results as JSON/PDF
            showToast('Export functionality coming soon!', 'info');
        }

        // Run new test
        function runNewTest() {
            clearForm();
            currentJobId = null;
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            showToast('Ready for new test!', 'success');
        }
    </script>
</body>
</html>
