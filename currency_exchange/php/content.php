    <!-- Hero Section -->
    <section class="gradient-bg text-white py-5 hero-section" style="padding-top: 100px !important;">
  <div class="container py-5">
    <!-- Currency Ticker -->
<div id="currency-ticker" class="text-white py-2 currency-ticker mb-4">
  <div class="container d-flex justify-content-center gap-4">
    <span id="usd-eur">USD/EUR: --</span>
    <span id="gbp-usd">GBP/USD: --</span>
    <span id="usd-jpy">USD/JPY: --</span>
  </div>
</div>
    <div class="hero-content animate-fade-in">
      <h1 class="display-4 fw-bold mb-4">
        Real-Time <span class="text-warning">Currency</span> Exchange
      </h1>
      <p class="lead mb-4">
        Access the latest foreign exchange rates with our powerful currency converter 
        and analytics tools. Make informed financial decisions with precision.
      </p>
      <div class="d-flex gap-3 flex-wrap justify-content-center">
        <a href="#converter" class="btn btn-light btn-lg px-4">Convert Now</a>
        <a href="#rates" class="btn btn-outline-light btn-lg px-4">View Rates</a>
      </div>
      <div class="d-flex justify-content-center mt-5 pt-3 gap-5 flex-wrap">
        <div>
          <h3 class="fw-bold">150+</h3>
          <p class="text-white-80 mb-0">Currencies</p>
        </div>
        <div>
          <h3 class="fw-bold">24/7</h3>
          <p class="text-white-80 mb-0">Real-time Data</p>
        </div>
        <div>
          <h3 class="fw-bold">10M+</h3>
          <p class="text-white-80 mb-0">Users</p>
        </div>
      </div>
    </div>
  </div>
    </section>

    <!-- Converter Section -->
    <section id="converters" class="py-3 bg-white" data-aos="fade-up">
        <div class="container py-3">
            <div class="text-center mb-2" data-aos="fade-up">
                <h2 class="fw-bold">Advanced Currency <span class="text-gradient">Converter</span></h2>
                <p class="text-muted lead">Convert between 150+ currencies with real-time exchange rates</p>
            </div>
            <div class="row justify-content-center" id="converterForm">
                <div class="col-lg-10">
                    <div class="converter-card">
                        <div class="row g-4 text-center align-items-center">
                        <!-- Amount -->
                        <div class="col-md-5">
                            <label class="label">Amount</label>
                            <div class="amount-box">
                            <input type="number" class="form-control" value="100">
                            <span class="currency-symbol">$</span>
                            </div>
                        </div>

                        <!-- Swap Button (centered vertically) -->
                        <div class="col-md-2 d-flex align-items-center justify-content-center">
                            <button class="swap-btn">⇄</button>
                        </div>

                        <!-- Converted Amount -->
                        <div class="col-md-5">
                            <label class="label">Converted Amount</label>
                            <div class="converted-amount">€85.08</div>
                        </div>
                        </div>

                        <!-- From & To Currency in parallel -->
                        <div class="row g-4 mt-4">
                        <div class="col-md-6">
                            <label class="label">From Currency</label>
                            <select class="form-select">
                            <option selected>🇺🇸 USD - US Dollar</option>
                            <option>🇪🇺 EUR - Euro</option>
                            <option>🇬🇧 GBP - British Pound</option>
                            <option>🇯🇵 JPY - Japanese Yen</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="label">To Currency</label>
                            <select class="form-select">
                            <option selected>🇪🇺 EUR - Euro</option>
                            <option>🇺🇸 USD - US Dollar</option>
                            <option>🇬🇧 GBP - British Pound</option>
                            <option>🇯🇵 JPY - Japanese Yen</option>
                            </select>
                        </div>
                        </div>

                        <!-- Exchange rate & Last Updated in parallel -->
                        <div class="row g-4 mt-4">
                        <div class="col-md-6 d-flex justify-content-between">
                            <span class="text-muted">Exchange Rate</span>
                            <span class="exchange-rate">1 USD = 0.85075 EUR</span>
                        </div>
                        <div class="col-md-6 d-flex justify-content-between">
                            <span class="text-muted">Last Updated</span>
                            <span class="last-updated">1:23:08 PM</span>
                        </div>
                        </div>

                        <!-- Update Conversion Button Centered -->
                        <div class="text-center">
                        <button class="update-btn" id="convertBtn">Update Conversion</button>
                        </div>
                    </div>
                     <p class="text-center text-muted small mt-3">
                        Exchange rates are updated in real-time. Actual rates may vary slightly for transactions.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section id="adv" class="py-5 bg-white">
        <div class="container py-3">
            <video src="./../video/Project 5.mp4" autoplay loop muted playsinline></video>
        </div>
    </section>

    <section id="rates" class="py-5 bg-white" data-aos="fade-up">
      <div class="container py-5">
        <div class="text-center mb-5">
          <h2 class="fw-bold">📊 Live <span class="text-primary">Currency Rates</span></h2>
          <p class="text-muted lead">Stay updated with real-time exchange values</p>
        </div>
        <div class="row" id="currencyGrid"></div>
    
        <div class="text-center mt-4">
          <div class="dropdown d-inline">
            <button class="btn btn-primary px-5 dropdown-toggle" type="button" id="addCurrencyBtn" data-bs-toggle="dropdown">
              ➕ Add Currency
            </button>
            <ul class="dropdown-menu shadow" id="currencyDropdown"></ul>
          </div>
        </div>
      </div>
    </section>

    <!-- Charts Section -->
    <section class="py-5 bg-white">
        <div class="container py-5">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="fw-bold">Currency <span class="text-gradient">Trends</span></h2>
                <p class="text-muted lead">Historical data and exchange rate trends</p>
            </div>
            <div class="row">
                <div class="col-lg-6 mb-4" data-aos="fade-right">
                    <div class="chart-container h-100">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0 fw-bold">USD to EUR (1 Year)</h5>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="chartRangeDropdown" data-bs-toggle="dropdown">
                                    1 Year
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#">1 Week</a></li>
                                    <li><a class="dropdown-item" href="#">1 Month</a></li>
                                    <li><a class="dropdown-item" href="#">3 Months</a></li>
                                    <li><a class="dropdown-item" href="#">1 Year</a></li>
                                    <li><a class="dropdown-item" href="#">5 Years</a></li>
                                </ul>
                            </div>
                        </div>
                        <canvas id="usdEurChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-6 mb-4" data-aos="fade-left">
                    <div class="chart-container h-100">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0 fw-bold">GBP to USD (1 Year)</h5>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="chartRangeDropdown2" data-bs-toggle="dropdown">
                                    1 Year
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#">1 Week</a></li>
                                    <li><a class="dropdown-item" href="#">1 Month</a></li>
                                    <li><a class="dropdown-item" href="#">3 Months</a></li>
                                    <li><a class="dropdown-item" href="#">1 Year</a></li>
                                    <li><a class="dropdown-item" href="#">5 Years</a></li>
                                </ul>
                            </div>
                        </div>
                        <canvas id="gbpUsdChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- News Section -->
    <section id="news" class="py-5 bg-light">
        <div class="container py-5">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="fw-bold">Financial <span class="text-gradient">News</span></h2>
                <p class="text-muted lead">Latest updates from the world of finance</p>
            </div>
            <div class="row">
                <div class="col-md-6 col-lg-4 mb-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="card card-hover h-100 border-0">
                        <div class="position-relative">
                            <img src="https://static.photos/finance/640x360/1" class="card-img-top" alt="Finance News">
                            <span class="badge bg-primary position-absolute top-0 end-0 m-3">Forex</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted"><i class="far fa-clock me-1"></i> 2 hours ago</small>
                                <small class="text-muted"><i class="far fa-eye me-1"></i> 2.4K</small>
                            </div>
                            <h5 class="card-title fw-bold">Dollar Strengthens Against Euro After ECB Decision</h5>
                            <p class="card-text text-muted">The US dollar rose to a three-month high against the euro after the European Central Bank signaled a slower pace of rate hikes.</p>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a href="#" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between">
                                Read More <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 mb-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="card card-hover h-100 border-0">
                        <div class="position-relative">
                            <img src="https://static.photos/finance/640x360/2" class="card-img-top" alt="Finance News">
                            <span class="badge bg-success position-absolute top-0 end-0 m-3">Markets</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted"><i class="far fa-clock me-1"></i> 5 hours ago</small>
                                <small class="text-muted"><i class="far fa-eye me-1"></i> 1.8K</small>
                            </div>
                            <h5 class="card-title fw-bold">Asian Markets Rally on Positive Trade Data</h5>
                            <p class="card-text text-muted">Asian stocks climbed as stronger-than-expected trade data from China boosted investor sentiment across the region.</p>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a href="#" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between">
                                Read More <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 mb-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="card card-hover h-100 border-0">
                        <div class="position-relative">
                            <img src="https://static.photos/finance/640x360/3" class="card-img-top" alt="Finance News">
                            <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-3">Crypto</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted"><i class="far fa-clock me-1"></i> 1 day ago</small>
                                <small class="text-muted"><i class="far fa-eye me-1"></i> 3.1K</small>
                            </div>
                            <h5 class="card-title fw-bold">Bitcoin Volatility Increases Ahead of Fed Meeting</h5>
                            <p class="card-text text-muted">Cryptocurrency markets experienced heightened volatility as traders positioned themselves ahead of the Federal Reserve's policy decision.</p>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a href="#" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between">
                                Read More <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mt-4">
                <a href="#" class="btn btn-primary px-5">View All News</a>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-5 bg-white">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0" data-aos="fade-right">
                    <img src="https://static.photos/office/640x360/1" alt="About FX Nexus" class="img-fluid rounded shadow">
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <h2 class="fw-bold mb-4">About <span class="text-gradient">FX Nexus</span></h2>
                    <p class="lead mb-4">We provide real-time currency exchange rates and financial data to individuals and businesses worldwide.</p>
                    <p class="text-muted">Founded in 2015, FX Nexus has grown to become one of the most trusted sources for currency information. Our platform aggregates data from multiple reliable sources to provide you with the most accurate and up-to-date exchange rates.</p>
                    <div class="row mt-4">
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                    <i class="fas fa-chart-line text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Real-Time Data</h6>
                                    <small class="text-muted">Updated every minute</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                    <i class="fas fa-globe text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">150+ Currencies</h6>
                                    <small class="text-muted">Global coverage</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                    <i class="fas fa-shield-alt text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Secure</h6>
                                    <small class="text-muted">Bank-level security</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-3 rounded me-3">
                                    <i class="fas fa-history text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Historical Data</h6>
                                    <small class="text-muted">Up to 10 years</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="btn btn-outline-primary me-2">Learn More</a>
                        <a href="#" class="btn btn-primary">Contact Us</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Newsletter -->
    <section class="py-5 gradient-bg-reverse text-white">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center" data-aos="fade-up">
                    <h2 class="fw-bold mb-4">Stay Updated with Market Insights</h2>
                    <p class="lead mb-5">Subscribe to our newsletter for the latest currency updates and financial news.</p>
                    <form class="row g-2 justify-content-center">
                        <div class="col-md-8">
                            <div class="input-group input-group-lg">
                                <input type="email" class="form-control" placeholder="Your email address">
                                <button class="btn btn-dark px-4" type="submit">Subscribe</button>
                            </div>
                        </div>
                    </form>
                    <p class="small mt-3 opacity-75">We respect your privacy. Unsubscribe at any time.</p>
                </div>
            </div>
        </div>
    </section>