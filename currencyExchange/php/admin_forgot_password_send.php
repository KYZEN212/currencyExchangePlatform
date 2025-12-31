<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config.php';
$servername = $servername;
$db_username = $username; // from config.php
$db_password = $password; // from config.php
$dbname = $dbname;

require_once __DIR__ . '/email_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_forgot_password_request.php');
    exit();
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
    header('Location: admin_forgot_password_request.php');
    exit();
}

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die('DB connection failed');
}
$conn->set_charset('utf8mb4');

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS admin_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  token VARCHAR(191) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (admin_id),
  INDEX (token),
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Find admin by email
$stmt = $conn->prepare("SELECT admin_id, username, email FROM admins WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$admin = $res->fetch_assoc();
$stmt->close();

// Always respond success to avoid enumeration
if ($admin) {
    $admin_id = (int)$admin['admin_id'];
    $token = bin2hex(random_bytes(32));
    $expires_at = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    // Invalidate previous tokens
    $conn->query("UPDATE admin_password_resets SET used = 1 WHERE admin_id = {$admin_id}");

    $stmt2 = $conn->prepare("INSERT INTO admin_password_resets (admin_id, token, expires_at, used) VALUES (?, ?, ?, 0)");
    $stmt2->bind_param('iss', $admin_id, $token, $expires_at);
    $stmt2->execute();
    $stmt2->close();

    $resetUrl = rtrim(APP_BASE_URL, '/') . '/php/admin_reset_password.php?token=' . urlencode($token);
    @send_reset_email($admin['email'], $admin['username'], $resetUrl);
}

$conn->close();

header('Location: admin.php?message=reset_link_sent');
exit();
