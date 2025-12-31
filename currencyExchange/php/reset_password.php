<?php
session_start();
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$token = $_GET['token'] ?? '';
if ($token === '') { http_response_code(400); echo 'Invalid token'; exit(); }

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) { die('DB connection failed'); }
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT pr.user_id, u.email, u.username, pr.expires_at, pr.used FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE pr.token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$reset = $res->fetch_assoc();
$stmt->close();

$valid = false;
if ($reset) {
  $now = new DateTime();
  $exp = new DateTime($reset['expires_at']);
  $valid = ($reset['used'] == 0) && ($now <= $exp);
}

if (!$valid) { http_response_code(400); echo 'Reset link is invalid or expired.'; $conn->close(); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ACCQURA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inria+Sans:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inria Sans', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f0f9f0 0%, #d4edda 25%, #a8e0b8 65%, #7ac29a 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .reset-container {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
            padding: 40px 30px;
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .reset-container:hover {
            transform: translateY(-5px);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .logo h1 {
            color: #2e7d32;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .input-container {
            position: relative;
            margin-bottom: 25px;
        }
        
        .input-container i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #2e7d32;
            font-size: 18px;
            z-index: 2;
            opacity: 0.8;
        }
        
        input[type="password"] {
            width: 100%;
            padding: 14px 16px 14px 50px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            color: #2d3748;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
        }
        
        input[type="password"]::placeholder {
            color: #a0aec0;
        }
        
        input[type="password"]:focus {
            outline: none;
            background: white;
            border-color: #4caf50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
        }
        
        .reset-btn {
            width: 100%;
            padding: 14px;
            background: #4caf50;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin: 10px 0 20px;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .reset-btn:hover {
            background: #43a047;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert.error {
            background: #fde2e1;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        
        .back-to-login {
            text-align: center;
            color: #555;
            font-size: 14px;
            margin-top: 15px;
        }
        
        .back-to-login a {
            color: #2e7d32;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border-bottom: 1px dashed #2e7d32;
            padding-bottom: 1px;
        }
        
        .back-to-login a:hover {
            color: #1b5e20;
            border-bottom-color: transparent;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo">
            <h1>Reset Password</h1>
        </div>
        <?php
            $err = $_GET['error'] ?? '';
            if ($err) {
                $map = [
                    'missing' => 'Please fill in all fields.',
                    'mismatch' => 'Passwords do not match.',
                    'weak' => 'Password must be at least 8 characters.',
                    'invalid' => 'This reset link is invalid. Please request a new one.',
                    'expired' => 'This reset link has expired or was already used. Please request a new one.',
                    'server' => 'Server error while updating password. Please try again.',
                ];
                $msg = $map[$err] ?? 'An error occurred. Please try again.';
                echo '<div class="alert error"><i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>' . htmlspecialchars($msg) . '</div>';
            }
        ?>
        
        <form method="POST" action="reset_password_submit.php">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            
            <div class="form-group">
                <div class="input-container">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="New password" minlength="8" required>
                </div>
            </div>
            
            <div class="form-group">
                <div class="input-container">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password_confirm" placeholder="Confirm password" minlength="8" required>
                </div>
            </div>
            
            <button type="submit" class="reset-btn">
                <i class="fas fa-key" style="margin-right: 8px;"></i>Update Password
            </button>
        </form>
        
        <div class="back-to-login">
            Remember your password? <a href="../html/login.html">Back to Login</a>
        </div>
    </div>
</body>
</html>