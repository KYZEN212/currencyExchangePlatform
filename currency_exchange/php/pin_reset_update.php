<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'Invalid request method.';
    exit();
}

$token = $_POST['token'] ?? '';
$pin = $_POST['pin'] ?? '';
$pin_confirm = $_POST['pin_confirm'] ?? '';

if ($token === '') { echo 'Missing token.'; exit(); }
if (!preg_match('/^\d{4}$/', $pin)) { echo 'PIN must be exactly 4 digits.'; exit(); }
if ($pin !== $pin_confirm) { echo 'PIN confirmation does not match.'; exit(); }

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { echo 'Database connection failed.'; exit(); }

// Look up token
$stmt = $conn->prepare('SELECT id, user_id, expires_at, used FROM pin_reset_tokens WHERE token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) { $conn->close(); echo 'Invalid token.'; exit(); }
if (intval($row['used']) === 1) { $conn->close(); echo 'This link has already been used.'; exit(); }
if (strtotime($row['expires_at']) <= time()) { $conn->close(); echo 'This link has expired.'; exit(); }

$user_id = (int)$row['user_id'];
$token_id = (int)$row['id'];

$conn->begin_transaction();
try {
    // Ensure user_pins table exists
    $conn->query("CREATE TABLE IF NOT EXISTS user_pins (
        user_id INT NOT NULL PRIMARY KEY,
        pin_hash VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_user_pins_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upsert hashed PIN
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmtUp = $conn->prepare("INSERT INTO user_pins (user_id, pin_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash)");
    $stmtUp->bind_param('is', $user_id, $pin_hash);
    $stmtUp->execute();
    $stmtUp->close();

    // Mark token as used
    $stmtTok = $conn->prepare('UPDATE pin_reset_tokens SET used = 1 WHERE id = ?');
    $stmtTok->bind_param('i', $token_id);
    $stmtTok->execute();
    $stmtTok->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo 'Failed to update PIN. Please try again.';
    exit();
}

$conn->close();

// Simple success page
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PIN Updated</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f3f4f6; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px}
    .card{background:#fff; width:100%; max-width:420px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.08); padding:24px; text-align:center}
    h1{font-size:20px; margin:0 0 8px}
    p{color:#6b7280; font-size:14px; margin:0 0 16px}
    a.button{display:inline-block; padding:12px 16px; border-radius:8px; border:1px solid #2563eb; background:#2563eb; color:#fff; font-weight:600; text-decoration:none}
    a.button:hover{background:#1d4ed8}
  </style>
</head>
<body>
  <div class="card">
    <h1>PIN updated successfully</h1>
    <p>Your 4-digit Security PIN has been updated.</p>
    <a class="button" href="dashboard.php">Go to Dashboard</a>
  </div>
</body>
</html>
