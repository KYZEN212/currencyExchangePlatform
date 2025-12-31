<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Forgot Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#0f172a; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px; position:relative; overflow:hidden}
    .card{background:#0b1220; color:#e2e8f0; width:100%; max-width:420px; border-radius:14px; box-shadow:0 10px 25px rgba(0,0,0,.35); padding:24px; border:1px solid #1e293b}
    h1{font-size:20px; margin:0 0 8px}
    p{color:#94a3b8; font-size:14px; margin:0 0 16px}
    /* Decorative currency circles */
    .coin{ position:absolute; opacity:.12; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
    .spin-slow{ animation: spin 32s linear infinite; }
    .spin-slow-rev{ animation: spin 40s linear infinite reverse; }
    @keyframes spin{ to{ transform: rotate(360deg); } }
    /* Equal sizing and equal left/right margins for input and button */
    input,button{width:calc(100% - 24px); margin:12px; box-sizing:border-box; padding:12px; border-radius:10px; font-size:16px}
    input{background:#0b1220; color:#e2e8f0; border:1px solid #334155}
    button{background:linear-gradient(135deg,#34d399,#22d3ee); color:#0b1220; border:0; font-weight:700; cursor:pointer}
    button:hover{filter:brightness(1.1)}
    .link{display:block; margin-top:12px; text-align:center; color:#22d3ee; text-decoration:none}
  </style>
</head>

  <!-- Currency-themed decorative background -->
  <div class="pointer-events-none absolute inset-0 -z-10">
    <!-- USD coin -->
    <svg class="coin spin-slow" width="220" height="220" viewBox="0 0 220 220" fill="none" style="top:-40px; left:-30px">
      <circle cx="110" cy="110" r="96" stroke="#22d3ee" stroke-width="3"/>
      <circle cx="110" cy="110" r="82" stroke="#34d399" stroke-width="2"/>
      <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="64" fill="#22d3ee" font-family="Inter, system-ui, Arial">$</text>
    </svg>
    <!-- JPY coin -->
    <svg class="coin spin-slow-rev" width="180" height="180" viewBox="0 0 180 180" fill="none" style="bottom:40px; right:-20px; position:absolute">
      <circle cx="90" cy="90" r="78" stroke="#34d399" stroke-width="3"/>
      <circle cx="90" cy="90" r="66" stroke="#22d3ee" stroke-width="2"/>
      <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="48" fill="#34d399" font-family="Inter, system-ui, Arial">¥</text>
    </svg>
    <!-- THB coin -->
    <svg class="coin spin-slow" width="160" height="160" viewBox="0 0 160 160" fill="none" style="top:55%; left:-30px; position:absolute">
      <circle cx="80" cy="80" r="68" stroke="#22d3ee" stroke-width="2"/>
      <circle cx="80" cy="80" r="56" stroke="#34d399" stroke-width="2"/>
      <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="40" fill="#22d3ee" font-family="Inter, system-ui, Arial">฿</text>
    </svg>
    <!-- MMK coin (Ks) -->
    <svg class="coin spin-slow-rev" width="190" height="190" viewBox="0 0 190 190" fill="none" style="top:20%; right:10%; position:absolute">
      <circle cx="95" cy="95" r="82" stroke="#22d3ee" stroke-width="3"/>
      <circle cx="95" cy="95" r="70" stroke="#34d399" stroke-width="2"/>
      <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="44" fill="#22d3ee" font-family="Inter, system-ui, Arial">Ks</text>
    </svg>
  </div>

  <div class="card">
    <h1>Forgot your admin password?</h1>
    <p>Enter the admin email to receive a secure reset link.</p>
    <form method="POST" action="admin_forgot_password_send.php">
      <input type="email" name="email" placeholder="Admin email" required>
      <button type="submit">Send reset link</button>
    </form>
    <a class="link" href="admin.php">Back to admin login</a>
  </div>
</body>
</html>
