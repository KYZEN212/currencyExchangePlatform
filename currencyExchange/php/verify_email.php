<?php
// php/verify_email.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo "<h2>Server error</h2><p>Could not connect to database.</p>";
    exit;
}

$token = isset($_GET['token']) ? $_GET['token'] : '';
if (!$token) {
    http_response_code(400);
    echo "<h2>Invalid link</h2><p>Missing verification token.</p>";
    exit;
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (email), INDEX (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $conn->prepare("SELECT email, expires_at, verified FROM email_verification_tokens WHERE token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(400);
    echo "<h2>Invalid link</h2><p>This verification link is not valid.</p>";
    exit;
}

if (strtotime($row['expires_at']) < time()) {
    http_response_code(400);
    echo "<h2>Link expired</h2><p>This verification link has expired. Please request a new one.</p>";
    exit;
}

if (intval($row['verified']) === 1) {
    $already = true;
} else {
    $already = false;
}

if (!$already) {
    $upd = $conn->prepare("UPDATE email_verification_tokens SET verified = 1 WHERE token = ? LIMIT 1");
    $upd->bind_param('s', $token);
    $upd->execute();
    $upd->close();
}

// If the session belongs to the same email, mark it verified for convenience
if (isset($_SESSION['reg_email']) && $_SESSION['reg_email'] === $row['email']) {
    $_SESSION['email_verified'] = true;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Email Verified</title>
  <style>
    body{font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:#f3f4f6; display:flex; align-items:center; justify-content:center; height:100vh; margin:0}
    .card{background:#fff; padding:24px 28px; border-radius:12px; box-shadow:0 10px 24px rgba(0,0,0,.1); max-width:480px; text-align:center}
    .btn{background:#2563eb; color:#fff; padding:10px 16px; border-radius:8px; display:inline-block; text-decoration:none; margin-top:12px}
    .muted{color:#6b7280; font-size:14px; margin-top:8px}
  </style>
</head>
<body>
  <div class="card">
    <h2>Verification successful</h2>
    <p class="muted">You can return to the registration page. This tab will close automatically.</p>
    <a class="btn" href="../html/registration.html?verified=1&stage=3">Back to Registration</a>
    <div class="muted">If this tab doesn't close, click the button above.</div>
  </div>
  <script>
    try {
      const targetUrl = '../html/registration.html?verified=1&stage=3';
      if (window.opener && !window.opener.closed) {
        // Navigate the original tab to Step 3 and try to close this tab
        try { window.opener.location.href = targetUrl; } catch (e) {}
        try { window.opener.postMessage({ type: 'emailVerified' }, '*'); } catch (e) {}
        try { localStorage.setItem('emailVerified', String(Date.now())); } catch (e) {}
        try { window.close(); } catch (e) {}
      } else {
        // No opener (or blocked). Redirect this current tab back to the registration page.
        try { localStorage.setItem('emailVerified', String(Date.now())); } catch (e) {}
        window.location.href = targetUrl;
      }
    } catch (e) { /* fallback link will remain */ }
  </script>
</body>
</html>
