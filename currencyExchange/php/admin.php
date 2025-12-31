<?php
// Disable caching to ensure fresh dashboard data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();

// Database credentials (standardize with config.php)
require_once 'config.php';
// Map config.php vars to local names used below
$db_username = $username;
$db_password = $password;

$message = '';
$show_requests = false;
$show_wallet = false;
$show_history = false;
$show_all_requests = false;
$show_deposit = false;
$show_fees = false;
$show_exchange_rate = false;

// Include the bank logic, currency logic, and exchange API
require_once 'bank.php';
require_once 'currency.php';
require_once 'exchange_api.php';

// Check if the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Verify credentials against admins table (email-based login; fallback to username)
    if ((isset($_POST['admin_email']) || isset($_POST['admin_username'])) && isset($_POST['admin_password'])) {
        $input_ident = isset($_POST['admin_email']) && $_POST['admin_email'] !== ''
            ? trim($_POST['admin_email'])
            : trim($_POST['admin_username'] ?? '');
        $byEmail = filter_var($input_ident, FILTER_VALIDATE_EMAIL) ? true : (isset($_POST['admin_email']) && $_POST['admin_email'] !== '');
        $input_pass = (string)$_POST['admin_password'];
        // Use DB creds from config.php which is already required above
        $loginConn = @new mysqli($servername, $db_username, $db_password, $dbname);
        if ($loginConn && !$loginConn->connect_error) {
            $loginConn->set_charset('utf8mb4');
            $sql = $byEmail
                ? "SELECT admin_id, username, password_hash, email FROM admins WHERE email = ? LIMIT 1"
                : "SELECT admin_id, username, password_hash, email FROM admins WHERE username = ? LIMIT 1";
            if ($stmt = $loginConn->prepare($sql)) {
                $stmt->bind_param('s', $input_ident);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $stored = (string)$row['password_hash'];
                    $ok = false;
                    // If stored looks like a hash, verify; otherwise allow legacy plaintext and upgrade
                    if (preg_match('/^(?:\$2y\$|\$argon2)/', $stored)) {
                        $ok = password_verify($input_pass, $stored);
                    } else {
                        $ok = hash_equals($stored, $input_pass);
                        if ($ok) {
                            // Upgrade to bcrypt hash
                            $newHash = password_hash($input_pass, PASSWORD_BCRYPT);
                            if ($newHash) {
                                if ($up = $loginConn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ? LIMIT 1")) {
                                    $aid = (int)$row['admin_id'];
                                    $up->bind_param('si', $newHash, $aid);
                                    $up->execute();
                                    $up->close();
                                }
                            }
                        }
                    }
                    if ($ok) {
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = (int)$row['admin_id'];
                        header("Location: admin.php");
                        exit();
                    } else {
                        // Wrong password
                        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Incorrect admin credentials.</div>";
                    }
                } else {
                    // No account found
                    if ($byEmail) {
                        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>No admin account found for this email.</div>";
                    } else {
                        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Incorrect admin credentials.</div>";
                    }
                }
                $stmt->close();
            }
            $loginConn->close();
        } else {
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Server error. Please try again later.</div>";
        }
    }
  
    
    // Optional success notices
    if (isset($_GET['message'])) {
        $msgKey = $_GET['message'];
        if ($msgKey === 'reset_link_sent') {
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>A reset link has been sent.</div>";
        } elseif ($msgKey === 'password_reset_success') {
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Your password has been updated. Please log in.</div>";
        }
    }

    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
            body { font-family: "Inter", sans-serif; }
            .blob { position:absolute; border-radius:9999px; filter: blur(40px); opacity:.35; }
            .floaty { animation: floaty 18s ease-in-out infinite; }
            @keyframes floaty { 0%,100%{ transform: translateY(0) } 50%{ transform: translateY(-16px) } }
            /* Decorative coin styles */
            .coin { position:absolute; opacity:.12; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
            .spin-slow { animation: spin 32s linear infinite; }
            .spin-slow-rev { animation: spin 40s linear infinite reverse; }
            @keyframes spin { to { transform: rotate(360deg); } }
        </style>
    </head>
    <body class="min-h-screen flex items-center justify-center bg-gradient-to-b from-slate-900 to-slate-800 relative overflow-hidden">

        <!-- Mouse trail ripple canvas (behind coins for background feel) -->
        <canvas id="rippleCanvas" class="pointer-events-none absolute inset-0 -z-10"></canvas>

        <!-- Currency-themed decorative background -->
        <div class="pointer-events-none absolute inset-0 -z-10">
            <!-- USD coin -->
            <svg class="coin spin-slow" width="220" height="220" viewBox="0 0 220 220" fill="none" style="top:-40px; left:-30px">
                <circle cx="110" cy="110" r="96" stroke="#22d3ee" stroke-width="3"/>
                <circle cx="110" cy="110" r="82" stroke="#34d399" stroke-width="2"/>
                <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="64" fill="#22d3ee" font-family="Inter, system-ui, Arial">$</text>
            </svg>
            <!-- JPY coin -->
            <svg class="coin spin-slow-rev" width="180" height="180" viewBox="0 0 180 180" fill="none" style="bottom:40px; right:-20px">
                <circle cx="90" cy="90" r="78" stroke="#34d399" stroke-width="3"/>
                <circle cx="90" cy="90" r="66" stroke="#22d3ee" stroke-width="2"/>
                <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="48" fill="#34d399" font-family="Inter, system-ui, Arial">¥</text>
            </svg>
            <!-- THB coin -->
            <svg class="coin spin-slow" width="160" height="160" viewBox="0 0 160 160" fill="none" style="top:55%; left:-30px">
                <circle cx="80" cy="80" r="68" stroke="#22d3ee" stroke-width="2"/>
                <circle cx="80" cy="80" r="56" stroke="#34d399" stroke-width="2"/>
                <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="40" fill="#22d3ee" font-family="Inter, system-ui, Arial">฿</text>
            </svg>
            <!-- MMK coin (Ks) -->
            <svg class="coin spin-slow-rev" width="190" height="190" viewBox="0 0 190 190" fill="none" style="top:20%; right:10%">
                <circle cx="95" cy="95" r="82" stroke="#22d3ee" stroke-width="3"/>
                <circle cx="95" cy="95" r="70" stroke="#34d399" stroke-width="2"/>
                <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="44" fill="#22d3ee" font-family="Inter, system-ui, Arial">Ks</text>
            </svg>
        </div>

        <div class="w-full max-w-md bg-slate-800/80 backdrop-blur border border-slate-700 shadow-2xl rounded-2xl p-8 text-slate-200">
            <div class="text-center mb-6">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-500/10 text-cyan-300 text-xs font-semibold mb-2 border border-cyan-500/20">
                    <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                    ADMIN PORTAL
                </div>
                <h2 class="text-2xl font-extrabold text-white">Welcome Back</h2>
                <p class="text-slate-400 text-sm">Sign in to manage the platform</p>
            </div>
            ' . $message . '
            <form method="POST">
                <input type="email" name="admin_email" placeholder="Email" class="w-full p-3 mb-4 rounded-lg border border-slate-700 bg-slate-900/70 text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <div class="relative mb-6">
                    <input type="password" id="admin_password" name="admin_password" placeholder="Password" class="w-full p-3 rounded-lg border border-slate-700 bg-slate-900/70 text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 pr-10">
                    <button type="button" id="togglePwd" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-200" tabindex="-1" aria-label="Toggle password visibility">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3C5 3 1.73 7.11 1 10c.73 2.89 4 7 9 7s8.27-4.11 9-7c-.73-2.89-4-7-9-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"/></svg>
                    </button>
                </div>
                <button type="submit" class="w-full p-3 rounded-lg font-semibold text-slate-900 bg-gradient-to-r from-emerald-400 to-cyan-400 shadow-lg hover:from-emerald-300 hover:to-cyan-300 transition-all">Log In</button>
                <div class="mt-3 text-right">
                    <a href="admin_forgot_password_request.php" class="text-cyan-300 hover:text-cyan-200 text-sm">Forgot password?</a>
                </div>
            </form>
            <div class="text-center mt-4 text-xs text-slate-500">ACCQURA Admin</div>
        </div>
        <script>
        // Listen for password reset success signals from other tabs
        (function(){
            const KEY = "admin_pwd_reset_success";
            function handleSignal(ts){
                try {
                    // Accept any timestamp; optionally ensure freshness if desired
                    localStorage.removeItem(KEY);
                } catch (e) {}
                // Navigate to show success message on this same tab
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set("message", "password_reset_success");
                    window.location.replace(url.toString());
                } catch (e) {
                    window.location.href = "admin.php?message=password_reset_success";
                }
            }
            // If key already present (user submitted earlier), handle immediately
            try {
                const existing = localStorage.getItem(KEY);
                if (existing) { handleSignal(existing); }
            } catch (e) {}
            // Live updates from other tab
            window.addEventListener("storage", function(ev){
                if (ev && ev.key === KEY && ev.newValue) {
                    handleSignal(ev.newValue);
                }
            });
            // Also listen for direct postMessage from reset window
            window.addEventListener("message", function(ev){
                try {
                    if (ev && ev.data && ev.data.type === "admin_pwd_reset_success") {
                        handleSignal(Date.now());
                    }
                } catch (e) {}
            });
            // Listen on BroadcastChannel for reset success
            try {
                const bc = new BroadcastChannel("admin_reset_channel");
                bc.onmessage = function(ev){
                    try {
                        if (ev && ev.data && ev.data.type === "admin_pwd_reset_success") {
                            handleSignal(Date.now());
                            try { window.focus(); } catch (e) {}
                        }
                    } catch (e) {}
                };
            } catch (e) {}
            // On tab visibility change, re-check key to catch missed events
            document.addEventListener("visibilitychange", function(){
                if (!document.hidden) {
                    try {
                        const existing = localStorage.getItem(KEY);
                        if (existing) { handleSignal(existing); }
                    } catch (e) {}
                }
            });
        })();
        </script>
        <script>
        (function(){
            const btn = document.getElementById("togglePwd");
            const inp = document.getElementById("admin_password");
            if(btn && inp){
                btn.addEventListener("click", function(){
                    inp.type = inp.type === "password" ? "text" : "password";
                });
            }
        })();

        // Aquamarine wave trail background following the cursor
        (function(){
            const canvas = document.getElementById("rippleCanvas");
            if (!canvas) return;
            const ctx = canvas.getContext("2d");
            // Improve line aesthetics
            ctx.lineCap = "round";
            ctx.lineJoin = "round";

            const AQUA = { r:127, g:255, b:212 };
            let dpr = Math.max(window.devicePixelRatio || 1, 1);
            function resize(){
                const w = window.innerWidth;
                const h = window.innerHeight;
                canvas.style.width = w + "px";
                canvas.style.height = h + "px";
                canvas.width = Math.floor(w * dpr);
                canvas.height = Math.floor(h * dpr);
            }
            resize();
            window.addEventListener("resize", resize);

            // Store recent mouse points to draw a smooth, wavy path
            const points = [];
            const MAX_POINTS = 60; // smaller buffer for faster rendering
            let mouseActive = false;
            // Adaptive quality flag based on FPS
            let qualityLow = false;
            let frames = 0, fps = 60;
            let lastFpsUpdate = performance.now();

            // Shattering coin particles
            const particles = [];
            const MAX_PARTICLES = 160;
            function spawnShards(x, y, dx, dy){
                const count = 4; // fewer shards per spawn for performance
                for (let i = 0; i < count; i++) {
                    // randomize direction around movement vector
                    const angle = Math.atan2(dy, dx) + (Math.random() - 0.5) * 1.2; // +/- ~34 deg
                    const speed = (1.0 + Math.random() * 1.6) * dpr;
                    const vx = Math.cos(angle) * speed;
                    const vy = Math.sin(angle) * speed;
                    particles.push({
                        x, y,
                        vx, vy,
                        life: 1.0,            // fades to 0
                        decay: 0.06 + Math.random() * 0.03,
                        size: (2.6 + Math.random() * 2.4) * dpr,
                        rot: Math.random() * Math.PI,
                        vr: (-0.1 + Math.random() * 0.2),
                        hue: Math.random() < 0.5 ? "emerald" : "cyan",
                    });
                }
                if (particles.length > MAX_PARTICLES) {
                    particles.splice(0, particles.length - MAX_PARTICLES);
                }
            }

            // Falling currency symbol particles
            const symbols = [];
            const MAX_SYMBOLS = 100;
            const SYMBOL_SET = ["$", "¥", "฿", "Ks"];
            let lastSymSpawn = 0;
            function spawnSymbol(x, y, dx, dy){
                const sym = SYMBOL_SET[Math.floor(Math.random() * SYMBOL_SET.length)];
                const base = 14 * dpr; // bigger base size
                const size = base + Math.random() * (22 * dpr); // larger range
                const spread = (Math.random() - 0.5) * 1.0; // lateral spread
                const speed = (0.4 + Math.random() * 0.9) * dpr; // slower initial speed
                const ang = Math.atan2(dy, dx) + spread;
                const vx = Math.cos(ang) * speed;
                const vy = Math.sin(ang) * speed;
                symbols.push({
                    sym,
                    x, y,
                    vx, vy,
                    size,
                    life: 1.0,
                    decay: 0.025 + Math.random() * 0.015, // slower fade
                    rot: (Math.random() - 0.5) * 0.25, // reduced spin speed
                    angle: Math.random() * Math.PI,
                    hue: Math.random() < 0.5 ? "emerald" : "cyan",
                });
                if (symbols.length > MAX_SYMBOLS) {
                    symbols.splice(0, symbols.length - MAX_SYMBOLS);
                }
            }

            window.addEventListener("mousemove", (e)=>{
                const rect = canvas.getBoundingClientRect();
                const x = (e.clientX - rect.left) * dpr;
                const y = (e.clientY - rect.top) * dpr;
                points.push({ x, y, t: performance.now() });
                if (points.length > MAX_POINTS) points.shift();
                mouseActive = true;

                // Spawn coin shards along direction of movement (use last segment)
                const len = points.length;
                if (len >= 2) {
                    const p0 = points[len - 2];
                    const p1 = points[len - 1];
                    let dx = p1.x - p0.x;
                    let dy = p1.y - p0.y;
                    const mag = Math.hypot(dx, dy) || 1;
                    dx /= mag; dy /= mag;
                    // Shards disabled per request; only symbols remain
                    const nowT = performance.now();
                    const symInterval = qualityLow ? 120 : 60; // throttle more on low FPS
                    if (nowT - lastSymSpawn > symInterval) {
                        // Spawn 2 symbols for fuller effect on normal quality
                        spawnSymbol(x, y, dx, dy);
                        if (!qualityLow) spawnSymbol(x, y, dx, dy);
                        lastSymSpawn = nowT;
                    }
                }
            });
            window.addEventListener("mouseleave", ()=>{ mouseActive = false; });

            function computeOffsetPoints(pts, time){
                if (pts.length < 2) return [];
                // Build offset path using sine along the local normal for a wave-like ribbon
                const amp = 7 * dpr; // amplitude
                const freq = 0.012;   // time frequency
                const stepPhase = 0.35; // spatial progression per point

                const offsetPts = new Array(pts.length);
                for (let i = 0; i < pts.length; i++) {
                    const p = pts[i];
                    const p0 = pts[i - 1] || p;
                    const p1 = pts[i + 1] || p;
                    // tangent
                    let tx = p1.x - p0.x;
                    let ty = p1.y - p0.y;
                    const len = Math.hypot(tx, ty) || 1;
                    tx /= len; ty /= len;
                    // normal
                    const nx = -ty;
                    const ny = tx;
                    // sine-based offset (grow from head so tail is calmer)
                    const phase = time * freq + i * stepPhase;
                    const m = Math.sin(phase) * amp * Math.min(1, i / 8);
                    offsetPts[i] = { x: p.x + nx * m, y: p.y + ny * m };
                }
                return offsetPts;
            }

            function animate(){
                const now = performance.now();
                // FPS tracking and adaptive quality toggle
                frames++;
                if (now - lastFpsUpdate > 500) {
                    fps = (frames * 1000) / (now - lastFpsUpdate);
                    frames = 0;
                    lastFpsUpdate = now;
                    qualityLow = fps < 50; // degrade quality if FPS drops under 50
                }
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.globalCompositeOperation = "lighter";

                // Draw trailing segments with fading thickness and alpha (disabled per request)
                if (false) {
                    // Keep only very recent points for rendering; shorter on low FPS
                    const MAX_AGE = qualityLow ? 130 : 180; // ms
                    const cutoff = now - MAX_AGE;
                    const recent = points.filter(p => p.t >= cutoff);
                    const tail = Math.max(6, Math.min(recent.length, 80));
                    const trail = recent.slice(Math.max(0, recent.length - tail));
                    const offPts = computeOffsetPoints(trail, now);
                    if (offPts.length > 1) {
                        // Segment-based drawing to vary thickness along the trail
                        const n = offPts.length - 1;

                        // Outer glow pass (thicker at tail, thinner at head). Decimate segments to reduce draw calls
                        const stepSeg = qualityLow ? 3 : 2;
                        for (let i = stepSeg; i < offPts.length; i += stepSeg) {
                            const prev = offPts[i - stepSeg];
                            const curr = offPts[i];
                            const t = i / n; // 0 tail -> 1 head
                            const w = (16 - 10 * t) * dpr; // thicker at tail, thinner at head
                            // gradient color per segment (emerald->cyan)
                            const g = ctx.createLinearGradient(prev.x, prev.y, curr.x, curr.y);
                            g.addColorStop(0, "rgba(52, 211, 153, 0.18)");
                            g.addColorStop(1, "rgba(34, 211, 238, 0.18)");
                            ctx.beginPath();
                            ctx.moveTo(prev.x, prev.y);
                            ctx.lineTo(curr.x, curr.y);
                            ctx.lineWidth = w;
                            ctx.strokeStyle = g;
                            ctx.shadowColor = "rgba(34, 211, 238, 0.30)";
                            ctx.shadowBlur = (qualityLow ? 10 : 16) * dpr;
                            ctx.stroke();
                        }
                        ctx.filter = "none";

                        // Core pass (bright center, also tapered)
                        for (let i = stepSeg; i < offPts.length; i += stepSeg) {
                            const prev = offPts[i - stepSeg];
                            const curr = offPts[i];
                            const t = i / n;
                            const w = (6 - 3.5 * t) * dpr;
                            const g = ctx.createLinearGradient(prev.x, prev.y, curr.x, curr.y);
                            g.addColorStop(0, "rgba(52, 211, 153, 0.75)");
                            g.addColorStop(1, "rgba(34, 211, 238, 0.85)");
                            ctx.beginPath();
                            ctx.moveTo(prev.x, prev.y);
                            ctx.lineTo(curr.x, curr.y);
                            ctx.lineWidth = w;
                            ctx.strokeStyle = g;
                            ctx.shadowColor = "rgba(34, 211, 238, 0.35)";
                            ctx.shadowBlur = (qualityLow ? 6 : 10) * dpr;
                            // no canvas filter here to keep FPS high
                            ctx.stroke();
                        }
                        ctx.filter = "none";

                        // Coin markers along the wave
                        // Draw up to 8 small glowing circles spaced along the trail
                        const maxCoins = qualityLow ? 4 : 6;
                        const step = Math.max(3, Math.floor(offPts.length / maxCoins));
                        for (let i = 0; i < offPts.length; i += step) {
                            const p = offPts[i];
                            const t = i / n; // 0 tail -> 1 head
                            const r = (9 - 5 * t) * dpr; // larger near tail, smaller at head
                            // Gradient fill from emerald to cyan centered on coin
                            const rg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, r);
                            rg.addColorStop(0, "rgba(34, 211, 238, 0.25)");
                            rg.addColorStop(1, "rgba(52, 211, 153, 0.0)");

                            // Soft glow fill
                            ctx.beginPath();
                            ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
                            ctx.fillStyle = rg;
                            ctx.shadowColor = "rgba(34, 211, 238, 0.28)";
                            ctx.shadowBlur = (qualityLow ? 8 : 12) * dpr;
                            ctx.fill();

                            // Crisp outer ring
                            ctx.beginPath();
                            ctx.arc(p.x, p.y, r * 0.55, 0, Math.PI * 2);
                            const sg = ctx.createLinearGradient(p.x - r, p.y, p.x + r, p.y);
                            sg.addColorStop(0, "rgba(52, 211, 153, 0.85)");
                            sg.addColorStop(1, "rgba(34, 211, 238, 0.95)");
                            ctx.lineWidth = 1.4 * dpr;
                            ctx.strokeStyle = sg;
                            ctx.shadowColor = "rgba(34, 211, 238, 0.28)";
                            ctx.shadowBlur = (qualityLow ? 5 : 8) * dpr;
                            ctx.stroke();
                        }
                        ctx.filter = "none";
                    }
                }

                // Slowly decay points when mouse stops to fade out the trail
                if (!mouseActive && points.length > 0) {
                    points.shift();
                }

                // Shards rendering disabled per request

                // Update and render falling currency symbols
                if (symbols.length) {
                    for (let i = symbols.length - 1; i >= 0; i--) {
                        const s = symbols[i];
                        s.x += s.vx;
                        s.y += s.vy;
                        s.vx *= 0.985; // stronger damping
                        s.vy *= 0.985; // stronger damping
                        s.vy += 0.018 * dpr; // gentler gravity
                        s.angle += s.rot * 0.5; // damp spin per frame
                        s.life -= s.decay;
                        if (s.life <= 0) { symbols.splice(i, 1); continue; }

                        // draw symbol text with high-contrast outlines
                        ctx.save();
                        ctx.translate(s.x, s.y);
                        ctx.rotate(s.angle);
                        ctx.globalAlpha = Math.max(0, Math.min(1, s.life)) * 0.7; // slightly more transparent in background
                        ctx.globalCompositeOperation = "source-over"; // avoid washout from lighter
                        const grad = ctx.createLinearGradient(-s.size, 0, s.size, 0);
                        if (s.hue === "emerald") {
                            grad.addColorStop(0, "rgba(52, 211, 153, 0.95)");
                            grad.addColorStop(1, "rgba(34, 211, 238, 0.75)");
                        } else {
                            grad.addColorStop(0, "rgba(34, 211, 238, 0.95)");
                            grad.addColorStop(1, "rgba(52, 211, 153, 0.75)");
                        }
                        ctx.font = "bold " + (s.size) + "px Inter, system-ui, Arial";
                        ctx.textAlign = "center";
                        ctx.textBaseline = "middle";
                        ctx.shadowColor = "rgba(34, 211, 238, 0.5)";
                        ctx.shadowBlur = (qualityLow ? 6 : 10) * dpr;
                        // no blur filter to reduce cost
                        // Outer dark outline
                        ctx.lineJoin = "round";
                        ctx.miterLimit = 2;
                        ctx.strokeStyle = "rgba(0,0,0,0.5)";
                        ctx.lineWidth = 2.2 * dpr;
                        ctx.strokeText(s.sym, 0, 0);
                        // Fill gradient
                        ctx.fillStyle = grad;
                        ctx.fillText(s.sym, 0, 0);
                        // Inner light edge
                        ctx.strokeStyle = "rgba(255,255,255,0.4)";
                        ctx.lineWidth = 0.8 * dpr;
                        ctx.strokeText(s.sym, 0, 0);
                        ctx.restore();
                    }
                    ctx.globalAlpha = 1;
                    ctx.filter = "none";
                }

                requestAnimationFrame(animate);
            }
            requestAnimationFrame(animate);
        })();
        </script>
    </body>
    </html>
    ';
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Helper functions (keeping existing ones)
function updateWalletBalance($conn, $user_id, $currency_id, $amount_change) {
    if ($amount_change >= 0) {
        $stmt = $conn->prepare("
            INSERT INTO wallets (user_id, currency_id, balance) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)
        ");
        $stmt->bind_param("iid", $user_id, $currency_id, $amount_change);
        return $stmt->execute();
    } else {
        $stmt_insert = $conn->prepare("INSERT IGNORE INTO wallets (user_id, currency_id, balance) VALUES (?, ?, 0)");
        $stmt_insert->bind_param("ii", $user_id, $currency_id);
        $stmt_insert->execute();

        $required_balance = abs($amount_change);
        $stmt_update = $conn->prepare("
            UPDATE wallets SET balance = balance + ?
            WHERE user_id = ? AND currency_id = ? AND balance >= ?
        ");
        $stmt_update->bind_param("diid", $amount_change, $user_id, $currency_id, $required_balance);
        $stmt_update->execute();
        return $stmt_update->affected_rows > 0;
    }
}

// Monthly admin profit (sum of conversion fees) converted to MMK for the selected year
$profit_year = isset($_GET['profit_year']) ? (int)$_GET['profit_year'] : (int)date('Y');
$monthly_profit = ['labels' => [], 'data' => [], 'year' => $profit_year];
try {
    $yr = (int)$monthly_profit['year'];
    // Resolve MMK currency_id
    $mmk_id = null;
    if ($stmt = $conn->prepare("SELECT currency_id FROM currencies WHERE UPPER(symbol)='MMK' LIMIT 1")) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $mmk_id = (int)$row['currency_id']; }
        $stmt->close();
    }
    // Build latest base->MMK rates map per currency
    $rate_map = [];
    if ($mmk_id) {
        $sql = "
            SELECT er.base_currency_id, er.rate
            FROM exchange_rates er
            INNER JOIN (
                SELECT base_currency_id, MAX(timestamp) AS ts
                FROM exchange_rates
                WHERE target_currency_id = ?
                GROUP BY base_currency_id
            ) latest
              ON latest.base_currency_id = er.base_currency_id AND latest.ts = er.timestamp
            WHERE er.target_currency_id = ?
        ";
        if ($st = $conn->prepare($sql)) {
            $st->bind_param('ii', $mmk_id, $mmk_id);
            $st->execute();
            $rsr = $st->get_result();
            while ($r = $rsr->fetch_assoc()) {
                $rate_map[(int)$r['base_currency_id']] = (float)$r['rate'];
            }
            $st->close();
        }
        // MMK->MMK rate is 1
        $rate_map[$mmk_id] = 1.0;
    }
    // Sum monthly fees for conversion and withdrawal separately, then convert to MMK using latest rate
    $monthly_conv = array_fill(1, 12, 0.0);
    $monthly_with = array_fill(1, 12, 0.0);

    // Conversion fees by month and source currency (from_currency_id)
    $sql_conv = "SELECT MONTH(created_at) AS m, from_currency_id AS cid, SUM(tax_amount) AS total
                 FROM fees
                 WHERE operation_type = 'conversion' AND YEAR(created_at) = ?
                 GROUP BY MONTH(created_at), from_currency_id
                 ORDER BY m";
    if ($stc = $conn->prepare($sql_conv)) {
        $stc->bind_param('i', $yr);
        $stc->execute();
        $rsc = $stc->get_result();
        while ($row = $rsc->fetch_assoc()) {
            $m = (int)$row['m'];
            $cid = (int)$row['cid'];
            $total = (float)($row['total'] ?? 0);
            $rate = isset($rate_map[$cid]) ? (float)$rate_map[$cid] : 0.0;
            // Ensure visibility even if a rate is missing: treat missing rate as 1 (assume already MMK)
            if ($rate <= 0) { $rate = 1.0; }
            $monthly_conv[$m] += $total * $rate;
        }
        $stc->close();
    }

    // Withdrawal fees by month and currency (currency_id)
    $sql_with = "SELECT MONTH(created_at) AS m, currency_id AS cid, SUM(tax_amount) AS total
                 FROM fees
                 WHERE operation_type = 'withdrawal' AND YEAR(created_at) = ?
                 GROUP BY MONTH(created_at), currency_id
                 ORDER BY m";
    if ($stw = $conn->prepare($sql_with)) {
        $stw->bind_param('i', $yr);
        $stw->execute();
        $rsw = $stw->get_result();
        while ($row = $rsw->fetch_assoc()) {
            $m = (int)$row['m'];
            $cid = (int)$row['cid'];
            $total = (float)($row['total'] ?? 0);
            $rate = isset($rate_map[$cid]) ? (float)$rate_map[$cid] : 0.0;
            if ($rate <= 0) { $rate = 1.0; }
            $monthly_with[$m] += $total * $rate;
        }
        $stw->close();
    }

    // Prepare chart arrays
    $labels = [];
    $data_conv = [];
    $data_with = [];
    for ($m = 1; $m <= 12; $m++) {
        $labels[] = date('M', mktime(0,0,0,$m,1,$yr));
        $data_conv[] = $monthly_conv[$m];
        $data_with[] = $monthly_with[$m];
    }
    $monthly_profit['labels'] = $labels;
    $monthly_profit['conv'] = $data_conv;
    $monthly_profit['with'] = $data_with;
} catch (Throwable $e) { /* ignore monthly profit failures */ }

// Build profit_years list (at least last 5 years) from fees
$profit_years = [];
try {
    $minY = (int)date('Y');
    $maxY = (int)date('Y');
    $rsYears = $conn->query("SELECT MIN(YEAR(created_at)) AS min_y, MAX(YEAR(created_at)) AS max_y FROM fees");
    if ($rsYears) {
        $rowY = $rsYears->fetch_assoc();
        if (!empty($rowY['min_y'])) { $minY = (int)$rowY['min_y']; }
        if (!empty($rowY['max_y'])) { $maxY = max((int)$rowY['max_y'], (int)date('Y')); }
    }
    // Ensure at least 5 years displayed
    if (($maxY - $minY + 1) < 5) { $minY = $maxY - 4; }
    for ($y = $maxY; $y >= $minY; $y--) { $profit_years[] = $y; }
} catch (Throwable $e) { /* ignore */ }

// Weekly admin profit (last 7 days): conversion + withdrawal fees converted to MMK
$weekly_profit = ['labels' => [], 'conv' => [], 'with' => []];
try {
    // Compute date range (inclusive)
    $end = new DateTime('today');
    $start = (clone $end)->modify('-6 days');
    $startStr = $start->format('Y-m-d');
    $endStr = $end->format('Y-m-d');

    // Resolve MMK currency_id
    $mmk_id = null;
    if ($st0 = $conn->prepare("SELECT currency_id FROM currencies WHERE UPPER(symbol)='MMK' LIMIT 1")) {
        $st0->execute();
        $rs0 = $st0->get_result();
        if ($r0 = $rs0->fetch_assoc()) { $mmk_id = (int)$r0['currency_id']; }
        $st0->close();
    }

    // Latest base->MMK rates
    $rate_map = [];
    if ($mmk_id) {
        $sqlr = "
            SELECT er.base_currency_id, er.rate
            FROM exchange_rates er
            INNER JOIN (
                SELECT base_currency_id, MAX(timestamp) AS ts
                FROM exchange_rates
                WHERE target_currency_id = ?
                GROUP BY base_currency_id
            ) latest
              ON latest.base_currency_id = er.base_currency_id AND latest.ts = er.timestamp
            WHERE er.target_currency_id = ?
        ";
        if ($sr = $conn->prepare($sqlr)) {
            $sr->bind_param('ii', $mmk_id, $mmk_id);
            $sr->execute();
            $rr = $sr->get_result();
            while ($rx = $rr->fetch_assoc()) {
                $rate_map[(int)$rx['base_currency_id']] = (float)$rx['rate'];
            }
            $sr->close();
        }
        $rate_map[$mmk_id] = 1.0;
    }

    // Prepare date labels and accumulators
    $labels = [];
    $acc_conv = [];
    $acc_with = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $labels[] = $key;
        $acc_conv[$key] = 0.0;
        $acc_with[$key] = 0.0;
    }

    // Conversion fees per day
    $sqlc = "SELECT DATE(created_at) AS d, from_currency_id AS cid, SUM(tax_amount) AS total
             FROM fees
             WHERE operation_type = 'conversion' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at), from_currency_id";
    if ($sc = $conn->prepare($sqlc)) {
        $sc->bind_param('ss', $startStr, $endStr);
        $sc->execute();
        $rc = $sc->get_result();
        while ($row = $rc->fetch_assoc()) {
            $day = $row['d'];
            $cid = (int)$row['cid'];
            $total = (float)($row['total'] ?? 0);
            $rate = isset($rate_map[$cid]) ? (float)$rate_map[$cid] : 1.0;
            $acc_conv[$day] = ($acc_conv[$day] ?? 0) + ($total * $rate);
        }
        $sc->close();
    }

    // Withdrawal fees per day
    $sqlw = "SELECT DATE(created_at) AS d, currency_id AS cid, SUM(tax_amount) AS total
             FROM fees
             WHERE operation_type = 'withdrawal' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at), currency_id";
    if ($sw = $conn->prepare($sqlw)) {
        $sw->bind_param('ss', $startStr, $endStr);
        $sw->execute();
        $rw = $sw->get_result();
        while ($row = $rw->fetch_assoc()) {
            $day = $row['d'];
            $cid = (int)$row['cid'];
            $total = (float)($row['total'] ?? 0);
            $rate = isset($rate_map[$cid]) ? (float)$rate_map[$cid] : 1.0;
            $acc_with[$day] = ($acc_with[$day] ?? 0) + ($total * $rate);
        }
        $sw->close();
    }

    // Build arrays aligned with labels
    $data_conv = [];
    $data_with = [];
    foreach ($labels as $key) {
        $data_conv[] = (float)$acc_conv[$key];
        $data_with[] = (float)$acc_with[$key];
    }
    $weekly_profit['labels'] = $labels;
    $weekly_profit['conv'] = $data_conv;
    $weekly_profit['with'] = $data_with;
} catch (Throwable $e) { /* ignore weekly profit failures */ }

function updateAdminWalletBalance($conn, $admin_id, $currency_id, $amount_change) {
    // Ensure wallet row exists (no-op if already present)
    $stmt_insert = $conn->prepare("
        INSERT INTO admin_wallet (admin_id, currency_id, balance) VALUES (?, ?, 0)
        ON DUPLICATE KEY UPDATE balance = balance + 0
    ");
    $stmt_insert->bind_param("ii", $admin_id, $currency_id);
    $stmt_insert->execute();

    if ($amount_change >= 0) {
        $stmt = $conn->prepare("
            UPDATE admin_wallet SET balance = balance + ?
            WHERE admin_id = ? AND currency_id = ?
        ");
        $stmt->bind_param("dii", $amount_change, $admin_id, $currency_id);
        return $stmt->execute();
    } else {
        $amount_to_subtract = abs($amount_change);
        $stmt = $conn->prepare("
            UPDATE admin_wallet SET balance = balance - ?
            WHERE admin_id = ? AND currency_id = ? AND balance >= ?
        ");
        $stmt->bind_param("diid", $amount_to_subtract, $admin_id, $currency_id, $amount_to_subtract);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}

function getCurrencySymbol($conn, $currency_id) {
    $stmt = $conn->prepare("SELECT symbol FROM currencies WHERE currency_id = ?");
    $stmt->bind_param("i", $currency_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['symbol'] : 'N/A';
}

function recordTransaction($conn, $user_id, $admin_id, $currency_id, $amount, $type, $user_payment_id = NULL, $proof_of_screenshot = NULL) {
    $type = strtolower($type);
    if ($type === 'admin_deposit' || $type === 'admin_withdrawal') {
        $stmt = $conn->prepare("INSERT INTO admin_transactions (admin_id, currency_id, amount, type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iids", $admin_id, $currency_id, $amount, $type);
    } else {
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, admin_id, currency_id, amount, type, user_payment_id, proof_of_screenshot) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsss", $user_id, $admin_id, $currency_id, $amount, $type, $user_payment_id, $proof_of_screenshot);
    }
    
    $result = $stmt->execute();
    return $result;
}

function reconcileAdminBalance($conn, $admin_id) {
    $conn->begin_transaction();
    try {
        $calculated_balances = [];
        $stmt = $conn->prepare("SELECT currency_id, amount, type FROM transactions");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $currency_id = $row['currency_id'];
            $amount = $row['amount'];
            $type = $row['type'];

            if (!isset($calculated_balances[$currency_id])) {
                $calculated_balances[$currency_id] = 0;
            }

            if ($type === 'deposit') {
                $calculated_balances[$currency_id] -= $amount;
            } elseif ($type === 'withdrawal') {
                $calculated_balances[$currency_id] += $amount;
            }
        }
        
        $stmt = $conn->prepare("SELECT currency_id, amount, type FROM admin_transactions WHERE admin_id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $currency_id = $row['currency_id'];
            $amount = $row['amount'];
            $type = $row['type'];

            if (!isset($calculated_balances[$currency_id])) {
                $calculated_balances[$currency_id] = 0;
            }

            if ($type === 'admin_deposit') {
                $calculated_balances[$currency_id] += $amount;
            } elseif ($type === 'admin_withdrawal') {
                $calculated_balances[$currency_id] -= $amount;
            }
        }

        $stmt_clear = $conn->prepare("DELETE FROM admin_wallet WHERE admin_id = ?");
        $stmt_clear->bind_param("i", $admin_id);
        $stmt_clear->execute();

        $stmt_insert = $conn->prepare("INSERT INTO admin_wallet (admin_id, currency_id, balance) VALUES (?, ?, ?)");
        foreach ($calculated_balances as $currency_id => $balance) {
            $stmt_insert->bind_param("iid", $admin_id, $currency_id, $balance);
            $stmt_insert->execute();
        }

        $conn->commit();
        return "Admin wallet balance has been successfully reconciled based on all transaction history.";
    } catch (Exception $e) {
        $conn->rollback();
        return "Error during balance reconciliation: " . $e->getMessage();
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'confirm' && isset($_POST['request_id'])) {
        $request_id = $_POST['request_id'];
        $conn->begin_transaction();
        try {
            $stmt_fetch = $conn->prepare("SELECT * FROM user_currency_requests WHERE request_id = ?");
            $stmt_fetch->bind_param("i", $request_id);
            $stmt_fetch->execute();
            $request_data = $stmt_fetch->get_result()->fetch_assoc();

            if ($request_data) {
                $user_id = $request_data['user_id'];
                $currency_id = $request_data['currency_id'];
                $amount = $request_data['amount'];
                $transaction_type = $request_data['transaction_type'];
                
                // Pre-check admin funds for deposit and show a friendly error in-place if insufficient
                if ($transaction_type === 'deposit') {
                    $stmt_bal = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                    if ($stmt_bal) {
                        $stmt_bal->bind_param("ii", $admin_id, $currency_id);
                        $stmt_bal->execute();
                        $res_bal = $stmt_bal->get_result()->fetch_assoc();
                        $stmt_bal->close();
                        $current_balance = $res_bal && isset($res_bal['balance']) ? (float)$res_bal['balance'] : 0.0;
                        if ($current_balance < (float)$amount) {
                            $conn->rollback();
                            $message = "Insufficient admin funds. Available: " . number_format($current_balance, 2) . " < Required: " . number_format((float)$amount, 2);
                            // Stop further processing of this request confirmation; dashboard remains visible
                            throw new Exception($message);
                        }
                    }
                }

                if ($transaction_type === 'deposit') {
                    $user_amount_change = $amount;
                    $admin_amount_change = -$amount;

                    // Deduct admin first to enforce sufficient funds (all within the same transaction)
                    if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $admin_amount_change)) {
                        throw new Exception("Insufficient admin currency for deposit transaction.");
                    }

                    if (!updateWalletBalance($conn, $user_id, $currency_id, $user_amount_change)) {
                        throw new Exception("Failed to update user's wallet for deposit.");
                    }

                    if (!recordTransaction($conn, $user_id, $admin_id, $currency_id, $amount, 'deposit', $request_data['user_payment_id'], $request_data['proof_of_screenshot'])) {
                        throw new Exception("Failed to record transaction.");
                    }
                    
                } else {
                    // Apply 2% withdrawal tax (fee added on top of the requested amount)
                    $fee_rate = 0.02;
                    $fee = (float)$amount * $fee_rate;
                    $total = (float)$amount + $fee;

                    $user_amount_change = -$total;        // deduct requested amount + fee
                    $admin_amount_change = $total;         // credit admin with amount + fee

                    if (!updateWalletBalance($conn, $user_id, $currency_id, $user_amount_change)) {
                        throw new Exception("User has insufficient currency for withdrawal (including 2% fee).");
                    }

                    if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $admin_amount_change)) {
                        throw new Exception("Failed to update admin's wallet for withdrawal.");
                    }

                    // Persist fee/profit in unified fees table
                    // Ensure required columns exist
                    try {
                        $dbName = $dbname;
                        $check = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fees'");
                        if ($check) {
                            $check->bind_param('s', $dbName);
                            $check->execute();
                            $res = $check->get_result();
                            $have = [];
                            while ($r = $res->fetch_assoc()) { $have[$r['COLUMN_NAME']] = true; }
                            $check->close();
                            if (empty($have['operation_type'])) { $conn->query("ALTER TABLE fees ADD COLUMN operation_type VARCHAR(20) NOT NULL DEFAULT 'conversion'"); }
                            if (empty($have['request_id'])) { $conn->query("ALTER TABLE fees ADD COLUMN request_id INT NULL"); }
                            if (empty($have['currency_id'])) { $conn->query("ALTER TABLE fees ADD COLUMN currency_id INT NULL"); }
                            // Ensure a usable timestamp exists for reporting
                            if (empty($have['created_at']) && empty($have['timestamp'])) {
                                $conn->query("ALTER TABLE fees ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                                $have['created_at'] = true;
                            }
                        }
                    } catch (Throwable $e) { /* ignore */ }

                    // Insert withdrawal fee row. Some databases enforce FK on from_currency_id.
                    // Verify that the currency exists; if not, set from_currency_id to NULL to avoid FK violation.
                    $fc = (int)$currency_id; $cc = (int)$currency_id; $fa = (float)$fee; $ar = (float)$amount; $fr = (float)$fee_rate;
                    $exists = false;
                    if ($chk = $conn->prepare("SELECT 1 FROM currencies WHERE currency_id = ? LIMIT 1")) {
                        $chk->bind_param('i', $fc);
                        $chk->execute();
                        $res = $chk->get_result();
                        $exists = (bool)($res && $res->fetch_row());
                        $chk->close();
                    }
                    if ($exists) {
                        // Set both from_currency_id and to_currency_id to the withdrawal currency
                        $tc = $fc;
                        // Prefer created_at if available for reporting
                        $sqlIns = "INSERT INTO fees (operation_type, request_id, user_id, from_currency_id, to_currency_id, currency_id, amount_converted, tax_amount, tax_rate, created_at) VALUES ('withdrawal', ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        $stmt_fee = $conn->prepare($sqlIns);
                        if ($stmt_fee) {
                            $stmt_fee->bind_param('iiiiiddd', $request_id, $user_id, $fc, $tc, $cc, $ar, $fa, $fr);
                            $stmt_fee->execute();
                            $stmt_fee->close();
                        }
                    } else {
                        // Currency not found; insert NULL for from_currency_id and to_currency_id to avoid FK violation
                        $sqlIns = "INSERT INTO fees (operation_type, request_id, user_id, from_currency_id, to_currency_id, currency_id, amount_converted, tax_amount, tax_rate, created_at) VALUES ('withdrawal', ?, ?, NULL, NULL, ?, ?, ?, ?, NOW())";
                        $stmt_fee = $conn->prepare($sqlIns);
                        if ($stmt_fee) {
                            $stmt_fee->bind_param('iiiddd', $request_id, $user_id, $cc, $ar, $fa, $fr);
                            $stmt_fee->execute();
                            $stmt_fee->close();
                        }
                    }

                    // Record the withdrawal for the requested amount (fee not separately recorded)
                    // Include user_payment_id and proof to link back to user_currency_requests for history details
                    if (!recordTransaction(
                            $conn,
                            $user_id,
                            $admin_id,
                            $currency_id,
                            $amount,
                            'withdrawal',
                            $request_data['user_payment_id'] ?? null,
                            $request_data['proof_of_screenshot'] ?? null
                        )) {
                        throw new Exception("Failed to record transaction.");
                    }
                }
                $status = 'completed';
                // Do not auto-clear notifications; let the user clear them from their dashboard
                $stmt_update = $conn->prepare("UPDATE user_currency_requests SET status = ?, admin_id = ?, decision_timestamp = NOW() WHERE request_id = ?");
                $stmt_update->bind_param("sii", $status, $admin_id, $request_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to update request status.");
                }
                if ($transaction_type === 'withdrawal') {
                    $message = "Request #{$request_id} completed. Fee collected (2%): " . number_format((float)$fee, 2);
                } else {
                    $message = "Request #{$request_id} has been completed successfully.";
                }
                $conn->commit();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error confirming request: " . $e->getMessage();
            header("Location: admin.php?view=requests&message=" . urlencode($message));
            exit();
        }
   } elseif ($_POST['action'] === 'reject' && isset($_POST['request_id'])) {
    $request_id = $_POST['request_id'];
    $reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : '';
    $custom_reason = isset($_POST['custom_reason']) ? trim($_POST['custom_reason']) : '';
    
    // Combine reasons if "Other" was selected
    if ($reject_reason === 'Other' && !empty($custom_reason)) {
        $reject_reason = $custom_reason;
    }
    
    $status = 'rejected';
    $stmt_update = $conn->prepare("UPDATE user_currency_requests SET status = ?, admin_id = ?, decision_timestamp = NOW(), reject_reason = ? WHERE request_id = ?");
    $stmt_update->bind_param("sisi", $status, $admin_id, $reject_reason, $request_id);
    $stmt_update->execute();
    
    $message = "Request #{$request_id} has been rejected.";
    if (!empty($reject_reason)) {
        $message .= " Reason: " . htmlspecialchars($reject_reason);
    }
    header("Location: admin.php?view=requests&message=" . urlencode($message));
    exit();

}
     elseif ($_POST['action'] === 'run_fix') {
        $message = reconcileAdminBalance($conn, $admin_id);
    } elseif ($_POST['action'] === 'admin_deposit' && isset($_POST['currency_id']) && isset($_POST['amount'])) {
        $currency_id = $_POST['currency_id'];
        $amount = $_POST['amount'];
    
        if ($amount <= 0) {
            $message = "Deposit amount must be positive.";
        } else {
            $conn->begin_transaction();
            try {
                // Create bank_deposit_history table if not exists
                $conn->query("CREATE TABLE IF NOT EXISTS bank_deposit_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NULL,
                    currency_id INT NOT NULL,
                    previous_balance DECIMAL(18,2) NOT NULL,
                    after_balance DECIMAL(18,2) NOT NULL,
                    amount DECIMAL(18,2) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Fetch previous admin wallet balance
                $prev_balance = 0.00;
                $stmt_prev = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                $stmt_prev->bind_param("ii", $admin_id, $currency_id);
                $stmt_prev->execute();
                $res_prev = $stmt_prev->get_result()->fetch_assoc();
                if ($res_prev && isset($res_prev['balance'])) { $prev_balance = (float)$res_prev['balance']; }
                $stmt_prev->close();

                if (!updateBankBalance($conn, $currency_id, -$amount)) {
                    throw new Exception("Insufficient bank funds for this deposit.");
                }
            
                if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $amount)) {
                    throw new Exception("Failed to update admin's wallet.");
                }
            
                if (!recordTransaction($conn, NULL, $admin_id, $currency_id, $amount, 'admin_deposit')) {
                    throw new Exception("Failed to record transaction.");
                }
            
                // Fetch new admin wallet balance and record in history
                $after_balance = $prev_balance + (float)$amount;
                $stmt_after = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                $stmt_after->bind_param("ii", $admin_id, $currency_id);
                $stmt_after->execute();
                $res_after = $stmt_after->get_result()->fetch_assoc();
                if ($res_after && isset($res_after['balance'])) { $after_balance = (float)$res_after['balance']; }
                $stmt_after->close();

                $stmt_hist = $conn->prepare("INSERT INTO bank_deposit_history (admin_id, currency_id, previous_balance, after_balance, amount) VALUES (?, ?, ?, ?, ?)");
                if ($stmt_hist) {
                    $stmt_hist->bind_param("iiddd", $admin_id, $currency_id, $prev_balance, $after_balance, $amount);
                    $stmt_hist->execute();
                    $stmt_hist->close();
                }

                $conn->commit();
                $message = "Successfully deposited " . number_format($amount, 2) . " " . getCurrencySymbol($conn, $currency_id) . " to your wallet.";
                // Keep dashboard view; no popup for success (only errors trigger the modal)
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error depositing funds: " . $e->getMessage();
                // Keep dashboard view; the centered popup will display over the dashboard
            }
        }
    }
    // Save exchange rate markup settings (multi-currencies) and optional refresh
    elseif ($_POST['action'] === 'save_settings') {
        $global_mode = isset($_POST['global_mode']) ? $_POST['global_mode'] : 'percent';
        $global_value = isset($_POST['global_value']) && $_POST['global_value'] !== '' ? floatval($_POST['global_value']) : 0.0;
        $mmk_percent = isset($_POST['mmk_percent']) && $_POST['mmk_percent'] !== '' ? floatval($_POST['mmk_percent']) : 0.0;
        $thb_percent = isset($_POST['thb_percent']) && $_POST['thb_percent'] !== '' ? floatval($_POST['thb_percent']) : 0.0;
        $jpy_percent = isset($_POST['jpy_percent']) && $_POST['jpy_percent'] !== '' ? floatval($_POST['jpy_percent']) : 0.0;
        $refresh_rates = isset($_POST['refresh_rates']) && $_POST['refresh_rates'] == '1';

        $settings = er_load_settings();
        $settings['global'] = ['mode' => $global_mode, 'value' => $global_value];
        if (!isset($settings['targets'])) { $settings['targets'] = []; }
        $settings['targets']['MMK'] = ['mode' => 'percent', 'value' => $mmk_percent];
        $settings['targets']['THB'] = ['mode' => 'percent', 'value' => $thb_percent];
        $settings['targets']['JPY'] = ['mode' => 'percent', 'value' => $jpy_percent];

        $saved = er_save_settings($settings);
        if ($saved && $refresh_rates) {
            // Map symbols -> ids
            $currencies = [];
            $rs = $conn->query("SELECT currency_id, symbol FROM currencies");
            if ($rs) {
                while ($row = $rs->fetch_assoc()) { $currencies[$row['symbol']] = (int)$row['currency_id']; }
            }
            $pairs = [
                ['from' => 'THB', 'to' => 'MMK'],
                ['from' => 'MMK', 'to' => 'THB'],
                ['from' => 'JPY', 'to' => 'MMK'],
                ['from' => 'MMK', 'to' => 'JPY']
            ];
            $ok = true;
            $conn->begin_transaction();
            foreach ($pairs as $p) {
                if (!isset($currencies[$p['from']]) || !isset($currencies[$p['to']])) continue;
                list($live, $eff) = er_get_effective_rate($p['from'], $p['to']);
                if ($eff === null) { $ok = false; break; }
                if (!addExchangeRate($conn, $currencies[$p['from']], $currencies[$p['to']], $eff)) { $ok = false; break; }
            }
            if ($ok) { $conn->commit(); $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Settings saved and key MMK pairs updated.</div>"; }
            else { $conn->rollback(); $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Settings saved, but failed to refresh some pairs.</div>"; }
        } elseif ($saved) {
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Settings saved successfully.</div>";
        } else {
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to save settings.</div>";
        }
    }
    // Sync exchange rate (supports symbol-based inputs and adds inverse) with fallback to id-based
    elseif ($_POST['action'] === 'sync_from_api') {
        $base_code = '';
        $target_code = '';
        // direct MMK buttons: base_currency is symbol
        if (isset($_POST['base_currency']) && !ctype_digit((string)$_POST['base_currency'])) {
            $base_code = strtoupper(trim($_POST['base_currency']));
            $target_code = 'MMK';
        } elseif (!empty($_POST['use_custom']) && isset($_POST['custom_base']) && isset($_POST['custom_target'])) {
            $base_code = strtoupper(trim($_POST['custom_base']));
            $target_code = strtoupper(trim($_POST['custom_target']));
        }

        if ($base_code && $target_code && $base_code !== $target_code) {
            $stmt = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN (?, ?)");
            $stmt->bind_param("ss", $base_code, $target_code);
            $stmt->execute();
            $res = $stmt->get_result();
            $map = [];
            while ($r = $res->fetch_assoc()) { $map[$r['symbol']] = (int)$r['currency_id']; }
            $stmt->close();
            if (isset($map[$base_code]) && isset($map[$target_code])) {
                list($live, $eff) = er_get_effective_rate($base_code, $target_code);
                if ($eff !== null) {
                    if (addExchangeRate($conn, $map[$base_code], $map[$target_code], $eff)) {
                        // also add inverse
                        addExchangeRate($conn, $map[$target_code], $map[$base_code], 1/$eff);
                        $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Synced: 1 {$base_code} = " . number_format($eff, 4) . " {$target_code} (inverse " . number_format(1/$eff, 6) . ")</div>";
                    } else {
                        $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Fetched rate but failed to store.</div>";
                    }
                } else {
                    $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to fetch live rate for {$base_code} → {$target_code}.</div>";
                }
            } else {
                $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Invalid currencies selected.</div>";
            }
        } else {
            // Fallback: original id-based flow
            $base_id = isset($_POST['base_currency']) ? intval($_POST['base_currency']) : 0;
            $target_id = isset($_POST['target_currency']) ? intval($_POST['target_currency']) : 0;
            if ($base_id > 0 && $target_id > 0 && $base_id !== $target_id) {
                $stmt = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE currency_id IN (?, ?)");
                $stmt->bind_param("ii", $base_id, $target_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $symbols = [];
                while ($r = $res->fetch_assoc()) { $symbols[$r['currency_id']] = $r['symbol']; }
                $stmt->close();
                if (isset($symbols[$base_id]) && isset($symbols[$target_id])) {
                    list($live, $eff) = er_get_effective_rate($symbols[$base_id], $symbols[$target_id]);
                    if ($eff !== null) {
                        if (addExchangeRate($conn, $base_id, $target_id, $eff)) {
                            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Synced from API: 1 " . htmlspecialchars($symbols[$base_id]) . " = " . number_format($eff, 4) . " " . htmlspecialchars($symbols[$target_id]) . " (with markup applied)</div>";
                        } else {
                            $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Fetched rate but failed to store (may already exist).</div>";
                        }
                    } else {
                        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to fetch live rate from API.</div>";
                    }
                } else {
                    $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Invalid currencies selected.</div>";
                }
            } else {
                $message = "<div class='bg-red-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Please choose different base and target currencies.</div>";
            }
        }
    }
}

// Check for message in URL parameter
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

// Check the view parameter
if (isset($_GET['view'])) {
    if ($_GET['view'] === 'requests') {
        $show_requests = true;
    } elseif ($_GET['view'] === 'wallet') {
        $show_wallet = true;
    } elseif ($_GET['view'] === 'history') {
        $show_history = true;
    } elseif ($_GET['view'] === 'deposit') {
        $show_deposit = true;
    } elseif ($_GET['view'] === 'fees') {
        $show_fees = true;
    } elseif ($_GET['view'] === 'exchange_rate') {
        $show_exchange_rate = true;
    }
}

// Fetch all available currencies
$currencies = [];
$stmt_currencies = $conn->prepare("SELECT currency_id, symbol FROM currencies");
$stmt_currencies->execute();
$result_currencies = $stmt_currencies->get_result();
while ($row = $result_currencies->fetch_assoc()) {
    $currencies[] = $row;
}
$stmt_currencies->close();

// Auto-sync only MMK-target pairs for today's rates (keeps admin from forgetting)
try {
    $autoSyncedMMK = 0;
    if (function_exists('getAvailableCurrencyPairs')) {
        $pairs = getAvailableCurrencyPairs($conn);
        if (!empty($pairs)) {
            $stmt_has_today = $conn->prepare("SELECT 1 FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? AND DATE(timestamp) = CURDATE() LIMIT 1");
            if ($stmt_has_today) {
                foreach ($pairs as $p) {
                    if (!isset($p['target_symbol']) || strtoupper($p['target_symbol']) !== 'MMK') continue;
                    $baseId = (int)$p['base_currency_id'];
                    $targetId = (int)$p['target_currency_id'];
                    $stmt_has_today->bind_param('ii', $baseId, $targetId);
                    $stmt_has_today->execute();
                    $has = $stmt_has_today->get_result()->fetch_row();
                    if ($has) continue;
                    // Fetch effective rate with current settings (includes any saved MMK markup)
                    list($live, $eff) = er_get_effective_rate($p['base_symbol'], $p['target_symbol']);
                    if ($eff !== null) {
                        if (addExchangeRate($conn, $baseId, $targetId, $eff)) { $autoSyncedMMK++; }
                    }
                }
                $stmt_has_today->close();
            }
        }
    }
    if ($autoSyncedMMK > 0) {
        $message = "<div class='bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded relative' role='alert'>Auto-synced today's MMK rates for " . intval($autoSyncedMMK) . " pair(s).</div>" . $message;
    }
} catch (Throwable $e) { /* ignore auto-sync failures */ }

// Fetch all exchange rates
$exchange_rates = [];
$stmt_rates = $conn->prepare("
    SELECT 
        er.*, 
        c1.symbol AS base_symbol, 
        c2.symbol AS target_symbol
    FROM exchange_rates er
    JOIN currencies c1 ON er.base_currency_id = c1.currency_id
    JOIN currencies c2 ON er.target_currency_id = c2.currency_id
");
$stmt_rates->execute();
$result_rates = $stmt_rates->get_result();
while ($row = $result_rates->fetch_assoc()) {
    $row['rate_ts'] = isset($row['updated_at']) && $row['updated_at']
        ? $row['updated_at']
        : (isset($row['created_at']) && $row['created_at']
            ? $row['created_at']
            : (isset($row['timestamp']) ? $row['timestamp'] : null));
    $exchange_rates[] = $row;
}
$stmt_rates->close();

// Load exchange rate markup settings
$rate_settings = er_load_settings();

// Append derived cross rates (via MMK) into $exchange_rates so they show together
try {
    // Build a quick lookup map from already-fetched rates
    $rate_map = [];
    foreach ($exchange_rates as $r) {
        $key = strtoupper(($r['base_symbol'] ?? '')) . '>' . strtoupper(($r['target_symbol'] ?? ''));
        $rate_map[$key] = [
            'rate' => isset($r['rate']) ? (float)$r['rate'] : null,
            'ts'   => isset($r['rate_ts']) ? $r['rate_ts'] : null
        ];
    }

    // Helper to get source rate and timestamp from map
    $get = function($base, $target) use ($rate_map) {
        $k = strtoupper($base) . '>' . strtoupper($target);
        return $rate_map[$k] ?? ['rate' => null, 'ts' => null];
    };

    // Compute derived using MMK as pivot
    $pairs = [];
    $usd_mmk = $get('USD','MMK');
    $jpy_mmk = $get('JPY','MMK');
    $thb_mmk = $get('THB','MMK');

    // USD ↔ JPY
    if (!empty($usd_mmk['rate']) && !empty($jpy_mmk['rate']) && $jpy_mmk['rate'] > 0) {
        $rate = $usd_mmk['rate'] / $jpy_mmk['rate'];
        $ts   = $usd_mmk['ts'] ?: $jpy_mmk['ts'];
        $pairs[] = ['base_symbol' => 'USD', 'target_symbol' => 'JPY', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'JPY', 'target_symbol' => 'USD', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }
    // USD ↔ THB
    if (!empty($usd_mmk['rate']) && !empty($thb_mmk['rate']) && $thb_mmk['rate'] > 0) {
        $rate = $usd_mmk['rate'] / $thb_mmk['rate'];
        $ts   = $usd_mmk['ts'] ?: $thb_mmk['ts'];
        $pairs[] = ['base_symbol' => 'USD', 'target_symbol' => 'THB', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'THB', 'target_symbol' => 'USD', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }
    // THB ↔ JPY
    if (!empty($thb_mmk['rate']) && !empty($jpy_mmk['rate']) && $jpy_mmk['rate'] > 0) {
        $rate = $thb_mmk['rate'] / $jpy_mmk['rate'];
        $ts   = $thb_mmk['ts'] ?: $jpy_mmk['ts'];
        $pairs[] = ['base_symbol' => 'THB', 'target_symbol' => 'JPY', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'JPY', 'target_symbol' => 'THB', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }

    // Avoid duplicating if DB already contains such pairs
    foreach ($pairs as $p) {
        $k = $p['base_symbol'] . '>' . $p['target_symbol'];
        if (!isset($rate_map[$k])) { $exchange_rates[] = $p; }
    }
} catch (Throwable $e) { /* ignore derive failures */ }

// Build live preview for 4 key currencies to MMK
$live_preview = [];
try {
    // Map symbols for quick existence checks
    $sym_map = [];
    $rs_c = $conn->query("SELECT currency_id, symbol FROM currencies");
    if ($rs_c) {
        while ($r = $rs_c->fetch_assoc()) { $sym_map[strtoupper($r['symbol'])] = (int)$r['currency_id']; }
    }
    $bases = ['USD','THB','JPY'];
    $target = 'MMK';
    foreach ($bases as $base) {
        if (!isset($sym_map[$base]) || !isset($sym_map[$target])) continue;
        list($live, $eff) = er_get_effective_rate($base, $target);
        if ($live !== null && $eff !== null) {
            $live_preview[] = [
                'base' => $base,
                'target' => $target,
                'live' => $live,
                'effective' => $eff
            ];
        }
    }
} catch (Throwable $e) { /* ignore preview errors */ }

// Fetch total users
$total_users = 0;
$stmt_total_users = $conn->prepare("SELECT COUNT(*) AS total FROM users");
$stmt_total_users->execute();
$result_total_users = $stmt_total_users->get_result()->fetch_assoc();
$total_users = $result_total_users['total'];
$stmt_total_users->close();

// Fetch active users based on login times (last 24 hours)
// Ensure table exists (safe guard if app was updated recently)
$conn->query("CREATE TABLE IF NOT EXISTS user_login_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$active_users = 0;
$stmt_active_users = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS active FROM user_login_events WHERE login_at >= CURDATE() AND login_at < CURDATE() + INTERVAL 1 DAY");
if ($stmt_active_users) {
    $stmt_active_users->execute();
    $res = $stmt_active_users->get_result();
    if ($res) { $row = $res->fetch_assoc(); $active_users = isset($row['active']) ? (int)$row['active'] : 0; }
    $stmt_active_users->close();
}

// Fetch all users
$users = [];
$stmt_users = $conn->prepare("SELECT user_id, username, email FROM users ORDER BY username ASC");
$stmt_users->execute();
$result_users = $stmt_users->get_result();
while ($row = $result_users->fetch_assoc()) {
    $users[] = $row;
}
$stmt_users->close();

// Fetch bank balances
$bank_accounts = [];
$stmt_bank_accounts = $conn->prepare("SELECT b.*, c.symbol FROM bank_accounts b JOIN currencies c ON b.currency_id = c.currency_id");
$stmt_bank_accounts->execute();
$result_bank_accounts = $stmt_bank_accounts->get_result();
while ($row = $result_bank_accounts->fetch_assoc()) {
    $bank_accounts[] = $row;
}
$stmt_bank_accounts->close();

// Fetch pending requests
$pending_requests = [];

$stmt_requests = $conn->prepare("
    SELECT uc.*, u.username, c.symbol 
    FROM user_currency_requests uc
    JOIN users u ON uc.user_id = u.user_id
    JOIN currencies c ON uc.currency_id = c.currency_id
    WHERE uc.status = 'pending'
    ORDER BY uc.request_timestamp DESC
");
$stmt_requests->execute();
$result_requests = $stmt_requests->get_result();
while ($row = $result_requests->fetch_assoc()) {
    $pending_requests[] = $row;
}
$pending_count = count($pending_requests);
$stmt_requests->close();

// When viewing notifications, mark current pending as seen
if ($show_requests) {
    $_SESSION['seen_pending_count'] = $pending_count;
}
// Compute unseen badge count based on last seen
$badge_count = $pending_count - (isset($_SESSION['seen_pending_count']) ? (int)$_SESSION['seen_pending_count'] : 0);
if ($badge_count < 0) { $badge_count = 0; }

// Fetch all requests history
$all_requests_history = [];
$stmt_all_requests = $conn->prepare("
    SELECT uc.*, u.username, c.symbol 
    FROM user_currency_requests uc
    JOIN users u ON uc.user_id = u.user_id
    JOIN currencies c ON uc.currency_id = c.currency_id
    ORDER BY uc.request_timestamp DESC
");
$stmt_all_requests->execute();
$result_all_requests = $stmt_all_requests->get_result();
while ($row = $result_all_requests->fetch_assoc()) {
    $all_requests_history[] = $row;
}
$stmt_all_requests->close();

// Fetch admin wallet balances
$admin_wallets = [];
$stmt_admin_wallet = $conn->prepare("
    SELECT w.balance, c.symbol 
    FROM admin_wallet w 
    JOIN currencies c ON w.currency_id = c.currency_id 
    WHERE w.admin_id = ?
");
$stmt_admin_wallet->bind_param("i", $admin_id);
$stmt_admin_wallet->execute();
$result_admin_wallet = $stmt_admin_wallet->get_result();
while ($row = $result_admin_wallet->fetch_assoc()) {
    $admin_wallets[] = $row;
}
$stmt_admin_wallet->close();

// Fetch transaction history (limit 10, optional type filter) — include related request metadata if available
$transaction_history = [];
$tx_type = (isset($_GET['tx_type']) && in_array(strtolower($_GET['tx_type']), ['deposit','withdrawal'])) ? strtolower($_GET['tx_type']) : null;
if ($tx_type) {
    $stmt_history = $conn->prepare("
        SELECT * FROM (
            SELECT 
                t.transaction_id AS id,
                t.user_id,
                u.username,
                t.type,
                t.amount,
                c.symbol,
                t.timestamp,
                lu.request_timestamp AS ucr_request_timestamp,
                lu.decision_timestamp AS ucr_decision_timestamp,
                lu.payment_channel AS ucr_payment_channel,
                t.proof_of_screenshot,
                'approved' AS approval_status
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            JOIN currencies c ON t.currency_id = c.currency_id
            LEFT JOIN user_currency_requests lu
              ON (
                    t.user_payment_id IS NOT NULL
                AND t.user_payment_id <> ''
                AND lu.user_payment_id = t.user_payment_id
                AND COALESCE(lu.decision_timestamp, lu.request_timestamp) = (
                        SELECT COALESCE(u2.decision_timestamp, u2.request_timestamp)
                        FROM user_currency_requests u2
                        WHERE u2.user_payment_id = t.user_payment_id
                          AND COALESCE(u2.decision_timestamp, u2.request_timestamp) <= t.timestamp
                        ORDER BY COALESCE(u2.decision_timestamp, u2.request_timestamp) DESC
                        LIMIT 1
                )
            )
            WHERE LOWER(t.type) = ?
            UNION ALL
            SELECT 
                ucr.request_id AS id,
                ucr.user_id,
                u.username,
                ucr.transaction_type AS type,
                ucr.amount,
                c.symbol,
                COALESCE(ucr.decision_timestamp, ucr.request_timestamp) AS timestamp,
                ucr.request_timestamp AS ucr_request_timestamp,
                ucr.decision_timestamp AS ucr_decision_timestamp,
                ucr.payment_channel AS ucr_payment_channel,
                ucr.proof_of_screenshot,
                'rejected' AS approval_status
            FROM user_currency_requests ucr
            JOIN users u ON ucr.user_id = u.user_id
            JOIN currencies c ON ucr.currency_id = c.currency_id
            WHERE ucr.status = 'rejected' AND LOWER(ucr.transaction_type) = ?
        ) x
        ORDER BY x.timestamp DESC
        LIMIT 10
    ");
    $stmt_history->bind_param("ss", $tx_type, $tx_type);
} else {
    $stmt_history = $conn->prepare("
        SELECT * FROM (
            SELECT 
                t.transaction_id AS id,
                t.user_id,
                u.username,
                t.type,
                t.amount,
                c.symbol,
                t.timestamp,
                lu.request_timestamp AS ucr_request_timestamp,
                lu.decision_timestamp AS ucr_decision_timestamp,
                lu.payment_channel AS ucr_payment_channel,
                t.proof_of_screenshot,
                'approved' AS approval_status
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            JOIN currencies c ON t.currency_id = c.currency_id
            LEFT JOIN user_currency_requests lu
              ON (
                    t.user_payment_id IS NOT NULL
                AND t.user_payment_id <> ''
                AND lu.user_payment_id = t.user_payment_id
                AND COALESCE(lu.decision_timestamp, lu.request_timestamp) = (
                        SELECT COALESCE(u2.decision_timestamp, u2.request_timestamp)
                        FROM user_currency_requests u2
                        WHERE u2.user_payment_id = t.user_payment_id
                          AND COALESCE(u2.decision_timestamp, u2.request_timestamp) <= t.timestamp
                        ORDER BY COALESCE(u2.decision_timestamp, u2.request_timestamp) DESC
                        LIMIT 1
                )
            )
            UNION ALL
            SELECT 
                ucr.request_id AS id,
                ucr.user_id,
                u.username,
                ucr.transaction_type AS type,
                ucr.amount,
                c.symbol,
                COALESCE(ucr.decision_timestamp, ucr.request_timestamp) AS timestamp,
                ucr.request_timestamp AS ucr_request_timestamp,
                ucr.decision_timestamp AS ucr_decision_timestamp,
                ucr.payment_channel AS ucr_payment_channel,
                ucr.proof_of_screenshot,
                'rejected' AS approval_status
            FROM user_currency_requests ucr
            JOIN users u ON ucr.user_id = u.user_id
            JOIN currencies c ON ucr.currency_id = c.currency_id
            WHERE ucr.status = 'rejected'
        ) x
        ORDER BY x.timestamp DESC
        LIMIT 10
    ");
}
$stmt_history->execute();
$result_history = $stmt_history->get_result();
while ($row = $result_history->fetch_assoc()) { $transaction_history[] = $row; }
$stmt_history->close();

// Fallback enrichment: for approved withdrawals with missing request/decision timestamps,
// try to locate the matching user_currency_requests row by user, type, amount and time proximity.
if (!empty($transaction_history)) {
    if ($stmt_enrich = $conn->prepare(
        "SELECT request_timestamp, decision_timestamp, payment_channel, proof_of_screenshot
         FROM user_currency_requests
         WHERE user_id = ?
           AND LOWER(transaction_type) = ?
           AND ABS(amount - ?) < 0.0001
           AND COALESCE(decision_timestamp, request_timestamp) <= ?
         ORDER BY COALESCE(decision_timestamp, request_timestamp) DESC
         LIMIT 1"
    )) {
        foreach ($transaction_history as &$tx) {
            $type_low = strtolower($tx['type'] ?? '');
            $approved = strtolower($tx['approval_status'] ?? 'approved') === 'approved';
            $need_req = empty($tx['ucr_request_timestamp']);
            $need_dec = empty($tx['ucr_decision_timestamp']);
            if ($approved && $need_req && $need_dec && in_array($type_low, ['withdrawal','deposit'])) {
                $uid = (int)($tx['user_id'] ?? 0);
                $amt = (float)($tx['amount'] ?? 0);
                $ts  = $tx['timestamp'] ?? null; // transactions.timestamp
                if ($uid > 0 && $amt > 0 && !empty($ts)) {
                    $stmt_enrich->bind_param('isds', $uid, $type_low, $amt, $ts);
                    if ($stmt_enrich->execute()) {
                        $rs = $stmt_enrich->get_result();
                        if ($rs && ($m = $rs->fetch_assoc())) {
                            $tx['ucr_request_timestamp'] = $m['request_timestamp'] ?? $tx['ucr_request_timestamp'] ?? null;
                            $tx['ucr_decision_timestamp'] = $m['decision_timestamp'] ?? $tx['ucr_decision_timestamp'] ?? null;
                            if (empty($tx['ucr_payment_channel']) && !empty($m['payment_channel'])) {
                                $tx['ucr_payment_channel'] = $m['payment_channel'];
                            }
                            if (empty($tx['proof_of_screenshot']) && !empty($m['proof_of_screenshot'])) {
                                $tx['proof_of_screenshot'] = $m['proof_of_screenshot'];
                            }
                        }
                    }
                }
            }
        }
        $stmt_enrich->close();
        unset($tx);
    }
}

// Fetch admin deposit history
$admin_deposit_history = [];
$stmt_admin_deposit_history = $conn->prepare("
    SELECT at.*, c.symbol
    FROM admin_transactions at
    JOIN currencies c ON at.currency_id = c.currency_id
    WHERE at.admin_id = ?
    ORDER BY at.timestamp DESC
");
$stmt_admin_deposit_history->bind_param("i", $admin_id);
$stmt_admin_deposit_history->execute();
$result_admin_deposit_history = $stmt_admin_deposit_history->get_result();
while ($row = $result_admin_deposit_history->fetch_assoc()) {
    $admin_deposit_history[] = $row;
}
$stmt_admin_deposit_history->close();

// Fetch bank deposit history (admin deposits from bank to wallet)
$bank_deposit_history = [];
$bank_date_filter = isset($_GET['bank_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bank_date']) ? $_GET['bank_date'] : null;
// Ensure table exists before selecting (safe check)
$tbl_exists_rs = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '" . $conn->real_escape_string($dbname) . "' AND table_name = 'bank_deposit_history'");
if ($tbl_exists_rs && $tbl_exists_rs->num_rows > 0) {
    if ($bank_date_filter) {
        $stmt_bhist = $conn->prepare("SELECT h.*, c.symbol FROM bank_deposit_history h JOIN currencies c ON h.currency_id = c.currency_id WHERE DATE(h.created_at) = ? ORDER BY h.created_at DESC LIMIT 200");
        $stmt_bhist->bind_param("s", $bank_date_filter);
    } else {
        $stmt_bhist = $conn->prepare("SELECT h.*, c.symbol FROM bank_deposit_history h JOIN currencies c ON h.currency_id = c.currency_id ORDER BY h.created_at DESC LIMIT 200");
    }
    if ($stmt_bhist) {
        $stmt_bhist->execute();
        $res_bhist = $stmt_bhist->get_result();
        while ($row = $res_bhist->fetch_assoc()) { $bank_deposit_history[] = $row; }
        $stmt_bhist->close();
    }
}

// Fetch conversion fees for profit tracking
$conversion_fees = [];
$daily_fees = [];
// Ensure unified fees table exists (for safety if dashboard hasn't created it yet)
$conn->query("CREATE TABLE IF NOT EXISTS fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation_type VARCHAR(20) NOT NULL DEFAULT 'conversion',
    request_id INT NULL,
    user_id INT NOT NULL,
    from_currency_id INT NULL,
    to_currency_id INT NULL,
    currency_id INT NULL,
    amount_converted DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL,
    tax_rate DECIMAL(5,4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (operation_type), INDEX (user_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Normalize legacy columns if table was renamed from conversion_fees
try {
    $dbName = $dbname;
    $checkCols = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fees' AND COLUMN_NAME IN ('id','fee_id','timestamp','created_at')");
    if ($checkCols) {
        $checkCols->bind_param('s', $dbName);
        $checkCols->execute();
        $resCols = $checkCols->get_result();
        $have = [];
        while ($r = $resCols->fetch_assoc()) { $have[$r['COLUMN_NAME']] = true; }
        $checkCols->close();
        if (empty($have['id']) && !empty($have['fee_id'])) {
            // Rename legacy fee_id to id
            $conn->query("ALTER TABLE fees CHANGE fee_id id INT NOT NULL AUTO_INCREMENT");
        }
        if (empty($have['created_at']) && !empty($have['timestamp'])) {
            // Rename legacy timestamp to created_at
            $conn->query("ALTER TABLE fees CHANGE `timestamp` created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }
} catch (Throwable $e) { /* ignore */ }
$stmt_fees = $conn->prepare("
    SELECT 
        cf.id AS fee_id,
        cf.user_id,
        cf.amount_converted,
        cf.tax_amount,
        cf.tax_rate,
        cf.created_at AS timestamp,
        u.username,
        c1.symbol AS from_symbol,
        c2.symbol AS to_symbol
    FROM fees cf
    JOIN users u ON cf.user_id = u.user_id
    JOIN currencies c1 ON cf.from_currency_id = c1.currency_id
    JOIN currencies c2 ON cf.to_currency_id = c2.currency_id
    WHERE cf.operation_type = 'conversion'
    ORDER BY cf.created_at DESC
    LIMIT 100
");
$stmt_fees->execute();
$result_fees = $stmt_fees->get_result();
while ($row = $result_fees->fetch_assoc()) {
    $conversion_fees[] = $row;
}
$stmt_fees->close();

// Fetch latest withdrawal fees (normalize fields to merge with conversion)
$withdrawal_fees = [];
if ($stmt_wf = $conn->prepare("
    SELECT 
        cf.id,
        u.username,
        'withdrawal' AS operation_type,
        NULL AS from_symbol,
        NULL AS to_symbol,
        c.symbol AS currency_symbol,
        cf.amount_converted,
        cf.tax_amount,
        cf.created_at AS ts
    FROM fees cf
    JOIN users u ON cf.user_id = u.user_id
    JOIN currencies c ON cf.currency_id = c.currency_id
    WHERE cf.operation_type = 'withdrawal'
    ORDER BY cf.created_at DESC
    LIMIT 100
")) {
    $stmt_wf->execute();
    $res_wf = $stmt_wf->get_result();
    while ($row = $res_wf->fetch_assoc()) { $withdrawal_fees[] = $row; }
    $stmt_wf->close();
}

// Build unified fees history (conversion + withdrawal)
$fees_history = [];
// Normalize conversion rows
foreach ($conversion_fees as $r) {
    $fees_history[] = [
        'operation_type'   => 'conversion',
        'username'         => $r['username'] ?? '',
        'from_symbol'      => $r['from_symbol'] ?? '',
        'to_symbol'        => $r['to_symbol'] ?? '',
        'currency_symbol'  => $r['from_symbol'] ?? '',
        'amount_converted' => (float)($r['amount_converted'] ?? 0),
        'tax_amount'       => (float)($r['tax_amount'] ?? 0),
        'ts'               => $r['timestamp'] ?? ($r['created_at'] ?? '')
    ];
}
// Normalize withdrawal rows
foreach ($withdrawal_fees as $r) {
    $fees_history[] = [
        'operation_type'   => 'withdrawal',
        'username'         => $r['username'] ?? '',
        'from_symbol'      => '',
        'to_symbol'        => '',
        'currency_symbol'  => $r['currency_symbol'] ?? '',
        'amount_converted' => (float)($r['amount_converted'] ?? 0),
        'tax_amount'       => (float)($r['tax_amount'] ?? 0),
        'ts'               => $r['ts'] ?? ''
    ];
}
// Sort by timestamp desc and limit 100
usort($fees_history, function($a,$b){ return strtotime($b['ts'] ?? '1970-01-01') <=> strtotime($a['ts'] ?? '1970-01-01'); });
$fees_history = array_slice($fees_history, 0, 100);

// Calculate today's conversion fees grouped by currency (only today, not cumulative)
$stmt_daily = $conn->prepare("
    SELECT 
        c.symbol,
        DATE(cf.created_at) as fee_date,
        SUM(cf.tax_amount) as total_fees,
        COUNT(*) as conversion_count
    FROM fees cf
    JOIN currencies c ON cf.from_currency_id = c.currency_id
    WHERE cf.operation_type = 'conversion' AND DATE(cf.created_at) = CURDATE()
    GROUP BY c.symbol, DATE(cf.created_at)
");
$stmt_daily->execute();
$result_daily = $stmt_daily->get_result();
while ($row = $result_daily->fetch_assoc()) {
    $daily_fees[] = $row;
}
$stmt_daily->close();

// Calculate today's withdrawal fees grouped by currency (only today)
$daily_withdraw_fees = [];
$stmt_daily_w = $conn->prepare("
    SELECT 
        c.symbol,
        DATE(cf.created_at) as fee_date,
        SUM(cf.tax_amount) as total_fees,
        COUNT(*) as withdrawal_count
    FROM fees cf
    JOIN currencies c ON cf.currency_id = c.currency_id
    WHERE cf.operation_type = 'withdrawal' AND DATE(cf.created_at) = CURDATE()
    GROUP BY c.symbol, DATE(cf.created_at)
");
if ($stmt_daily_w) {
    $stmt_daily_w->execute();
    $result_daily_w = $stmt_daily_w->get_result();
    while ($row = $result_daily_w->fetch_assoc()) { $daily_withdraw_fees[] = $row; }
    $stmt_daily_w->close();
}

// Weekly admin profit chart data (per day per currency, split by conversion/withdrawal)
$weekly_profit = [ 'labels' => [], 'datasets' => [], 'start' => '', 'end' => '' ];
$weekly_by_sym = [ 'labels' => [], 'symbols' => [], 'conv' => [], 'with' => [], 'start' => '', 'end' => '' ];
try {
    // Read requested range; default to last 7 days (inclusive)
    $ws = isset($_GET['weekly_start']) ? trim($_GET['weekly_start']) : date('Y-m-d', strtotime('-6 day'));
    $we = isset($_GET['weekly_end']) ? trim($_GET['weekly_end']) : date('Y-m-d');
    $isDate = function($s){ return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); };
    if (!$isDate($ws)) { $ws = date('Y-m-d', strtotime('-6 day')); }
    if (!$isDate($we)) { $we = date('Y-m-d'); }
    if ($ws > $we) { $tmp = $ws; $ws = $we; $we = $tmp; }
    // Clamp to 7 days max (make end = start + 6 days if exceeded)
    $maxEnd = date('Y-m-d', strtotime($ws . ' +6 day'));
    if ($we > $maxEnd) { $we = $maxEnd; }

    // Build date labels ws..we
    $labels = [];
    $curTs = strtotime($ws);
    $endTs = strtotime($we);
    while ($curTs <= $endTs) { $labels[] = date('Y-m-d', $curTs); $curTs = strtotime('+1 day', $curTs); }
    $weekly_by_sym['labels'] = $labels;
    $weekly_by_sym['start'] = $ws;
    $weekly_by_sym['end'] = $we;

    // Initialize maps
    $conv_day = []; // conv_day[date][symbol] = total
    $with_day = []; // with_day[date][symbol] = total
    foreach ($labels as $d) { $conv_day[$d] = []; $with_day[$d] = []; }

    // Conversion totals per day and currency
    if ($stc = $conn->prepare(
        "SELECT DATE(cf.created_at) AS d, c.symbol, SUM(cf.tax_amount) AS total
         FROM fees cf
         JOIN currencies c ON cf.from_currency_id = c.currency_id
         WHERE cf.operation_type = 'conversion' AND DATE(cf.created_at) BETWEEN ? AND ?
         GROUP BY DATE(cf.created_at), c.symbol"
    )) {
        $stc->bind_param('ss', $ws, $we);
        $stc->execute();
        $rc = $stc->get_result();
        while ($r = $rc->fetch_assoc()) { $conv_day[$r['d']][strtoupper($r['symbol'])] = (float)$r['total']; }
        $stc->close();
    }
    // Withdrawal totals per day and currency
    if ($stw = $conn->prepare(
        "SELECT DATE(cf.created_at) AS d, c.symbol, SUM(cf.tax_amount) AS total
         FROM fees cf
         JOIN currencies c ON cf.currency_id = c.currency_id
         WHERE cf.operation_type = 'withdrawal' AND DATE(cf.created_at) BETWEEN ? AND ?
         GROUP BY DATE(cf.created_at), c.symbol"
    )) {
        $stw->bind_param('ss', $ws, $we);
        $stw->execute();
        $rw = $stw->get_result();
        while ($r = $rw->fetch_assoc()) { $with_day[$r['d']][strtoupper($r['symbol'])] = (float)$r['total']; }
        $stw->close();
    }

    // Collect all symbols encountered
    $syms = [];
    foreach ($labels as $d) { $syms = array_merge($syms, array_keys($conv_day[$d]), array_keys($with_day[$d])); }
    $syms = array_values(array_unique(array_filter($syms)));
    // Order preferred USD, MMK, THB, JPY
    $preferred = ['USD','MMK','THB','JPY'];
    $rest = array_values(array_diff($syms, $preferred)); sort($rest);
    $syms = array_values(array_merge(array_intersect($preferred, $syms), $rest));
    $weekly_by_sym['symbols'] = $syms;

    // Build aligned arrays per symbol for both conv and with
    $conv_out = []; $with_out = [];
    foreach ($syms as $s) {
        $rowC = []; $rowW = [];
        foreach ($labels as $d) {
            $rowC[] = isset($conv_day[$d][$s]) ? (float)$conv_day[$d][$s] : 0.0;
            $rowW[] = isset($with_day[$d][$s]) ? (float)$with_day[$d][$s] : 0.0;
        }
        $conv_out[$s] = $rowC;
        $with_out[$s] = $rowW;
    }
    $weekly_by_sym['conv'] = $conv_out;
    $weekly_by_sym['with'] = $with_out;
} catch (Throwable $e) { /* ignore weekly aggregation failures */ }

// Registration chart data and YEAR filter (dynamic options)
$registration_chart = ['labels' => [], 'data' => [], 'year' => (int)date('Y')];
$allowed_years = [];
// Determine registration timestamp column in users table
$possible_cols = ['created_at','registration_date','registered_at','created_on','signup_at','signup_date','created','timestamp','reg_time','reg_date'];
$placeholders = implode(',', array_fill(0, count($possible_cols), '?'));
$types = str_repeat('s', count($possible_cols) + 1);
$query = "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name IN ($placeholders) ORDER BY FIELD(column_name, '" . implode("','", $possible_cols) . "') LIMIT 1";
$stmt_col = $conn->prepare($query);
$params = array_merge([$dbname], $possible_cols);
$stmt_col->bind_param($types, ...$params);
$stmt_col->execute();
$res_col = $stmt_col->get_result();
$reg_col = null;
if ($row = $res_col->fetch_assoc()) { $reg_col = $row['column_name']; }
$stmt_col->close();

if ($reg_col) {
    // Determine available year range from user registrations
    $yearRes = $conn->query("SELECT MIN(YEAR($reg_col)) AS min_y, MAX(YEAR($reg_col)) AS max_y FROM users WHERE $reg_col IS NOT NULL");
    $yrRow = $yearRes ? $yearRes->fetch_assoc() : null;
    $minY = isset($yrRow['min_y']) && $yrRow['min_y'] ? (int)$yrRow['min_y'] : (int)date('Y');
    $maxY = isset($yrRow['max_y']) && $yrRow['max_y'] ? (int)$yrRow['max_y'] : (int)date('Y');
    // Always include the current year even if there are no registrations yet
    $maxY = max($maxY, (int)date('Y'));
    for ($y = $maxY; $y >= $minY; $y--) { $allowed_years[] = $y; }

    // Use selected year if provided; otherwise default to current year, clamped to range
    $selected_year = isset($_GET['reg_year']) ? (int)$_GET['reg_year'] : (int)date('Y');
    if (!in_array($selected_year, $allowed_years, true)) {
        // Clamp to nearest valid year within range
        $selected_year = min(max($selected_year, $minY), $maxY);
    }
    $registration_chart['year'] = $selected_year;

    // Build monthly counts for selected YEAR (Jan–Dec), fill missing months with 0
    $yr = (int)$selected_year;
    $sql = "SELECT MONTH($reg_col) AS m, COUNT(*) AS c FROM users WHERE YEAR($reg_col) = $yr GROUP BY MONTH($reg_col) ORDER BY m";
    $res = $conn->query($sql);
    $countsByMonth = array_fill(1, 12, 0);
    while ($r = $res->fetch_assoc()) {
        $m = (int)$r['m'];
        $countsByMonth[$m] = (int)$r['c'];
    }
    $labels = [];
    $data = [];
    for ($m = 1; $m <= 12; $m++) {
        $labels[] = date('M', mktime(0,0,0,$m,1,$yr));
        $data[] = $countsByMonth[$m];
    }
    $registration_chart = ['labels' => $labels, 'data' => $data, 'year' => $selected_year];
    // Optional single-date check
    $reg_date = isset($_GET['reg_date']) ? trim($_GET['reg_date']) : '';
    $reg_date_count = null;
    if ($reg_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_date)) {
        $safe_date = $conn->real_escape_string($reg_date);
        $sqlc = "SELECT COUNT(*) AS c FROM users WHERE DATE($reg_col) = '$safe_date'";
        $resc = $conn->query($sqlc);
        if ($resc) { $rowc = $resc->fetch_assoc(); $reg_date_count = isset($rowc['c']) ? (int)$rowc['c'] : 0; }
    }
}

// Count registered users in 2025
$reg_count_2025 = null;
if ($reg_col) {
    $res_cnt = $conn->query("SELECT COUNT(*) AS c FROM users WHERE YEAR($reg_col) = 2025");
    if ($res_cnt) {
        $row_cnt = $res_cnt->fetch_assoc();
        $reg_count_2025 = isset($row_cnt['c']) ? (int)$row_cnt['c'] : 0;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Admin Dashboard & Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #ef4444;
            color: white;
            font-size: 0.75rem;
            font-weight: bold;
        }

        /* Admin theme palette */
        .admin-theme {
            --admin-bg: #182233;         /* medium-dark background */
            --admin-surface: #202B3D;    /* elevated surface */
            --admin-primary: #14B8A6;    /* teal */
            --admin-primary-2: #2DD4BF;  /* teal accent */
            --admin-text: #E8EEF6;       /* light text */
            --admin-text-muted: #BAC6D8; /* muted text */
            --admin-border: rgba(255, 255, 255, 0.12);
            --admin-shadow: rgba(0, 0, 0, 0.5);
        }

        /* Dark theme overrides using admin palette */
        .dark-theme { background-color: var(--admin-bg); color: var(--admin-text); }
        .dark-theme .bg-white { background-color: var(--admin-surface) !important; }
        .dark-theme .bg-gray-50, .dark-theme .bg-indigo-50 { background-color: var(--admin-bg) !important; }
        .dark-theme .bg-gray-100 { background-color: var(--admin-bg) !important; }
        .dark-theme .text-gray-900, .dark-theme .text-gray-800 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-700 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-600, .dark-theme .text-gray-500 { color: var(--admin-text-muted) !important; }
        .dark-theme .border-gray-100, .dark-theme .border-gray-200 { border-color: var(--admin-border) !important; }
        .dark-theme .shadow-inner { box-shadow: inset 0 2px 4px 0 var(--admin-shadow) !important; }
        .dark-theme .shadow-lg, .dark-theme .shadow-md, .dark-theme .shadow-xl { box-shadow: 0 10px 15px -3px var(--admin-shadow), 0 4px 6px -4px var(--admin-shadow) !important; }
        .dark-theme nav a { color: var(--admin-text-muted) !important; }
        .dark-theme nav a:hover { color: var(--admin-primary) !important; }
        .dark-theme nav:not(.glass-nav), .dark-theme .bg-white.p-6.rounded-xl.shadow-lg, .dark-theme .bg-white.p-6.shadow-lg.rounded-xl { background-color: var(--admin-surface) !important; }
        .dark-theme .rounded-xl, .dark-theme .rounded-lg { border: 1px solid var(--admin-border); }
        .dark-theme .no-border { border: 0 !important; }
        .dark-theme .text-indigo-800 { color: var(--admin-text) !important; }
        .dark-theme .border-indigo-200 { border-color: var(--admin-border) !important; }

        /* Map Tailwind utility colors used in markup to the admin palette within the admin scope */
        .dark-theme .text-blue-600, .dark-theme .text-indigo-600, .dark-theme .text-indigo-700 { color: var(--admin-primary) !important; }
        .dark-theme .hover\:text-blue-600:hover { color: var(--admin-primary) !important; }
        .dark-theme .bg-blue-600, .dark-theme .bg-indigo-600 { background-color: var(--admin-primary) !important; }
        .dark-theme .hover\:bg-indigo-700:hover, .dark-theme .hover\:bg-blue-700:hover { background-color: var(--admin-primary-2) !important; }
        .dark-theme .bg-indigo-100 { background-color: rgba(20, 184, 166, 0.12) !important; }
        .dark-theme .text-green-700 { color: var(--admin-primary) !important; }
        .dark-theme .text-indigo-700 { color: var(--admin-primary) !important; }
        .dark-theme .border-gray-100 { border-color: var(--admin-border) !important; }
        /* Hover lift utility */
        .hover-lift { transition: transform 150ms ease, box-shadow 150ms ease; }
        .hover-lift:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 20px 25px -5px var(--admin-shadow), 0 10px 10px -5px var(--admin-shadow); }
        /* Dark inputs/selects */
        .dark-theme select, .dark-theme input, .dark-theme textarea { background-color: var(--admin-surface); border-color: var(--admin-border); color: var(--admin-text); }
        .glass-nav { background: rgba(24, 34, 51, 0.7) !important; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid var(--admin-border); }
    </style>
</head>
<body class="font-sans leading-normal tracking-normal admin-theme dark-theme">
    <?php if (!empty($message) && (preg_match('/^(Error|Insufficient)/', $message))): ?>
    <div id="popup-overlay" class="fixed inset-0 z-50 flex items-center justify-center">
        <div id="popup-backdrop" class="absolute inset-0 bg-black bg-opacity-50"></div>
        <div class="relative bg-white text-red-700 border border-red-400 rounded-lg shadow-2xl max-w-lg w-[90%] p-6 z-10">
            <div class="flex items-start gap-3">
                <i class="fas fa-exclamation-triangle text-red-600 mt-1.5"></i>
                <div class="flex-1"><?php echo htmlspecialchars($message); ?></div>
                <button id="popup-close" class="text-red-700 hover:text-red-900 text-2xl leading-none" aria-label="Close">&times;</button>
            </div>
        </div>
    </div>
    <script>
      (function(){
        const ov = document.getElementById('popup-overlay');
        const bd = document.getElementById('popup-backdrop');
        const cl = document.getElementById('popup-close');
        const close = ()=> { if (ov) ov.remove(); };
        if (bd) bd.addEventListener('click', close);
        if (cl) cl.addEventListener('click', close);
        document.addEventListener('keydown', (e)=> { if (e.key === 'Escape') close(); });
      })();
    </script>
    <?php endif; ?>
    <nav class="glass-nav w-full p-3 md:p-4 shadow-md">
        <div class="w-full max-w-7xl mx-auto flex items-center justify-between">
            <div class="text-lg md:text-xl font-bold text-gray-800">Admin Panel</div>
            <div class="flex items-center space-x-6">
                <a href="admin.php" class="<?php echo (!$show_requests && !$show_history && !$show_deposit && !$show_exchange_rate && !$show_fees) ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Dashboard">
                    <i class="fas fa-home text-2xl"></i>
                    <span class="sr-only">Dashboard</span>
                </a>
                <a href="admin_user_management.php" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="User Management">
                    <i class="fas fa-users-cog text-2xl"></i>
                    <span class="sr-only">User Management</span>
                </a>
                <a href="admin.php?view=requests" class="relative <?php echo $show_requests ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Notifications">
                    <i class="fas fa-bell text-2xl"></i>
                    <?php if (isset($badge_count) && $badge_count > 0): ?>
                        <span class="notification-badge"><?php echo $badge_count; ?></span>
                    <?php endif; ?>
                    <span class="sr-only">Notifications</span>
                </a>
                <a href="admin.php?view=history" class="<?php echo $show_history ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Transaction History">
                    <i class="fas fa-history text-2xl"></i>
                    <span class="sr-only">Transaction History</span>
                </a>
                <a href="admin.php?view=fees" class="<?php echo $show_fees ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Conversion Fees">
                    <i class="fas fa-coins text-2xl"></i>
                    <span class="sr-only">Conversion Fees</span>
                </a>
                <a href="admin.php?view=exchange_rate" class="<?php echo $show_exchange_rate ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Exchange Rate">
                    <i class="fas fa-exchange-alt text-2xl"></i>
                    <span class="sr-only">Exchange Rate</span>
                </a>
                <div class="relative" id="settingsMenu">
                    <button id="settingsBtn" type="button" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Settings" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-cog text-2xl"></i>
                        <span class="sr-only">Settings</span>
                    </button>
                    <div id="settingsDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50 overflow-hidden">
                        <a href="admin_forgot_password_request.php" class="flex items-center gap-3 px-4 py-3 text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-key text-gray-600"></i>
                            <span>Reset Password</span>
                        </a>
                        <div class="h-px bg-gray-100"></div>
                        <a href="admin_logout.php" class="flex items-center gap-3 px-4 py-3 text-red-600 hover:bg-gray-50">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <script>
    (function(){
        const btn = document.getElementById('settingsBtn');
        const menu = document.getElementById('settingsDropdown');
        const wrapper = document.getElementById('settingsMenu');
        if (!btn || !menu || !wrapper) return;
        function close(){ menu.classList.add('hidden'); btn.setAttribute('aria-expanded','false'); }
        function open(){ menu.classList.remove('hidden'); btn.setAttribute('aria-expanded','true'); }
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            if (menu.classList.contains('hidden')) open(); else close();
        });
        document.addEventListener('click', function(e){
            if (!wrapper.contains(e.target)) close();
        });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    })();
    </script>

    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg no-border">
      
            <?php if (!$show_requests && !$show_all_requests && !$show_history && !$show_deposit && !$show_exchange_rate && !$show_fees): ?>
            <?php
                // Initialize balances for all wallet types
                $mmk_balance = 0; 
                $usd_balance = 0; 
                $thb_balance = 0;
                $jpy_balance = 0;
                $mmk_currency_id = null;
                
                // Set wallet balances
                foreach ($admin_wallets as $w) {
                    if ($w['symbol'] === 'MMK') { $mmk_balance = $w['balance']; }
                    if ($w['symbol'] === 'USD') { $usd_balance = $w['balance']; }
                    if ($w['symbol'] === 'THB') { $thb_balance = $w['balance']; }
                    if ($w['symbol'] === 'JPY') { $jpy_balance = $w['balance']; }
                    if ($w['symbol'] === 'MMK') { $mmk_balance = $w['balance']; }
                    if ($w['symbol'] === 'USD') { $usd_balance = $w['balance']; }
                }
                foreach ($currencies as $cur) { if ($cur['symbol'] === 'MMK') { $mmk_currency_id = $cur['currency_id']; break; } }
            ?>

            <div class="max-w-7xl mx-auto">
                <h1 class="text-3xl font-extrabold text-gray-800 mb-6">System Dashboard Overview</h1>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-white p-6 shadow-xl rounded-xl border border-gray-100 h-full flex flex-col items-center">
                            <div class="flex-shrink-0 mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-28 w-28 text-indigo-600 bg-indigo-100 rounded-full p-5 border-4 border-white shadow-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-800">Admin</h2>
                            <p class="text-sm text-gray-500 mb-8">System Administrator</p>

                            <div class="w-full grid grid-cols-2 gap-4 mb-8 p-4 bg-gray-50 rounded-lg shadow-inner border border-gray-200">
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">MMK Wallet</p>
                                    <p class="text-base font-extrabold text-green-600 mt-1"><?php echo number_format($mmk_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">USD Wallet</p>
                                    <p class="text-base font-extrabold text-blue-600 mt-1"><?php echo number_format($usd_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">THB Wallet</p>
                                    <p class="text-base font-extrabold text-purple-600 mt-1"><?php echo number_format($thb_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">JPY Wallet</p>
                                    <p class="text-base font-extrabold text-amber-600 mt-1"><?php echo number_format($jpy_balance, 2); ?></p>
                                </div>
                            </div>

                            <div class="w-full mt-auto p-4 bg-white rounded-xl border border-gray-100">
                                <h3 class="text-lg font-semibold mb-3">Deposit from Bank</h3>
                                <button type="button" onclick="openDepositModal()" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg transition duration-150 shadow-md">
                                    Deposit
                                </button>
                            </div>
                        </div>

                    </div>

                    <div class="lg:col-span-2 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 hover:shadow-xl transition duration-300">
                                <h3 class="text-xl font-bold text-gray-800 mb-4">Users Logged In Today</h3>
                                <div class="flex items-center space-x-3">
                                    <span class="text-5xl font-extrabold text-green-700"><?php echo $active_users; ?></span>
                                </div>
                            </div>
                            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 hover:shadow-xl transition duration-300">
                                <h3 class="text-xl font-bold text-gray-800 mb-4">Total Users</h3>
                                <div class="flex items-center">
                                    <span class="text-5xl font-extrabold text-indigo-700"><?php echo $total_users; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 min-h-80">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-xl font-bold text-gray-800">User Registration Chart</h3>
                                <div class="flex items-center gap-3">
                                    <?php /* Year combo removed per request */ ?>
                                    <?php $rd = isset($reg_date) ? $reg_date : ''; ?>
                                    <?php $yy = isset($registration_chart['year']) ? (int)$registration_chart['year'] : (int)date('Y'); ?>
                                    <?php $minY = sprintf('%04d-01-01', $yy); $maxY = sprintf('%04d-12-31', $yy); ?>
                                    <div class="flex items-center gap-1 text-sm">
                                        <label for="regDateCheck" class="text-gray-600">Check date</label>
                                        <input id="regDateCheck" type="date" value="<?php echo htmlspecialchars($rd); ?>" min="<?php echo htmlspecialchars($minY); ?>" max="<?php echo htmlspecialchars($maxY); ?>" class="p-2 border border-gray-300 rounded-lg text-sm" />
                                        <?php if ($rd && $reg_date_count !== null): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                                                <?php echo htmlspecialchars($rd); ?>: <?php echo number_format((int)$reg_date_count); ?> user(s)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="w-full">
                                <canvas id="regChart" height="224"></canvas>
                            </div>
                            <script>
                                (function(){
                                    const labels = <?php echo json_encode($registration_chart['labels']); ?> || [];
                                    const data = <?php echo json_encode($registration_chart['data']); ?> || [];
                                    const canvas = document.getElementById('regChart');
                                    const urlParams = new URLSearchParams(window.location.search);
                                    const currentYear = urlParams.get('reg_year') || String(<?php echo json_encode((string)$registration_chart['year']); ?>);
                                    if (!canvas) return;
                                    const container = canvas.parentElement;
                                    let empty = document.getElementById('regChartEmpty');
                                    if (!labels.length) {
                                        canvas.style.display = 'none';
                                        if (!empty) {
                                            empty = document.createElement('div');
                                            empty.id = 'regChartEmpty';
                                            empty.className = 'text-center text-gray-500 py-8';
                                            empty.textContent = 'No registrations found for ' + currentYear;
                                            container.appendChild(empty);
                                        } else {
                                            empty.textContent = 'No registrations found for ' + currentYear;
                                            empty.style.display = '';
                                        }
                                        return;
                                    } else {
                                        if (empty) empty.style.display = 'none';
                                        canvas.style.display = '';
                                    }
                                    const ctx = canvas.getContext('2d');
                                    const chart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: labels,
                                            datasets: [{
                                                label: 'Registrations',
                                                data: data,
                                                borderColor: '#6366f1',
                                                backgroundColor: 'rgba(99,102,241,0.2)',
                                                tension: 0.35,
                                                fill: true,
                                                pointRadius: 3,
                                                pointHoverRadius: 5,
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            scales: {
                                                x: { title: { display: true, text: 'Month' } },
                                                y: { beginAtZero: true, title: { display: true, text: 'Number of users registered' }, ticks: { stepSize: 5 } }
                                            },
                                            plugins: {
                                                legend: { display: false },
                                                tooltip: { callbacks: { label: (ctx) => ` ${ctx.parsed.y} registrations` } }
                                            }
                                        }
                                    });

                                    // Year combo removed per request
                                    const regDateInput = document.getElementById('regDateCheck');
                                    function isDateStr(s){ return /^\d{4}-\d{2}-\d{2}$/.test(s); }
                                    if (regDateInput) {
                                        regDateInput.addEventListener('change', function(){
                                            const url = new URL(window.location.href);
                                            const v = this.value;
                                            if (isDateStr(v)) { url.searchParams.set('reg_date', v); }
                                            else { url.searchParams.delete('reg_date'); }
                                            url.searchParams.set('_', Date.now().toString());
                                            window.location.assign(url.toString());
                                        });
                                    }
                                })();
                            </script>
                        </div>
                    </div>
                </div>

                <!-- Monthly Admin Profit (MMK) (Bar) under Registration Chart -->
                <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 mt-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-xl font-bold text-gray-800">Monthly Admin Profit (MMK)</h3>
                        <div class="flex items-center gap-2 text-sm">
                            <label for="profitYear" class="text-gray-600">Year</label>
                            <?php if (empty($profit_years)) { $profit_years = [ (int)date('Y'), (int)date('Y')-1, (int)date('Y')-2, (int)date('Y')-3, (int)date('Y')-4 ]; } ?>
                            <select id="profitYear" class="p-2 border border-gray-300 rounded-lg text-sm">
                                <?php $py = isset($monthly_profit['year']) ? (int)$monthly_profit['year'] : (int)date('Y'); ?>
                                <?php foreach ($profit_years as $y): ?>
                                    <option value="<?php echo (int)$y; ?>" <?php echo ($py===(int)$y)?'selected':''; ?>><?php echo (int)$y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="w-full">
                        <canvas id="monthlyProfitChart" height="224"></canvas>
                    </div>
                    <script>
                        (function(){
                            const labels = <?php echo json_encode($monthly_profit['labels'] ?? []); ?> || [];
                            const conv = <?php echo json_encode($monthly_profit['conv'] ?? []); ?> || [];
                            const withf = <?php echo json_encode($monthly_profit['with'] ?? []); ?> || [];
                            const canvas = document.getElementById('monthlyProfitChart');
                            if (!canvas) return;
                            const container = canvas.parentElement;
                            let empty = document.getElementById('monthlyProfitEmpty');
                            const hasData = labels.length && (conv.length || withf.length);
                            if (!hasData) {
                                canvas.style.display = 'none';
                                if (!empty) {
                                    empty = document.createElement('div');
                                    empty.id = 'monthlyProfitEmpty';
                                    empty.className = 'text-center text-gray-500 py-8';
                                    empty.textContent = 'No monthly profit data available.';
                                    container.appendChild(empty);
                                } else {
                                    empty.textContent = 'No monthly profit data available.';
                                    empty.style.display = '';
                                }
                                return;
                            } else {
                                if (empty) empty.style.display = 'none';
                                canvas.style.display = '';
                            }
                            const ctx = canvas.getContext('2d');
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: labels,
                                    datasets: [
                                        {
                                            label: 'Conversion Fees (MMK)',
                                            data: conv,
                                            backgroundColor: 'rgba(37, 99, 235, 0.5)',
                                            borderColor: '#2563eb',
                                            borderWidth: 1,
                                            stack: 'fees'
                                        },
                                        {
                                            label: 'Withdrawal Fees (MMK)',
                                            data: withf,
                                            backgroundColor: 'rgba(239, 68, 68, 0.5)',
                                            borderColor: '#ef4444',
                                            borderWidth: 1,
                                            stack: 'fees'
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        x: { title: { display: true, text: 'Month' }, stacked: true },
                                        y: { beginAtZero: true, stacked: true, title: { display: true, text: 'Fees (MMK)' } }
                                    },
                                    plugins: {
                                        legend: { display: true },
                                        tooltip: {
                                            callbacks: {
                                                label: (ctx) => ` ${ctx.dataset.label}: ${Number(ctx.parsed.y||0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} MMK`
                                            }
                                        }
                                    }
                                }
                            });
                            // Change year handler
                            const profitYear = document.getElementById('profitYear');
                            if (profitYear) {
                                profitYear.addEventListener('change', function(){
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('profit_year', this.value);
                                    url.searchParams.set('_', Date.now().toString());
                                    window.location.assign(url.toString());
                                });
                            }
                        })();
                    </script>
                </div>

                

                <!-- Bank Deposit History (positioned under the overview grid) -->
                <div class="bg-white p-6 shadow-lg rounded-xl no-border mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800">Bank Deposit History</h3>
                        <div class="flex items-center space-x-2">
                            <label class="text-sm text-gray-600" for="bank_date">Date</label>
                            <style>
                                /* Make calendar icon white for the date input */
                                #bank_date::-webkit-calendar-picker-indicator { filter: invert(1) brightness(1.6); opacity: 1; }
                                /* Optional: ensure consistent look in dark schemes */
                                #bank_date { color-scheme: light; }
                            </style>
                            <input id="bank_date" name="bank_date" type="date"
                                   min="<?php echo htmlspecialchars($admin_history_min_date ?? ''); ?>"
                                   max="<?php echo htmlspecialchars(date('Y-m-d')); ?>"
                                   value="<?php echo htmlspecialchars($_GET['bank_date'] ?? ''); ?>"
                                   class="p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <script>
                            (function(){
                                const input = document.getElementById('bank_date');
                                if (!input) return;
                                const minDate = input.getAttribute('min');
                                const maxDate = input.getAttribute('max');
                                function isInvalid(val){
                                    if (!val) return false;
                                    if (maxDate && val > maxDate) return true;
                                    if (minDate && val < minDate) return true;
                                    return false;
                                }
                                input.addEventListener('change', function(){
                                    if (isInvalid(this.value)){
                                        alert("You can't choose this date.");
                                        this.value = '';
                                        return;
                                    }
                                    const url = new URL(window.location.href);
                                    if (this.value) { url.searchParams.set('bank_date', this.value); }
                                    else { url.searchParams.delete('bank_date'); }
                                    url.searchParams.set('_', Date.now().toString());
                                    window.location.assign(url.toString());
                                });
                            })();
                        </script>
                    </div>
                    <div class="overflow-x-auto">
                        <style>
                            .no-border { border: 0 !important; }
                            .no-border .bank-history-scroll,
                            .no-border .table-bank-history,
                            .no-border .table-bank-history thead tr,
                            .no-border .table-bank-history tbody tr { border-bottom: 0 !important; }
                            .no-border .table-bank-history tbody tr:last-child { border-bottom: 0 !important; }
                            .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
                            .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px rgba(0,0,0,0.5); background-color: #0b1220; }
                            /* Subtle faded dividers like user management */
                            .table-bank-history { border-collapse: separate; border-spacing: 0; }
                            .table-bank-history thead tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                            .table-bank-history tbody tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                            .table-bank-history tbody tr:last-child { border-bottom-color: transparent !important; }
                            /* Sticky header */
                            .table-bank-history thead th { position: sticky; top: 0; z-index: 10; background-color: #0b1220; }
                            /* Scrollbar theming for the bank history scroll area */
                            .bank-history-scroll { scrollbar-color: #374151 #0b1220; scrollbar-width: thin; }
                            .bank-history-scroll::-webkit-scrollbar { width: 10px; }
                            .bank-history-scroll::-webkit-scrollbar-track { background: #0b1220; border-radius: 8px; }
                            .bank-history-scroll::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 8px; border: 2px solid #0b1220; }
                            .bank-history-scroll::-webkit-scrollbar-thumb:hover { background-color: #4b5563; }
                        </style>
                        <div class="overflow-y-auto bank-history-scroll" style="max-height: 480px;">
                        <style>

                        </style>
                        <table class="min-w-full table-bank-history">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Previous Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Current Value</th>
                                    
                                 
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($bank_deposit_history)): ?>
                                    <?php foreach ($bank_deposit_history as $h): ?>
                                        <tr class="hover-lift-row">
                                            <td class="px-6 py-3 text-sm text-gray-800 font-semibold">
                                                <?php echo number_format((float)$h['amount'], 2) . ' ' . htmlspecialchars($h['symbol']); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo htmlspecialchars(date('Y-m-d', strtotime($h['created_at']))); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-600">
                                                <?php echo htmlspecialchars(date('H:i:s', strtotime($h['created_at']))); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo number_format((float)$h['previous_balance'], 2); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo number_format((float)$h['after_balance'], 2); ?>
                                            </td>
                                      
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-6 text-center text-gray-500">No bank deposit history<?php echo !empty($_GET['bank_date']) ? ' for '.htmlspecialchars($_GET['bank_date']) : ''; ?>.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>

    
                <!-- User Management section removed from dashboard as requested -->

                <script>
                    function openDepositModal() {
                        const m = document.getElementById('depositModal');
                        if (m) m.classList.remove('hidden');
                    }
                    function closeDepositModal() {
                        const m = document.getElementById('depositModal');
                        if (m) m.classList.add('hidden');
                    }
                </script>
            </div>
            <?php endif; ?>

            <!-- Deposit Modal -->
            <div id="depositModal" class="hidden fixed inset-0 z-50">
                <div class="absolute inset-0 bg-black bg-opacity-50" onclick="closeDepositModal()"></div>
                <div class="relative mx-auto my-8 bg-white rounded-xl shadow-2xl w-11/12 max-w-2xl h-[70vh] overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 border-b">
                        <h3 class="text-lg font-semibold">Deposit</h3>
                        <button class="text-gray-500 hover:text-gray-800" onclick="closeDepositModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <iframe src="deposit.php" class="w-full h-[calc(70vh-52px)]"></iframe>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    const sel = document.getElementById('tx_type');
                    if (!sel) return;
                    sel.addEventListener('change', function(){
                        const url = new URL(window.location.href);
                        if (this.value) url.searchParams.set('tx_type', this.value); else url.searchParams.delete('tx_type');
                        url.searchParams.set('_', Date.now().toString());
                        window.location.assign(url.toString());
                    });
                });
            </script>

            <div class="flex items-center space-x-4 mb-6 <?php echo (!$show_wallet && !$show_all_requests && !$show_history && !$show_deposit && !$show_exchange_rate) ? 'hidden' : ''; ?>">
                <div class="bg-gray-700 rounded-full h-12 w-12 flex items-center justify-center text-white text-xl font-bold">
                    <?php if ($show_wallet): ?>
                        <i class="fas fa-wallet"></i>
                    <?php elseif ($show_all_requests): ?>
                        <i class="fas fa-list-alt"></i>
                    <?php elseif ($show_history): ?>
                        <i class="fas fa-history"></i>
                    <?php elseif ($show_deposit): ?>
                        <i class="fas fa-plus-circle"></i>
                    <?php elseif ($show_exchange_rate): ?>
                        <i class="fas fa-chart-line"></i>
                    <?php else: ?>
                        <i class="fas fa-user-shield"></i>
                    <?php endif; ?>
                </div>
                
                <div>
                    <?php if ($show_all_requests): ?>
                        <h2 class="text-2xl font-bold text-gray-800">Requests History</h2>
                        <p class="text-gray-500">A complete log of all user requests and their statuses.</p>
                    <?php elseif ($show_exchange_rate): ?>
                        <h2 class="text-2xl font-bold text-gray-800">Exchange Rate Management</h2>
                        <p class="text-gray-500">Sync live rates from API with custom markup for your country.</p>
                    <?php elseif ($show_history): ?>
                        <h2 class="text-2xl font-bold text-gray-800">User History</h2>
                        <p class="text-gray-500">Manage User's Transaction History.</p>
                    <?php else: ?>
                        <h2 class="text-2xl font-bold text-gray-800">Admin Dashboard</h2>
                        <p class="text-gray-500">Manage all users.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            
            <div id="user-list-section" class="hidden">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Registered Users</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($users) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full table-bank-history">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">User ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Email</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['user_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No registered users found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="requests-section" class="<?php echo $show_requests ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Pending User Requests (<?php echo $pending_count; ?>)</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($pending_requests) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($pending_requests as $request): ?>
                                <div class="bg-white p-4 rounded-lg shadow border border-gray-200">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-lg font-bold text-gray-800">
                                            <?php echo htmlspecialchars(ucfirst($request['transaction_type'])); ?> Request #<?php echo htmlspecialchars($request['request_id']); ?>
                                        </span>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm text-gray-500"><?php echo htmlspecialchars($request['request_timestamp']); ?></span>
                                            <?php 
                                                $reqStr = !empty($request['request_timestamp']) ? date('M j, Y H:i', strtotime($request['request_timestamp'])) : '—';
                                                $decStr = !empty($request['decision_timestamp']) ? date('M j, Y H:i', strtotime($request['decision_timestamp'])) : '—';
                                                $rawCh  = trim((string)($request['payment_channel'] ?? ''));
                                                $keyCh  = strtolower($rawCh);
                                                if ($rawCh !== '') {
                                                    if ($keyCh === 'kpay') { $rawCh = 'KPay'; }
                                                    elseif ($keyCh === 'ayapay') { $rawCh = 'AyaPay'; }
                                                    elseif ($keyCh === 'wavepay' || $keyCh === 'wave') { $rawCh = 'WavePay'; }
                                                } else { $rawCh = '—'; }
                                            ?>
                                            <button type="button" title="Details"
                                                class="open-details inline-flex items-center px-2 py-1 border border-gray-300 rounded-md shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                                data-requested="<?php echo htmlspecialchars($reqStr); ?>"
                                                data-decision="<?php echo htmlspecialchars($decStr); ?>"
                                                data-channel="<?php echo htmlspecialchars($rawCh); ?>"
                                                data-proof="<?php echo htmlspecialchars($request['proof_of_screenshot'] ?? ''); ?>">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p class="text-gray-700"><span class="font-medium">User:</span> <?php echo htmlspecialchars($request['username']); ?></p>
                                    <p class="text-gray-700"><span class="font-medium">Amount:</span> <?php echo number_format($request['amount'], 2) . ' ' . htmlspecialchars($request['symbol']); ?></p>
                                    <?php if ($request['transaction_type'] == 'deposit' && !empty($request['user_payment_id'])): ?>
                                        <p class="text-gray-700"><span class="font-medium">Transaction ID:</span> <?php echo htmlspecialchars($request['user_payment_id']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($request['transaction_type'] == 'deposit' && $request['proof_of_screenshot']): ?>
                                        <p class="mt-2">
                                            <a href="<?php echo htmlspecialchars($request['proof_of_screenshot']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">
                                                View Proof of Payment
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($request['description']): ?>
                                        <p class="text-gray-700 mt-2"><span class="font-medium">Details:</span> <?php echo htmlspecialchars($request['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-4 flex space-x-2">
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="flex-1">
                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <button type="submit" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-opacity-50 transition duration-200">
                                                <i class="fas fa-check-circle mr-2"></i>Confirm
                                            </button>
                                        </form>
                                      <!-- Replace the existing reject button/form with this -->
<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="flex-1">
    <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
    <input type="hidden" name="action" value="reject">
    <button type="button" onclick="showRejectReasonModal(<?php echo htmlspecialchars($request['request_id']); ?>)" 
            class="w-full bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-500 focus:ring-opacity-50 transition duration-200">
        <i class="fas fa-times-circle mr-2"></i>Reject
    </button>
</form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-clipboard-check text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No pending requests at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Wallet section removed as requested -->

            <div id="transaction-history-section" class="<?php echo $show_history ? '' : 'hidden'; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Transaction History</h3>
                    <div class="flex items-center space-x-2">
                        <label for="tx_type" class="text-sm text-gray-600">Type</label>
                        <select id="tx_type" class="p-2 border border-gray-300 rounded-lg text-sm">
                            <option value="" <?php echo empty($_GET['tx_type']) ? 'selected' : ''; ?>>All</option>
                            <option value="deposit" <?php echo (isset($_GET['tx_type']) && $_GET['tx_type']==='deposit') ? 'selected' : ''; ?>>Deposit</option>
                            <option value="withdrawal" <?php echo (isset($_GET['tx_type']) && $_GET['tx_type']==='withdrawal') ? 'selected' : ''; ?>>Withdrawal</option>
                        </select>
                    </div>
                </div>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php 
                        $filtered_history = $transaction_history;
                        if (!empty($tx_type)) {
                            $filtered_history = array_values(array_filter($transaction_history, function($row) use ($tx_type){
                                return isset($row['type']) && strtolower($row['type']) === $tx_type;
                            }));
                        }
                    ?>
                    <?php if (count($filtered_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <style>
                                /* Match Bank Deposit History styles */
                                .table-bank-history { border-collapse: separate; border-spacing: 0; }
                                .table-bank-history thead tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                                .table-bank-history tbody tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                                .table-bank-history tbody tr:last-child { border-bottom-color: rgba(255,255,255,0.04) !important; }
                                .table-bank-history thead th { position: sticky; top: 0; z-index: 10; background-color: #0b1220 !important; }
                                .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
                                .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px rgba(0,0,0,0.5); background-color: #0b1220; }
                                .bank-history-scroll { scrollbar-color: #374151 #0b1220; scrollbar-width: thin; }
                                .bank-history-scroll::-webkit-scrollbar { width: 10px; }
                                .bank-history-scroll::-webkit-scrollbar-track { background: #0b1220; border-radius: 8px; }
                                .bank-history-scroll::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 8px; border: 2px solid #0b1220; }
                                .bank-history-scroll::-webkit-scrollbar-thumb:hover { background-color: #4b5563; }
                            </style>
                            <div class="overflow-y-auto bank-history-scroll" style="max-height: 480px;">
                            <table class="min-w-full table-bank-history">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">User</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Decision</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($filtered_history as $transaction): ?>
                                        <tr class="hover-lift-row">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($transaction['username'] ?? ''); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $type = $transaction['type'];
                                                    echo htmlspecialchars(ucfirst($type));
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo number_format($transaction['amount'], 2) . ' ' . htmlspecialchars($transaction['symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($transaction['timestamp']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $decision = strtolower($transaction['approval_status'] ?? 'approved');
                                                    if ($decision === 'rejected') {
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Rejected</span>';
                                                    } else {
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Approved</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $reqStr = !empty($transaction['ucr_request_timestamp']) ? date('M j, Y H:i', strtotime($transaction['ucr_request_timestamp'])) : '—';
                                                    $decStr = !empty($transaction['ucr_decision_timestamp']) ? date('M j, Y H:i', strtotime($transaction['ucr_decision_timestamp'])) : '—';
                                                    $rawCh  = trim((string)($transaction['ucr_payment_channel'] ?? ''));
                                                    $keyCh  = strtolower($rawCh);
                                                    if ($rawCh !== '') {
                                                        if ($keyCh === 'kpay') { $rawCh = 'KPay'; }
                                                        elseif ($keyCh === 'ayapay') { $rawCh = 'AyaPay'; }
                                                        elseif ($keyCh === 'wavepay' || $keyCh === 'wave') { $rawCh = 'WavePay'; }
                                                    } else { $rawCh = '—'; }
                                                ?>
                                                <button type="button" title="Details"
                                                    class="open-details inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                                    data-requested="<?php echo htmlspecialchars($reqStr); ?>"
                                                    data-decision="<?php echo htmlspecialchars($decStr); ?>"
                                                    data-channel="<?php echo htmlspecialchars($rawCh); ?>"
                                                    data-proof="<?php echo htmlspecialchars($transaction['proof_of_screenshot'] ?? ''); ?>"
                                                    data-status="<?php echo htmlspecialchars(strtolower($transaction['approval_status'] ?? 'approved')); ?>">
                                                    <i class="fas fa-info-circle mr-1"></i> Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-history text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No <?php echo htmlspecialchars($tx_type ?: 'transactions'); ?> found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Standalone Deposit section removed as requested -->

            <!-- CONVERSION FEES SECTION -->
            <div id="fees-section" class="<?php echo $show_fees ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-coins mr-2 text-yellow-500"></i>Today's Conversion Fees (Profit)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
                    <?php if (count($daily_fees) > 0): ?>
                        <?php foreach ($daily_fees as $daily): ?>
                            <div class="bg-gradient-to-r from-green-400 to-green-600 rounded-lg shadow p-4 text-white">
                                <div class="text-xs font-semibold opacity-80">Today's Profit (<?php echo htmlspecialchars($daily['symbol']); ?>)</div>
                                <div class="text-3xl font-bold mt-1"><?php echo number_format($daily['total_fees'], 2); ?></div>
                                <div class="text-xs opacity-75 mt-1"><?php echo $daily['conversion_count']; ?> conversions</div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-4 bg-gray-100 rounded-lg p-4 text-center">
                            <i class="fas fa-chart-line text-4xl text-gray-400 mb-2"></i>
                            <p class="text-gray-600">No conversion fees collected today</p>
                        </div>
                    <?php endif; ?>
                </div>

                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-hand-holding-usd mr-2 text-red-500"></i>Today's Withdrawal Fees (Profit)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
                    <?php if (!empty($daily_withdraw_fees)): ?>
                        <?php foreach ($daily_withdraw_fees as $dailyw): ?>
                            <div class="bg-gradient-to-r from-red-400 to-red-600 rounded-lg shadow p-4 text-white">
                                <div class="text-xs font-semibold opacity-80">Today's Profit (<?php echo htmlspecialchars($dailyw['symbol']); ?>)</div>
                                <div class="text-3xl font-bold mt-1"><?php echo number_format($dailyw['total_fees'], 2); ?></div>
                                <div class="text-xs opacity-75 mt-1"><?php echo (int)($dailyw['withdrawal_count'] ?? 0); ?> withdrawals</div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-4 bg-gray-100 rounded-lg p-4 text-center">
                            <i class="fas fa-receipt text-4xl text-gray-400 mb-2"></i>
                            <p class="text-gray-600">No withdrawal fees collected today</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Weekly Admin Profit (Conversion + Withdrawal) by currency and day -->
                <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800">Weekly Admin Profit (by Currency)</h3>
                    </div>
                    <?php $we = htmlspecialchars($weekly_by_sym['end'] ?? date('Y-m-d')); ?>
                    <div class="flex items-center gap-2 mb-3 text-sm">
                        <label for="weeklyEnd" class="text-gray-600">Date</label>
                        <input id="weeklyEnd" type="date" value="<?php echo $we; ?>" class="p-2 border border-gray-300 rounded-lg text-sm" />
                        <?php $ws = htmlspecialchars($weekly_by_sym['start'] ?? date('Y-m-d', strtotime('-6 day'))); ?>
                        <input id="weeklyStart" type="date" value="<?php echo $ws; ?>" class="hidden" aria-hidden="true" />
                        <span class="text-gray-400">(shows previous 7 days)</span>
                    </div>
                    <!-- Custom legend: 4 currencies + withdrawal fee type (total 5 blocks) -->
                    <div class="flex flex-wrap items-center gap-3 mb-3 text-xs">
                        <div class="flex items-center gap-2 mr-4"><span class="inline-block w-3 h-3 rounded" style="background:#2563eb"></span><span class="text-gray-700 font-medium">USD</span></div>
                        <div class="flex items-center gap-2 mr-4"><span class="inline-block w-3 h-3 rounded" style="background:#10b981"></span><span class="text-gray-700 font-medium">MMK</span></div>
                        <div class="flex items-center gap-2 mr-4"><span class="inline-block w-3 h-3 rounded" style="background:#8b5cf6"></span><span class="text-gray-700 font-medium">THB</span></div>
                        <div class="flex items-center gap-2 mr-4"><span class="inline-block w-3 h-3 rounded" style="background:#f59e0b"></span><span class="text-gray-700 font-medium">JPY</span></div>
                        <div class="flex items-center gap-2 mr-4"><span class="inline-block w-3 h-3 rounded" style="background:#ef4444"></span><span class="text-gray-700 font-medium">Withdrawal Fees</span></div>
                    </div>
                    <div class="w-full">
                        <canvas id="weeklyProfitChart" height="224"></canvas>
                    </div>
                    <script>
                        (function(){
                            const labels = <?php echo json_encode($weekly_by_sym['labels']); ?> || [];
                            const symbols = <?php echo json_encode($weekly_by_sym['symbols']); ?> || [];
                            const convMap = <?php echo json_encode($weekly_by_sym['conv']); ?> || {};
                            const withMap = <?php echo json_encode($weekly_by_sym['with']); ?> || {};
                            const canvas = document.getElementById('weeklyProfitChart');
                            if (!canvas) return;
                            const container = canvas.parentElement;
                            let empty = document.getElementById('weeklyProfitEmpty');
                            if (!labels.length || !symbols.length) {
                                canvas.style.display = 'none';
                                if (!empty) {
                                    empty = document.createElement('div');
                                    empty.id = 'weeklyProfitEmpty';
                                    empty.className = 'text-center text-gray-500 py-8';
                                    empty.textContent = 'No fees recorded in this range';
                                    container.appendChild(empty);
                                } else {
                                    empty.textContent = 'No fees recorded in the last 7 days';
                                    empty.style.display = '';
                                }
                            } else {
                                if (empty) empty.style.display = 'none';
                                canvas.style.display = '';
                            }
                            // Colors per currency and per fee type
                            const colorBySym = { USD: '#2563eb', MMK: '#10b981', THB: '#8b5cf6', JPY: '#f59e0b' };
                            const fallbackPalette = ['#8b5cf6','#06b6d4','#84cc16','#ec4899','#f43f5e','#22c55e','#3b82f6'];
                            function colorFor(sym, i){ return colorBySym[sym] || fallbackPalette[i % fallbackPalette.length]; }

                            // For each currency, create a stack with two datasets: conversion and withdrawal
                            const datasets = [];
                            symbols.forEach((sym, i) => {
                                const col = colorFor(sym, i);
                                datasets.push({
                                    label: sym + ' Conversion',
                                    data: (convMap[sym] || []),
                                    backgroundColor: col + '80',
                                    borderColor: col,
                                    borderWidth: 1,
                                    stack: sym
                                });
                                datasets.push({
                                    label: sym + ' Withdrawal',
                                    data: (withMap[sym] || []),
                                    backgroundColor: '#ef444480',
                                    borderColor: '#ef4444',
                                    borderWidth: 1,
                                    stack: sym
                                });
                            });
                            const ctx = canvas.getContext('2d');
                            if (labels.length && symbols.length) {
                                new Chart(ctx, {
                                    type: 'bar',
                                    data: { labels, datasets },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            x: { stacked: true, title: { display: true, text: 'Date' } },
                                            y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Fees' } }
                                        },
                                        plugins: {
                                            legend: { display: false },
                                            tooltip: {
                                                filter: (ctx) => Number(ctx.parsed.y) !== 0,
                                                callbacks: {
                                                    title: (items) => {
                                                        const it = items && items[0];
                                                        if (!it) return '';
                                                        const date = it.label || '';
                                                        const sym = it.dataset && it.dataset.stack ? it.dataset.stack : '';
                                                        return sym ? `${date} — ${sym}` : date;
                                                    },
                                                    label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.parsed.y).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`
                                                }
                                            }
                                        }
                                    }
                                });
                            }

                            // Wire 7-day range controls
                            const startInp = document.getElementById('weeklyStart');
                            const endInp = document.getElementById('weeklyEnd');
                            function isDateStr(s){ return /^\d{4}-\d{2}-\d{2}$/.test(s); }
                            function toDate(s){ const d=new Date(s); return isNaN(d) ? null : d; }
                            function fmt(d){ const z=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; }
                            function applyRange(fromChanged){
                                let s = startInp ? startInp.value : '', e = endInp ? endInp.value : '';
                                if (!isDateStr(s) && !isDateStr(e)) return;
                                if (fromChanged && isDateStr(s)) {
                                    const sd = toDate(s);
                                    const ed = new Date(sd); ed.setDate(sd.getDate()+6);
                                    if (endInp) endInp.value = fmt(ed);
                                    e = endInp ? endInp.value : '';
                                } else if (!fromChanged && isDateStr(e)) {
                                    const ed = toDate(e);
                                    const sd = new Date(ed); sd.setDate(ed.getDate()-6);
                                    if (startInp) startInp.value = fmt(sd);
                                    s = startInp ? startInp.value : fmt(sd);
                                }
                                if (isDateStr(s) && isDateStr(e)) {
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('weekly_start', s);
                                    url.searchParams.set('weekly_end', e);
                                    url.searchParams.set('_', Date.now().toString());
                                    window.location.assign(url.toString());
                                }
                            }
                            if (startInp) startInp.addEventListener('change', () => applyRange(true));
                            if (endInp) endInp.addEventListener('change', () => applyRange(false));
                        })();
                    </script>
                </div>

                <h3 class="text-xl font-semibold text-gray-800 mb-4 mt-8">Fees History</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($fees_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conversion</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date & Time</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($fees_history as $fee): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($fee['username']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars(ucfirst($fee['operation_type'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php
                                                    if (($fee['operation_type'] ?? '') === 'conversion') {
                                                        echo htmlspecialchars($fee['from_symbol']) . ' → ' . htmlspecialchars($fee['to_symbol']);
                                                    } else {
                                                        echo htmlspecialchars($fee['currency_symbol']);
                                                    }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php
                                                    $cur = ($fee['operation_type'] === 'conversion') ? ($fee['from_symbol'] ?? '') : ($fee['currency_symbol'] ?? '');
                                                    echo number_format((float)$fee['amount_converted'], 2) . ' ' . htmlspecialchars($cur);
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600">
                                                +<?php echo number_format((float)$fee['tax_amount'], 2); ?> <?php echo htmlspecialchars(($fee['operation_type'] === 'conversion') ? ($fee['from_symbol'] ?? '') : ($fee['currency_symbol'] ?? '')); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo !empty($fee['ts']) ? date('M j, Y H:i', strtotime($fee['ts'])) : '—'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-receipt text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No fees recorded yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- EXCHANGE RATE SECTION -->
            <div id="exchange-rate-section" class="<?php echo $show_exchange_rate ? '' : 'hidden'; ?> relative overflow-hidden">
                
                <!-- Live Rate Preview Card -->
                <?php
                // Fetch live preview rate for USD to MMK
                list($live_usd_mmk, $eff_usd_mmk) = er_get_effective_rate('USD', 'MMK');
                ?>
                <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-gray-700 text-white rounded-full p-3">
                                <i class="fas fa-chart-line text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-800">Live Exchange Rate</h3>
                                <p class="text-gray-500 text-sm">Updated in real-time from global markets</p>
                            </div>
                        </div>
                        <button onclick="location.reload()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i>Refresh
                        </button>
                    </div>
                    <?php if (!empty($live_preview)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mt-6">
                        <?php foreach ($live_preview as $card): ?>
                        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                            <div class="text-gray-500 text-sm mb-2">1 <?php echo htmlspecialchars($card['base']); ?> =</div>
                            <div class="text-3xl font-bold mb-1 text-gray-900">
                                <?php echo number_format($card['effective'], 4); ?>
                                <span class="text-xl ml-2"><?php echo htmlspecialchars($card['target']); ?></span>
                            </div>
                            <div class="text-gray-500 text-xs">Live: <?php echo number_format($card['live'], 4); ?> → With markup</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-500 text-sm mt-4">No preview available. Ensure USD, THB, JPY, and MMK exist in the currencies table.</div>
                    <?php endif; ?>
                </div>

                <!-- Markup Settings Card -->
                <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="bg-gray-700 text-white rounded-full p-3">
                            <i class="fas fa-sliders-h text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Markup Settings</h3>
                            <p class="text-gray-500 text-sm">Adjust to match local market rates</p>
                        </div>
                    </div>
                    
                    <form action="admin.php?view=exchange_rate" method="POST">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="global_value" value="0">
                        
                        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                            <label for="mmk_percent" class="block text-gray-700 font-bold mb-3 text-lg">
                                <i class="fas fa-flag text-red-500 mr-2"></i>Myanmar Kyat (MMK) Markup
                            </label>
                            <div class="flex items-center gap-4">
                                <input type="number" step="1" id="mmk_percent" name="mmk_percent" 
                                       value="<?php echo isset($rate_settings['targets']['MMK']) ? htmlspecialchars($rate_settings['targets']['MMK']['value']) : '70'; ?>" 
                                       placeholder="70" 
                                       class="w-40 p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-2xl font-bold text-center">
                                <span class="text-3xl font-bold text-gray-600">%</span>
                                <div class="flex-1 ml-4">
                                    <div class="text-sm text-gray-600">
                                        <strong>Current:</strong> 1 USD = <span class="text-green-600 font-bold"><?php echo $eff_usd_mmk ? number_format($eff_usd_mmk, 2) : '---'; ?> MMK</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="mt-6 w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-xl transition-colors shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                            <i class="fas fa-save mr-2"></i>Save Markup Setting
                        </button>
                    </form>
                </div>

                <!-- Sync Rate Card -->
                <div class="bg-gray-50 rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="bg-gray-700 text-white rounded-full p-3">
                            <i class="fas fa-download text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Sync Exchange Rate</h3>
                            <p class="text-gray-500 text-sm">Fetch and save live rates to your database</p>
                        </div>
                    </div>
                    
                    <form action="admin.php?view=exchange_rate" method="POST">
                        <input type="hidden" name="action" value="sync_from_api">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
                                <label class="block text-gray-700 font-bold mb-3">
                                    <i class="fas fa-arrow-right text-blue-500 mr-2"></i>From Currency
                                </label>
                                <select name="base_currency" class="w-full p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold bg-white">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>" <?php echo $currency['symbol'] === 'USD' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currency['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
                                <label class="block text-gray-700 font-bold mb-3">
                                    <i class="fas fa-arrow-left text-purple-500 mr-2"></i>To Currency
                                </label>
                                <select name="target_currency" class="w-full p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-lg font-semibold bg-white">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>" <?php echo $currency['symbol'] === 'MMK' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currency['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-5 px-6 rounded-xl transition-colors shadow-lg hover:shadow-2xl transform hover:-translate-y-0.5 text-lg">
                            <i class="fas fa-cloud-download-alt mr-2"></i>Sync & Save Rate to Database
                        </button>
                    </form>
                </div>

                <!-- Existing Rates -->
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Current Exchange Rates</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($exchange_rates) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">Pair</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($exchange_rates as $rate): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($rate['base_symbol'] . ' → ' . $rate['target_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                1 <?php echo htmlspecialchars($rate['base_symbol']); ?> = <?php echo number_format($rate['rate'], 4); ?> <?php echo htmlspecialchars($rate['target_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo !empty($rate['rate_ts']) ? htmlspecialchars($rate['rate_ts']) : '<span class="text-gray-400">N/A</span>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-chart-line text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No exchange rates synced yet. Use the form above to sync from API.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="all-requests-history" class="<?php echo $show_all_requests ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">All User Requests History</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($all_requests_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Timestamp</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reject Reason</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($all_requests_history as $request): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['username']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(ucfirst($request['transaction_type'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo number_format($request['amount'], 2) . ' ' . htmlspecialchars($request['symbol']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
    <?php echo !empty($request['reject_reason']) ? htmlspecialchars($request['reject_reason']) : '—'; ?>
</td>
                                                <?php
                                                    $status = $request['status'];
                                                    $color_class = 'bg-gray-100 text-gray-800';
                                                    if ($status === 'pending') {
                                                        $color_class = 'bg-yellow-100 text-yellow-800';
                                                    } elseif ($status === 'completed') {
                                                        $color_class = 'bg-green-100 text-green-800';
                                                    } elseif ($status === 'rejected') {
                                                        $color_class = 'bg-red-100 text-red-800';
                                                    }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color_class; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['request_timestamp']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-list-alt text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No user requests found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading dialog removed for Exchange Rate view -->

    <!-- Details Modal -->
    <div id="detailsModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:1rem;">
        <div class="modal-content" style="background-color:#ffffff; color:#111827; margin:0; padding:0; border-radius:1rem; width:90%; max-width:460px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.6), 0 10px 10px -5px rgba(0,0,0,0.5); border:1px solid #e5e7eb;">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeDetailsModal()" style="cursor:pointer; float:right; font-size:28px; font-weight:bold;">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-info-circle mr-2"></i>Request Details</h3>
                <p class="text-blue-100 text-sm mt-1">Review request and approval timestamps</p>
            </div>
            <div class="p-6 bg-gray-50">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">Requested</span>
                        <span id="adm-req" class="text-gray-900">—</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span id="adm-dec-label" class="font-medium text-gray-700">Approved</span>
                        <span id="adm-dec" class="text-gray-900">—</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">Payment Channel</span>
                        <span id="adm-ch" class="text-gray-900">—</span>
                    </div>
                    <div id="adm-proof-row" class="flex items-center justify-between hidden">
                        <span class="font-medium text-gray-700">Proof of Payment</span>
                        <a id="adm-proof" href="#" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">View</a>
                    </div>
                </div>
                <div class="mt-6 text-right">
                    <button type="button" onclick="closeDetailsModal()" class="inline-flex items-center px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white font-semibold rounded-lg border border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirm Modal -->
    <div id="logoutModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:1rem;">
        <div class="modal-content" style="background-color:#ffffff; color:#111827; margin:0; padding:0; border-radius:1rem; width:90%; max-width:480px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.6), 0 10px 10px -5px rgba(0,0,0,0.5); border:1px solid #e5e7eb;">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeLogoutModal()" style="cursor:pointer; float:right; font-size:28px; font-weight:bold;">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-sign-out-alt mr-2"></i>Confirm Logout</h3>
                <p class="text-gray-200 text-sm mt-1">End your admin session</p>
            </div>
            <div class="p-6 bg-gray-100">
                <p class="text-gray-700 mb-6">Are you sure you want to log out?</p>
                <div class="flex space-x-3">
                    <a id="confirmLogoutBtn" href="#" class="flex-1 text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">Logout</a>
                    <button type="button" onclick="closeLogoutModal()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg border border-gray-600 hover:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Reason Modal -->
<div id="rejectReasonModal" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.7); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background-color:#ffffff; color:#111827; margin:0; padding:0; border-radius:1rem; width:90%; max-width:500px; max-height:85vh; overflow-y:auto; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7); border:1px solid #e5e7eb;">
        <div class="bg-gradient-to-r from-red-600 to-red-700 text-white p-6 rounded-t-xl">
            <span class="close" onclick="closeRejectReasonModal()" style="cursor:pointer; float:right; font-size:28px; font-weight:bold;">&times;</span>
            <h3 class="text-2xl font-bold"><i class="fas fa-times-circle mr-2"></i>Reject Request</h3>
            <p class="text-red-100 text-sm mt-1">Provide a reason for rejection (visible to user)</p>
        </div>
        <div class="p-6">
            <form id="rejectForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" id="rejectRequestId" name="request_id" value="">
                
                <div class="mb-6">
                    <label for="rejectReason" class="block text-gray-700 font-medium mb-2">
                        <i class="fas fa-comment-alt text-red-500 mr-2"></i>Rejection Reason
                    </label>
                    <select id="rejectReason" name="reject_reason" class="w-full p-3 border border-gray-300 rounded-lg mb-3 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" required>
                        <option value="">-- Select a reason --</option>
                        <option value="Insufficient funds">Insufficient funds</option>
                        <option value="Incorrect payment details">Incorrect payment details</option>
                        <option value="Missing proof of payment">Missing proof of payment</option>
                        <option value="Suspicious activity">Suspicious activity</option>
                        <option value="Amount exceeds limit">Amount exceeds limit</option>
                        <option value="Payment verification failed">Payment verification failed</option>
                        <option value="Other">Other (specify below)</option>
                    </select>
                    
                    <textarea id="customReason" name="custom_reason" 
                              placeholder="If you selected 'Other', please specify the reason here..."
                              class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent hidden"
                              rows="3"></textarea>
                </div>
                
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">
                        <i class="fas fa-paper-plane mr-2"></i>Submit Rejection
                    </button>
                    <button type="button" onclick="closeRejectReasonModal()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* Animation for modal */
    #rejectReasonModal .modal-content {
        animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
</style>

    <style>
        /* Animated gradient background */
        .loading-backdrop {
            background: linear-gradient(-45deg, #667eea, #764ba2, #f093fb, #4facfe);
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            backdrop-filter: blur(8px);
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Smooth slow spinner */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .loading-spinner {
            animation: spin 4s cubic-bezier(0.4, 0.0, 0.2, 1) infinite;
            border-color: #667eea;
            border-top-color: transparent;
            border-right-color: #764ba2;
            border-bottom-color: #f093fb;
        }

        /* Pulsing glow effect */
        @keyframes pulseGlow {
            0%, 100% { 
                opacity: 0.2; 
                transform: translate(-50%, -50%) scale(1);
            }
            50% { 
                opacity: 0.4; 
                transform: translate(-50%, -50%) scale(1.1);
            }
        }
        .pulse-glow {
            animation: pulseGlow 3s ease-in-out infinite;
        }

        /* Exchange rate section fade in */
        #exchange-rate-section {
            animation: fadeInSection 0.8s ease-out;
        }

        @keyframes fadeInSection {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Colorful background for exchange rate section */
        #exchange-rate-section::before {
            content: '';
            position: absolute;
            top: -100px;
            left: 0;
            right: 0;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            opacity: 0.08;
            border-radius: 50%;
            filter: blur(60px);
            z-index: -1;
            animation: floatBackground 8s ease-in-out infinite;
        }

        @keyframes floatBackground {
            0%, 100% { transform: translateY(0px) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }

        /* Card entrance animation */
        .loading-card {
            animation: cardEntrance 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Success bounce */
        @keyframes successBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .success-bounce {
            animation: successBounce 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
    </style>

    <script>
        function showLogoutModal(targetUrl) {
            var btn = document.getElementById('confirmLogoutBtn');
            btn.setAttribute('href', targetUrl);
            document.getElementById('logoutModal').style.display = 'flex';
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var logoutLinks = document.querySelectorAll('a[href$="admin_logout.php"]');
            logoutLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    showLogoutModal(link.href);
                });
            });

            // Close when clicking the backdrop
            window.addEventListener('click', function(event) {
                var modal = document.getElementById('logoutModal');
                if (event.target === modal) { modal.style.display = 'none'; }
            });

            // Details modal wiring
            const detailsModal = document.getElementById('detailsModal');
            const reqEl = document.getElementById('adm-req');
            const decEl = document.getElementById('adm-dec');
            const chEl  = document.getElementById('adm-ch');
            const proofRowEl = document.getElementById('adm-proof-row');
            const proofEl = document.getElementById('adm-proof');
            function openDetailsModal(requested, decision, channel, proof, status){
                if (reqEl) reqEl.textContent = requested || '—';
                if (decEl) decEl.textContent = decision || '—';
                if (chEl)  chEl.textContent  = channel || '—';
                const decLabel = document.getElementById('adm-dec-label');
                const st = (status || '').toLowerCase();
                if (decLabel) {
                    decLabel.textContent = (st === 'rejected') ? 'Rejected' : 'Approved';
                }
                const hasProof = !!(proof && proof.trim() !== '');
                if (proofRowEl) proofRowEl.classList.toggle('hidden', !hasProof);
                if (proofEl) {
                    if (hasProof) { proofEl.setAttribute('href', proof); }
                    else { proofEl.setAttribute('href', '#'); }
                }
                if (detailsModal) detailsModal.style.display = 'flex';
            }
            window.closeDetailsModal = function(){ if (detailsModal) detailsModal.style.display = 'none'; };
            document.querySelectorAll('.open-details').forEach(function(btn){
                btn.addEventListener('click', function(){
                    openDetailsModal(
                        btn.getAttribute('data-requested') || '—',
                        btn.getAttribute('data-decision')  || '—',
                        btn.getAttribute('data-channel')   || '—',
                        btn.getAttribute('data-proof')     || '',
                        btn.getAttribute('data-status')    || ''
                    );
                });
            });
        });

        // Reject Reason Modal Functions
function showRejectReasonModal(requestId) {
    const modal = document.getElementById('rejectReasonModal');
    const requestIdInput = document.getElementById('rejectRequestId');
    const rejectReasonSelect = document.getElementById('rejectReason');
    const customReasonTextarea = document.getElementById('customReason');
    
    if (requestIdInput) {
        requestIdInput.value = requestId;
    }
    
    // Reset form
    if (rejectReasonSelect) rejectReasonSelect.value = '';
    if (customReasonTextarea) {
        customReasonTextarea.value = '';
        customReasonTextarea.classList.add('hidden');
    }
    
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeRejectReasonModal() {
    const modal = document.getElementById('rejectReasonModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Show/hide custom reason textarea based on selection
document.addEventListener('DOMContentLoaded', function() {
    const rejectReasonSelect = document.getElementById('rejectReason');
    const customReasonTextarea = document.getElementById('customReason');
    
    if (rejectReasonSelect && customReasonTextarea) {
        rejectReasonSelect.addEventListener('change', function() {
            if (this.value === 'Other') {
                customReasonTextarea.classList.remove('hidden');
                customReasonTextarea.required = true;
            } else {
                customReasonTextarea.classList.add('hidden');
                customReasonTextarea.required = false;
            }
        });
    }
    
    // Close modal when clicking outside
    const rejectModal = document.getElementById('rejectReasonModal');
    if (rejectModal) {
        rejectModal.addEventListener('click', function(event) {
            if (event.target === rejectModal) {
                closeRejectReasonModal();
            }
        });
    }
});
    </script>
</body>
</html>
