<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Database configuration
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user data
$userid = $_SESSION['user_id'];
$session_username = $_SESSION['username'];
$session_userimage = $_SESSION['userimage'] ?? '';
$initials = strtoupper(substr($session_username, 0, 1));

// Get user data from database
$sql = "SELECT username, user_walletAddress, email, phone_number, nrc_number FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows <= 0) {
    die("User not found.");
}

$row = $result->fetch_assoc();
$username = $row['username'];
$walletAddress = $row['user_walletAddress'] ?? '';
$email = $row['email'] ?? '';
$phoneNumber = $row['phone_number'] ?? '';
$nrcNumber = $row['nrc_number'] ?? '';

// Derive image path from session
$image_path = '';
if (!empty($session_userimage)) {
    $upload_dir = __DIR__ . '/uploads/';
    if (file_exists($upload_dir . $session_userimage)) {
        $image_path = 'uploads/' . $session_userimage;
    } else {
        $base = pathinfo($session_userimage, PATHINFO_FILENAME);
        foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
            if (file_exists($upload_dir . $base . '.' . $ext)) {
                $image_path = 'uploads/' . $base . '.' . $ext;
                break;
            }
        }
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | ACCQURA</title>
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
            color: #2d3748;
        }
        
        .dashboard-container {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 1100px;
            min-height: 800px;
            margin: 20px auto;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #2e7d32, #4caf50);
            padding: 20px 25px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-avatar span {
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
        }
        
        .user-details h1 {
            font-size: 1.3rem;
            margin-bottom: 5px;
        }
        
        .user-details p {
            opacity: 0.9;
            font-size: 0.85rem;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
        }
        
        /* Mobile Menu Toggle Button */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
        }
        
        .nav-tabs {
            display: flex;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            padding: 0 25px;
            flex-shrink: 0;
            overflow-x: auto;
            position: relative;
        }
        
        .nav-tab {
            padding: 12px 20px;
            text-decoration: none;
            color: #555;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
            position: relative;
            margin: 0 5px;
        }

        .nav-tab i {
            margin-right: 10px;
        }
        
        .nav-tab:hover {
            color: #2e7d32;
            background-color: rgba(46, 125, 50, 0.05);
        }
        
        .nav-tab.active {
            color: #2e7d32;
            border-bottom-color: #2e7d32;
        }
        
        .dashboard-content {
            padding: 25px;
            flex: 1;
            overflow-y: auto;
        }
        
        /* Profile-specific styles */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 25px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .profile-header {
            text-align: center;
        
        }
        
        .profile-title {
            font-size: 1.5rem;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .profile-subtitle {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .profile-section {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e0e0;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #2d3748;
        }
        
        .section-title i {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #f0f9f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2e7d32;
            font-size: 0.9rem;
        }
        
        .profile-avatar-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .profile-avatar-wrapper {
            position: relative;
            width: 150px;
            height: 150px;
        }
        
        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #e0e0e0;
        }
        
        .avatar-fallback {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 700;
            border: 4px solid #e0e0e0;
        }
        
        .avatar-edit {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background-color: white;
            color: #2e7d32;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }
        
        .avatar-edit:hover {
            background-color: #2e7d32;
            color: white;
            transform: scale(1.1);
        }
        
        .info-group {
            margin-bottom: 20px;
        }
        
        .info-label {
            display: block;
            margin-bottom: 6px;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .info-value {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-input {
            width: 100%;
            padding: 10px 12px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #2d3748;
            transition: all 0.3s;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        
        .info-input:focus {
            outline: none;
            border-color: #4caf50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
        }
        
        .info-input.readonly {
            background-color: #f8f9fa;
            color: #6b7280;
            cursor: not-allowed;
        }
        
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: #4caf50;
            color: white;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
        }
        
        .btn-primary:hover {
            background: #43a047;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #555c68;
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #4caf50;
            color: #4caf50;
        }
        
        .btn-outline:hover {
            background: #4caf50;
            color: white;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 6px 15px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        
        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .refresh-btn {
            background-color: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        
        .refresh-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            animation: modalFadeIn 0.3s ease-in;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background-color: white;
            margin: 8% auto;
            padding: 2rem;
            border-radius: 1.5rem;
            width: 90%;
            max-width: 450px;
            position: relative;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalSlideDown 0.4s ease-out;
        }
        
        @keyframes modalSlideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .close-btn {
            color: #0c0c0c;
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .close-btn:hover {
            color: #718096;
        }
        
        .modal-content h3 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: #2d3748;
            font-size: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2d3748;
            font-weight: 600;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #E2E8F0;
            border-radius: 0.5rem;
            box-sizing: border-box;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #4caf50;
        }
        
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 600;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .dashboard-container {
                max-width: 95%;
                min-height: 700px;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px 20px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .nav-tabs {
                flex-direction: column;
                position: absolute;
                top: 60px;
                left: 0;
                right: 0;
                background-color: #f8f9fa;
                border-bottom: 1px solid #e0e0e0;
                padding: 0;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
                z-index: 100;
            }
            
            .nav-tabs.show {
                max-height: 400px;
                overflow-y: auto;
            }
            
            .nav-tab {
                padding: 15px 20px;
                border-bottom: 1px solid #e0e0e0;
                margin: 0;
                width: 100%;
            }
            
            .nav-tab:last-child {
                border-bottom: none;
            }
            
            .dashboard-content {
                padding: 20px;
            }
            
            .profile-avatar-wrapper {
                width: 120px;
                height: 120px;
            }
            
            .avatar-fallback {
                font-size: 2.5rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-container {
                max-width: 100%;
                margin: 10px;
                border-radius: 12px;
            }
            
            .dashboard-header {
                padding: 15px;
            }
            
            .user-details h1 {
                font-size: 1.1rem;
            }
            
            .profile-avatar-wrapper {
                width: 100px;
                height: 100px;
            }
            
            .avatar-fallback {
                font-size: 2rem;
            }
            
            .profile-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="user-info">
                <div class="user-avatar">
                    <?php if (!empty($image_path)): ?>
                        <img src="<?php echo htmlspecialchars($image_path); ?>" alt="User">
                    <?php else: ?>
                        <span><?php echo htmlspecialchars($initials); ?></span>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <h1>Welcome, <?php echo htmlspecialchars($session_username); ?>!</h1>
                    <p>Your Profile Settings</p>
                </div>
            </div>
            <div class="header-actions">
                <button class="refresh-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
        
        <div class="nav-tabs" id="navTabs">
            <a href="dashboard.php" class="nav-tab">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
            <a href="dashboard.php?action=notifications" class="nav-tab">
                <i class="fas fa-bell me-2"></i>Notifications
            </a>
            <a href="p2pTradeList.php" class="nav-tab">
                <i class="fas fa-exchange-alt me-2"></i>P2P Trade
            </a>
            <a href="p2pTransactionHistory.php" class="nav-tab">
                <i class="fas fa-history me-2"></i>P2P History
            </a>
            <a href="profile.php" class="nav-tab active">
                <i class="fas fa-user me-2"></i>Profile
            </a>
        </div>
        
        <div class="dashboard-content">
            <div class="profile-content">
                <div class="profile-header">
                    <h1 class="profile-title">Profile Settings</h1>
                   
                </div>
                
                <div class="profile-section">
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i>
                        <h3>Profile Picture</h3>
                    </div>
                    
                    <div class="profile-avatar-container">
                        <div class="profile-avatar-wrapper">
                            <?php if (!empty($image_path)): ?>
                                <img id="profileImage" class="profile-avatar" src="<?php echo htmlspecialchars($image_path); ?>" alt="Profile Image">
                                <div id="profileFallback" class="avatar-fallback" style="display: none;">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php else: ?>
                                <img id="profileImage" class="profile-avatar" src="" alt="Profile Image" style="display: none;">
                                <div id="profileFallback" class="avatar-fallback">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                            <div class="avatar-edit" id="editImageBtn">
                                <i class="fas fa-camera"></i>
                            </div>
                        </div>
                        <input type="file" id="imageInput" name="userimage" style="display:none" accept="image/*">
                        <p style="color: #6b7280; font-size: 0.85rem; text-align: center;">
                            Click the camera icon to upload a new profile picture
                        </p>
                    </div>
                </div>
                
                <!-- In the HTML section, replace the Account Information section with this: -->
<div class="profile-section">
    <div class="section-title">
        <i class="fas fa-user-edit"></i>
        <h3>Account Information</h3>
    </div>
    
    <div class="info-group">
        <label class="info-label">Username</label>
        <div class="info-value">
            <input type="text" id="usernameInput" class="info-input readonly" 
                   value="<?php echo htmlspecialchars($username); ?>" readonly>
            <button class="btn btn-outline btn-small" id="editUsernameBtn">
                <i class="fas fa-pen"></i> Edit
            </button>
        </div>
    </div>
    
    <div class="info-group">
        <label class="info-label">Email Address</label>
        <div class="info-value">
            <input type="email" id="emailInput" class="info-input readonly" 
                   value="<?php echo htmlspecialchars($email); ?>" readonly>
            <button class="btn btn-outline btn-small" id="editEmailBtn" style="display: none;">
                <i class="fas fa-pen"></i> Edit
            </button>
        </div>
    </div>
    
    <div class="info-group">
        <label class="info-label">Phone Number</label>
        <div class="info-value">
            <input type="tel" id="phoneInput" class="info-input readonly" 
                   value="<?php echo htmlspecialchars($phoneNumber); ?>" readonly>
            <button class="btn btn-outline btn-small" id="editPhoneBtn" style="display: none;">
                <i class="fas fa-pen"></i> Edit
            </button>
        </div>
    </div>
    
    <div class="info-group">
        <label class="info-label">NRC Number</label>
        <div class="info-value">
            <input type="text" id="nrcInput" class="info-input readonly" 
                   value="<?php echo htmlspecialchars($nrcNumber); ?>" readonly>
            <button class="btn btn-outline btn-small" id="editNrcBtn" style="display: none;">
                <i class="fas fa-pen"></i> Edit
            </button>
        </div>
    </div>
    
    <div class="info-group">
        <label class="info-label">Wallet Address</label>
        <div class="info-value">
            <input type="text" id="walletAddressInput" class="info-input readonly" 
                   value="<?php echo htmlspecialchars($walletAddress); ?>" readonly>
            <button class="btn btn-primary btn-small" id="copyWalletBtn">
                <i class="fas fa-copy"></i> Copy
            </button>
        </div>
    </div>
</div>
                
                <div class="profile-section">
                    <div class="section-title">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Security</h3>
                    </div>
                    
                    <div class="btn-group">
                        <button class="btn btn-primary" id="changePasswordBtn">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                        <button class="btn btn-secondary" id="resetPinBtn">
                         <i class="fas fa-lock"></i> Reset PIN
                       </button>
                        <button class="btn btn-outline" id="loginHistoryBtn">
                            <i class="fas fa-history"></i> Login History
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Login History Modal -->
    <div id="loginHistoryModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="closeLoginHistory">&times;</span>
            <h3>Login History</h3>
            <div id="loginHistoryBody" style="max-height: 360px; overflow:auto; text-align:left;">
                <p style="color:#718096;">Loading...</p>
            </div>
        </div>
    </div>
    
    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="closePassword">&times;</span>
            <h3>Change Password</h3>
            <form id="changePasswordForm" method="POST">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" name="current_password" id="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-key me-2"></i>Change Password
                </button>
                <p id="passwordMessage" style="margin-top: 15px; text-align: center;"></p>
            </form>
        </div>
    </div>
    <!-- PIN Reset Request Modal -->
<div id="pinResetModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" id="closePinReset">&times;</span>
        <h3>Reset Security PIN</h3>
        <form id="pinResetForm" method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" 
                       value="<?php echo htmlspecialchars($row['email'] ?? ''); ?>" 
                       placeholder="Enter your email" required>
                <p style="color:#718096; font-size:0.85rem; margin-top:5px;">
                    We'll email you a secure link to set a new 4-digit PIN.
                </p>
            </div>
            <div class="btn-group" style="flex-direction: column; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="flex: 1; display: flex; align-items: center; justify-content: center;">
    <i class="fas fa-paper-plane me-2"></i>Send PIN Reset Link
</button>
                <button type="button" class="btn btn-secondary" id="cancelPinReset" style="flex: 1; display: flex; align-items: center; justify-content: center;" >
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
            </div>
            <p id="pinResetMessage" style="margin-top: 15px; text-align: center;"></p>
        </form>
    </div>
</div>
    
    <!-- Change Username Modal -->
    <div id="changeUsernameModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="closeUsername">&times;</span>
            <h3>Change Username</h3>
            <form id="changeUsernameForm" method="POST">
                <div class="form-group">
                    <label for="new_username">New Username</label>
                    <input type="text" name="new_username" id="new_username" required minlength="3" maxlength="20" placeholder="Enter new username">
                    <small style="color:#718096;">3-20 characters.</small>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-user-edit me-2"></i>Update Username
                </button>
                <p id="usernameMessage" style="margin-top: 15px; text-align: center;"></p>
            </form>
        </div>
    </div>
    
    <script>
        // Mobile Menu Functions
        function toggleMobileMenu() {
            const navTabs = document.getElementById('navTabs');
            navTabs.classList.toggle('show');
        }
        
        function hideMobileMenu() {
            const navTabs = document.getElementById('navTabs');
            navTabs.classList.remove('show');
        }
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const navTabs = document.getElementById('navTabs');
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            
            if (navTabs && mobileMenuToggle && !navTabs.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                navTabs.classList.remove('show');
            }
        });
        
        // Profile Image Upload
        document.getElementById("editImageBtn").onclick = () => {
            document.getElementById("imageInput").click();
        };
        
        document.getElementById("imageInput").onchange = function() {
            const file = this.files[0];
            if (!file) return;
            
            // Validate file size (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert("File size should be less than 5MB");
                return;
            }
            
            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert("Please upload a valid image file (JPEG, PNG, GIF, WebP)");
                return;
            }
            
            const formData = new FormData();
            formData.append("userimage", file);
            
            // Show loading state
            const editBtn = document.getElementById("editImageBtn");
            const originalHTML = editBtn.innerHTML;
            editBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            editBtn.style.pointerEvents = 'none';
            
            fetch("edit_image.php", {
                method: "POST",
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update profile image
                    const imgEl = document.getElementById("profileImage");
                    const fallbackEl = document.getElementById("profileFallback");
                    
                    if (data.newImagePath) {
                        imgEl.src = data.newImagePath + "?t=" + new Date().getTime();
                        imgEl.style.display = "block";
                        if (fallbackEl) fallbackEl.style.display = "none";
                        
                        // Also update the small avatar in header
                        const headerAvatar = document.querySelector('.user-avatar img');
                        if (headerAvatar) {
                            headerAvatar.src = data.newImagePath + "?t=" + new Date().getTime();
                        }
                        
                        // Show success message
                        showNotification("Profile picture updated successfully!", "success");
                    }
                } else {
                    showNotification(data.message || "Upload failed. Please try again.", "error");
                }
            })
            .catch(() => {
                showNotification("An error occurred. Please try again.", "error");
            })
            .finally(() => {
                // Reset button state
                editBtn.innerHTML = originalHTML;
                editBtn.style.pointerEvents = 'auto';
                // Reset file input
                this.value = '';
            });
        };
        
        // Password modal
        const passwordModal = document.getElementById("changePasswordModal");
        document.getElementById("changePasswordBtn").onclick = () => passwordModal.style.display = "block";
        document.getElementById("closePassword").onclick = () => {
            passwordModal.style.display = "none";
            document.getElementById("passwordMessage").textContent = "";
            document.getElementById("changePasswordForm").reset();
        };
        
        // Username modal
        const usernameModal = document.getElementById("changeUsernameModal");
        document.getElementById("editUsernameBtn").onclick = () => {
            const currentUsername = document.getElementById("usernameInput").value;
            document.getElementById("new_username").value = currentUsername;
            usernameModal.style.display = "block";
        };
        document.getElementById("closeUsername").onclick = () => {
            usernameModal.style.display = "none";
            document.getElementById("usernameMessage").textContent = "";
            document.getElementById("changeUsernameForm").reset();
        };
        
        // Close modals when clicking outside
        window.onclick = (event) => {
            if (event.target == passwordModal) {
                passwordModal.style.display = "none";
                document.getElementById("passwordMessage").textContent = "";
                document.getElementById("changePasswordForm").reset();
            }
            if (event.target == usernameModal) {
                usernameModal.style.display = "none";
                document.getElementById("usernameMessage").textContent = "";
                document.getElementById("changeUsernameForm").reset();
            }
            if (event.target == loginHistoryModal) {
                loginHistoryModal.style.display = 'none';
            }
        };
        
        // AJAX password change
        const changePasswordForm = document.getElementById('changePasswordForm');
        const passwordMessage = document.getElementById('passwordMessage');
        
        changePasswordForm.addEventListener('submit', function(e){
            e.preventDefault();
            
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Basic validation
            if (newPassword.length < 6) {
                passwordMessage.textContent = "Password must be at least 6 characters long.";
                passwordMessage.style.color = "red";
                return;
            }
            
            if (newPassword !== confirmPassword) {
                passwordMessage.textContent = "Passwords do not match.";
                passwordMessage.style.color = "red";
                return;
            }
            
            const formData = new FormData(this);
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
            submitBtn.disabled = true;
            
            fetch('change_password.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                const isSuccess = data.toLowerCase().includes("success");
                passwordMessage.textContent = data;
                passwordMessage.style.color = isSuccess ? "#2e7d32" : "#ef4444";
                
                if (isSuccess) {
                    this.reset();
                    setTimeout(() => {
                        passwordModal.style.display = "none";
                        passwordMessage.textContent = "";
                        showNotification("Password changed successfully!", "success");
                    }, 1500);
                }
            })
            .catch(() => {
                passwordMessage.textContent = "An error occurred. Please try again.";
                passwordMessage.style.color = "#ef4444";
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            });
        });
        
        // AJAX username change
        const changeUsernameForm = document.getElementById('changeUsernameForm');
        const usernameMessage = document.getElementById('usernameMessage');
        
        changeUsernameForm.addEventListener('submit', function(e){
            e.preventDefault();
            
            const newUsername = document.getElementById('new_username').value.trim();
            
            if (newUsername.length < 3 || newUsername.length > 20) {
                usernameMessage.textContent = "Username must be between 3 and 20 characters.";
                usernameMessage.style.color = "#ef4444";
                return;
            }
            
            const formData = new FormData(this);
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
            
            fetch('change_username.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                const isSuccess = !!data.success;
                usernameMessage.textContent = data.message || (isSuccess ? 'Username updated.' : 'Update failed.');
                usernameMessage.style.color = isSuccess ? "#2e7d32" : "#ef4444";
                
                if (isSuccess && data.new_username) {
                    // Update displayed username
                    document.getElementById("usernameInput").value = data.new_username;
                    
                    // Update header username
                    const headerUsername = document.querySelector('.user-details h1');
                    if (headerUsername) {
                        headerUsername.textContent = "Welcome, " + data.new_username + "!";
                    }
                    
                    setTimeout(() => {
                        usernameModal.style.display = "none";
                        usernameMessage.textContent = "";
                        this.reset();
                        showNotification("Username updated successfully!", "success");
                    }, 1500);
                }
            })
            .catch(() => {
                usernameMessage.textContent = "An error occurred. Please try again.";
                usernameMessage.style.color = "#ef4444";
            })
            .finally(() => {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            });
        });
        
        // Login History modal
        const loginHistoryModal = document.getElementById('loginHistoryModal');
        const closeLoginHistory = document.getElementById('closeLoginHistory');
        const loginHistoryBtn = document.getElementById('loginHistoryBtn');
        const loginHistoryBody = document.getElementById('loginHistoryBody');
        
        // Helper to format date
        function formatLoginAt(ts) {
            if (!ts) return '';
            try {
                const d = new Date(String(ts).replace(' ', 'T'));
                if (isNaN(d.getTime())) return ts;
                const dd = String(d.getDate()).padStart(2, '0');
                const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                const mon = months[d.getMonth()];
                const yyyy = d.getFullYear();
                const hh = String(d.getHours()).padStart(2, '0');
                const mm = String(d.getMinutes()).padStart(2, '0');
                return `${dd} ${mon} ${yyyy}, ${hh}:${mm}`;
            } catch (_) { return ts; }
        }
        
        closeLoginHistory.onclick = () => { loginHistoryModal.style.display = 'none'; };
        
        loginHistoryBtn.onclick = () => {
            loginHistoryModal.style.display = 'block';
            loginHistoryBody.innerHTML = '<p style="color:#718096; text-align: center;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
            
            fetch('get_login_history.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
                    loginHistoryBody.innerHTML = '<p style="color:#718096; text-align: center;">No login history found.</p>';
                    return;
                }
                
                const rows = data.items.map(ev => `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px; color: #2d3748; font-size: 0.9rem;">${formatLoginAt(ev.login_at)}</td>
                        <td style="padding: 12px; color: #2d3748; font-size: 0.9rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">${ev.ip_address || 'N/A'}</td>
                        <td style="padding: 12px; color: #2d3748; font-size: 0.9rem;">${ev.browser_name || 'N/A'}</td>
                    </tr>
                `).join('');
                
                loginHistoryBody.innerHTML = `
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background-color: #f8f9fa;">
                                    <th style="padding: 12px; text-align: left; color: #2d3748; font-weight: 600; font-size: 0.9rem;">Login Time</th>
                                    <th style="padding: 12px; text-align: left; color: #2d3748; font-weight: 600; font-size: 0.9rem;">IP Address</th>
                                    <th style="padding: 12px; text-align: left; color: #2d3748; font-weight: 600; font-size: 0.9rem;">Browser</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>`;
            })
            .catch(() => {
                loginHistoryBody.innerHTML = '<p style="color: #ef4444; text-align: center;">Failed to load login history.</p>';
            });
        };
        
        // Copy wallet address
        const copyWalletBtn = document.getElementById('copyWalletBtn');
        if (copyWalletBtn) {
            copyWalletBtn.onclick = () => {
                const inp = document.getElementById('walletAddressInput');
                const val = inp.value.trim();
                
                if (!val) {
                    showNotification("No wallet address to copy.", "error");
                    return;
                }
                
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(val)
                        .then(() => showNotification("Wallet address copied to clipboard!", "success"))
                        .catch(() => showNotification("Failed to copy to clipboard.", "error"));
                } else {
                    // Fallback for older browsers
                    inp.focus();
                    inp.select();
                    try {
                        const successful = document.execCommand('copy');
                        if (successful) {
                            showNotification("Wallet address copied to clipboard!", "success");
                        } else {
                            showNotification("Failed to copy to clipboard.", "error");
                        }
                    } catch (err) {
                        showNotification("Failed to copy to clipboard.", "error");
                    }
                    inp.setSelectionRange(inp.value.length, inp.value.length);
                }
            };
        }
        
        // Notification function (matching dashboard style)
        function showNotification(message, type = "info") {
            const notification = document.createElement('div');
            notification.className = `notification ${type} show`;
            notification.innerHTML = `<strong>${type === 'success' ? 'Success!' : type === 'error' ? 'Error!' : 'Info!'}</strong><br>${message}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 15px 25px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
                z-index: 1000;
                font-size: 14px;
                font-weight: 600;
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                color: white;
                max-width: 90%;
                text-align: center;
            `;
            
            if (type === 'success') {
                notification.style.background = 'rgba(16, 185, 129, 0.9)';
            } else if (type === 'error') {
                notification.style.background = 'rgba(239, 68, 68, 0.9)';
            } else {
                notification.style.background = 'rgba(59, 130, 246, 0.9)';
            }
            
            document.body.appendChild(notification);
            
            // Trigger animation
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateX(-50%) translateY(10px)';
            }, 10);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(-50%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 500);
            }, 5000);
        }
        
        // Add back button functionality to header refresh button
        document.querySelector('.refresh-btn').addEventListener('click', function(e) {
            if (e.ctrlKey || e.metaKey) {
                // If control/command is pressed, open in new tab
                window.open('dashboard.php', '_blank');
            } else {
                // Normal click, go back to dashboard
                window.location.href = 'dashboard.php';
            }
        });

        // PIN Reset modal
const pinResetModal = document.getElementById('pinResetModal');
const closePinReset = document.getElementById('closePinReset');
const resetPinBtn = document.getElementById('resetPinBtn');
const cancelPinReset = document.getElementById('cancelPinReset');
const pinResetForm = document.getElementById('pinResetForm');
const pinResetMessage = document.getElementById('pinResetMessage');

// Open PIN reset modal
if (resetPinBtn) {
    resetPinBtn.onclick = () => {
        pinResetModal.style.display = 'block';
    };
}

// Close PIN reset modal
if (closePinReset) {
    closePinReset.onclick = () => {
        pinResetModal.style.display = 'none';
        pinResetMessage.textContent = '';
        pinResetForm.reset();
    };
}

if (cancelPinReset) {
    cancelPinReset.onclick = () => {
        pinResetModal.style.display = 'none';
        pinResetMessage.textContent = '';
        pinResetForm.reset();
    };
}

// Update the existing window.onclick function to include pinResetModal
// Find this function and add the new condition:
window.onclick = (event) => {
    if (event.target == passwordModal) {
        passwordModal.style.display = "none";
        document.getElementById("passwordMessage").textContent = "";
        document.getElementById("changePasswordForm").reset();
    }
    if (event.target == usernameModal) {
        usernameModal.style.display = "none";
        document.getElementById("usernameMessage").textContent = "";
        document.getElementById("changeUsernameForm").reset();
    }
    if (event.target == loginHistoryModal) {
        loginHistoryModal.style.display = 'none';
    }
    // Add this new condition:
    if (event.target == pinResetModal) {
        pinResetModal.style.display = 'none';
        pinResetMessage.textContent = '';
        pinResetForm.reset();
    }
};

// Handle PIN reset form submission
if (pinResetForm) {
    pinResetForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        submitBtn.disabled = true;
        
        // Clear previous message
        pinResetMessage.textContent = '';
        
        // Send AJAX request
        fetch('pin_reset_send.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Check if response looks successful
            const isSuccess = data.toLowerCase().includes('success')  
                             data.toLowerCase().includes('sent') 
                             data.toLowerCase().includes('email');
            
            pinResetMessage.textContent = data;
            pinResetMessage.style.color = isSuccess ? "#2e7d32" : "#ef4444";
            
            if (isSuccess) {
                setTimeout(() => {
                    pinResetModal.style.display = "none";
                    pinResetMessage.textContent = "";
                    pinResetForm.reset();
                    showNotification("PIN reset link sent to your email!", "success");
                }, 2000);
            }
        })
        .catch(error => {
            pinResetMessage.textContent = "An error occurred. Please try again.";
            pinResetMessage.style.color = "#ef4444";
            console.error('Error:', error);
        })
        .finally(() => {
            submitBtn.innerHTML = originalBtnText;
            submitBtn.disabled = false;
        });
    });
}
    </script>
</body>
</html>