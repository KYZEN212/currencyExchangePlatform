<?php
session_start();
require_once __DIR__ . '/config.php';

$servername = $servername;
$db_username = $username; // from config.php
$db_password = $password; // from config.php
$dbname = $dbname;

$token = $_GET['token'] ?? '';
if ($token === '') { http_response_code(400); echo 'Invalid token'; exit(); }

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) { die('DB connection failed'); }
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT apr.admin_id, a.email, a.username, apr.expires_at, apr.used FROM admin_password_resets apr JOIN admins a ON a.admin_id = apr.admin_id WHERE apr.token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$reset = $res->fetch_assoc();
$stmt->close();

$valid = false;
if ($reset) {
  $now = new DateTime();
  $exp = new DateTime($reset['expires_at']);
  $valid = ((int)$reset['used'] === 0) && ($now <= $exp);
}

if (!$valid) { http_response_code(400); echo 'Reset link is invalid or expired.'; $conn->close(); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Reset Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#0f172a; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px; position:relative; overflow:hidden}
    .card{background:#0b1220; color:#e2e8f0; width:100%; max-width:420px; border-radius:14px; box-shadow:0 10px 25px rgba(0,0,0,.35); padding:24px; border:1px solid #1e293b}
    h1{font-size:20px; margin:0 0 8px}
    /* Decorative currency circles */
    .coin{ position:absolute; opacity:.12; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
    .spin-slow{ animation: spin 32s linear infinite; }
    .spin-slow-rev{ animation: spin 40s linear infinite reverse; }
    @keyframes spin{ to{ transform: rotate(360deg); } }
    input,button{width:100%; padding:12px; border-radius:10px; border:1px solid #334155}
    input{margin:8px 0 12px; background:#0b1220; color:#e2e8f0}
    button{background:linear-gradient(135deg,#34d399,#22d3ee); color:#0b1220; border:0; font-weight:700; cursor:pointer}
    button:hover{filter:brightness(1.1)}
    .alert{margin:8px 0 12px; padding:10px 12px; border-radius:8px; font-size:14px}
    .alert.error{background:#3f1d1d; color:#fecaca; border:1px solid #b91c1c}
    .hint{color:#94a3b8; font-size:12px; margin-top:6px}
  </style>
</head>
<body>
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
    <h1>Set a new admin password</h1>
    <?php
      $err = $_GET['error'] ?? '';
      if ($err) {
        $map = [
          'missing' => 'Please fill in all fields.',
          'mismatch' => 'Passwords do not match.',
          'weak' => 'Password must be at least 12 characters and include upper, lower, digit, and special symbol.',
          'invalid' => 'This reset link is invalid. Please request a new one.',
          'expired' => 'This reset link has expired or was already used. Please request a new one.',
          'server' => 'Server error while updating password. Please try again.',
        ];
        $msg = $map[$err] ?? 'An error occurred. Please try again.';
        echo '<div class="alert error">' . htmlspecialchars($msg) . '</div>';
      }
    ?>
    <form method="POST" action="admin_reset_password_submit.php">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <input type="password" name="password" placeholder="New password" minlength="10" required>
      <input type="password" name="password_confirm" placeholder="Confirm password" minlength="10" required>
      <div class="hint">Use at least 12 characters with uppercase, lowercase, numbers, and a special symbol.</div>
      <button type="submit">Update Password</button>
    </form>
  </div>
</body>
</html>
