<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(); }

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
$logFile = $logDir . '/reset.log';
$log = function(string $msg) use ($logFile) {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
};

$log('---- reset_password_submit START ----');

$token = $_POST['token'] ?? '';
$pw = $_POST['password'] ?? '';
$pw2 = $_POST['password_confirm'] ?? '';
if ($token === '' || $pw === '' || $pw2 === '') {
    $log('Validation failed: missing fields');
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=missing');
    exit();
}
if ($pw !== $pw2) {
    $log('Validation failed: mismatch');
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=mismatch');
    exit();
}
if (strlen($pw) < 8) {
    $log('Validation failed: weak password');
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=weak');
    exit();
}

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) { die('DB connection failed'); }
$conn->set_charset('utf8mb4');
$log('DB connected');

$stmt = $conn->prepare("SELECT pr.user_id, pr.expires_at, pr.used FROM password_resets pr WHERE pr.token = ? LIMIT 1");
if (!$stmt) { $log('Prepare failed (select token): ' . $conn->error); $conn->close(); header('Location: reset_password.php?token=' . urlencode($token) . '&error=server'); exit(); }
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    $log('Token invalid');
    $conn->close();
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=invalid');
    exit();
}

$now = new DateTime();
$exp = new DateTime($row['expires_at']);
if ($row['used'] == 1 || $now > $exp) {
    $log('Token expired or used');
    $conn->close();
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=expired');
    exit();
}

$hash = password_hash($pw, PASSWORD_DEFAULT);

$conn->begin_transaction();
try {
    $stmtU = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    if (!$stmtU) { throw new Exception('Prepare failed (update users): ' . $conn->error); }
    $stmtU->bind_param('si', $hash, $row['user_id']);
    $stmtU->execute();
    $stmtU->close();

    $stmtP = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
    if (!$stmtP) { throw new Exception('Prepare failed (update resets): ' . $conn->error); }
    $stmtP->bind_param('s', $token);
    $stmtP->execute();
    $stmtP->close();

    $conn->commit();
    $log('Password updated and token marked used for user_id=' . $row['user_id']);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    $log('Exception: ' . $e->getMessage());
    header('Location: reset_password.php?token=' . urlencode($token) . '&error=server');
    exit();
}

$conn->close();
$log('Success redirect to login');
header('Location: ../html/login.html?message=password_reset_success');
exit();
