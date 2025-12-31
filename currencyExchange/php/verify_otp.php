<?php
session_start();

require_once __DIR__ . '/email_config.php';

// DB config (mirror testLogin.php)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "currency_platform";

// Trusted device (remember me) settings — mirror testLogin.php
if (!defined('TRUST_COOKIE_NAME')) { define('TRUST_COOKIE_NAME', 'trusted_login'); }
if (!defined('TRUST_TTL_SECONDS')) { define('TRUST_TTL_SECONDS', 30 * 24 * 60 * 60); }
if (!defined('TRUST_SECRET_KEY')) { define('TRUST_SECRET_KEY', 'change-this-secret-key-please'); }
function ua_hash_v(): string { $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); return substr(sha1($ua), 0, 16); }
function make_trust_token_v(int $userId, int $expires): string {
    $nonce = bin2hex(random_bytes(8));
    $payload = $userId . '|' . $expires . '|' . $nonce . '|' . ua_hash_v();
    $sig = hash_hmac('sha256', $payload, TRUST_SECRET_KEY);
    return base64_encode($payload . '|' . $sig);
}

function redirect_with_msg(string $url, string $msg) {
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    header('Location: ' . $url . $sep . 'm=' . urlencode($msg));
    exit();
}

$twofa = $_SESSION['2fa'] ?? null;
if (!$twofa || empty($twofa['email']) || empty($twofa['user_id']) || empty($twofa['otp']) || empty($twofa['expires_at'])) {
    header('Location: ../html/login.html');
    exit();
}

$action = $_GET['action'] ?? '';
if ($action === 'resend') {
    try {
        $newCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $newCode = (string)mt_rand(100000, 999999);
    }
    $_SESSION['2fa']['otp'] = $newCode;
    $_SESSION['2fa']['expires_at'] = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');
    @send_otp_email($twofa['email'], $twofa['username'] ?? $twofa['email'], $newCode);
    redirect_with_msg('../html/otp.html', 'A new code was sent to your email.');
}

// Only POST handles verification
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../html/otp.html');
    exit();
}

$code = isset($_POST['otp']) ? trim($_POST['otp']) : '';
if (!preg_match('/^\d{6}$/', $code)) {
    redirect_with_msg('../html/otp.html', 'Enter the 6-digit code.');
}

// Expiry check
if (strtotime($twofa['expires_at']) < time()) {
    redirect_with_msg('../html/otp.html', 'Code expired. Click Resend.');
}

// Attempts limit
$attempts = (int)($twofa['attempts'] ?? 0);
if ($attempts >= 5) {
    unset($_SESSION['2fa']);
    header('Location: ../html/login.html?error=locked&remaining=60');
    exit();
}

if ($code !== $twofa['otp']) {
    $_SESSION['2fa']['attempts'] = $attempts + 1;
    redirect_with_msg('../html/otp.html', 'Incorrect code. Try again.');
}

// Success: finalize login
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    redirect_with_msg('../html/otp.html', 'DB connection failed.');
}

// Ensure login events table exists
$conn->query("CREATE TABLE IF NOT EXISTS user_login_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    browser_name VARCHAR(100) NULL,
    device VARCHAR(100) NULL,
    browser_version VARCHAR(50) NULL,
    os_name VARCHAR(100) NULL,
    os_version VARCHAR(50) NULL,
    INDEX (user_id), INDEX (login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add missing columns if needed
try {
    $dbName = $dbname;
    $checkCol = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_login_events' AND COLUMN_NAME IN ('ip_address','user_agent','browser_name','device','browser_version','os_name','os_version','device_model')");
    if ($checkCol) {
        $checkCol->bind_param('s', $dbName);
        $checkCol->execute();
        $resCol = $checkCol->get_result();
        $existing = [];
        while ($r = $resCol->fetch_assoc()) { $existing[$r['COLUMN_NAME']] = true; }
        $checkCol->close();
        if (empty($existing['ip_address'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN ip_address VARCHAR(45) NULL"); }
        if (empty($existing['user_agent'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN user_agent VARCHAR(255) NULL"); }
        if (empty($existing['browser_name'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN browser_name VARCHAR(100) NULL"); }
        if (empty($existing['device'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN device VARCHAR(100) NULL"); }
        if (empty($existing['browser_version'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN browser_version VARCHAR(50) NULL"); }
        if (empty($existing['os_name'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN os_name VARCHAR(100) NULL"); }
        if (empty($existing['os_version'])) { $conn->query("ALTER TABLE user_login_events ADD COLUMN os_version VARCHAR(50) NULL"); }
    }
} catch (Throwable $e) { /* ignore */ }

// Capture environment data
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
if (strpos((string)$ip, ',') !== false) { $ip = trim(explode(',', (string)$ip)[0]); }
if ($ip === '::1') { $ip = '127.0.0.1'; }
elseif (stripos((string)$ip, '::ffff:') === 0) { $ip = substr((string)$ip, strrpos((string)$ip, ':') + 1); }
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$browser = 'Unknown'; $browserVersion = '';
if (preg_match('/Edg[e|A|i]?\/(\d\.?)+/i', $ua, $m)) { $browser = 'Edge'; $browserVersion = $m[1] ?? ''; }
elseif (preg_match('/OPR\/(\d\.?)+/i', $ua, $m)) { $browser = 'Opera'; $browserVersion = $m[1] ?? ''; }
elseif (preg_match('/Chrome\/(\d[\d\.]*)/i', $ua, $m)) { $browser = 'Chrome'; $browserVersion = $m[1] ?? ''; }
elseif (preg_match('/Version\/(\d[\d\.]*) .*Safari/i', $ua, $m)) { $browser = 'Safari'; $browserVersion = $m[1] ?? ''; }
elseif (preg_match('/Firefox\/(\d[\d\.]*)/i', $ua, $m)) { $browser = 'Firefox'; $browserVersion = $m[1] ?? ''; }
elseif (preg_match('/MSIE\s([\d\.]+)/i', $ua, $m) || preg_match('/Trident\/.*rv:([\d\.]+)/i', $ua, $m)) { $browser = 'IE'; $browserVersion = $m[1] ?? ''; }
$osName = 'Unknown'; $osVersion = ''; $device = 'Unknown';
if (preg_match('/Windows NT\s(10\.0|11\.0|6\.3|6\.2|6\.1)/i', $ua, $m)) { $osName='Windows'; $map=['10.0'=>'10/11','11.0'=>'11','6.3'=>'8.1','6.2'=>'8','6.1'=>'7']; $osVersion=$map[$m[1]]??$m[1]; $device='Windows'; }
elseif (preg_match('/Android\s([\d\.]+)/i', $ua, $m)) { $osName='Android'; $osVersion=$m[1]; $device='Android'; }
elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) { $osName='iOS'; if (preg_match('/OS\s([\d_]+)/i', $ua, $mm)) { $osVersion=str_replace('_','.',$mm[1]); } $device='iOS'; }
elseif (preg_match('/Mac OS X\s([\d_]+)/i', $ua, $m)) { $osName='macOS'; $osVersion=str_replace('_','.',$m[1]); $device='macOS'; }
elseif (preg_match('/Linux/i', $ua)) { $osName='Linux'; $device='Linux'; }

$stmt_login = $conn->prepare("INSERT INTO user_login_events (user_id, ip_address, user_agent, browser_name, device, browser_version, os_name, os_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
if ($stmt_login) { $stmt_login->bind_param('isssssss', $twofa['user_id'], $ip, $ua, $browser, $device, $browserVersion, $osName, $osVersion); $stmt_login->execute(); $stmt_login->close(); }

// Clear throttle for this email
$now = (new DateTime())->format('Y-m-d H:i:s');
$stmtClr = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, 0, NULL, ?) ON DUPLICATE KEY UPDATE attempts = 0, locked_until = NULL, last_attempt_at = VALUES(last_attempt_at)");
if ($stmtClr) { $stmtClr->bind_param('ss', $twofa['email'], $now); $stmtClr->execute(); $stmtClr->close(); }

// Promote session to fully authenticated
$_SESSION['user_id'] = $twofa['user_id'];
$_SESSION['username'] = $twofa['username'] ?? '';
if (!empty($twofa['userimage'])) { $_SESSION['userimage'] = $twofa['userimage']; }

// If user chose remember me, set a trusted device cookie (30 days)
if (!empty($twofa['remember'])) {
    $exp = time() + TRUST_TTL_SECONDS;
    $token = make_trust_token_v((int)$twofa['user_id'], $exp);
    // Cookie params: 30 days, HTTPOnly, SameSite=Lax
    setcookie(
        TRUST_COOKIE_NAME,
        $token,
        [
            'expires' => $exp,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

unset($_SESSION['2fa']);

header('Location: ./dashboard.php');
exit();

