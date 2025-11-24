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
    define('LOCK_SECONDS', 60); // 1 minute
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

$conn->query("CREATE TABLE IF NOT EXISTS login_throttle (
  email VARCHAR(191) NOT NULL PRIMARY KEY,
  attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_attempt_at DATETIME NULL,
  INDEX (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$now = (new DateTime())->format('Y-m-d H:i:s');
$stmtLt = $conn->prepare("SELECT attempts, locked_until FROM login_throttle WHERE email = ?");
$stmtLt->bind_param('s', $email);
$stmtLt->execute();
$resLt = $stmtLt->get_result();
$rowLt = $resLt->fetch_assoc();
$stmtLt->close();

if ($rowLt && $rowLt['locked_until'] && strtotime($rowLt['locked_until']) > time()) {
    // Cap any existing lock duration to at most LOCK_SECONDS
    $remaining = strtotime($rowLt['locked_until']) - time();
    if ($remaining > LOCK_SECONDS) {
        $newLock = (new DateTime('+' . LOCK_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
        $stmtCap = $conn->prepare("UPDATE login_throttle SET locked_until = ? WHERE email = ?");
        if ($stmtCap) { $stmtCap->bind_param('ss', $newLock, $email); $stmtCap->execute(); $stmtCap->close(); }
        $remaining = LOCK_SECONDS;
    }
    $remaining = max(1, (int)$remaining);
    header("Location: ../html/login.html?error=locked&remaining=" . $remaining);
    exit();
}

// If a previous lock exists but has expired, clear it and reset attempts
if ($rowLt && $rowLt['locked_until'] && strtotime($rowLt['locked_until']) <= time()) {
    $stmtClr = $conn->prepare("UPDATE login_throttle SET attempts = 0, locked_until = NULL, last_attempt_at = NOW() WHERE email = ?");
    if ($stmtClr) { $stmtClr->bind_param('s', $email); $stmtClr->execute(); $stmtClr->close(); }
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
            // Password is correct and user is verified, set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            if ($hasUserimage) {
                $_SESSION['userimage'] = $user['userimage'] ?? '';
            } else {
                // No DB column: try to discover latest uploaded avatar by filename pattern
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
                    if ($latest !== '') { $_SESSION['userimage'] = $latest; }
                }
            }
            // Ensure login events table exists and log this login with metadata
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

            // Add missing columns robustly by checking INFORMATION_SCHEMA
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

            // Prefer Cloudflare's original client IP, then X-Forwarded-For, then REMOTE_ADDR
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
            // take first IP if proxied (XFF may contain a list)
            if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
            // Normalize common IPv6 forms
            if ($ip === '::1') { $ip = '127.0.0.1'; }
            elseif (stripos($ip, '::ffff:') === 0) { $ip = substr($ip, strrpos($ip, ':') + 1); }
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            // Enhanced UA parsing
            $browser = 'Unknown';
            $browserVersion = '';
            if (preg_match('/Edg[e|A|i]?\/([\d\.]+)/i', $ua, $m)) { $browser = 'Edge'; $browserVersion = $m[1]; }
            elseif (preg_match('/OPR\/([\d\.]+)/i', $ua, $m)) { $browser = 'Opera'; $browserVersion = $m[1]; }
            elseif (preg_match('/Chrome\/([\d\.]+)/i', $ua, $m)) { $browser = 'Chrome'; $browserVersion = $m[1]; }
            elseif (preg_match('/Version\/([\d\.]+).*Safari/i', $ua, $m)) { $browser = 'Safari'; $browserVersion = $m[1]; }
            elseif (preg_match('/Firefox\/([\d\.]+)/i', $ua, $m)) { $browser = 'Firefox'; $browserVersion = $m[1]; }
            elseif (preg_match('/MSIE\s([\d\.]+)/i', $ua, $m) || preg_match('/Trident\/.*rv:([\d\.]+)/i', $ua, $m)) { $browser = 'IE'; $browserVersion = $m[1]; }

            $osName = 'Unknown';
            $osVersion = '';
            $device = 'Unknown';
            $deviceModel = '';
            if (preg_match('/Windows NT\s(10\.0|11\.0|6\.3|6\.2|6\.1)/i', $ua, $m)) {
                $osName = 'Windows';
                $map = ['10.0' => '10/11', '11.0' => '11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
                $osVersion = $map[$m[1]] ?? $m[1];
                $device = 'Windows';
                $deviceModel = 'Windows PC';
            } elseif (preg_match('/Android\s([\d\.]+)/i', $ua, $m)) {
                $osName = 'Android';
                $osVersion = $m[1];
                $device = 'Android';
                if (preg_match('/Android[^;\)]*;\s*([^;\)]*?)(?:\sBuild|\)|;)/i', $ua, $mm)) {
                    $deviceModel = trim($mm[1]);
                }
            } elseif (preg_match('/iPhone|iPad|iPod/i', $ua, $dm)) {
                $osName = 'iOS';
                if (preg_match('/OS\s([\d_]+)/i', $ua, $m)) { $osVersion = str_replace('_', '.', $m[1]); }
                $device = 'iOS';
                // Apple user agents don't expose exact model; use device type if present
                $deviceModel = isset($dm[0]) ? $dm[0] : 'iOS Device';
            } elseif (preg_match('/Mac OS X\s([\d_]+)/i', $ua, $m)) {
                $osName = 'macOS';
                $osVersion = str_replace('_', '.', $m[1]);
                $device = 'macOS';
                $deviceModel = 'Mac';
            } elseif (preg_match('/Linux/i', $ua)) {
                $osName = 'Linux';
                $device = 'Linux';
                $deviceModel = 'Linux PC';
            }

            $stmt_login = $conn->prepare("INSERT INTO user_login_events (user_id, ip_address, user_agent, browser_name, device, browser_version, os_name, os_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt_login) { $stmt_login->bind_param('isssssss', $user['user_id'], $ip, $ua, $browser, $device, $browserVersion, $osName, $osVersion); $stmt_login->execute(); $stmt_login->close(); }

            // Clear throttle on successful login
            $stmtClr = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, 0, NULL, ?) ON DUPLICATE KEY UPDATE attempts = 0, locked_until = NULL, last_attempt_at = VALUES(last_attempt_at)");
            if ($stmtClr) { $stmtClr->bind_param('ss', $email, $now); $stmtClr->execute(); $stmtClr->close(); }

            // Redirect to dashboard
            header("Location: ./dashboard.php");
            exit();
        } else {
            // User exists but is not verified
            header("Location: ../html/login.html?error=not_verified");
            exit();
        }
    } else {
        // Incorrect password: increment attempts and possibly lock
        $attempts = 0;
        if ($rowLt) { $attempts = (int)$rowLt['attempts']; }
        $attempts++;
        if ($attempts >= MAX_ATTEMPTS) {
            $lockedUntil = (new DateTime('+' . LOCK_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
            $stmtUp = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until), last_attempt_at = VALUES(last_attempt_at)");
            if ($stmtUp) { $stmtUp->bind_param('siss', $email, $attempts, $lockedUntil, $now); $stmtUp->execute(); $stmtUp->close(); }
            // Notify user about temporary lock (avoid enumeration: only when user exists)
            @send_ban_email($email, $user['username'] ?? $email, LOCK_SECONDS);
            header("Location: ../html/login.html?error=locked&remaining=" . LOCK_SECONDS);
            exit();
        } else {
            $stmtUp = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, ?, NULL, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt_at = VALUES(last_attempt_at)");
            if ($stmtUp) { $stmtUp->bind_param('sis', $email, $attempts, $now); $stmtUp->execute(); $stmtUp->close(); }
            header("Location: ../html/login.html?error=incorrect_password");
            exit();
        }
    }
} else {
    // User not found: increment attempts and possibly lock
    $attempts = 0;
    if ($rowLt) { $attempts = (int)$rowLt['attempts']; }
    $attempts++;
    if ($attempts >= MAX_ATTEMPTS) {
        $lockedUntil = (new DateTime('+' . LOCK_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
        $stmtUp = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until), last_attempt_at = VALUES(last_attempt_at)");
        if ($stmtUp) { $stmtUp->bind_param('siss', $email, $attempts, $lockedUntil, $now); $stmtUp->execute(); $stmtUp->close(); }
        header("Location: ../html/login.html?error=locked&remaining=" . LOCK_SECONDS);
        exit();
    } else {
        $stmtUp = $conn->prepare("INSERT INTO login_throttle (email, attempts, locked_until, last_attempt_at) VALUES (?, ?, NULL, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt_at = VALUES(last_attempt_at)");
        if ($stmtUp) { $stmtUp->bind_param('sis', $email, $attempts, $now); $stmtUp->execute(); $stmtUp->close(); }
        header("Location: ../html/login.html?error=user_not_found");
        exit();
    }
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>