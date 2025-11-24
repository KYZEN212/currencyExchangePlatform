<?php
// php/registration.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include PHPMailer
require_once '../phpmailer/src/Exception.php';
require_once '../phpmailer/src/PHPMailer.php';
require_once '../phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Abstract API Configuration ---
// REPLACE 'YOUR_ABSTRACT_API_KEY' with your actual key
$abstract_api_key = 'e49eafed64f547f9a7a29089b21414c7';
// ----------------------------------

// Database configuration
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "currency_platform";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]));
}

// Verify OTP submitted by user, mark as verified and set session
function verifyOtp($conn) {
    $otp = $_POST['otp'] ?? '';
    $email = $_SESSION['reg_email'] ?? '';
    if (empty($otp) || empty($email)) {
        echo json_encode(['success'=>false, 'message'=>'Missing OTP or session']);
        return;
    }
    // Ensure OTP table exists
    $conn->query("CREATE TABLE IF NOT EXISTS email_verification_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        verified TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare("SELECT id, otp_code, expires_at, attempts, verified FROM email_verification_otps WHERE email = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) { echo json_encode(['success'=>false,'message'=>'No OTP found']); return; }
    if (intval($row['verified']) === 1) { $_SESSION['email_verified'] = true; echo json_encode(['success'=>true]); return; }
    if (strtotime($row['expires_at']) < time()) { echo json_encode(['success'=>false,'message'=>'OTP expired']); return; }

    $ok = hash_equals($row['otp_code'], trim($otp));
    if (!$ok) {
        $newAttempts = intval($row['attempts']) + 1;
        $upd = $conn->prepare("UPDATE email_verification_otps SET attempts = ? WHERE id = ?");
        $upd->bind_param('ii', $newAttempts, $row['id']);
        $upd->execute();
        $upd->close();
        echo json_encode(['success'=>false,'message'=>'Invalid OTP']);
        return;
    }

    $upd = $conn->prepare("UPDATE email_verification_otps SET verified = 1 WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();
    $_SESSION['email_verified'] = true;
    echo json_encode(['success'=>true]);
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Handle different actions (OTP-based verification + captcha)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'init_captcha') {
        initCaptcha();
    } elseif ($action === 'start_registration') {
        startRegistration($conn, $abstract_api_key);
    } elseif ($action === 'check_verification_status') {
        checkVerificationStatus($conn);
    } elseif ($action === 'verify_otp') {
        verifyOtp($conn);
    } elseif (isset($_FILES['nrc_front_photo'])) {
        completeRegistration($conn);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

// Initialize a simple image-selection CAPTCHA challenge
function initCaptcha() {
    // Use local images from ../image/ directory
    $dir = realpath(__DIR__ . '/../image');
    if ($dir === false) {
        echo json_encode(['success'=>false, 'message'=>'Image directory not found']);
        return;
    }
    $files = glob($dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    if (!$files || count($files) < 9) {
        echo json_encode(['success'=>false, 'message'=>'Not enough images for CAPTCHA']);
        return;
    }

    // Group images by prefix before the first underscore, e.g., traffic_light_1.jpg -> traffic light
    $groups = [];
    foreach ($files as $path) {
        $base = basename($path);
        $name = strtolower(pathinfo($base, PATHINFO_FILENAME));
        $parts = explode('_', $name);
        $prefix = count($parts) > 1 ? $parts[0] : $name; // e.g., traffic
        // If first two parts make a better label (traffic_light)
        if (count($parts) > 1 && in_array($parts[0] . '_' . $parts[1], ['traffic_light'])) {
            $prefix = $parts[0] . '_' . $parts[1];
        }
        if (!isset($groups[$prefix])) $groups[$prefix] = [];
        $groups[$prefix][] = $base;
    }

    // Choose a target group with at least 3 images
    $eligible = array_filter($groups, function($arr){ return count($arr) >= 3; });
    if (!$eligible) { echo json_encode(['success'=>false, 'message'=>'No suitable image groups for CAPTCHA']); return; }
    $keys = array_keys($eligible);
    $targetKey = $keys[array_rand($keys)];
    $targetLabel = str_replace('_', ' ', $targetKey); // human-readable

    // Pick 3 target images
    shuffle($eligible[$targetKey]);
    $chosenTargets = array_slice($eligible[$targetKey], 0, 3);

    // Build distractor pool from all other images
    $otherImages = [];
    foreach ($groups as $k => $arr) {
        if ($k === $targetKey) continue;
        foreach ($arr as $b) { $otherImages[] = $b; }
    }
    shuffle($otherImages);
    $chosenDistractors = array_slice($otherImages, 0, 6);

    // Compose final 9 tiles and shuffle
    $images = [];
    foreach ($chosenTargets as $b) { $images[] = ['url' => '../image/' . $b, 'is_target' => 1]; }
    foreach ($chosenDistractors as $b) { $images[] = ['url' => '../image/' . $b, 'is_target' => 0]; }
    shuffle($images);

    // Save solution indices in session
    $_SESSION['captcha_solution'] = [];
    $payload = [];
    foreach ($images as $idx => $img) {
        if ($img['is_target']) { $_SESSION['captcha_solution'][] = $idx; }
        $payload[] = ['url'=>$img['url']];
    }
    $_SESSION['captcha_target'] = $targetLabel;

    echo json_encode(['success'=>true, 'target'=>$targetLabel, 'images'=>$payload]);
}

// Start registration: validate email/password, captcha, send OTP to email
function startRegistration($conn, $abstract_api_key) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $selected = isset($_POST['captcha_selected']) ? $_POST['captcha_selected'] : '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        return;
    }
    // CAPTCHA validation
    $solution = $_SESSION['captcha_solution'] ?? [];
    if (!$solution || $selected === '') {
        echo json_encode(['success'=>false,'message'=>'Captcha required']);
        return;
    }
    $selectedArr = array_filter(array_map('intval', explode(',', $selected)), function($v){ return $v>=0 && $v<9; });
    sort($selectedArr);
    $sol = $solution; sort($sol);
    if ($selectedArr !== $sol) {
        echo json_encode(['success'=>false,'message'=>'Captcha verification failed']);
        return;
    }

    // Email checks
    $email_check_result = emailExists($conn, $email, $abstract_api_key);
    if (!$email_check_result['is_valid']) {
        echo json_encode(['success' => false, 'message' => $email_check_result['message']]);
        return;
    }

    // Create OTP table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS email_verification_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        verified TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Generate 6-digit OTP and store (invalidate previous)
    $otp = strval(random_int(100000, 999999));
    $expires = date('Y-m-d H:i:s', time() + 15*60); // 15 minutes
    $stmtDel = $conn->prepare("DELETE FROM email_verification_otps WHERE email = ?");
    $stmtDel->bind_param('s', $email);
    $stmtDel->execute();
    $stmtDel->close();

    $stmtIns = $conn->prepare("INSERT INTO email_verification_otps (email, otp_code, expires_at, verified) VALUES (?, ?, ?, 0)");
    $stmtIns->bind_param('sss', $email, $otp, $expires);
    if (!$stmtIns->execute()) { echo json_encode(['success'=>false,'message'=>'Failed to initiate verification']); return; }
    $stmtIns->close();

    // Persist email/password in session until completion
    $_SESSION['reg_email'] = $email;
    $_SESSION['reg_password'] = $password;
    unset($_SESSION['email_verified']);

    if (sendOtpEmail($email, $otp)) {
        echo json_encode(['success'=>true,'message'=>'OTP has been sent to your email.']);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to send OTP email.']);
    }
}

function checkVerificationStatus($conn) {
    if (!isset($_SESSION['reg_email'])) {
        echo json_encode(['success'=>false, 'message'=>'No registration session found']);
        return;
    }
    $email = $_SESSION['reg_email'];
    $stmt = $conn->prepare("SELECT verified FROM email_verification_otps WHERE email = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($res && intval($res['verified']) === 1) { $_SESSION['email_verified'] = true; echo json_encode(['success'=>true, 'verified'=>true]); }
    else { echo json_encode(['success'=>true, 'verified'=>false]); }
}

// Removed OTP verification (replaced by token link)

function completeRegistration($conn) {
    if (!isset($_SESSION['email_verified']) || !$_SESSION['email_verified']) {
        echo json_encode(['success' => false, 'message' => 'Email verification required']);
        return;
    }
    if (!isset($_SESSION['reg_email']) || !isset($_SESSION['reg_password'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        return;
    }
    $email = $_SESSION['reg_email'];
    $password = $_SESSION['reg_password'];

    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $first_name . ' ' . $last_name; // Combine first and last name for username
    $country = $_POST['country'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $nrc_number = $_POST['nrc_number'] ?? '';

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($country) || empty($phone_number) || empty($nrc_number)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        return;
    }

    // PIN: must be provided at registration and be 4 digits
    $pin = $_POST['pin'] ?? '';
    $pin_confirm = $_POST['pin_confirm'] ?? '';
    if (!preg_match('/^\d{4}$/', $pin)) {
        echo json_encode(['success' => false, 'message' => 'PIN must be 4 digits.']);
        return;
    }
    if ($pin !== $pin_confirm) {
        echo json_encode(['success' => false, 'message' => 'PIN and confirmation do not match.']);
        return;
    }

    // Check for duplicates
    if (usernameExists($conn, $username)) {
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
        return;
    }

    if (phoneExists($conn, $phone_number)) {
        echo json_encode(['success' => false, 'message' => 'Phone number already registered']);
        return;
    }

    if (nrcExists($conn, $nrc_number)) {
        echo json_encode(['success' => false, 'message' => 'NRC number already registered']);
        return;
    }

    // Handle file uploads
    $nrc_front_photo = uploadFile('nrc_front_photo');
    $nrc_back_photo = uploadFile('nrc_back_photo');

    if (!$nrc_front_photo) {
        echo json_encode(['success' => false, 'message' => 'Error uploading NRC front photo']);
        return;
    }

    if (!$nrc_back_photo) {
        // If front upload succeeded but back failed, you might want to delete the front file here
        echo json_encode(['success' => false, 'message' => 'Error uploading NRC back photo']);
        return;
    }

    // In the completeRegistration function, update this section:
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $verified_status = 1; // Email verified
    $user_status = 1; // Account is active by default

    // Use a transaction to ensure user, wallet, and PIN creation are atomic
    $conn->begin_transaction();

    // Update the INSERT statement to include both status fields
    $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, password_hash, email, verified_status, user_status, phone_number, nrc_number, nrc_front_photo, nrc_back_photo, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssiisssss", $username, $first_name, $last_name, $password_hash, $email, $verified_status, $user_status, $phone_number, $nrc_number, $nrc_front_photo, $nrc_back_photo, $country);

    if ($stmt->execute()) {
        $new_user_id = $conn->insert_id;
        $stmt->close();

        $maxAttempts = 5;
        $addrSet = false;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $addr = generateUuidV4();
            $upd = $conn->prepare("UPDATE users SET user_walletAddress = ? WHERE user_id = ?");
            if ($upd) {
                $upd->bind_param('si', $addr, $new_user_id);
                if ($upd->execute()) {
                    $addrSet = true;
                    $upd->close();
                    break;
                }
                $upd->close();
            }
        }
        if (!$addrSet) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to set wallet address. Ensure users.user_walletAddress exists and is UNIQUE.']);
            return;
        }

        // Create wallets for MMK, USD, JPY, THB with zero balance, resolving currency_id by symbol
        $wantedSymbols = ['MMK','USD','JPY','THB'];
        $placeholders = implode(',', array_fill(0, count($wantedSymbols), '?'));
        $types = str_repeat('s', count($wantedSymbols));
        $ids = [];
        $sqlCur = "SELECT currency_id, symbol FROM currencies WHERE symbol IN ($placeholders)";
        if ($stmtCur = $conn->prepare($sqlCur)) {
            $stmtCur->bind_param($types, ...$wantedSymbols);
            if ($stmtCur->execute()) {
                $resCur = $stmtCur->get_result();
                while ($r = $resCur->fetch_assoc()) {
                    $ids[strtoupper($r['symbol'])] = (int)$r['currency_id'];
                }
            }
            $stmtCur->close();
        }
        // Verify all required symbols exist
        foreach ($wantedSymbols as $sym) {
            if (!isset($ids[$sym]) || $ids[$sym] <= 0) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Required currency not found: ' . $sym]);
                return;
            }
        }

        $stmtWallet = $conn->prepare("INSERT INTO wallets (user_id, currency_id, balance) VALUES (?, ?, 0.00)
            ON DUPLICATE KEY UPDATE balance = VALUES(balance)");
        if ($stmtWallet) {
            foreach ($wantedSymbols as $sym) {
                $currency_id = $ids[$sym];
                $stmtWallet->bind_param('ii', $new_user_id, $currency_id);
                if (!$stmtWallet->execute()) {
                    $conn->rollback();
                    error_log('Wallet creation failed (' . $sym . '): ' . $stmtWallet->error);
                    echo json_encode(['success' => false, 'message' => 'Database error while creating wallets.']);
                    $stmtWallet->close();
                    return;
                }
            }
            $stmtWallet->close();
        } else {
            // Prepare failed
            $conn->rollback();
            error_log('Prepare wallet insert failed: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error while preparing wallets.']);
            return;
        }

        // Ensure user_pins table exists
        $conn->query("CREATE TABLE IF NOT EXISTS user_pins (
            user_id INT NOT NULL PRIMARY KEY,
            pin_hash VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_pins_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Store hashed PIN for this user
        $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmtPin = $conn->prepare("INSERT INTO user_pins (user_id, pin_hash) VALUES (?, ?)");
        if ($stmtPin) {
            $stmtPin->bind_param('is', $new_user_id, $pin_hash);
            if (!$stmtPin->execute()) {
                $conn->rollback();
                error_log('Failed to save user PIN: ' . $stmtPin->error);
                echo json_encode(['success' => false, 'message' => 'Database error while saving PIN.']);
                $stmtPin->close();
                return;
            }
            $stmtPin->close();
        } else {
            $conn->rollback();
            error_log('Prepare PIN insert failed: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error while preparing PIN.']);
            return;
        }

        // Commit user, wallets, and PIN
        $conn->commit();

        // Clear only registration session keys
        unset($_SESSION['reg_email'], $_SESSION['reg_password'], $_SESSION['email_verified'], $_SESSION['captcha_solution'], $_SESSION['captcha_target']);
        echo json_encode(['success' => true, 'message' => 'Registration successful!']);
    } else {
        // Rollback user creation if failed
        $conn->rollback();
        // Log the error
        error_log("Registration DB Error: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        $stmt->close();
    }
}

// Helper functions 

/**
 * Checks if the email exists in the database OR if it's invalid according to Abstract API.
 * Returns an array with 'is_valid' (boolean) and 'message' (string).
 */
function emailExists($conn, $email, $abstract_api_key) {
    // 1. Check in local database
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        return ['is_valid' => false, 'message' => 'Email already registered'];
    }
    $stmt->close();

    // 2. Check using Abstract API for deliverability/quality
    // Only proceed if a key is provided and the email is not already in the DB
    if (!empty($abstract_api_key) && $abstract_api_key !== 'YOUR_ABSTRACT_API_KEY') {
        $api_url = "https://emailvalidation.abstractapi.com/v1/?api_key=" . urlencode($abstract_api_key) . "&email=" . urlencode($email);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('Abstract API cURL Error: ' . curl_error($ch));
            // Don't block registration if the API call fails, but log the error.
            curl_close($ch);
            return ['is_valid' => true, 'message' => ''];
        }

        curl_close($ch);
        $data = json_decode($response, true);

        // Check the Abstract API response for deliverability
        if (isset($data['deliverability']) && $data['deliverability'] === 'UNDELIVERABLE') {
            return ['is_valid' => false, 'message' => 'The email address appears to be invalid or undeliverable.'];
        }

        // Optional: Check for disposable/spam trap, etc.
        if (isset($data['is_disposable_email']['value']) && $data['is_disposable_email']['value']) {
            return ['is_valid' => false, 'message' => 'Disposable email addresses are not allowed.'];
        }
    }

    // Email is not in the DB and passed (or skipped) API checks
    return ['is_valid' => true, 'message' => ''];
}

function usernameExists($conn, $username) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function phoneExists($conn, $phone_number) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE phone_number = ?");
    $stmt->bind_param("s", $phone_number);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function nrcExists($conn, $nrc_number) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE nrc_number = ?");
    $stmt->bind_param("s", $nrc_number);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function sendOtpEmail($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'naing270104@gmail.com';
        $mail->Password = 'pwuzpusgmtkvpbfd';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('naing270104@gmail.com', 'Currency Platform');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your verification code';
        $mail->Body = "<h2>Your OTP</h2><p>Use this 6-digit code to verify your email:</p><h3 style='letter-spacing:2px'>{$otp}</h3><p>This code expires in 15 minutes.</p>";
        $mail->AltBody = "Your OTP code is: {$otp} (expires in 15 minutes)";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function uploadFile($fieldName) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    
    $target_dir = "../uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES[$fieldName]["name"], PATHINFO_EXTENSION));
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $filename;
    
    $check = getimagesize($_FILES[$fieldName]["tmp_name"]);
    if ($check === false) {
        return false;
    }
    
    if ($_FILES[$fieldName]["size"] > 5000000) {
        return false;
    }
    
    $allowed_extensions = ["jpg", "jpeg", "png", "gif", "pdf"];
    if (!in_array($file_extension, $allowed_extensions)) {
        return false;
    }
    
    if (move_uploaded_file($_FILES[$fieldName]["tmp_name"], $target_file)) {
        return $filename;
    }
    
    return false;
}

function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

$conn->close();
?>