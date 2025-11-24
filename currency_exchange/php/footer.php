    <!-- Footer -->
    <footer class="bg-dark text-white py-5">
        <div class="container py-4">
            <div class="row">
                <div class="col-lg-3 mb-4 mb-lg-0">
                    <h5 class="fw-bold mb-4">FX Nexus</h5>
                    <p class="text-white-70">Providing real-time currency exchange rates and financial tools since 2015.</p>
                    <div class="d-flex gap-3 mt-4">
                        <a href="#" class="text-white fs-5"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white fs-5"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white fs-5"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white fs-5"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4 mb-4 mb-md-0">
                    <h6 class="fw-bold mb-4">Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#" class="text-white-70">Home</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Currency Converter</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Exchange Rates</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Financial News</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">About Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-md-4 mb-4 mb-md-0">
                    <h6 class="fw-bold mb-4">Tools</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#" class="text-white-70">Historical Rates</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Currency Charts</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Currency API</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Currency Widgets</a></li>
                        <li class="mb-2"><a href="#" class="text-white-70">Mobile App</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-md-4">
                    <h6 class="fw-bold mb-4">Contact</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="fas fa-map-marker-alt me-2"></i> 123 Finance St, New York</li>
                        <li class="mb-2"><i class="fas fa-phone me-2"></i> +1 (555) 123-4567</li>
                        <li class="mb-2"><i class="fas fa-envelope me-2"></i> info@fxnexus.com</li>
                        <li class="mb-2"><i class="fas fa-clock me-2"></i> Mon-Fri: 9AM-6PM</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4 bg-secondary">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <p class="mb-0 small text-white-50">&copy; 2023 FX Nexus. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <ul class="list-inline mb-0">
                        <li class="list-inline-item"><a href="#" class="text-white-50 small">Privacy Policy</a></li>
                        <li class="list-inline-item"><a href="#" class="text-white-50 small">Terms of Service</a></li>
                        <li class="list-inline-item"><a href="#" class="text-white-50 small">Sitemap</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- JavaScript Codes -->
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            let navbar = document.querySelector(".navbar");
            if (window.scrollY > 50) {
                navbar.classList.add("scrolled");
            } else {
                navbar.classList.remove("scrolled");
            }
        });

        // Initialize AOS
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        // Currency Ticker Functionality
        const mainCurrencies = ["USD", "EUR", "GBP", "JPY"]; // always shown in cards
        let currenciesData = {};
  
        // Load currencies JSON dynamically
        async function loadCurrencies() {
            try {
                const res = await fetch("currencies_full.json"); // full ISO 4217 list
                currenciesData = await res.json();
        
                // Build dropdown
                const dropdown = document.getElementById("currencyDropdown");
                Object.entries(currenciesData).forEach(([code, info]) => {
                    if (!mainCurrencies.includes(code)) {
                        const li = document.createElement("li");
                        li.innerHTML = `<a class="dropdown-item" href="#" data-currency="${code}">
                            <img src="${info.flag}" width="20" class="me-2"> ${info.name} (${code})
                        </a>`;
                        dropdown.appendChild(li);
                    }
                });
            } catch (error) {
                console.log("Currency data not available, using default data");
                // Fallback data
                currenciesData = {
                    "USD": { "flag": "https://flagcdn.com/w40/us.png", "name": "US Dollar" },
                    "EUR": { "flag": "https://flagcdn.com/w40/eu.png", "name": "Euro" },
                    "GBP": { "flag": "https://flagcdn.com/w40/gb.png", "name": "British Pound" },
                    "JPY": { "flag": "https://flagcdn.com/w40/jp.png", "name": "Japanese Yen" }
                };
            }
        }
  
        // Mock base rates (will expand dynamically)
        const rates = {};
        function getRate(base, target) {
            if (!rates[base]) rates[base] = {};
            if (!rates[base][target]) {
                // init with random realistic value
                const rnd = (Math.random() * (1.5 - 0.5) + 0.5).toFixed(4);
                rates[base][target] = parseFloat(rnd);
                if (!rates[target]) rates[target] = {};
                rates[target][base] = +(1 / rnd).toFixed(4);
            }
            return rates[base][target];
        }
  
        // Create a card
        function createCard(code, isBase = false) {
            const card = document.createElement("div");
            card.className = "col-md-6 col-lg-3 mb-4";
            card.innerHTML = `
                <div class="card card-hover h-100 border-0" data-code="${code}">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center">
                                <img src="${currenciesData[code]?.flag || 'https://flagcdn.com/w40/us.png'}" class="currency-flag">
                                <h5 class="mb-0 fw-bold">${code}</h5>
                            </div>
                            ${isBase ? "" : '<button class="btn btn-sm btn-danger remove-btn">X</button>'}
                        </div>
                        <table class="table mb-3"><tbody></tbody></table>
                        <div class="text-end">
                            <small class="text-muted">Updated: <span class="last-updated">just now</span></small>
                        </div>
                    </div>
                </div>
            `;
            updateRates(card, code);
            if (!isBase) {
                card.querySelector(".remove-btn").addEventListener("click", () => card.remove());
            }
            document.getElementById("currencyGrid").appendChild(card);
        }
  
        // Update rates with fluctuation
        function updateRates(card, code) {
            const tbody = card.querySelector("tbody");
            tbody.innerHTML = "";
  
            mainCurrencies.forEach(cur => {
                if (cur === code) return; // skip self
  
                let rate = getRate(code, cur);
                const change = (Math.random() - 0.5) * 0.004; // ±0.2%
                rate = +(rate * (1 + change)).toFixed(4);
                rates[code][cur] = rate;
                rates[cur][code] = +(1 / rate).toFixed(4);
  
                const changePercent = (change * 100).toFixed(2);
                const isUp = change > 0;
  
                tbody.innerHTML += `
                    <tr>
                        <td class="fw-medium">${cur}</td>
                        <td class="text-end fw-bold">${rate}</td>
                        <td class="text-end text-${isUp ? "success" : "danger"}">
                            <i class="fas fa-arrow-${isUp ? "up" : "down"}"></i> ${Math.abs(changePercent)}%
                        </td>
                    </tr>`;
            });
  
            // Random update time (0–5s)
            const randomSec = Math.floor(Math.random() * 6);
            card.querySelector(".last-updated").textContent = `${randomSec}s ago`;
        }
  
        // Auto update currency cards
        setInterval(() => {
            document.querySelectorAll("#currencyGrid .card").forEach(card => {
                const code = card.dataset.code;
                if (code) updateRates(card, code);
            });
        }, 5000);
  
        // Handle Add Currency
        document.getElementById("currencyDropdown").addEventListener("click", e => {
            if (e.target.closest("a")) {
                e.preventDefault();
                const code = e.target.closest("a").dataset.currency;
                if (!document.querySelector(`#currencyGrid .card[data-code="${code}"]`)) {
                    createCard(code);
                }
            }
        });

        // Currency Ticker Updates
        async function fetchRates() {
            // Simulated API data (replace with real API later)
            const data = {
                usd_eur: { 
                    rate: (0.84 + Math.random()/100).toFixed(4), 
                    change: (Math.random()-0.5).toFixed(2) 
                },
                gbp_usd: { 
                    rate: (1.34 + Math.random()/100).toFixed(4), 
                    change: (Math.random()-0.5).toFixed(2) 
                },
                usd_jpy: { 
                    rate: (110 + Math.random()).toFixed(4), 
                    change: (Math.random()-0.5).toFixed(2) 
                }
            };

            updateTicker("usd-eur", "USD/EUR", data.usd_eur);
            updateTicker("gbp-usd", "GBP/USD", data.gbp_usd);
            updateTicker("usd-jpy", "USD/JPY", data.usd_jpy);
        }

        function updateTicker(id, label, obj) {
            const el = document.getElementById(id);
            const changeClass = obj.change >= 0 ? "up" : "down";
            el.innerHTML = `${label}: ${obj.rate} <span class="${changeClass}">${obj.change >= 0 ? '+' : ''}${obj.change}%</span>`;
        }

        // Update ticker every 5s
        setInterval(fetchRates, 5000);
        fetchRates();

        // Currency Converter Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Get converter elements
            const amountInput = document.querySelector('.converter-card input[type="number"]');
            const fromSelect = document.querySelectorAll('.converter-card .form-select')[0];
            const toSelect = document.querySelectorAll('.converter-card .form-select')[1];
            const convertedAmount = document.querySelector('.converted-amount');
            const exchangeRateDisplay = document.querySelector('.exchange-rate');
            const lastUpdatedDisplay = document.querySelector('.last-updated');
            const swapBtn = document.querySelector('.swap-btn');
            const convertBtn = document.getElementById('convertBtn');
            const currencySymbol = document.querySelector('.currency-symbol');

            // Sample exchange rates
            const exchangeRates = {
                'USD': { 'EUR': 0.85075, 'GBP': 0.73015, 'JPY': 110.25 },
                'EUR': { 'USD': 1.17525, 'GBP': 0.85825, 'JPY': 129.65 },
                'GBP': { 'USD': 1.36950, 'EUR': 1.16500, 'JPY': 151.00 },
                'JPY': { 'USD': 0.00907, 'EUR': 0.00771, 'GBP': 0.00662 }
            };

            // Currency symbols
            const currencySymbols = {
                'USD': '$',
                'EUR': '€',
                'GBP': '£',
                'JPY': '¥'
            };

            // Update currency symbol based on selected currency
            function updateCurrencySymbol() {
                const selectedOption = fromSelect.options[fromSelect.selectedIndex].text;
                const currencyCode = selectedOption.split(' ')[1]; // Get currency code like USD, EUR, etc.
                currencySymbol.textContent = currencySymbols[currencyCode] || '$';
            }

            // Convert currency function
            function convertCurrency() {
                const amount = parseFloat(amountInput.value) || 0;
                const fromCurrency = fromSelect.options[fromSelect.selectedIndex].text.split(' ')[1];
                const toCurrency = toSelect.options[toSelect.selectedIndex].text.split(' ')[1];
                
                if (fromCurrency === toCurrency) {
                    convertedAmount.textContent = amount.toFixed(2);
                    exchangeRateDisplay.textContent = `1 ${fromCurrency} = 1 ${toCurrency}`;
                    return;
                }
                
                const rate = exchangeRates[fromCurrency][toCurrency];
                const converted = amount * rate;
                
                // Format the converted amount with appropriate symbol
                const toSymbol = currencySymbols[toCurrency] || toCurrency;
                convertedAmount.textContent = `${toSymbol}${converted.toFixed(2)}`;
                exchangeRateDisplay.textContent = `1 ${fromCurrency} = ${rate.toFixed(5)} ${toCurrency}`;
                
                // Update last updated time
                const now = new Date();
                lastUpdatedDisplay.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            // Swap currencies function
            function swapCurrencies() {
                const fromIndex = fromSelect.selectedIndex;
                const toIndex = toSelect.selectedIndex;
                
                fromSelect.selectedIndex = toIndex;
                toSelect.selectedIndex = fromIndex;
                
                updateCurrencySymbol();
                convertCurrency();
            }

            // Event listeners
            swapBtn.addEventListener('click', swapCurrencies);
            convertBtn.addEventListener('click', convertCurrency);
            amountInput.addEventListener('input', convertCurrency);
            fromSelect.addEventListener('change', function() {
                updateCurrencySymbol();
                convertCurrency();
            });
            toSelect.addEventListener('change', convertCurrency);

            // Initialize
            updateCurrencySymbol();
            convertCurrency();
        });

        // Charts Initialization
        document.addEventListener('DOMContentLoaded', function() {
            // USD to EUR Chart
            const usdEurCtx = document.getElementById('usdEurChart').getContext('2d');
            const usdEurChart = new Chart(usdEurCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'USD to EUR',
                        data: [0.92, 0.91, 0.90, 0.89, 0.88, 0.89, 0.90, 0.91, 0.90, 0.89, 0.88, 0.89],
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.05)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } }
                }
            });

            // GBP to USD Chart
            const gbpUsdCtx = document.getElementById('gbpUsdChart').getContext('2d');
            const gbpUsdChart = new Chart(gbpUsdCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'GBP to USD',
                        data: [1.35, 1.34, 1.33, 1.32, 1.31, 1.30, 1.29, 1.30, 1.31, 1.32, 1.33, 1.31],
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.05)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#f59e0b',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            // Chart range dropdown functionality
            document.querySelectorAll('.dropdown-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const range = this.textContent;
                    this.closest('.dropdown').querySelector('.dropdown-toggle').textContent = range;
                    // Here you would update the chart data based on the selected range
                    console.log('Selected range:', range);
                });
            });
        });

        // Initialize currency system when page loads
        window.addEventListener('load', function() {
            loadCurrencies().then(() => {
                // Add base 4 currencies
                ["USD", "EUR", "GBP", "JPY"].forEach(c => createCard(c, true));
            });
        });
    </script>
</body>
</html>