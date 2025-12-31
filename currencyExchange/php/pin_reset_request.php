<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['username'])) {
    header('Location: login.html');
    exit();
}

// Fetch user email from DB
$userEmail = '';
$userName = $_SESSION['username'];
$conn = new mysqli($servername, $username, $password, $dbname);
if (!$conn->connect_error) {
    $stmt = $conn->prepare('SELECT email FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $userName);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $userEmail = $res ? ($res['email'] ?? '') : '';
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Security PIN</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f3f4f6; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px}
    .card{background:#fff; width:100%; max-width:420px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.08); padding:24px}
    h1{font-size:20px; margin:0 0 8px}
    p{color:#6b7280; font-size:14px; margin:0 0 16px}
    input,button{width:100%; padding:12px; border-radius:8px; border:1px solid #d1d5db}
    input{margin:8px 0 12px}
    button{background:#2563eb; color:#fff; border:0; font-weight:600; cursor:pointer}
    button:hover{background:#1d4ed8}
    button.secondary{background:#6b7280; margin-top:12px}
    button.secondary:hover{background:#4b5563}
  </style>
</head>
<body>
  <div class="card">
    <h1>Reset your Security PIN</h1>
    <p>We'll email you a secure link to set a new 4-digit PIN.</p>
    <form method="POST" action="pin_reset_send.php">
      <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($userEmail); ?>" required>
      <button type="submit">Send PIN reset link</button>
    </form>
    <button type="button" class="secondary" onclick="window.location.href='profile.php'">Close</button>
  </div>
</body>
</html>
