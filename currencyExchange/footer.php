<!-- Footer -->
<footer class="bg-dark text-white py-5">
    <div class="container py-4">
        <div class="row">
            <div class="col-lg-4 mb-4 mb-lg-0">
                <h5 class="fw-bold mb-4">
                    <i class="fas fa-exchange-alt me-2"></i>ACCQURA
                </h5>
                <p class="text-white-70 mb-4">
                    Your trusted partner for secure and efficient currency exchange. 
                    We provide real-time rates, low fees, and exceptional service.
                </p>
                <div class="d-flex gap-3">
                    <a href="#" class="text-white fs-5">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="#" class="text-white fs-5">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="text-white fs-5">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="#" class="text-white fs-5">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                <h6 class="fw-bold mb-4">Quick Links</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="index.php" class="text-white-70">Home</a></li>
                    <li class="mb-2"><a href="#converter" class="text-white-70">Converter</a></li>
                    <li class="mb-2"><a href="#rates" class="text-white-70">Exchange Rates</a></li>
                    <li class="mb-2"><a href="#features" class="text-white-70">Features</a></li>
                    <li class="mb-2"><a href="#about" class="text-white-70">About Us</a></li>
                </ul>
            </div>
            <div class="col-lg-3 col-md-4 mb-4 mb-md-0">
                <h6 class="fw-bold mb-4">Services</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="#" class="text-white-70">Currency Exchange</a></li>
                    <li class="mb-2"><a href="#" class="text-white-70">International Transfers</a></li>
                    <li class="mb-2"><a href="#" class="text-white-70">Rate Alerts</a></li>
                    <li class="mb-2"><a href="#" class="text-white-70">Business Solutions</a></li>
                    <li class="mb-2"><a href="#" class="text-white-70">API Integration</a></li>
                </ul>
            </div>
            <div class="col-lg-3 col-md-4">
                <h6 class="fw-bold mb-4">Contact Us</h6>
                <ul class="list-unstyled">
                    <li class="mb-2">
                        <i class="fas fa-map-marker-alt me-2"></i> 
                        123 Finance Street, Yangon, Myanmar
                    </li>
                    <li class="mb-2">
                        <i class="fas fa-phone me-2"></i> 
                        +95 1 234 5678
                    </li>
                    <li class="mb-2">
                        <i class="fas fa-envelope me-2"></i> 
                        info@accqura.com
                    </li>
                    <li class="mb-2">
                        <i class="fas fa-clock me-2"></i> 
                        Mon-Fri: 9AM-6PM
                    </li>
                </ul>
            </div>
        </div>
        <hr class="my-4 bg-secondary">
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start">
                <p class="mb-0 small text-white-50">
                    &copy; <?php echo date('Y'); ?> ACCQURA. All rights reserved.
                </p>
            </div>
            <div class="col-md-6 text-center text-md-end">
                <ul class="list-inline mb-0">
                    <li class="list-inline-item">
                        <a href="#" class="text-white-50 small">Privacy Policy</a>
                    </li>
                    <li class="list-inline-item">
                        <a href="#" class="text-white-50 small">Terms of Service</a>
                    </li>
                    <li class="list-inline-item">
                        <a href="#" class="text-white-50 small">Disclaimer</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Navbar scroll effect
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });
    
    // Auto-update rates every 10 seconds
    setInterval(() => {
        const rateCards = document.querySelectorAll('#ratesGrid .card');
        rateCards.forEach(card => {
            const baseCurrency = card.getAttribute('data-currency');
            if (baseCurrency) {
                const rows = card.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const targetCell = row.querySelector('td:first-child');
                    if (targetCell) {
                        const targetCurrency = targetCell.textContent.trim();
                        const rateCell = row.querySelector('td:nth-child(2)');
                        const changeCell = row.querySelector('td:last-child');
                        
                        if (rateCell && changeCell && targetCurrency) {
                            const rate = getExchangeRate(baseCurrency, targetCurrency);
                            const change = (Math.random() - 0.5) * 0.02;
                            
                            rateCell.textContent = rate.toFixed(4);
                            changeCell.innerHTML = `
                                <i class="fas fa-arrow-${change >= 0 ? 'up' : 'down'}"></i> 
                                ${Math.abs(change*100).toFixed(2)}%
                            `;
                            changeCell.className = `text-end ${change >= 0 ? 'text-success' : 'text-danger'}`;
                        }
                    }
                });
                
                // Update timestamp
                const timestamp = card.querySelector('.last-updated');
                if (timestamp) {
                    timestamp.textContent = `${Math.floor(Math.random() * 5)}s ago`;
                }
            }
        });
    }, 10000);
</script>
</body>
</html>