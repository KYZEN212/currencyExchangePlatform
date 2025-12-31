<?php
session_start();

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Get the logged-in user's username from the session
$session_username = $_SESSION['username'];
$initials = strtoupper(substr($session_username, 0, 1));
$currencies = [];
$message = '';

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to safely get user ID
function getUserId($conn, $username) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        return $user['user_id'];
    }
    return null;
}

$user_id = getUserId($conn, $session_username);

// Get all currencies for the dropdown
$stmt_currencies = $conn->prepare("SELECT currency_id, symbol, name FROM currencies");
$stmt_currencies->execute();
$result_currencies = $stmt_currencies->get_result();
while ($row = $result_currencies->fetch_assoc()) {
    $currencies[] = $row;
}
$stmt_currencies->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deposit_amount'])) {
    if (!$user_id) {
        $message = "User not found.";
    } else {
        $currency_id = $_POST['deposit_currency_id'];
        $amount = $_POST['deposit_amount'];
        $user_payment_id = $_POST['user_payment_id'];
        $payment_channel = $_POST['payment_channel'] ?? null;
        $proof_screenshot_url = null;

        // Server-side validation
        $pcNorm = '';
        if ($payment_channel !== null) {
            $pcNorm = strtolower(str_replace(' ', '', trim($payment_channel)));
        }
        $allowedChannels = ['kpay','wavepay','ayapay'];
        if ($pcNorm === '' || !in_array($pcNorm, $allowedChannels, true)) {
            $message = "Please select a payment channel.";
        } else {
            $payment_channel = $pcNorm;
        }

        // Handle file upload
        if ($message === '' && isset($_FILES['proof_screenshot']) && $_FILES['proof_screenshot']['error'] == 0) {
            $target_dir = "uploads/";
            $file_name = uniqid() . '-' . basename($_FILES["proof_screenshot"]["name"]);
            $target_file = $target_dir . $file_name;
            
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            if (move_uploaded_file($_FILES["proof_screenshot"]["tmp_name"], $target_file)) {
                $proof_screenshot_url = $target_file;
            } else {
                $message = "Sorry, there was an error uploading your file.";
            }
        } elseif ($message === '') {
            $message = "Please upload a proof of payment screenshot.";
        }

        if ($message == '') {
            $stmt = $conn->prepare("INSERT INTO user_currency_requests (user_id, currency_id, amount, transaction_type, user_payment_id, proof_of_screenshot, payment_channel) VALUES (?, ?, ?, 'deposit', ?, ?, ?)");
            $stmt->bind_param('iidsss', $user_id, $currency_id, $amount, $user_payment_id, $proof_screenshot_url, $payment_channel);
            if ($stmt->execute()) {
                header("Location: dashboard.php?message=Deposit%20request%20submitted%20successfully.");
                exit();
            } else {
                $message = "Error submitting request: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Funds | ACCQURA</title>
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
            max-width: 800px;
            margin: 20px auto;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #2e7d32, #4caf50);
            padding: 20px 25px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .back-btn {
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
        
        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .nav-tabs {
            display: flex;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            padding: 0 25px;
            overflow-x: auto;
        }
        
        .nav-tab {
            padding: 12px 20px;
            text-decoration: none;
            color: #555;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
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
        }
        
        .notification {
            margin-bottom: 25px;
            padding: 15px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .notification.success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .notification.error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .deposit-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .deposit-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .deposit-header h2 {
            font-size: 1.5rem;
            color: #2d3748;
        }
        
        .deposit-header p {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .deposit-form {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #e0e0e0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .form-select, .form-input {
            width: 100%;
            padding: 12px 15px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #2d3748;
            transition: all 0.3s;
        }
        
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #4caf50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
        }
        
        .channel-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .channel-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            background: white;
            color: #2d3748;
            font-size: 0.9rem;
        }
        
        .channel-btn.kpay {
            background-color: #2563eb;
            color: white;
        }
        
        .channel-btn.wavepay {
            background-color: #f59e0b;
            color: white;
        }
        
        .channel-btn.ayapay {
            background-color: #dc2626;
            color: white;
        }
        
        .channel-btn.active {
            border-color: #4caf50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.3);
            transform: scale(0.98);
        }
        
        .channel-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .file-input-container {
            position: relative;
            overflow: hidden;
        }
        
        .file-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-label {
            display: block;
            padding: 12px 15px;
            background: white;
            border: 2px dashed #e0e0e0;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            color: #6b7280;
            transition: all 0.3s;
        }
        
        .file-input-label:hover {
            border-color: #4caf50;
            background-color: rgba(76, 175, 80, 0.05);
        }
        
        .file-input-label i {
            margin-right: 8px;
        }
        
        .file-name {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #4caf50;
            font-weight: 600;
        }
        
        .submit-btn {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .qr-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .qr-modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .qr-modal-content {
            background-color: white;
            border-radius: 16px;
            padding: 25px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px);
            transition: transform 0.3s;
        }
        
        .qr-modal.show .qr-modal-content {
            transform: translateY(0);
        }
        
        .qr-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .qr-modal-header h3 {
            color: #2d3748;
            font-size: 1.2rem;
        }
        
        .qr-modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
            padding: 5px;
        }
        
        .qr-image {
            width: 100%;
            height: auto;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            margin-bottom: 15px;
        }
        
        .qr-channel-name {
            text-align: center;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                max-width: 95%;
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
            
            .nav-tabs {
                flex-direction: column;
                padding: 0;
            }
            
            .nav-tab {
                padding: 15px 20px;
                border-bottom: 1px solid #e0e0e0;
                width: 100%;
            }
            
            .deposit-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .channel-buttons {
                flex-direction: column;
            }
            
            .channel-btn {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-container {
                max-width: 100%;
                margin: 10px;
                border-radius: 12px;
            }
            
            .dashboard-content {
                padding: 20px;
            }
            
            .deposit-form {
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
                    <span><?php echo htmlspecialchars($initials); ?></span>
                </div>
                <div class="user-details">
                    <h1><?php echo htmlspecialchars($session_username); ?></h1>
                    <p>Deposit Funds</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <div class="nav-tabs">
            <a href="dashboard.php" class="nav-tab">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
            <a href="deposit_form.php" class="nav-tab active">
                <i class="fas fa-plus-circle me-2"></i>Deposit
            </a>
            <a href="withdraw_form.php" class="nav-tab">
                <i class="fas fa-minus-circle me-2"></i>Withdraw
            </a>
        </div>
        
        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <div class="notification <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="deposit-header">
                <div class="deposit-icon">
                    <i class="fas fa-plus"></i>
                </div>
                <div>
                    <h2>Deposit Funds</h2>
                    <p>Submit a request to add funds to your wallet</p>
                </div>
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" enctype="multipart/form-data" class="deposit-form" id="depositForm">
                <div class="form-group">
                    <label for="deposit_currency_id">Currency to Deposit</label>
                    <select id="deposit_currency_id" name="deposit_currency_id" required class="form-select">
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>">
                                <?php echo htmlspecialchars($currency['name']); ?> (<?php echo htmlspecialchars($currency['symbol']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="deposit_amount">Amount</label>
                    <input type="number" id="deposit_amount" name="deposit_amount" step="0.01" min="0.01" required 
                           placeholder="Enter amount" class="form-input">
                </div>
                
                <div class="form-group">
                    <label>Payment Channel</label>
                    <div class="channel-buttons">
                        <button type="button" class="channel-btn kpay" data-channel="kpay" data-qr="../QR/kpay-qr.jpg">
                            <i class="fas fa-mobile-alt"></i> KPay
                        </button>
                        <button type="button" class="channel-btn wavepay" data-channel="wavepay" data-qr="../QR/wavepay-qr.jpg">
                            <i class="fas fa-wave-square"></i> WavePay
                        </button>
                        <button type="button" class="channel-btn ayapay" data-channel="ayapay" data-qr="../QR/ayapay-qr.jpg">
                            <i class="fas fa-university"></i> AYA Pay
                        </button>
                    </div>
                    <input type="hidden" id="payment_channel" name="payment_channel" value="">
                    <p id="channel_error" class="error-message" style="color: #dc2626; font-size: 0.85rem; margin-top: 5px; display: none;">
                        Please select a payment channel
                    </p>
                    <p class="help-text" style="color: #6b7280; font-size: 0.85rem; margin-top: 5px;">
                        Choose the channel used for your transfer
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="user_payment_id">Payment/Transaction ID</label>
                    <input type="text" id="user_payment_id" name="user_payment_id" required 
                           placeholder="Enter your transaction ID" class="form-input">
                </div>
                
                <div class="form-group">
                    <label>Proof of Payment</label>
                    <div class="file-input-container">
                        <input type="file" id="proof_screenshot" name="proof_screenshot" 
                               accept="image/*" required class="file-input">
                        <label for="proof_screenshot" class="file-input-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>Click to upload screenshot</span>
                        </label>
                    </div>
                    <div id="file_name" class="file-name"></div>
                    <p class="help-text" style="color: #6b7280; font-size: 0.85rem; margin-top: 5px;">
                        Upload a screenshot of your payment transfer (max 5MB)
                    </p>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Deposit Request
                </button>
            </form>
        </div>
    </div>
    
    <!-- QR Modal -->
    <div class="qr-modal" id="qrModal">
        <div class="qr-modal-content">
            <div class="qr-modal-header">
                <h3>Scan QR Code</h3>
                <button class="qr-modal-close" id="qrClose">&times;</button>
            </div>
            <div class="qr-channel-name" id="qrChannelName"></div>
            <img src="" alt="QR Code" class="qr-image" id="qrImage">
            <p style="text-align: center; color: #6b7280; font-size: 0.9rem;">
                Scan this QR code with your banking app
            </p>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Channel selection
            const channelButtons = document.querySelectorAll('.channel-btn');
            const channelInput = document.getElementById('payment_channel');
            const channelError = document.getElementById('channel_error');
            
            // QR modal elements
            const qrModal = document.getElementById('qrModal');
            const qrImage = document.getElementById('qrImage');
            const qrChannelName = document.getElementById('qrChannelName');
            const qrClose = document.getElementById('qrClose');
            
            // File upload display
            const fileInput = document.getElementById('proof_screenshot');
            const fileNameDisplay = document.getElementById('file_name');
            
            // Form submission
            const depositForm = document.getElementById('depositForm');
            const submitBtn = document.getElementById('submitBtn');
            
            // Set active channel
            function setActiveChannel(button) {
                channelButtons.forEach(btn => {
                    btn.classList.remove('active');
                });
                button.classList.add('active');
                channelInput.value = button.dataset.channel;
                channelError.style.display = 'none';
            }
            
            // Show QR modal
            function showQRModal(src, channelName) {
                qrImage.src = src;
                qrChannelName.textContent = channelName + ' QR Code';
                qrModal.classList.add('show');
            }
            
            // Close QR modal
            function closeQRModal() {
                qrModal.classList.remove('show');
            }
            
            // Handle channel button clicks
            channelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setActiveChannel(this);
                    
                    // Show QR code if available
                    const qrSrc = this.dataset.qr;
                    if (qrSrc) {
                        const channelName = this.textContent.trim();
                        showQRModal(qrSrc, channelName);
                    }
                });
            });
            
            // Handle file input change
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    fileNameDisplay.textContent = 'Selected: ' + this.files[0].name;
                } else {
                    fileNameDisplay.textContent = '';
                }
            });
            
            // Close QR modal when clicking X
            qrClose.addEventListener('click', closeQRModal);
            
            // Close QR modal when clicking outside
            qrModal.addEventListener('click', function(e) {
                if (e.target === qrModal) {
                    closeQRModal();
                }
            });
            
            // Close QR modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeQRModal();
                }
            });
            
            // Form validation
            depositForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Check if channel is selected
                if (!channelInput.value) {
                    channelError.style.display = 'block';
                    isValid = false;
                } else {
                    channelError.style.display = 'none';
                }
                
                // Check if file is selected
                if (!fileInput.files.length) {
                    alert('Please upload a proof of payment screenshot');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                }
            });
            
            // Enable form resubmission if user goes back
            window.addEventListener('pageshow', function() {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Deposit Request';
            });
        });
    </script>
</body>
</html>