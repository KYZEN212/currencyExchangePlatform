<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

require_once __DIR__ . '/email_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password_request.php');
    exit();
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
    header('Location: forgot_password_request.php');
    exit();
}

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die('DB connection failed');
}
$conn->set_charset('utf8mb4');

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(191) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (user_id),
  INDEX (token),
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Find user by email
$stmt = $conn->prepare("SELECT user_id, username FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

// Always respond success to avoid email enumeration, but only create token if user exists
if ($user) {
    $user_id = (int)$user['user_id'];
    $token = bin2hex(random_bytes(32));
    $expires_at = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    // Invalidate previous tokens
    $conn->query("UPDATE password_resets SET used = 1 WHERE user_id = {$user_id}");

    $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at, used) VALUES (?, ?, ?, 0)");
    $stmt2->bind_param('iss', $user_id, $token, $expires_at);
    $stmt2->execute();
    $stmt2->close();

    $resetUrl = rtrim(APP_BASE_URL, '/') . '/php/reset_password.php?token=' . urlencode($token);
    // Try to send email (best effort)
    @send_reset_email($email, $user['username'], $resetUrl);
}

$conn->close();

// Redirect back to login with a message
header('Location: ../html/login.html?message=reset_link_sent');
exit();
