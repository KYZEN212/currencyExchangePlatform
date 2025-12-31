<?php
session_start();
require_once 'config.php';

$token = $_GET['token'] ?? '';
if ($token === '') {
    echo 'Invalid request: token missing.';
    exit();
}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo 'Database connection failed.';
    exit();
}

$stmt = $conn->prepare('SELECT prt.id, prt.user_id, u.username, u.email, prt.expires_at, prt.used FROM pin_reset_tokens prt JOIN users u ON prt.user_id = u.user_id WHERE prt.token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

$valid = false;
if ($row) {
    $notExpired = (strtotime($row['expires_at']) > time());
    $notUsed = ((int)$row['used'] === 0);
    $valid = $notExpired && $notUsed;
}

$conn->close();

if (!$valid) {
    echo 'This link is invalid or has expired.';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set New Security PIN</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background: linear-gradient(135deg, #f0f9f0 0%, #d4edda 25%, #a8e0b8 65%, #7ac29a 100%);display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px}
    .card{background:#fff; width:100%; max-width:420px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.08); padding:24px}
    h1{font-size:20px; margin:0 0 8px}
    p{color:#6b7280; font-size:14px; margin:0 0 16px}
    input,button{width:100%; padding:12px; border-radius:8px; border:1px solid #d1d5db}
    input{margin:8px 0 12px}
    button{background:#2563eb; color:#fff; border:0; font-weight:600; cursor:pointer}
    button:hover{background:#1d4ed8}
  </style>
</head>
<body>
  <div class="card">
    <h1>Set a new 4-digit PIN</h1>
    <p>Choose a PIN you can remember. Do not share it with anyone.</p>
    <form method="POST" action="pin_reset_update.php">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <input type="password" name="pin" placeholder="New PIN (4 digits)" pattern="\d{4}" required>
      <input type="password" name="pin_confirm" placeholder="Confirm PIN" pattern="\d{4}" required>
      <button type="submit">Update PIN</button>
    </form>
  </div>
</body>
</html>
