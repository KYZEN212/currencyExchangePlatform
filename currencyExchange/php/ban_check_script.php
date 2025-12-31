<!-- Real-time Ban Check Script - Include this in all user pages -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Check ban status every 3 seconds
let banCheckInterval;
let isCheckingBan = false;

function checkBanStatus() {
    if (isCheckingBan) return; // Prevent multiple simultaneous checks
    
    isCheckingBan = true;
    
    fetch('check_ban_status.php')
        .then(response => response.json())
        .then(data => {
            if (data.banned) {
                // User has been banned - show alert and logout
                clearInterval(banCheckInterval); // Stop checking
                
                Swal.fire({
                    icon: 'error',
                    title: 'Account Suspended!',
                    html: '<strong>Reason:</strong> ' + data.reason + '<br><br>' +
                          (data.is_permanent ? 
                          '<strong>This is a permanent suspension</strong>' :
                          '<strong>Duration:</strong> ' + data.duration + '<br><strong>Expires:</strong> ' + data.expires_at),
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#dc2626',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showClass: {
                        popup: 'animate__animated animate__shakeX'
                    }
                }).then(() => {
                    // Logout and redirect
                    window.location.href = 'logout.php?banned=1';
                });
            }
            isCheckingBan = false;
        })
        .catch(error => {
            console.error('Ban check error:', error);
            isCheckingBan = false;
        });
}

// Start checking when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Check immediately
    checkBanStatus();
    
    // Then check every 3 seconds
    banCheckInterval = setInterval(checkBanStatus, 3000);
});

// Stop checking when page is hidden (performance optimization)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        clearInterval(banCheckInterval);
    } else {
        checkBanStatus();
        banCheckInterval = setInterval(checkBanStatus, 3000);
    }
});

// ---------------- Wallet Distribution (MMK) Pie Chart Injection ----------------
// We cannot edit dashboard.php directly, so we:
// - Load Chart.js dynamically if missing
// - Scrape wallet balances and symbols from existing wallet cards in the DOM
// - Use available exchangeRates on the page to build conversion to MMK
// - Inject a card with a canvas and render a pie chart; highlight the largest slice

(function() {
    function ensureChartJs(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        s.onload = cb;
        document.head.appendChild(s);
    }

    function buildMmkMapFromExchangeRates(rates) {
        var mmk = { USD: null, JPY: null, THB: null };
        try {
            (rates || []).forEach(function(r){
                if (!r) return;
                var b = r.base_symbol, t = r.target_symbol;
                if (t === 'MMK' && (b === 'USD' || b === 'JPY' || b === 'THB')) {
                    var v = parseFloat(r.rate);
                    if (!isNaN(v) && v > 0) mmk[b] = v;
                }
            });
        } catch(_){ }
        return mmk;
    }

    function rateToMMK(sym, exchangeRates) {
        if (sym === 'MMK') return 1;
        // direct
        var direct = (exchangeRates || []).find(function(r){ return r.base_symbol === sym && r.target_symbol === 'MMK'; });
        if (direct && direct.rate) {
            var dv = parseFloat(direct.rate);
            if (!isNaN(dv) && dv > 0) return dv;
        }
        // inverse
        var inv = (exchangeRates || []).find(function(r){ return r.base_symbol === 'MMK' && r.target_symbol === sym; });
        if (inv && inv.rate) {
            var iv = parseFloat(inv.rate);
            if (!isNaN(iv) && iv > 0) return 1/iv;
        }
        // cross via admin MMK map for USD/JPY/THB
        var mmk = buildMmkMapFromExchangeRates(exchangeRates);
        if (sym === 'USD' && typeof mmk.USD === 'number' && mmk.USD > 0) return mmk.USD;
        if (sym === 'JPY' && typeof mmk.JPY === 'number' && mmk.JPY > 0) return mmk.JPY;
        if (sym === 'THB' && typeof mmk.THB === 'number' && mmk.THB > 0) return mmk.THB;
        // bridge via USD if possible: sym->USD * USD->MMK
        var toUSD = (exchangeRates || []).find(function(r){ return r.base_symbol === sym && r.target_symbol === 'USD'; });
        var rateToUSD = null;
        if (toUSD && toUSD.rate) rateToUSD = parseFloat(toUSD.rate);
        if (!rateToUSD) {
            var invToUSD = (exchangeRates || []).find(function(r){ return r.base_symbol === 'USD' && r.target_symbol === sym; });
            if (invToUSD && invToUSD.rate) {
                var v = parseFloat(invToUSD.rate);
                if (!isNaN(v) && v > 0) rateToUSD = 1/v;
            }
        }
        if (rateToUSD && mmk.USD) return rateToUSD * mmk.USD;
        return null;
    }

    function extractWalletBalancesFromDOM() {
        var map = {};
        try {
            var container = document.querySelector('#dashboard-content');
            if (!container) return map;
            var rows = container.querySelectorAll('.row.g-3.mb-4');
            if (!rows || !rows.length) return map;
            var cardsRow = rows[0];
            var cards = cardsRow.querySelectorAll('.card');
            cards.forEach(function(card){
                var symEl = card.querySelector('.badge');
                var balEl = card.querySelector('h3.h4');
                if (!symEl || !balEl) return;
                var sym = (symEl.textContent || '').trim();
                var balRaw = (balEl.textContent || '').trim().replace(/,/g,'');
                var bal = parseFloat(balRaw);
                if (!isNaN(bal)) {
                    map[sym] = (map[sym] || 0) + bal; // in case of duplicates, sum
                }
            });
        } catch(_) {}
        return map;
    }

    function ensurePieCard() {
        if (document.getElementById('walletPieMMK')) return true;
        var content = document.getElementById('dashboard-content');
        if (!content) return false;
        var targetRow = content.querySelector('.row.g-3.mb-4');
        var html = '\n                <!-- Wallet Distribution (MMK) Pie Chart -->\n                <div class="card border-0 shadow-lg mb-4" style="border-radius: 1.25rem; overflow: hidden;">\n                    <div class="card-body p-4">\n                        <div class="d-flex justify-content-between align-items-center mb-3">\n                            <div class="d-flex align-items-center gap-2">\n                                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: rgba(16, 185, 129, 0.15);">\n                                    <i class="fas fa-chart-pie" style="font-size: 1.4rem; color: rgb(16, 185, 129);"></i>\n                                </div>\n                                <div>\n                                    <h3 class="h6 fw-bold text-dark mb-0">Wallet Distribution (in MMK)</h3>\n                                    <p class="text-muted mb-0" style="font-size: 0.85rem;">All balances converted to MMK</p>\n                                </div>\n                            </div>\n                        </div>\n                        <div class="row align-items-center">\n                            <div class="col-md-8">\n                                <canvas id="walletPieMMK" height="160"></canvas>\n                            </div>\n                            <div class="col-md-4">\n                                <div id="walletPieMMKTop" class="mt-3"></div>\n                            </div>\n                        </div>\n                    </div>\n                </div>\n';
        if (targetRow && targetRow.parentNode) {
            targetRow.insertAdjacentHTML('beforebegin', html);
            return true;
        }
        content.insertAdjacentHTML('afterbegin', html);
        return true;
    }

    function renderWalletPie() {
        if (!window.exchangeRates) return; // requires global from dashboard
        var balances = extractWalletBalancesFromDOM();
        var labels = [];
        var data = [];
        Object.keys(balances).forEach(function(sym){
            var bal = parseFloat(balances[sym]) || 0;
            if (bal <= 0) return;
            var rate = rateToMMK(sym, window.exchangeRates);
            if (!rate || rate <= 0) return;
            labels.push(sym);
            data.push(bal * rate);
        });
        var topInfo = document.getElementById('walletPieMMKTop');
        var canvas = document.getElementById('walletPieMMK');
        if (!canvas || !topInfo) return;
        if (!data.length) {
            topInfo.innerHTML = '<span class="text-muted">No wallet balances to display.</span>';
            return;
        }
        var maxIdx = 0; for (var i=1;i<data.length;i++) if (data[i]>data[maxIdx]) maxIdx=i;
        var total = data.reduce(function(a,b){return a+b;},0);
        var largestSym = labels[maxIdx];
        var largestAmt = data[maxIdx];
        var pct = total>0 ? ((largestAmt/total)*100).toFixed(1) : '0.0';
        topInfo.innerHTML = '\n            <div class="card bg-light border-0">\n                <div class="card-body">\n                    <div class="small text-muted">Largest holding</div>\n                    <div class="fw-bold">'+largestSym+'</div>\n                    <div class="text-success fw-semibold">MMK '+largestAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+' ('+pct+'%)</div>\n                </div>\n            </div>';
        var colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16','#e11d48'];
        var offsets = data.map(function(_,i){ return i===maxIdx ? 12 : 0; });
        var ctx = canvas.getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, data.length),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    offset: offsets
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function(ctx){ var v=ctx.parsed||0; return ' '+ctx.label+': MMK '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); } } }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        // Only on dashboard pages that have #dashboard-content
        if (!document.getElementById('dashboard-content')) return;
        // If a native dashboard chart exists, do not inject our MMK chart to avoid duplicates
        if (document.getElementById('walletPieChart')) return;
        // Avoid duplicates of our own injected chart
        if (document.getElementById('walletPieMMK')) return;
        // Insert the card
        if (!ensurePieCard()) return;
        // Load chart lib then render
        ensureChartJs(renderWalletPie);
    });
})();
</script>
