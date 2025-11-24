<?php
session_start();
require_once 'config.php';
require_once 'email_config.php';

header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['username'])) {
    header('Location: login.html');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pin_reset_request.php');
    exit();
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
    header('Location: pin_reset_request.php?message=' . urlencode('Email is required.'));
    exit();
}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    header('Location: pin_reset_request.php?message=' . urlencode('Database connection failed.'));
    exit();
}

// Ensure email belongs to current user
$currentUser = $_SESSION['username'];
$stmt = $conn->prepare('SELECT user_id, username, email FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user || $user['username'] !== $currentUser) {
    $conn->close();
    header('Location: pin_reset_request.php?message=' . urlencode('The email does not match your account.'));
    exit();
}

// Create table for PIN reset tokens if not exists
$conn->query("CREATE TABLE IF NOT EXISTS pin_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(token),
    INDEX(user_id),
    CONSTRAINT fk_pin_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Invalidate previous tokens for this user
$del = $conn->prepare('DELETE FROM pin_reset_tokens WHERE user_id = ? OR expires_at < NOW()');
$del->bind_param('i', $user['user_id']);
$del->execute();
$del->close();

$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes
$ins = $conn->prepare('INSERT INTO pin_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
$ins->bind_param('iss', $user['user_id'], $token, $expires);
$ok = $ins->execute();
$ins->close();

if (!$ok) {
    $conn->close();
    header('Location: pin_reset_request.php?message=' . urlencode('Could not start PIN reset. Please try again.'));
    exit();
}

$resetUrl = rtrim(APP_BASE_URL, '/') . '/php/pin_reset_verify.php?token=' . urlencode($token);

if (send_pin_reset_email($user['email'], $currentUser, $resetUrl)) {
    // Redirect to dashboard with notification message
    $msg = 'A PIN reset link has been sent to your email address.';
    header('Location: dashboard.php?action=notifications&message=' . urlencode($msg));
    $conn->close();
    exit();
} else {
    $conn->close();
    header('Location: pin_reset_request.php?message=' . urlencode('Failed to send email. Please try again later.'));
    exit();
}

// Unreachable, kept for completeness
$conn->close();
