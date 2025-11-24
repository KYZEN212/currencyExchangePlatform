<?php
session_start();
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$token = $_GET['token'] ?? '';
if ($token === '') { http_response_code(400); echo 'Invalid token'; exit(); }

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) { die('DB connection failed'); }
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT pr.user_id, u.email, u.username, pr.expires_at, pr.used FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE pr.token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$reset = $res->fetch_assoc();
$stmt->close();

$valid = false;
if ($reset) {
  $now = new DateTime();
  $exp = new DateTime($reset['expires_at']);
  $valid = ($reset['used'] == 0) && ($now <= $exp);
}

if (!$valid) { http_response_code(400); echo 'Reset link is invalid or expired.'; $conn->close(); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f3f4f6; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px}
    .card{background:#fff; width:100%; max-width:420px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.08); padding:24px}
    h1{font-size:20px; margin:0 0 8px}
    input,button{width:100%; padding:12px; border-radius:8px; border:1px solid #d1d5db}
    input{margin:8px 0 12px}
    button{background:#2563eb; color:#fff; border:0; font-weight:600; cursor:pointer}
    button:hover{background:#1d4ed8}
    .alert{margin:8px 0 12px; padding:10px 12px; border-radius:8px; font-size:14px}
    .alert.error{background:#fde2e1; color:#b91c1c; border:1px solid #fca5a5}
  </style>
</head>
<body>
  <div class="card">
    <h1>Set a new password</h1>
    <?php
      $err = $_GET['error'] ?? '';
      if ($err) {
        $map = [
          'missing' => 'Please fill in all fields.',
          'mismatch' => 'Passwords do not match.',
          'weak' => 'Password must be at least 8 characters.',
          'invalid' => 'This reset link is invalid. Please request a new one.',
          'expired' => 'This reset link has expired or was already used. Please request a new one.',
          'server' => 'Server error while updating password. Please try again.',
        ];
        $msg = $map[$err] ?? 'An error occurred. Please try again.';
        echo '<div class="alert error">' . htmlspecialchars($msg) . '</div>';
      }
    ?>
    <form method="POST" action="reset_password_submit.php">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <input type="password" name="password" placeholder="New password" minlength="8" required>
      <input type="password" name="password_confirm" placeholder="Confirm password" minlength="8" required>
      <button type="submit">Update Password</button>
    </form>
  </div>
</body>
</html>
