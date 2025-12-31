<?php
session_start();
require_once __DIR__ . '/config.php';

$servername = $servername;
$db_username = $username; // from config.php
$db_password = $password; // from config.php
$dbname = $dbname;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }

$token = $_POST['token'] ?? '';
$pwd = $_POST['password'] ?? '';
$pwd2 = $_POST['password_confirm'] ?? '';
if ($token === '' || $pwd === '' || $pwd2 === '') { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=missing'); exit(); }
if ($pwd !== $pwd2) { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=mismatch'); exit(); }

// Strong password policy: >=12 chars, upper, lower, digit, special
function is_strong_password(string $p): bool {
    if (strlen($p) < 12) return false;
    $hasUpper = preg_match('/[A-Z]/', $p);
    $hasLower = preg_match('/[a-z]/', $p);
    $hasDigit = preg_match('/[0-9]/', $p);
    $hasSpecial = preg_match('/[^A-Za-z0-9]/', $p);
    return (bool)($hasUpper && $hasLower && $hasDigit && $hasSpecial);
}
if (!is_strong_password($pwd)) { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=weak'); exit(); }

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=server'); exit(); }
$conn->set_charset('utf8mb4');

// Validate token
$stmt = $conn->prepare("SELECT apr.id, apr.admin_id, apr.expires_at, apr.used FROM admin_password_resets apr WHERE apr.token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=invalid'); exit(); }
$now = new DateTime();
$exp = new DateTime($row['expires_at']);
if ((int)$row['used'] !== 0 || $now > $exp) { header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=expired'); exit(); }

$admin_id = (int)$row['admin_id'];
$hash = password_hash($pwd, PASSWORD_BCRYPT);

$conn->begin_transaction();
try {
    $u1 = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ? LIMIT 1");
    $u1->bind_param('si', $hash, $admin_id);
    if (!$u1->execute()) { throw new Exception('pwd update fail'); }
    $u1->close();

    $u2 = $conn->prepare("UPDATE admin_password_resets SET used = 1 WHERE id = ? LIMIT 1");
    $u2->bind_param('i', $row['id']);
    if (!$u2->execute()) { throw new Exception('token update fail'); }
    $u2->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    header('Location: admin_reset_password.php?token=' . urlencode($token) . '&error=server');
    exit();
}

// Notify existing admin login tabs and avoid opening/navigating any other tab automatically.
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password Reset Successful</title>
  <script>
    (function(){
      var payload = { type: 'admin_pwd_reset_success', ts: Date.now() };
      // BroadcastChannel notification
      try {
        var bc = new BroadcastChannel('admin_reset_channel');
        bc.postMessage(payload);
      } catch (e) {}
      // localStorage notification (storage event)
      try { localStorage.setItem('admin_pwd_reset_success', String(payload.ts)); } catch (e) {}
      // Try to close this tab; if blocked, user can use the button below
      try { window.close(); } catch (e) {}
    })();
  </script>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#334155;background:#f8fafc}
    .card{max-width:520px;margin:18vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:24px;text-align:center}
    .btn{display:inline-block;margin-top:14px;padding:10px 16px;border-radius:10px;background:#0ea5e9;color:#fff;text-decoration:none;font-weight:600}
    .btn:hover{background:#0284c7}
  </style>
</head>
<body>
  <div class="card">
    <h2>Password reset successful</h2>
    <p>Your password has been updated.</p>
    <button class="btn" type="button" onclick="window.location.href='admin.php?message=password_reset_success'">Go to Admin Login</button>
  </div>
</body>
</html>
<?php
exit();
