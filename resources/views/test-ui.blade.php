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
            <div class="d-flex align-items-center">
                <span class="navbar-brand mb-0 h1 me-4">
                    <i class="bi bi-shield-check me-2"></i>
                    Fraud Detection System
                </span>
                
                <!-- Navigation Menu -->
                <ul class="navbar-nav flex-row me-auto">
                    <li class="nav-item me-3">
                        <a class="nav-link active" href="#" onclick="showSection('testing')" id="nav-testing">
                            <i class="bi bi-bug me-1"></i>
                            Testing
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="showSection('training')" id="nav-training">
                            <i class="bi bi-robot me-1"></i>
                            Model Training
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="d-flex align-items-center">
                <span class="text-white-50 me-3">AI-Powered Platform</span>
                <button class="btn btn-outline-light btn-sm" onclick="refreshSystemHealth()">
                    <i class="bi bi-arrow-clockwise me-1"></i>
                    Refresh Status
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Testing Section -->
        <div id="testing-section">
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

        <!-- Training Section -->
        <div id="training-section" style="display: none;">
            <div class="row">
                <!-- Getting Started Guide -->
                <div class="col-12 mb-4">
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-lightbulb me-2"></i>
                                Getting Started with Model Training
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6>Step-by-Step Training Process:</h6>
                                    <ol class="mb-3">
                                        <li><strong>Prepare Training Data:</strong> Upload a CSV file with fraud detection features and labels</li>
                                        <li><strong>Validate Data Quality:</strong> System will check data completeness and quality</li>
                                        <li><strong>Configure Training:</strong> Choose training parameters (we recommend starting with "Balanced" preset)</li>
                                        <li><strong>Monitor Progress:</strong> Track training progress and performance metrics</li>
                                        <li><strong>Evaluate Results:</strong> Review model performance and deploy if satisfactory</li>
                                    </ol>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <strong>First time training?</strong> Start with our sample dataset to understand the process.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-success" onclick="showDataSchemaGuide()">
                                            <i class="bi bi-file-text me-2"></i>
                                            View Data Schema Guide
                                        </button>
                                        <button class="btn btn-outline-info" onclick="downloadSampleDataset()">
                                            <i class="bi bi-download me-2"></i>
                                            Download Sample Dataset
                                        </button>
                                        <button class="btn btn-primary" onclick="showTrainingWizard()">
                                            <i class="bi bi-play-circle me-2"></i>
                                            Start Training Process
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Training Dashboard -->
                <div class="col-12 mb-4">
                    <div class="card">
                        <div class="card-header bg-gradient text-white" style="background: linear-gradient(135deg, #8b5cf6, #a855f7);">
                            <h4 class="mb-0">
                                <i class="bi bi-robot me-2"></i>
                                Model Training Dashboard
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded">
                                        <i class="bi bi-cpu text-primary" style="font-size: 2rem;"></i>
                                        <h5 class="mt-2">Current Model</h5>
                                        <p class="text-muted mb-0">v1.0.0 (Production)</p>
                                        <small class="text-success">85% Accuracy</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded">
                                        <i class="bi bi-graph-up text-success" style="font-size: 2rem;"></i>
                                        <h5 class="mt-2">Training Jobs</h5>
                                        <p class="text-muted mb-0" id="trainingJobsCount">Loading...</p>
                                        <small class="text-muted">This month</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded">
                                        <i class="bi bi-database text-info" style="font-size: 2rem;"></i>
                                        <h5 class="mt-2">Datasets</h5>
                                        <p class="text-muted mb-0" id="datasetsCount">Loading...</p>
                                        <small class="text-muted">Available</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded">
                                        <i class="bi bi-lightning text-warning" style="font-size: 2rem;"></i>
                                        <h5 class="mt-2">Performance</h5>
                                        <p class="text-muted mb-0">92% Precision</p>
                                        <small class="text-success">↑ 3% vs last model</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Active Training Jobs Monitor -->
                            <div class="row mt-4" id="activeTrainingSection" style="display: none;">
                                <div class="col-12">
                                    <h6>Active Training Jobs</h6>
                                    <div id="activeTrainingJobs">
                                        <!-- Active jobs will be populated here -->
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <h6>Quick Actions</h6>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-primary" onclick="showTrainingWizard()">
                                            <i class="bi bi-plus-circle me-2"></i>
                                            Start New Training
                                        </button>
                                        <button class="btn btn-outline-secondary" onclick="showDatasetManager()">
                                            <i class="bi bi-upload me-2"></i>
                                            Upload Dataset
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>Recent Training Jobs</h6>
                                    <div id="recentTrainingJobs">
                                        <div class="text-center text-muted">
                                            <div class="spinner-border spinner-border-sm me-2"></div>
                                            Loading recent jobs...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Schema Guide Modal -->
                <div class="modal fade" id="dataSchemaModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-file-text me-2"></i>
                                    Training Data Schema Guide
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Required Columns</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>Column Name</th>
                                                        <th>Type</th>
                                                        <th>Description</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><code>is_fraud</code></td>
                                                        <td>boolean</td>
                                                        <td>Target variable (0=legitimate, 1=fraud)</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>applicant_age</code></td>
                                                        <td>integer</td>
                                                        <td>Age of applicant in years</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>annual_income</code></td>
                                                        <td>float</td>
                                                        <td>Annual income in CAD</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>loan_amount</code></td>
                                                        <td>float</td>
                                                        <td>Requested loan amount</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>debt_to_income_ratio</code></td>
                                                        <td>float</td>
                                                        <td>Debt to income ratio (0-1)</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>credit_score</code></td>
                                                        <td>integer</td>
                                                        <td>Credit score (300-850)</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>employment_months</code></td>
                                                        <td>integer</td>
                                                        <td>Months at current employment</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>vehicle_age</code></td>
                                                        <td>integer</td>
                                                        <td>Age of vehicle in years</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>loan_to_value_ratio</code></td>
                                                        <td>float</td>
                                                        <td>Loan amount / vehicle value</td>
                                                    </tr>
                                                    <tr>
                                                        <td><code>previous_applications</code></td>
                                                        <td>integer</td>
                                                        <td>Number of previous applications</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Sample CSV Format</h6>
                                        <pre class="bg-light p-3 rounded"><code>is_fraud,applicant_age,annual_income,loan_amount,debt_to_income_ratio,credit_score,employment_months,vehicle_age,loan_to_value_ratio,previous_applications
0,35,75000,25000,0.35,720,24,3,0.85,0
1,22,35000,45000,0.65,580,6,8,1.2,3
0,45,95000,30000,0.25,780,60,2,0.75,1
1,28,40000,35000,0.55,620,12,10,1.1,2</code></pre>
                                        
                                        <h6 class="mt-4">Data Quality Requirements</h6>
                                        <ul class="list-unstyled">
                                            <li><i class="bi bi-check-circle text-success me-2"></i>Minimum 1,000 records recommended</li>
                                            <li><i class="bi bi-check-circle text-success me-2"></i>At least 5% fraud cases (positive class)</li>
                                            <li><i class="bi bi-check-circle text-success me-2"></i>No more than 10% missing values per column</li>
                                            <li><i class="bi bi-check-circle text-success me-2"></i>Balanced representation across age groups</li>
                                            <li><i class="bi bi-check-circle text-success me-2"></i>CSV format with header row</li>
                                        </ul>
                                        
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <strong>Tip:</strong> The system will automatically validate your data and provide quality scores for each column.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary" onclick="downloadSampleDataset()">
                                    <i class="bi bi-download me-1"></i>
                                    Download Sample
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Training Wizard Modal -->
                <div class="modal fade" id="trainingWizardModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-magic me-2"></i>
                                    Training Wizard
                                    <span class="badge bg-secondary ms-2" id="wizardStepIndicator">Step 1 of 3</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Progress Bar -->
                                <div class="progress mb-4" style="height: 8px;">
                                    <div class="progress-bar" id="wizardProgressBar" style="width: 33%"></div>
                                </div>

                                <!-- Step 1: Dataset Selection -->
                                <div id="wizard-step-1" class="wizard-step">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-database me-2"></i>
                                        Step 1: Select Training Dataset
                                    </h6>
                                    <div class="alert alert-info">
                                        <i class="bi bi-lightbulb me-2"></i>
                                        Choose a dataset with at least 1,000 records and good quality score (>70%) for best results.
                                    </div>
                                    <div id="datasetSelection">
                                        <div class="text-center">
                                            <div class="spinner-border text-primary"></div>
                                            <p class="mt-2">Loading available datasets...</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 2: Training Configuration -->
                                <div id="wizard-step-2" class="wizard-step" style="display: none;">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-gear me-2"></i>
                                        Step 2: Training Configuration
                                    </h6>
                                    <div class="alert alert-success">
                                        <i class="bi bi-check-circle me-2"></i>
                                        For your first training, we recommend using the "Balanced" preset which provides good performance in reasonable time.
                                    </div>
                                    <form id="trainingConfigForm">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">
                                                    Training Name
                                                    <i class="bi bi-question-circle text-muted" title="Give your training a descriptive name"></i>
                                                </label>
                                                <input type="text" class="form-control" name="name" required 
                                                       placeholder="e.g., Fraud Model v2.0">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">
                                                    Training Preset
                                                    <i class="bi bi-question-circle text-muted" title="Choose training speed vs accuracy tradeoff"></i>
                                                </label>
                                                <select class="form-select" name="preset" onchange="updatePresetDescription()">
                                                    <option value="fast">Fast (5-10 minutes, good for testing)</option>
                                                    <option value="balanced" selected>Balanced (15-30 minutes, recommended)</option>
                                                    <option value="thorough">Thorough (45-90 minutes, best accuracy)</option>
                                                </select>
                                                <small class="text-muted" id="presetDescription">
                                                    Balanced training provides good performance with reasonable training time.
                                                </small>
                                            </div>
                                            <div class="col-12 mb-3">
                                                <label class="form-label">Description (Optional)</label>
                                                <textarea class="form-control" name="description" rows="2" 
                                                         placeholder="Describe what makes this training unique or what you're testing"></textarea>
                                            </div>
                                        </div>
                                        
                                        <!-- Advanced Options -->
                                        <div class="accordion" id="advancedOptions">
                                            <div class="accordion-item">
                                                <h2 class="accordion-header">
                                                    <button class="accordion-button collapsed" type="button" 
                                                            data-bs-toggle="collapse" data-bs-target="#advancedCollapse">
                                                        <i class="bi bi-sliders me-2"></i>
                                                        Advanced Options (Optional)
                                                    </button>
                                                </h2>
                                                <div id="advancedCollapse" class="accordion-collapse collapse">
                                                    <div class="accordion-body">
                                                        <div class="alert alert-warning">
                                                            <i class="bi bi-exclamation-triangle me-2"></i>
                                                            Only modify these if you understand machine learning parameters.
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">
                                                                    Cross-Validation Folds
                                                                    <i class="bi bi-question-circle text-muted" title="Number of folds for cross-validation (3-10)"></i>
                                                                </label>
                                                                <input type="number" class="form-control" name="cv_folds" 
                                                                       value="5" min="3" max="10">
                                                                <small class="text-muted">Higher values = more robust validation, longer training</small>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label class="form-label">
                                                                    Test Size (%)
                                                                    <i class="bi bi-question-circle text-muted" title="Percentage of data reserved for testing"></i>
                                                                </label>
                                                                <input type="number" class="form-control" name="test_size" 
                                                                       value="20" min="10" max="40">
                                                                <small class="text-muted">Percentage of data held out for final testing</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Step 3: Review and Start -->
                                <div id="wizard-step-3" class="wizard-step" style="display: none;">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-eye me-2"></i>
                                        Step 3: Review and Start Training
                                    </h6>
                                    <div id="trainingReview">
                                        <!-- Review content will be populated here -->
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" id="wizardPrevBtn" 
                                        onclick="previousWizardStep()" style="display: none;">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="wizardNextBtn" 
                                        onclick="nextWizardStep()">
                                    Next
                                    <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                                <button type="button" class="btn btn-success" id="wizardStartBtn" 
                                        onclick="startTraining()" style="display: none;">
                                    <i class="bi bi-play-fill me-1"></i>
                                    Start Training
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Training Monitor Modal -->
                <div class="modal fade" id="trainingMonitorModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-activity me-2"></i>
                                    Training Monitor
                                    <span class="badge bg-primary ms-2" id="monitorJobName">Job Name</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <!-- Training Progress -->
                                        <div class="card mb-4">
                                            <div class="card-header">
                                                <h6 class="mb-0">Training Progress</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span>Overall Progress</span>
                                                    <span id="overallProgress">0%</span>
                                                </div>
                                                <div class="progress mb-3" style="height: 20px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                                         id="progressBar" style="width: 0%"></div>
                                                </div>
                                                <div class="row text-center">
                                                    <div class="col-3">
                                                        <small class="text-muted">Current Step</small>
                                                        <div id="currentStep">Initializing</div>
                                                    </div>
                                                    <div class="col-3">
                                                        <small class="text-muted">Elapsed Time</small>
                                                        <div id="elapsedTime">00:00:00</div>
                                                    </div>
                                                    <div class="col-3">
                                                        <small class="text-muted">Estimated Remaining</small>
                                                        <div id="estimatedTime">Calculating...</div>
                                                    </div>
                                                    <div class="col-3">
                                                        <small class="text-muted">Status</small>
                                                        <div id="trainingStatus">Running</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Real-time Metrics -->
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Real-time Metrics</h6>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="metricsChart" width="400" height="200"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <!-- Training Configuration -->
                                        <div class="card mb-4">
                                            <div class="card-header">
                                                <h6 class="mb-0">Configuration</h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="trainingConfig">
                                                    <!-- Configuration details will be populated here -->
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Current Metrics -->
                                        <div class="card mb-4">
                                            <div class="card-header">
                                                <h6 class="mb-0">Current Metrics</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row text-center">
                                                    <div class="col-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <small class="text-muted">Accuracy</small>
                                                            <div class="h6 mb-0" id="currentAccuracy">--</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <small class="text-muted">Precision</small>
                                                            <div class="h6 mb-0" id="currentPrecision">--</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <small class="text-muted">Recall</small>
                                                            <div class="h6 mb-0" id="currentRecall">--</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <small class="text-muted">F1-Score</small>
                                                            <div class="h6 mb-0" id="currentF1">--</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Training Log -->
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Training Log</h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="trainingLog" style="height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.8rem;">
                                                    <!-- Log entries will be populated here -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-danger" id="stopTrainingBtn" onclick="stopTraining()">
                                    <i class="bi bi-stop-fill me-1"></i>
                                    Stop Training
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dataset Manager Modal -->
                <div class="modal fade" id="datasetManagerModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-database me-2"></i>
                                    Dataset Manager
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Upload Section -->
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h6 class="mb-0">Upload New Dataset</h6>
                                    </div>
                                    <div class="card-body">
                                        <form id="datasetUploadForm">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Dataset Name</label>
                                                    <input type="text" class="form-control" name="name" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">File</label>
                                                    <input type="file" class="form-control" name="file" 
                                                           accept=".csv,.json" required>
                                                </div>
                                                <div class="col-12 mb-3">
                                                    <label class="form-label">Description</label>
                                                    <textarea class="form-control" name="description" rows="2"></textarea>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-upload me-1"></i>
                                                Upload Dataset
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Existing Datasets -->
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Existing Datasets</h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="datasetsList">
                                            <div class="text-center">
                                                <div class="spinner-border text-primary"></div>
                                                <p class="mt-2">Loading datasets...</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
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

        // Section switching functionality
        function showSection(sectionName) {
            // Hide all sections
            document.getElementById('testing-section').style.display = 'none';
            document.getElementById('training-section').style.display = 'none';
            
            // Show selected section
            document.getElementById(sectionName + '-section').style.display = 'block';
            
            // Update navigation
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            document.getElementById('nav-' + sectionName).classList.add('active');
            
            // Load section-specific data
            if (sectionName === 'training') {
                loadTrainingDashboard();
            }
        }

        // Training section functionality
        async function loadTrainingDashboard() {
            try {
                // Load training jobs count
                const jobsResponse = await fetch('/api/training/jobs?limit=10');
                const jobs = await jobsResponse.json();
                document.getElementById('trainingJobsCount').textContent = jobs.length;
                
                // Load datasets count
                const datasetsResponse = await fetch('/api/training/datasets?limit=100');
                const datasets = await datasetsResponse.json();
                document.getElementById('datasetsCount').textContent = datasets.length;
                
                // Load recent training jobs
                displayRecentTrainingJobs(jobs.slice(0, 5));
                
            } catch (error) {
                console.error('Failed to load training dashboard:', error);
                document.getElementById('trainingJobsCount').textContent = 'Error';
                document.getElementById('datasetsCount').textContent = 'Error';
            }
        }

        function displayRecentTrainingJobs(jobs) {
            const container = document.getElementById('recentTrainingJobs');
            
            if (jobs.length === 0) {
                container.innerHTML = '<p class="text-muted">No recent training jobs</p>';
                return;
            }
            
            const html = jobs.map(job => {
                const statusClass = {
                    'completed': 'text-success',
                    'running': 'text-primary',
                    'failed': 'text-danger',
                    'queued': 'text-warning'
                }[job.status] || 'text-muted';
                
                return `
                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                        <div>
                            <small class="fw-bold">${job.name}</small>
                            <br>
                            <small class="text-muted">${new Date(job.created_at).toLocaleDateString()}</small>
                        </div>
                        <span class="badge ${statusClass}">${job.status}</span>
                    </div>
                `;
            }).join('');
            
            container.innerHTML = html;
        }

        // Training wizard functionality
        let currentWizardStep = 1;
        let selectedDataset = null;

        function showTrainingWizard() {
            currentWizardStep = 1;
            selectedDataset = null;
            updateWizardStep();
            loadAvailableDatasets();
            
            const modal = new bootstrap.Modal(document.getElementById('trainingWizardModal'));
            modal.show();
        }

        function updateWizardStep() {
            // Hide all steps
            document.querySelectorAll('.wizard-step').forEach(step => {
                step.style.display = 'none';
            });
            
            // Show current step
            document.getElementById(`wizard-step-${currentWizardStep}`).style.display = 'block';
            
            // Update buttons
            const prevBtn = document.getElementById('wizardPrevBtn');
            const nextBtn = document.getElementById('wizardNextBtn');
            const startBtn = document.getElementById('wizardStartBtn');
            
            prevBtn.style.display = currentWizardStep > 1 ? 'inline-block' : 'none';
            nextBtn.style.display = currentWizardStep < 3 ? 'inline-block' : 'none';
            startBtn.style.display = currentWizardStep === 3 ? 'inline-block' : 'none';
        }

        function nextWizardStep() {
            if (currentWizardStep === 1 && !selectedDataset) {
                showToast('Please select a dataset first', 'warning');
                return;
            }
            
            if (currentWizardStep === 2) {
                const form = document.getElementById('trainingConfigForm');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                populateTrainingReview();
            }
            
            currentWizardStep++;
            updateWizardStep();
        }

        function previousWizardStep() {
            currentWizardStep--;
            updateWizardStep();
        }

        async function loadAvailableDatasets() {
            try {
                const response = await fetch('/api/training/datasets?status=ready');
                const datasets = await response.json();
                
                const container = document.getElementById('datasetSelection');
                
                if (datasets.length === 0) {
                    container.innerHTML = `
                        <div class="text-center text-muted">
                            <i class="bi bi-database" style="font-size: 3rem;"></i>
                            <p class="mt-2">No datasets available</p>
                            <button class="btn btn-primary" onclick="showDatasetManager()">
                                Upload Dataset
                            </button>
                        </div>
                    `;
                    return;
                }
                
                const html = datasets.map(dataset => `
                    <div class="card mb-2 dataset-option" data-dataset-id="${dataset.id}" 
                         onclick="selectDataset(${dataset.id}, '${dataset.name}')">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">${dataset.name}</h6>
                                    <p class="text-muted mb-1">${dataset.description || 'No description'}</p>
                                    <small class="text-muted">
                                        ${dataset.record_count} records • ${dataset.formatted_file_size}
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-success">Ready</span>
                                    <br>
                                    <small class="text-muted">Quality: ${Math.round(dataset.quality_score * 100)}%</small>
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Failed to load datasets:', error);
                document.getElementById('datasetSelection').innerHTML = `
                    <div class="text-center text-danger">
                        <p>Failed to load datasets</p>
                        <button class="btn btn-outline-primary" onclick="loadAvailableDatasets()">
                            Retry
                        </button>
                    </div>
                `;
            }
        }

        function selectDataset(datasetId, datasetName) {
            selectedDataset = { id: datasetId, name: datasetName };
            
            // Update visual selection
            document.querySelectorAll('.dataset-option').forEach(option => {
                option.classList.remove('border-primary');
            });
            document.querySelector(`[data-dataset-id="${datasetId}"]`).classList.add('border-primary');
            
            showToast(`Selected dataset: ${datasetName}`, 'success');
        }

        function populateTrainingReview() {
            const form = document.getElementById('trainingConfigForm');
            const formData = new FormData(form);
            
            const config = {
                name: formData.get('name'),
                preset: formData.get('preset'),
                description: formData.get('description'),
                cv_folds: formData.get('cv_folds'),
                test_size: formData.get('test_size')
            };
            
            const html = `
                <div class="card">
                    <div class="card-body">
                        <h6>Training Configuration Summary</h6>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Dataset:</strong></td>
                                <td>${selectedDataset.name}</td>
                            </tr>
                            <tr>
                                <td><strong>Training Name:</strong></td>
                                <td>${config.name}</td>
                            </tr>
                            <tr>
                                <td><strong>Preset:</strong></td>
                                <td>${config.preset}</td>
                            </tr>
                            <tr>
                                <td><strong>Cross-Validation:</strong></td>
                                <td>${config.cv_folds} folds</td>
                            </tr>
                            <tr>
                                <td><strong>Test Size:</strong></td>
                                <td>${config.test_size}%</td>
                            </tr>
                        </table>
                        ${config.description ? `<p><strong>Description:</strong> ${config.description}</p>` : ''}
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Training will begin immediately after clicking "Start Training". 
                            You can monitor progress from the dashboard.
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('trainingReview').innerHTML = html;
        }

        async function startTraining() {
            const form = document.getElementById('trainingConfigForm');
            const formData = new FormData(form);
            
            const trainingRequest = {
                dataset_id: selectedDataset.id,
                name: formData.get('name'),
                description: formData.get('description'),
                preset: formData.get('preset'),
                cv_folds: parseInt(formData.get('cv_folds')),
                test_size: parseFloat(formData.get('test_size')) / 100,
                created_by: 'user' // In a real app, this would be the authenticated user
            };
            
            try {
                const response = await fetch('/api/training/jobs', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(trainingRequest)
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    showToast('Training job started successfully!', 'success');
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('trainingWizardModal'));
                    modal.hide();
                    
                    // Refresh dashboard
                    loadTrainingDashboard();
                } else {
                    showToast('Failed to start training: ' + (result.message || 'Unknown error'), 'error');
                }
                
            } catch (error) {
                showToast('Error starting training: ' + error.message, 'error');
            }
        }

        // Dataset manager functionality
        function showDatasetManager() {
            loadDatasetsList();
            const modal = new bootstrap.Modal(document.getElementById('datasetManagerModal'));
            modal.show();
        }

        async function loadDatasetsList() {
            try {
                const response = await fetch('/api/training/datasets');
                const datasets = await response.json();
                
                const container = document.getElementById('datasetsList');
                
                if (datasets.length === 0) {
                    container.innerHTML = '<p class="text-muted">No datasets uploaded yet</p>';
                    return;
                }
                
                const html = datasets.map(dataset => {
                    const statusClass = {
                        'ready': 'bg-success',
                        'processing': 'bg-warning',
                        'error': 'bg-danger',
                        'uploading': 'bg-info'
                    }[dataset.status] || 'bg-secondary';
                    
                    return `
                        <div class="card mb-2">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1">${dataset.name}</h6>
                                        <p class="text-muted mb-1">${dataset.description || 'No description'}</p>
                                        <small class="text-muted">
                                            ${dataset.record_count} records • ${dataset.formatted_file_size} • 
                                            Uploaded ${new Date(dataset.created_at).toLocaleDateString()}
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge ${statusClass}">${dataset.status}</span>
                                        ${dataset.quality_score ? `<br><small class="text-muted">Quality: ${Math.round(dataset.quality_score * 100)}%</small>` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Failed to load datasets:', error);
                document.getElementById('datasetsList').innerHTML = `
                    <div class="text-center text-danger">
                        <p>Failed to load datasets</p>
                    </div>
                `;
            }
        }

        // Dataset upload functionality
        document.getElementById('datasetUploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('/api/training/datasets/upload', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: formData
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    showToast('Dataset uploaded successfully!', 'success');
                    this.reset();
                    loadDatasetsList();
                } else {
                    showToast('Failed to upload dataset: ' + (result.message || 'Unknown error'), 'error');
                }
                
            } catch (error) {
                showToast('Error uploading dataset: ' + error.message, 'error');
            }
        });

        // Additional training functions
        function showDataSchemaGuide() {
            const modal = new bootstrap.Modal(document.getElementById('dataSchemaModal'));
            modal.show();
        }

        function downloadSampleDataset() {
            // Create sample CSV data
            const sampleData = `is_fraud,applicant_age,annual_income,loan_amount,debt_to_income_ratio,credit_score,employment_months,vehicle_age,loan_to_value_ratio,previous_applications
0,35,75000,25000,0.35,720,24,3,0.85,0
1,22,35000,45000,0.65,580,6,8,1.2,3
0,45,95000,30000,0.25,780,60,2,0.75,1
1,28,40000,35000,0.55,620,12,10,1.1,2
0,52,120000,40000,0.20,800,120,1,0.70,0
1,19,25000,30000,0.80,550,3,12,1.5,5
0,38,85000,28000,0.30,750,36,4,0.80,1
1,31,45000,50000,0.60,600,18,15,1.3,4
0,42,110000,35000,0.25,770,84,2,0.65,0
1,26,30000,40000,0.75,570,8,10,1.4,6`;

            // Create and download file
            const blob = new Blob([sampleData], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'fraud_detection_sample_dataset.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showToast('Sample dataset downloaded successfully!', 'success');
        }

        function updatePresetDescription() {
            const preset = document.querySelector('[name="preset"]').value;
            const descriptions = {
                'fast': 'Fast training uses fewer iterations and simpler parameters. Good for testing and quick experiments.',
                'balanced': 'Balanced training provides good performance with reasonable training time. Recommended for most use cases.',
                'thorough': 'Thorough training uses extensive hyperparameter optimization and cross-validation. Best accuracy but longer training time.'
            };
            
            document.getElementById('presetDescription').textContent = descriptions[preset] || '';
        }

        // Training monitoring functionality
        let trainingMonitorInterval = null;
        let metricsChart = null;

        function showTrainingMonitor(jobId, jobName) {
            document.getElementById('monitorJobName').textContent = jobName;
            
            // Initialize metrics chart
            initializeMetricsChart();
            
            // Start monitoring
            startTrainingMonitor(jobId);
            
            const modal = new bootstrap.Modal(document.getElementById('trainingMonitorModal'));
            modal.show();
            
            // Clean up when modal is closed
            document.getElementById('trainingMonitorModal').addEventListener('hidden.bs.modal', function() {
                stopTrainingMonitor();
            });
        }

        function initializeMetricsChart() {
            const ctx = document.getElementById('metricsChart').getContext('2d');
            
            if (metricsChart) {
                metricsChart.destroy();
            }
            
            metricsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Accuracy',
                            data: [],
                            borderColor: 'rgb(37, 99, 235)',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            tension: 0.1
                        },
                        {
                            label: 'Precision',
                            data: [],
                            borderColor: 'rgb(5, 150, 105)',
                            backgroundColor: 'rgba(5, 150, 105, 0.1)',
                            tension: 0.1
                        },
                        {
                            label: 'Recall',
                            data: [],
                            borderColor: 'rgb(217, 119, 6)',
                            backgroundColor: 'rgba(217, 119, 6, 0.1)',
                            tension: 0.1
                        },
                        {
                            label: 'F1-Score',
                            data: [],
                            borderColor: 'rgb(220, 38, 38)',
                            backgroundColor: 'rgba(220, 38, 38, 0.1)',
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 1
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            });
        }

        function startTrainingMonitor(jobId) {
            if (trainingMonitorInterval) {
                clearInterval(trainingMonitorInterval);
            }
            
            trainingMonitorInterval = setInterval(async () => {
                try {
                    const response = await fetch(`/api/training/jobs/${jobId}/status`);
                    const status = await response.json();
                    
                    updateTrainingProgress(status);
                    
                    if (status.status === 'completed' || status.status === 'failed') {
                        stopTrainingMonitor();
                    }
                } catch (error) {
                    console.error('Training monitor error:', error);
                }
            }, 2000);
        }

        function stopTrainingMonitor() {
            if (trainingMonitorInterval) {
                clearInterval(trainingMonitorInterval);
                trainingMonitorInterval = null;
            }
        }

        function updateTrainingProgress(status) {
            // Update progress bar
            const progress = status.progress || 0;
            document.getElementById('overallProgress').textContent = `${Math.round(progress * 100)}%`;
            document.getElementById('progressBar').style.width = `${progress * 100}%`;
            
            // Update status information
            document.getElementById('currentStep').textContent = status.current_step || 'Processing';
            document.getElementById('trainingStatus').textContent = status.status || 'Running';
            
            // Update elapsed time
            if (status.started_at) {
                const elapsed = new Date() - new Date(status.started_at);
                document.getElementById('elapsedTime').textContent = formatDuration(elapsed);
            }
            
            // Update estimated time
            if (status.estimated_completion) {
                const remaining = new Date(status.estimated_completion) - new Date();
                document.getElementById('estimatedTime').textContent = remaining > 0 ? formatDuration(remaining) : 'Almost done';
            }
            
            // Update current metrics
            if (status.current_metrics) {
                document.getElementById('currentAccuracy').textContent = 
                    status.current_metrics.accuracy ? `${Math.round(status.current_metrics.accuracy * 100)}%` : '--';
                document.getElementById('currentPrecision').textContent = 
                    status.current_metrics.precision ? `${Math.round(status.current_metrics.precision * 100)}%` : '--';
                document.getElementById('currentRecall').textContent = 
                    status.current_metrics.recall ? `${Math.round(status.current_metrics.recall * 100)}%` : '--';
                document.getElementById('currentF1').textContent = 
                    status.current_metrics.f1_score ? `${Math.round(status.current_metrics.f1_score * 100)}%` : '--';
            }
            
            // Update metrics chart
            if (status.metrics_history && metricsChart) {
                updateMetricsChart(status.metrics_history);
            }
            
            // Update training log
            if (status.log_entries) {
                updateTrainingLog(status.log_entries);
            }
            
            // Update configuration display
            if (status.configuration) {
                updateTrainingConfig(status.configuration);
            }
        }

        function updateMetricsChart(metricsHistory) {
            const labels = metricsHistory.map((_, index) => `Epoch ${index + 1}`);
            
            metricsChart.data.labels = labels;
            metricsChart.data.datasets[0].data = metricsHistory.map(m => m.accuracy || 0);
            metricsChart.data.datasets[1].data = metricsHistory.map(m => m.precision || 0);
            metricsChart.data.datasets[2].data = metricsHistory.map(m => m.recall || 0);
            metricsChart.data.datasets[3].data = metricsHistory.map(m => m.f1_score || 0);
            
            metricsChart.update('none');
        }

        function updateTrainingLog(logEntries) {
            const logContainer = document.getElementById('trainingLog');
            const html = logEntries.slice(-20).map(entry => {
                const timestamp = new Date(entry.timestamp).toLocaleTimeString();
                return `<div class="mb-1"><span class="text-muted">[${timestamp}]</span> ${entry.message}</div>`;
            }).join('');
            
            logContainer.innerHTML = html;
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        function updateTrainingConfig(config) {
            const configContainer = document.getElementById('trainingConfig');
            const html = `
                <table class="table table-sm">
                    <tr><td><strong>Dataset:</strong></td><td>${config.dataset_name || 'N/A'}</td></tr>
                    <tr><td><strong>Preset:</strong></td><td>${config.preset || 'N/A'}</td></tr>
                    <tr><td><strong>CV Folds:</strong></td><td>${config.cv_folds || 'N/A'}</td></tr>
                    <tr><td><strong>Test Size:</strong></td><td>${config.test_size ? Math.round(config.test_size * 100) + '%' : 'N/A'}</td></tr>
                    <tr><td><strong>Records:</strong></td><td>${config.total_records || 'N/A'}</td></tr>
                </table>
            `;
            configContainer.innerHTML = html;
        }

        function formatDuration(milliseconds) {
            const seconds = Math.floor(milliseconds / 1000);
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            
            return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        async function stopTraining() {
            if (confirm('Are you sure you want to stop the training? This action cannot be undone.')) {
                try {
                    const response = await fetch(`/api/training/jobs/${currentTrainingJobId}/stop`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken
                        }
                    });
                    
                    if (response.ok) {
                        showToast('Training stopped successfully', 'warning');
                        stopTrainingMonitor();
                    } else {
                        showToast('Failed to stop training', 'error');
                    }
                } catch (error) {
                    showToast('Error stopping training: ' + error.message, 'error');
                }
            }
        }

        // Update wizard step indicators
        function updateWizardStep() {
            // Hide all steps
            document.querySelectorAll('.wizard-step').forEach(step => {
                step.style.display = 'none';
            });
            
            // Show current step
            document.getElementById(`wizard-step-${currentWizardStep}`).style.display = 'block';
            
            // Update step indicator
            document.getElementById('wizardStepIndicator').textContent = `Step ${currentWizardStep} of 3`;
            
            // Update progress bar
            const progressWidth = (currentWizardStep / 3) * 100;
            document.getElementById('wizardProgressBar').style.width = `${progressWidth}%`;
            
            // Update buttons
            const prevBtn = document.getElementById('wizardPrevBtn');
            const nextBtn = document.getElementById('wizardNextBtn');
            const startBtn = document.getElementById('wizardStartBtn');
            
            prevBtn.style.display = currentWizardStep > 1 ? 'inline-block' : 'none';
            nextBtn.style.display = currentWizardStep < 3 ? 'inline-block' : 'none';
            startBtn.style.display = currentWizardStep === 3 ? 'inline-block' : 'none';
        }
    </script>
</body>
</html>
