<?php
session_start();

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "currency_platform";

// Email helpers
require_once __DIR__ . '/email_config.php';

// Login throttle configuration (lock after 3 failed attempts)
if (!defined('MAX_ATTEMPTS')) {
    define('MAX_ATTEMPTS', 3);
}
if (!defined('LOCK_SECONDS')) {
    define('LOCK_SECONDS', 10); // 1 minute
}

// Trusted device (remember me) settings
if (!defined('TRUST_COOKIE_NAME')) { define('TRUST_COOKIE_NAME', 'trusted_login'); }
if (!defined('TRUST_TTL_SECONDS')) { define('TRUST_TTL_SECONDS', 30 * 24 * 60 * 60); } // 30 days
// Use a secret key for HMAC. In production, store in env or config.
if (!defined('TRUST_SECRET_KEY')) { define('TRUST_SECRET_KEY', 'change-this-secret-key-please'); }

function ua_hash(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(sha1($ua), 0, 16);
}

function make_trust_token(int $userId, int $expires): string {
    $nonce = bin2hex(random_bytes(8));
    $payload = $userId . '|' . $expires . '|' . $nonce . '|' . ua_hash();
    $sig = hash_hmac('sha256', $payload, TRUST_SECRET_KEY);
    return base64_encode($payload . '|' . $sig);
}

function parse_trust_token(string $token): ?array {
    $raw = base64_decode($token, true);
    if ($raw === false) return null;
    $parts = explode('|', $raw);
    if (count($parts) !== 5) return null;
    [$uid, $exp, $nonce, $uah, $sig] = $parts;
    if (!ctype_digit($uid) || !ctype_digit($exp)) return null;
    $payload = $uid . '|' . $exp . '|' . $nonce . '|' . $uah;
    $calc = hash_hmac('sha256', $payload, TRUST_SECRET_KEY);
    if (!hash_equals($calc, $sig)) return null;
    if ($uah !== ua_hash()) return null; // different browser/UA
    if ((int)$exp < time()) return null; // expired
    return ['user_id' => (int)$uid, 'expires' => (int)$exp];
}

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get form data
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'];
$remember = isset($_POST['remember']);

// Session-based throttle (replaces login_throttle table)
$now = (new DateTime())->format('Y-m-d H:i:s');
if (!isset($_SESSION['login_throttle'])) {
    $_SESSION['login_throttle'] = [];
}
$th = $_SESSION['login_throttle'][$email] ?? ['attempts' => 0, 'locked_until' => 0];
// If currently locked, redirect with remaining seconds
if (!empty($th['locked_until']) && (int)$th['locked_until'] > time()) {
    $remaining = max(1, (int)$th['locked_until'] - time());
    header("Location: ../html/login.html?error=locked&remaining=" . $remaining);
    exit();
}
// If lock expired, reset attempts
if (!empty($th['locked_until']) && (int)$th['locked_until'] <= time()) {
    $th = ['attempts' => 0, 'locked_until' => 0];
    $_SESSION['login_throttle'][$email] = $th;
}

// Detect if users.userimage column exists (schema compatibility)
$hasUserimage = false;
try {
    $stmtCol = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'userimage' LIMIT 1");
    if ($stmtCol) {
        $stmtCol->bind_param('s', $dbname);
        $stmtCol->execute();
        $resCol = $stmtCol->get_result();
        $hasUserimage = (bool)$resCol->fetch_row();
        $stmtCol->close();
    }
} catch (Throwable $e) { /* ignore */ }

// Prepare SQL statement to retrieve user data by email (conditionally include userimage)
if ($hasUserimage) {
    $stmt = $conn->prepare("SELECT user_id, password_hash, username, user_status, userimage FROM users WHERE email = ?");
} else {
    $stmt = $conn->prepare("SELECT user_id, password_hash, username, user_status FROM users WHERE email = ?");
}
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // User found, verify password and status
    $user = $result->fetch_assoc();
    
    if (password_verify($password, $user['password_hash'])) {
        // Check if user is verified (user_status = 1)
        if ($user['user_status'] == 1) {
            // If a trusted cookie exists for this user and matches current browser, bypass OTP
            $trusted = null;
            if (!empty($_COOKIE[TRUST_COOKIE_NAME])) {
                $trusted = parse_trust_token((string)$_COOKIE[TRUST_COOKIE_NAME]);
            }
            if ($trusted && (int)$trusted['user_id'] === (int)$user['user_id']) {
                // Complete login immediately
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                if ($hasUserimage) {
                    $_SESSION['userimage'] = $user['userimage'] ?? '';
                } else {
                    $uploadsDir = __DIR__ . '/uploads/';
                    $uid = (int)$user['user_id'];
                    if (is_dir($uploadsDir)) {
                        $latest = '';
                        $latestMTime = 0;
                        $patterns = ["user_{$uid}_*.jpg","user_{$uid}_*.jpeg","user_{$uid}_*.png","user_{$uid}_*.gif","user_{$uid}_*.webp"];
                        foreach ($patterns as $pat) {
                            foreach (glob($uploadsDir . $pat) as $path) {
                                $mt = @filemtime($path) ?: 0;
                                if ($mt > $latestMTime) { $latestMTime = $mt; $latest = basename($path); }
                            }
                        }
                        if ($latest !== '') { $_SESSION['userimage'] = $latest; }
                    }
                }
                // Log login event and clear throttle (reusing code patterns below)
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

                // IP/UA capture
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
                if (strpos((string)$ip, ',') !== false) { $ip = trim(explode(',', (string)$ip)[0]); }
                if ($ip === '::1') { $ip = '127.0.0.1'; }
                elseif (stripos((string)$ip, '::ffff:') === 0) { $ip = substr((string)$ip, strrpos((string)$ip, ':') + 1); }
                $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                $browser = 'Unknown'; $browserVersion = '';
                if (preg_match('/Edg[e|A|i]?\\/([\d\.]+)/i', $ua, $m)) { $browser = 'Edge'; $browserVersion = $m[1]; }
                elseif (preg_match('/OPR\\/([\d\.]+)/i', $ua, $m)) { $browser = 'Opera'; $browserVersion = $m[1]; }
                elseif (preg_match('/Chrome\\/([\d\.]+)/i', $ua, $m)) { $browser = 'Chrome'; $browserVersion = $m[1]; }
                elseif (preg_match('/Version\\/([\d\.]+).*Safari/i', $ua, $m)) { $browser = 'Safari'; $browserVersion = $m[1]; }
                elseif (preg_match('/Firefox\\/([\d\.]+)/i', $ua, $m)) { $browser = 'Firefox'; $browserVersion = $m[1]; }
                elseif (preg_match('/MSIE\s([\d\.]+)/i', $ua, $m) || preg_match('/Trident\\/.*rv:([\d\.]+)/i', $ua, $m)) { $browser = 'IE'; $browserVersion = $m[1]; }

                $osName = 'Unknown'; $osVersion = ''; $device = 'Unknown';
                if (preg_match('/Windows NT\s(10\.0|11\.0|6\.3|6\.2|6\.1)/i', $ua, $m)) { $osName='Windows'; $map=['10.0'=>'10/11','11.0'=>'11','6.3'=>'8.1','6.2'=>'8','6.1'=>'7']; $osVersion=$map[$m[1]]??$m[1]; $device='Windows'; }
                elseif (preg_match('/Android\s([\d\.]+)/i', $ua, $m)) { $osName='Android'; $osVersion=$m[1]; $device='Android'; }
                elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) { $osName='iOS'; if (preg_match('/OS\s([\d_]+)/i', $ua, $mm)) { $osVersion=str_replace('_','.', $mm[1]); } $device='iOS'; }
                elseif (preg_match('/Mac OS X\s([\d_]+)/i', $ua, $m)) { $osName='macOS'; $osVersion=str_replace('_','.', $m[1]); $device='macOS'; }
                elseif (preg_match('/Linux/i', $ua)) { $osName='Linux'; $device='Linux'; }

                $stmt_login = $conn->prepare("INSERT INTO user_login_events (user_id, ip_address, user_agent, browser_name, device, browser_version, os_name, os_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt_login) { $stmt_login->bind_param('isssssss', $user['user_id'], $ip, $ua, $browser, $device, $browserVersion, $osName, $osVersion); $stmt_login->execute(); $stmt_login->close(); }

                // Clear throttle (session-based)
                if (isset($_SESSION['login_throttle'][$email])) {
                    unset($_SESSION['login_throttle'][$email]);
                }

                header('Location: ./dashboard.php');
                exit();
            }

            // Step 1: Prepare 2FA session and email OTP instead of logging in immediately
            $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

            // Try to resolve user image name (optional)
            $pendingUserimage = '';
            if ($hasUserimage) {
                $pendingUserimage = $user['userimage'] ?? '';
            } else {
                $uploadsDir = __DIR__ . '/uploads/';
                $uid = (int)$user['user_id'];
                if (is_dir($uploadsDir)) {
                    $latest = '';
                    $latestMTime = 0;
                    $patterns = [
                        "user_{$uid}_*.jpg","user_{$uid}_*.jpeg","user_{$uid}_*.png","user_{$uid}_*.gif","user_{$uid}_*.webp"
                    ];
                    foreach ($patterns as $pat) {
                        foreach (glob($uploadsDir . $pat) as $path) {
                            $mt = @filemtime($path) ?: 0;
                            if ($mt > $latestMTime) { $latestMTime = $mt; $latest = basename($path); }
                        }
                    }
                    if ($latest !== '') { $pendingUserimage = $latest; }
                }
            }

            $_SESSION['2fa'] = [
                'email' => $email,
                'user_id' => (int)$user['user_id'],
                'username' => $user['username'],
                'userimage' => $pendingUserimage,
                'otp' => $otpCode,
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'created_at' => $now,
                'remember' => $remember ? 1 : 0,
            ];

            // Send the OTP via email (best-effort)
            @send_otp_email($email, $user['username'], $otpCode);

            // Redirect to OTP input page
            header("Location: ../html/otp.html");
            exit();
        } else {
            // User exists but is not verified
            header("Location: ../html/login.html?error=not_verified");
            exit();
        }
    } else {
        // Incorrect password: increment attempts and possibly lock (session-based)
        $attempts = (int)($th['attempts'] ?? 0) + 1;
        if ($attempts >= MAX_ATTEMPTS) {
            $_SESSION['login_throttle'][$email] = [
                'attempts' => 0,
                'locked_until' => time() + LOCK_SECONDS,
            ];
            @send_ban_email($email, $user['username'] ?? $email, LOCK_SECONDS);
            header("Location: ../html/login.html?error=locked&remaining=" . LOCK_SECONDS);
            exit();
        } else {
            $_SESSION['login_throttle'][$email] = [
                'attempts' => $attempts,
                'locked_until' => 0,
            ];
            header("Location: ../html/login.html?error=incorrect_password");
            exit();
        }
    }
} else {
    // User not found: increment attempts and possibly lock (session-based)
    $attempts = (int)($th['attempts'] ?? 0) + 1;
    if ($attempts >= MAX_ATTEMPTS) {
        $_SESSION['login_throttle'][$email] = [
            'attempts' => 0,
            'locked_until' => time() + LOCK_SECONDS,
        ];
        header("Location: ../html/login.html?error=locked&remaining=" . LOCK_SECONDS);
        exit();
    } else {
        $_SESSION['login_throttle'][$email] = [
            'attempts' => $attempts,
            'locked_until' => 0,
        ];
        header("Location: ../html/login.html?error=user_not_found");
        exit();
    }
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>