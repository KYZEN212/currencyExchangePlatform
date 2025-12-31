<!-- Hero Section -->
<section class="hero-section" style="padding: 120px 0 80px;">
    <div class="container">
        <!-- Currency Ticker -->
        <div id="currency-ticker" class="currency-ticker mb-4">
            <div class="container d-flex justify-content-center flex-wrap gap-4">
                <span id="usd-eur">USD/EUR: --</span>
                <span id="gbp-usd">GBP/USD: --</span>
                <span id="usd-jpy">USD/JPY: --</span>
                <span id="usd-mmk">USD/MMK: --</span>
            </div>
        </div>
        
        <div class="row align-items-center">
            <div class="col-lg-6 mb-4 mb-lg-0">
                <h1 class="display-4 fw-bold mb-4">
                    Smart <span class="text-gradient">Currency Exchange</span> Made Simple
                </h1>
                <p class="lead mb-4" style="color: #1e3a1e;">
                    Exchange currencies with real-time rates, low fees, and complete transparency. 
                    Join thousands who trust ACCQURA for their international transactions.
                </p>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="#converter" class="btn btn-primary btn-lg px-4">
                        <i class="fas fa-exchange-alt me-2"></i>Start Converting
                    </a>
                    <a href="#rates" class="btn btn-outline-primary btn-lg px-4">
                        <i class="fas fa-chart-line me-2"></i>View Rates
                    </a>
                </div>
                <div class="row mt-5 pt-3">
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                <i class="fas fa-bolt text-primary fs-4"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 fw-bold">Real-Time</h4>
                                <small class="text-muted">Live exchange rates</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                <i class="fas fa-shield-alt text-primary fs-4"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 fw-bold">Secure</h4>
                                <small class="text-muted">Bank-level security</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                <i class="fas fa-coins text-primary fs-4"></i>
                            </div>
                            <div>
                                <h4 class="mb-0 fw-bold">Multiple Currencies</h4>
                                <small class="text-muted">Local coverage</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <h3 class="fw-bold">Quick Converter</h3>
                            <p class="text-muted">Get instant conversion estimates</p>
                        </div>
                        <div class="converter-widget">
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">From</label>
                                    <select class="form-select" id="quickFrom">
                                        <option value="USD">🇺🇸 USD - US Dollar</option>
                                        <option value="EUR">🇪🇺 EUR - Euro</option>
                                        <option value="GBP">🇬🇧 GBP - British Pound</option>
                                        <option value="JPY">🇯🇵 JPY - Japanese Yen</option>
                                        <option value="MMK">🇲🇲 MMK - Myanma0r Kyat</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">To</label>
                                    <select class="form-select" id="quickTo">
                                        <option value="MMK">🇲🇲 MMK - Myanmar Kyat</option>
                                        <option value="USD">🇺🇸 USD - US Dollar</option>
                                        <option value="EUR">🇪🇺 EUR - Euro</option>
                                        <option value="GBP">🇬🇧 GBP - British Pound</option>
                                        <option value="JPY">🇯🇵 JPY - Japanese Yen</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold">Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">$</span>
                                    <input type="number" class="form-control" id="quickAmount" value="100" min="0" step="0.01">
                                </div>
                            </div>
                            <div class="result-card bg-light p-4 rounded mb-4">
                                <div class="text-center">
                                    <small class="text-muted">CONVERTED AMOUNT</small>
                                    <h2 class="fw-bold text-primary mt-2" id="quickResult">--</h2>
                                    <div class="text-muted small mt-2" id="quickRate">Rate: --</div>
                                </div>
                            </div>
                            <button class="btn btn-primary w-100" id="quickConvert">
                                <i class="fas fa-exchange-alt me-2"></i>Convert Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Main Converter Section -->
<section id="converter" class="section-padding" style="background: #f8f9fa;">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Advanced <span class="text-gradient">Currency Converter</span></h2>
            <p class="lead text-muted">Convert between 150+ currencies with real-time exchange rates</p>
        </div>
        
        <div class="card border-0 shadow-lg">
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- Amount Input -->
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" id="amountSymbol">$</span>
                            <input type="number" class="form-control" id="convertAmount" value="100" min="0" step="0.01">
                        </div>
                    </div>
                    
                    <!-- Swap Button -->
                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                        <button class="btn btn-outline-primary rounded-circle p-3" id="swapCurrencies">
                            <i class="fas fa-exchange-alt"></i>
                        </button>
                    </div>
                    
                    <!-- Converted Amount -->
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Converted Amount</label>
                        <div class="converted-display bg-light p-3 rounded">
                            <h3 class="fw-bold text-primary mb-0" id="convertedAmount">--</h3>
                            <small class="text-muted" id="targetCurrency">Select currency</small>
                        </div>
                    </div>
                </div>
                
                <!-- Currency Selection -->
                <div class="row g-4 mt-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">From Currency</label>
                        <select class="form-select" id="fromCurrency">
                            <option value="USD">🇺🇸 USD - US Dollar</option>
                            <option value="EUR">🇪🇺 EUR - Euro</option>
                            <option value="GBP">🇬🇧 GBP - British Pound</option>
                            <option value="JPY">🇯🇵 JPY - Japanese Yen</option>
                            <option value="MMK">🇲🇲 MMK - Myanmar Kyat</option>
                            <option value="THB">🇹🇭 THB - Thai Baht</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">To Currency</label>
                        <select class="form-select" id="toCurrency">
                            <option value="MMK">🇲🇲 MMK - Myanmar Kyat</option>
                            <option value="USD">🇺🇸 USD - US Dollar</option>
                            <option value="EUR">🇪🇺 EUR - Euro</option>
                            <option value="GBP">🇬🇧 GBP - British Pound</option>
                            <option value="JPY">🇯🇵 JPY - Japanese Yen</option>
                            <option value="THB">🇹🇭 THB - Thai Baht</option>
                        </select>
                    </div>
                </div>
                
                <!-- Rate Info -->
                <div class="row g-4 mt-4">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <span class="text-muted">Exchange Rate</span>
                            <span class="fw-bold" id="exchangeRate">--</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <span class="text-muted">Last Updated</span>
                            <span class="fw-bold" id="lastUpdated"><?php echo date('h:i A'); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Convert Button -->
                <div class="text-center mt-5">
                    <button class="btn btn-primary btn-lg px-5" id="convertButton">
                        <i class="fas fa-calculator me-2"></i>Convert Currency
                    </button>
                </div>
                
                <p class="text-center text-muted small mt-3">
                    Exchange rates are updated in real-time. Rates may vary for actual transactions.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Live Exchange Rates -->
<section id="rates" class="section-padding">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Live <span class="text-gradient">Exchange Rates</span></h2>
            <p class="lead text-muted">Real-time currency values updated every minute</p>
        </div>
        
        <div class="row" id="ratesGrid">
            <!-- Rates will be populated by JavaScript -->
        </div>
        
        <div class="text-center mt-5">
            <div class="dropdown d-inline-block">
                <button class="btn btn-primary px-4 dropdown-toggle" type="button" id="addCurrencyBtn" data-bs-toggle="dropdown">
                    <i class="fas fa-plus-circle me-2"></i>Add More Currencies
                </button>
                <ul class="dropdown-menu shadow" id="currencyDropdown">
                    <li><a class="dropdown-item" href="#" data-currency="CNY">🇨🇳 Chinese Yuan</a></li>
                    <li><a class="dropdown-item" href="#" data-currency="INR">🇮🇳 Indian Rupee</a></li>
                    <li><a class="dropdown-item" href="#" data-currency="KRW">🇰🇷 South Korean Won</a></li>
                    <li><a class="dropdown-item" href="#" data-currency="SGD">🇸🇬 Singapore Dollar</a></li>
                    <li><a class="dropdown-item" href="#" data-currency="AUD">🇦🇺 Australian Dollar</a></li>
                    <li><a class="dropdown-item" href="#" data-currency="CAD">🇨🇦 Canadian Dollar</a></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section id="features" class="section-padding" style="background: #f8f9fa;">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Why Choose <span class="text-gradient">ACCQURA</span></h2>
            <p class="lead text-muted">Experience the difference with our premium features</p>
        </div>
        
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-hover border-0 h-100">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-4 mb-4 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-bolt text-primary fs-3"></i>
                        </div>
                        <h5 class="fw-bold mb-3">Real-Time Rates</h5>
                        <p class="text-muted">Get live exchange rates updated every minute from multiple reliable sources.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-hover border-0 h-100">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-4 mb-4 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-lock text-primary fs-3"></i>
                        </div>
                        <h5 class="fw-bold mb-3">Secure Transactions</h5>
                        <p class="text-muted">Bank-level encryption and security protocols to protect your financial data.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-hover border-0 h-100">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-4 mb-4 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-coins text-primary fs-3"></i>
                        </div>
                        <h5 class="fw-bold mb-3">Low Fees</h5>
                        <p class="text-muted">Competitive exchange rates with transparent, low transaction fees.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-hover border-0 h-100">
                    <div class="card-body text-center p-4">
                        <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-4 mb-4 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-headset text-primary fs-3"></i>
                        </div>
                        <h5 class="fw-bold mb-3">24/7 Support</h5>
                        <p class="text-muted">Round-the-clock customer support to assist you with any queries.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- About Section -->
<section id="about" class="section-padding">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 mb-4 mb-lg-0">
                <h2 class="fw-bold mb-4">About <span class="text-gradient">ACCQURA</span></h2>
                <p class="lead mb-4">We're revolutionizing currency exchange with technology and transparency.</p>
                <p class="text-muted mb-4">
                    Founded with the mission to simplify international currency exchange, ACCQURA provides 
                    real-time exchange rates, secure transactions, and a user-friendly platform for both 
                    individuals and businesses.
                </p>
                <!-- <div class="row">
                    <div class="col-6 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                <i class="fas fa-users text-primary"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">50,000+ Users</h6>
                                <small class="text-muted">Trusted worldwide</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                <i class="fas fa-globe text-primary"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Multi Currencies</h6>
                                <small class="text-muted">Global reach</small>
                            </div>
                        </div>
                    </div>
                </div> -->
                <div class="mt-4">
                    <a href="#" class="btn btn-primary me-2">Learn More</a>
                    <a href="../html/registration.html" class="btn btn-outline-primary">Get Started</a>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden">
                    <div class="row g-0">
                        <div class="col-md-6 p-4">
                            <h5 class="fw-bold mb-3"><i class="fas fa-chart-line text-primary me-2"></i> Market Trends</h5>
                            <p class="text-muted small">Track currency performance with our advanced analytics tools.</p>
                        </div>
                        <div class="col-md-6 p-4 bg-light">
                            <h5 class="fw-bold mb-3"><i class="fas fa-history text-primary me-2"></i> Historical Data</h5>
                            <p class="text-muted small">Access up to 10 years of historical exchange rate data.</p>
                        </div>
                        <div class="col-md-6 p-4 bg-light">
                            <h5 class="fw-bold mb-3"><i class="fas fa-mobile-alt text-primary me-2"></i> Mobile App</h5>
                            <p class="text-muted small">Exchange currencies on the go with our iOS and Android apps.</p>
                        </div>
                        <div class="col-md-6 p-4">
                            <h5 class="fw-bold mb-3"><i class="fas fa-bell text-primary me-2"></i> Rate Alerts</h5>
                            <p class="text-muted small">Get notified when your desired exchange rate is reached.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Call to Action -->
<section class="bg-gradient-primary text-white py-5">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="fw-bold mb-4">Ready to Start Exchanging?</h2>
                <p class="lead mb-5">Join thousands of satisfied users who trust ACCQURA for their currency exchange needs.</p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="../html/registration.html" class="btn btn-light btn-lg px-4">
                        <i class="fas fa-user-plus me-2"></i>Create Free Account
                    </a>
                    <a href="#converter" class="btn btn-outline-light btn-lg px-4">
                        <i class="fas fa-exchange-alt me-2"></i>Try Converter
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    // Sample exchange rates (in a real app, these would come from an API)
    const exchangeRates = {
        'USD': { 'MMK': 2100, 'EUR': 0.92, 'GBP': 0.79, 'JPY': 150, 'THB': 35 },
        'EUR': { 'USD': 1.09, 'MMK': 2280, 'GBP': 0.86, 'JPY': 163, 'THB': 38 },
        'GBP': { 'USD': 1.27, 'EUR': 1.16, 'MMK': 2660, 'JPY': 190, 'THB': 44 },
        'JPY': { 'USD': 0.0067, 'EUR': 0.0061, 'GBP': 0.0053, 'MMK': 14, 'THB': 0.23 },
        'MMK': { 'USD': 0.00048, 'EUR': 0.00044, 'GBP': 0.00038, 'JPY': 0.071, 'THB': 0.017 },
        'THB': { 'USD': 0.028, 'EUR': 0.026, 'GBP': 0.023, 'JPY': 4.35, 'MMK': 60 }
    };
    
    // Currency symbols
    const currencySymbols = {
        'USD': '$', 'EUR': '€', 'GBP': '£', 'JPY': '¥', 'MMK': 'K', 'THB': '฿'
    };
    
    // Currency names
    const currencyNames = {
        'USD': 'US Dollar', 'EUR': 'Euro', 'GBP': 'British Pound', 
        'JPY': 'Japanese Yen', 'MMK': 'Myanmar Kyat', 'THB': 'Thai Baht'
    };
    
    // Currency flags (emoji)
    const currencyFlags = {
        'USD': '🇺🇸', 'EUR': '🇪🇺', 'GBP': '🇬🇧', 'JPY': '🇯🇵', 'MMK': '🇲🇲', 'THB': '🇹🇭'
    };
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initializeTicker();
        initializeQuickConverter();
        initializeMainConverter();
        initializeRatesGrid();
        setupEventListeners();
    });
    
    // Currency Ticker
    function initializeTicker() {
        const pairs = [
            { id: 'usd-eur', from: 'USD', to: 'EUR' },
            { id: 'gbp-usd', from: 'GBP', to: 'USD' },
            { id: 'usd-jpy', from: 'USD', to: 'JPY' },
            { id: 'usd-mmk', from: 'USD', to: 'MMK' }
        ];
        
        function updateTicker() {
            pairs.forEach(pair => {
                const rate = getExchangeRate(pair.from, pair.to);
                const change = (Math.random() - 0.5) * 0.01; // Simulated change
                const element = document.getElementById(pair.id);
                if (element) {
                    element.innerHTML = `${pair.from}/${pair.to}: ${rate.toFixed(4)} 
                    <span class="${change >= 0 ? 'up' : 'down'}">${change >= 0 ? '+' : ''}${(change*100).toFixed(2)}%</span>`;
                }
            });
        }
        
        updateTicker();
        setInterval(updateTicker, 5000); // Update every 5 seconds
    }
    
    // Quick Converter
    function initializeQuickConverter() {
        const quickFrom = document.getElementById('quickFrom');
        const quickTo = document.getElementById('quickTo');
        const quickAmount = document.getElementById('quickAmount');
        const quickResult = document.getElementById('quickResult');
        const quickRate = document.getElementById('quickRate');
        const quickConvert = document.getElementById('quickConvert');
        
        function updateQuickConverter() {
            const from = quickFrom.value;
            const to = quickTo.value;
            const amount = parseFloat(quickAmount.value) || 0;
            
            if (from && to && amount > 0) {
                const rate = getExchangeRate(from, to);
                const result = amount * rate;
                
                quickResult.textContent = `${currencySymbols[to] || ''}${result.toFixed(2)}`;
                quickRate.textContent = `Rate: 1 ${from} = ${rate.toFixed(4)} ${to}`;
            }
        }
        
        quickFrom.addEventListener('change', updateQuickConverter);
        quickTo.addEventListener('change', updateQuickConverter);
        quickAmount.addEventListener('input', updateQuickConverter);
        quickConvert.addEventListener('click', updateQuickConverter);
        
        updateQuickConverter();
    }
    
    // Main Converter
    function initializeMainConverter() {
        const fromCurrency = document.getElementById('fromCurrency');
        const toCurrency = document.getElementById('toCurrency');
        const convertAmount = document.getElementById('convertAmount');
        const amountSymbol = document.getElementById('amountSymbol');
        const convertedAmount = document.getElementById('convertedAmount');
        const targetCurrency = document.getElementById('targetCurrency');
        const exchangeRate = document.getElementById('exchangeRate');
        const convertButton = document.getElementById('convertButton');
        const swapCurrencies = document.getElementById('swapCurrencies');
        
        function updateMainConverter() {
            const from = fromCurrency.value;
            const to = toCurrency.value;
            const amount = parseFloat(convertAmount.value) || 0;
            
            // Update symbol
            amountSymbol.textContent = currencySymbols[from] || '$';
            
            if (from && to && amount > 0) {
                const rate = getExchangeRate(from, to);
                const result = amount * rate;
                
                convertedAmount.textContent = `${currencySymbols[to] || ''}${result.toFixed(2)}`;
                targetCurrency.textContent = currencyNames[to] || '';
                exchangeRate.textContent = `1 ${from} = ${rate.toFixed(4)} ${to}`;
                
                // Update last updated time
                document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString([], 
                    { hour: '2-digit', minute: '2-digit' });
            }
        }
        
        function swapConverterCurrencies() {
            const fromValue = fromCurrency.value;
            const toValue = toCurrency.value;
            
            fromCurrency.value = toValue;
            toCurrency.value = fromValue;
            
            updateMainConverter();
        }
        
        fromCurrency.addEventListener('change', updateMainConverter);
        toCurrency.addEventListener('change', updateMainConverter);
        convertAmount.addEventListener('input', updateMainConverter);
        convertButton.addEventListener('click', updateMainConverter);
        swapCurrencies.addEventListener('click', swapConverterCurrencies);
        
        updateMainConverter();
    }
    
    // Live Rates Grid
    function initializeRatesGrid() {
        const ratesGrid = document.getElementById('ratesGrid');
        const baseCurrencies = ['USD', 'EUR', 'GBP', 'JPY'];
        
        baseCurrencies.forEach(currency => {
            if (currency !== 'MMK') {
                createRateCard(currency);
            }
        });
        
        // Add currency dropdown functionality
        document.getElementById('currencyDropdown').addEventListener('click', function(e) {
            if (e.target.classList.contains('dropdown-item')) {
                e.preventDefault();
                const currency = e.target.getAttribute('data-currency');
                if (!document.querySelector(`#ratesGrid .card[data-currency="${currency}"]`)) {
                    createRateCard(currency);
                }
            }
        });
    }
    
    function createRateCard(baseCurrency) {
        const ratesGrid = document.getElementById('ratesGrid');
        const card = document.createElement('div');
        card.className = 'col-md-6 col-lg-3 mb-4';
        card.setAttribute('data-currency', baseCurrency);
        
        const targetCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'MMK', 'THB'].filter(c => c !== baseCurrency);
        
        let ratesHTML = '';
        targetCurrencies.forEach(target => {
            if (target !== baseCurrency) {
                const rate = getExchangeRate(baseCurrency, target);
                const change = (Math.random() - 0.5) * 0.02; // Simulated change
                ratesHTML += `
                    <tr>
                        <td class="fw-medium">${target}</td>
                        <td class="text-end fw-bold">${rate.toFixed(4)}</td>
                        <td class="text-end ${change >= 0 ? 'text-success' : 'text-danger'}">
                            <i class="fas fa-arrow-${change >= 0 ? 'up' : 'down'}"></i> ${Math.abs(change*100).toFixed(2)}%
                        </td>
                    </tr>
                `;
            }
        });
        
        card.innerHTML = `
            <div class="card card-hover h-100 border-0">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex align-items-center">
                            <div class="currency-flag fs-4 me-2">${currencyFlags[baseCurrency] || '🏳️'}</div>
                            <h5 class="mb-0 fw-bold">${baseCurrency}</h5>
                        </div>
                        <button class="btn btn-sm btn-outline-danger remove-rate" title="Remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>${ratesHTML}</tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <small class="text-muted">Updated: <span class="last-updated">just now</span></small>
                    </div>
                </div>
            </div>
        `;
        
        ratesGrid.appendChild(card);
        
        // Add remove functionality
        card.querySelector('.remove-rate').addEventListener('click', function() {
            card.remove();
        });
    }
    
    // Helper function to get exchange rate
    function getExchangeRate(from, to) {
        if (from === to) return 1;
        if (exchangeRates[from] && exchangeRates[from][to]) {
            return exchangeRates[from][to];
        }
        // If direct rate not found, try through USD
        if (from !== 'USD' && to !== 'USD') {
            const toUSD = exchangeRates[from] && exchangeRates[from]['USD'];
            const fromUSD = exchangeRates['USD'] && exchangeRates['USD'][to];
            if (toUSD && fromUSD) {
                return toUSD * fromUSD;
            }
        }
        return 1; // Fallback
    }
    
    // Setup event listeners
    function setupEventListeners() {
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                if (this.getAttribute('href') !== '#') {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetElement = document.getElementById(targetId);
                    if (targetElement) {
                        window.scrollTo({
                            top: targetElement.offsetTop - 80,
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });
    }
</script>