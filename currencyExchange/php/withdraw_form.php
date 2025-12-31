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
$wallets = [];
$message = '';
// PIN state for current user
$pin_hash = null;

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

// Load user's PIN hash (created during registration)
if ($user_id) {
    $stmt_pin = $conn->prepare("SELECT pin_hash FROM user_pins WHERE user_id = ? LIMIT 1");
    if ($stmt_pin) {
        $stmt_pin->bind_param("i", $user_id);
        $stmt_pin->execute();
        $res = $stmt_pin->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && !empty($row['pin_hash'])) { $pin_hash = $row['pin_hash']; }
        $stmt_pin->close();
    }
}

// Get user's wallet balances for the dropdown and balance check
$stmt_wallets = $conn->prepare("
    SELECT w.currency_id, w.balance, c.symbol, c.name
    FROM wallets w
    JOIN currencies c ON w.currency_id = c.currency_id
    WHERE w.user_id = ?
");
$stmt_wallets->bind_param("i", $user_id);
$stmt_wallets->execute();
$result_wallets = $stmt_wallets->get_result();
while ($row = $result_wallets->fetch_assoc()) {
    $wallets[] = $row;
}
$stmt_wallets->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['withdraw_amount'])) {
    if (!$user_id) {
        $message = "User not found.";
    } else {
        $currency_id = $_POST['withdraw_currency_id'];
        $amount = $_POST['withdraw_amount'];
        $withdrawal_details = trim($_POST['withdrawal_details'] ?? '');

        // Require withdrawal details (server-side guard in addition to HTML required)
        if ($withdrawal_details === '') {
            $message = "Withdrawal details are required.";
        }
        
        // Require and verify 4-digit PIN before proceeding
        $entered_pin = $_POST['withdraw_pin'] ?? '';
        if (empty($pin_hash)) {
            $message = "Security PIN not set. Please contact support.";
        } elseif (!preg_match('/^\d{4}$/', $entered_pin)) {
            $message = "Please enter your 4-digit PIN.";
        } elseif (!password_verify($entered_pin, $pin_hash)) {
            $message = "Incorrect PIN.";
        }
        
        if (empty($message)) {
        
        // Check if user has sufficient balance
        $current_balance = 0;
        $current_symbol = '';
        foreach ($wallets as $wallet) {
            if ($wallet['currency_id'] == $currency_id) {
                $current_balance = $wallet['balance'];
                $current_symbol = isset($wallet['symbol']) ? strtoupper($wallet['symbol']) : '';
                break;
            }
        }

        // Currency-specific absolute minimums
        $absolute_mins = [
            'MMK' => 50000,
            'USD' => 100,
            'THB' => 1000,
            'JPY' => 1000,
        ];
        $min_required = 0.0;
        if ($current_symbol !== '' && isset($absolute_mins[$current_symbol])) {
            $min_required = (float)$absolute_mins[$current_symbol];
        }
        // Apply 2% withdrawal tax client-visible info
        $fee_rate = 0.02;
        $fee_amount = (float)$amount * $fee_rate;
        $total_deduct = (float)$amount + $fee_amount;

        if ($amount > $current_balance) {
            $message = "Insufficient balance. You only have " . number_format($current_balance, 2) . " available.";
        } elseif ($total_deduct > $current_balance) {
            $message = "Insufficient balance including 2% fee. Maximum available to withdraw is " . number_format(max(0, $current_balance / (1+$fee_rate)), 2) . ".";
        } elseif ($current_balance > 0 && $min_required > 0 && $amount < $min_required) {
            $message = "Minimum withdrawal for {$current_symbol} is " . number_format($min_required, 2) . ".";
        } else {
            // Insert withdrawal request into the database
            $stmt = $conn->prepare("INSERT INTO user_currency_requests (user_id, currency_id, amount, transaction_type, description) VALUES (?, ?, ?, 'withdrawal', ?)");
            $stmt->bind_param("iids", $user_id, $currency_id, $amount, $withdrawal_details);

            if ($stmt->execute()) {
                header("Location: dashboard.php?message=Withdrawal%20request%20submitted%20successfully.");
                exit();
            } else {
                $message = "Error submitting request: " . $stmt->error;
            }
            $stmt->close();
        }
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
    <title>Withdraw Funds | ACCQURA</title>
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
        
        .withdraw-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .withdraw-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .withdraw-header h2 {
            font-size: 1.5rem;
            color: #2d3748;
        }
        
        .withdraw-header p {
            color: #6b7280;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .withdraw-form {
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
        
        .form-select, .form-input, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #2d3748;
            transition: all 0.3s;
            resize: vertical;
        }
        
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.2);
        }
        
        .balance-info {
            background-color: rgba(220, 38, 38, 0.05);
            border-left: 4px solid #dc2626;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .balance-info p {
            color: #dc2626;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .amount-info {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .info-item {
            background-color: white;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            font-size: 0.85rem;
        }
        
        .info-item .label {
            color: #6b7280;
            font-weight: 500;
            margin-right: 5px;
        }
        
        .info-item .value {
            color: #2d3748;
            font-weight: 600;
        }
        
        .submit-btn {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #dc2626, #ef4444);
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
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .pin-modal {
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
        
        .pin-modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .pin-modal-content {
            background-color: white;
            border-radius: 16px;
            padding: 25px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px);
            transition: transform 0.3s;
        }
        
        .pin-modal.show .pin-modal-content {
            transform: translateY(0);
        }
        
        .pin-modal-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .pin-modal-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(59, 130, 246, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
            font-size: 1rem;
        }
        
        .pin-modal-header h3 {
            color: #2d3748;
            font-size: 1.2rem;
        }
        
        .pin-modal-body p {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .pin-input-container {
            margin-bottom: 20px;
        }
        
        .pin-input {
            width: 100%;
            padding: 14px;
            text-align: center;
            font-size: 1.5rem;
            letter-spacing: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            color: #2d3748;
            transition: all 0.3s;
        }
        
        .pin-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        .pin-error {
            color: #dc2626;
            font-size: 0.85rem;
            margin-top: 8px;
            text-align: center;
            display: none;
        }
        
        .pin-modal-actions {
            display: flex;
            gap: 12px;
        }
        
        .pin-modal-cancel {
            flex: 1;
            padding: 12px;
            background-color: #f3f4f6;
            color: #6b7280;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pin-modal-cancel:hover {
            background-color: #e5e7eb;
        }
        
        .pin-modal-confirm {
            flex: 1;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pin-modal-confirm:hover {
            opacity: 0.9;
            transform: translateY(-2px);
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
            
            .withdraw-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .amount-info {
                flex-direction: column;
            }
            
            .pin-modal-content {
                width: 95%;
                padding: 20px;
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
            
            .withdraw-form {
                padding: 20px;
            }
            
            .pin-input {
                font-size: 1.2rem;
                letter-spacing: 8px;
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
                    <p>Withdraw Funds</p>
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
            <a href="deposit_form.php" class="nav-tab">
                <i class="fas fa-plus-circle me-2"></i>Deposit
            </a>
            <a href="withdraw_form.php" class="nav-tab active">
                <i class="fas fa-minus-circle me-2"></i>Withdraw
            </a>
        </div>
        
        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <div class="notification <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="withdraw-header">
                <div class="withdraw-icon">
                    <i class="fas fa-minus"></i>
                </div>
                <div>
                    <h2>Withdraw Funds</h2>
                    <p>Submit a request to withdraw funds from your wallet</p>
                </div>
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="withdraw-form" id="withdrawForm">
                <div class="form-group">
                    <label for="withdraw_currency_id">Currency to Withdraw</label>
                    <select id="withdraw_currency_id" name="withdraw_currency_id" required class="form-select">
                        <?php foreach ($wallets as $wallet): ?>
                            <option 
                                value="<?php echo htmlspecialchars($wallet['currency_id']); ?>"
                                data-balance="<?php echo htmlspecialchars($wallet['balance']); ?>"
                                data-symbol="<?php echo htmlspecialchars($wallet['symbol']); ?>"
                            >
                                <?php echo htmlspecialchars($wallet['name']); ?> (<?php echo htmlspecialchars($wallet['symbol']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="balance-info" id="balanceInfo">
                    <p id="balanceText">Available Balance: Loading...</p>
                </div>
                
                <div class="form-group">
                    <label for="withdraw_amount">Amount to Withdraw</label>
                    <input type="number" id="withdraw_amount" name="withdraw_amount" step="0.01" min="0.01" required 
                           placeholder="Enter amount" class="form-input">
                    <div id="amountError" class="error-message" style="color: #dc2626; font-size: 0.85rem; margin-top: 5px; display: none;"></div>
                    
                    <div class="amount-info">
                        <div class="info-item" id="feeInfo">
                            <span class="label">Fee (2%):</span>
                            <span class="value">0.00</span>
                        </div>
                        <div class="info-item" id="totalInfo">
                            <span class="label">Total Deducted:</span>
                            <span class="value">0.00</span>
                        </div>
                        <div class="info-item" id="minInfo">
                            <span class="label">Minimum:</span>
                            <span class="value">0.00</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="withdrawal_details">Withdrawal Details</label>
                    <textarea id="withdrawal_details" name="withdrawal_details" rows="4" required 
                              placeholder="Enter bank account details, or other withdrawal information"
                              class="form-textarea"></textarea>
                    <div id="detailsError" class="error-message" style="color: #dc2626; font-size: 0.85rem; margin-top: 5px; display: none;"></div>
                    <p class="help-text" style="color: #6b7280; font-size: 0.85rem; margin-top: 5px;">
                        Please provide accurate withdrawal details (bank account etc.)
                    </p>
                </div>
                
                <input type="hidden" name="withdraw_pin" id="withdraw_pin_hidden" value="">
                
                <button type="button" class="submit-btn" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Withdrawal Request
                </button>
            </form>
        </div>
    </div>
    
    <!-- PIN Modal -->
    <div class="pin-modal" id="pinModal">
        <div class="pin-modal-content">
            <div class="pin-modal-header">
                <div class="pin-modal-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h3>Security Verification</h3>
            </div>
            <div class="pin-modal-body">
                <p>Please enter your 4-digit PIN to confirm the withdrawal request.</p>
                <div class="pin-input-container">
                    <input type="password" id="pinInput" inputmode="numeric" pattern="\d{4}" maxlength="4" 
                           minlength="4" class="pin-input" placeholder="••••">
                    <div id="pinError" class="pin-error">Please enter a valid 4-digit PIN</div>
                </div>
                <div class="pin-modal-actions">
                    <button type="button" id="cancelPinBtn" class="pin-modal-cancel">Cancel</button>
                    <button type="button" id="confirmPinBtn" class="pin-modal-confirm">Confirm Withdrawal</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form elements
            const withdrawForm = document.getElementById('withdrawForm');
            const currencySelect = document.getElementById('withdraw_currency_id');
            const amountInput = document.getElementById('withdraw_amount');
            const detailsInput = document.getElementById('withdrawal_details');
            const submitBtn = document.getElementById('submitBtn');
            const balanceInfo = document.getElementById('balanceInfo');
            const balanceText = document.getElementById('balanceText');
            const feeInfo = document.getElementById('feeInfo');
            const totalInfo = document.getElementById('totalInfo');
            const minInfo = document.getElementById('minInfo');
            const amountError = document.getElementById('amountError');
            const detailsError = document.getElementById('detailsError');
            const pinHidden = document.getElementById('withdraw_pin_hidden');
            
            // PIN modal elements
            const pinModal = document.getElementById('pinModal');
            const pinInput = document.getElementById('pinInput');
            const pinError = document.getElementById('pinError');
            const cancelPinBtn = document.getElementById('cancelPinBtn');
            const confirmPinBtn = document.getElementById('confirmPinBtn');
            
            // Currency-specific minimums
            const MIN_WITHDRAWALS = {
                'MMK': 50000,
                'USD': 100,
                'THB': 1000,
                'JPY': 1000
            };
            
            // Update balance display
            function updateBalanceDisplay() {
                const selectedOption = currencySelect.options[currencySelect.selectedIndex];
                const symbol = selectedOption.getAttribute('data-symbol') || '';
                const balance = parseFloat(selectedOption.getAttribute('data-balance') || '0');
                
                balanceText.textContent = `Available Balance: ${balance.toFixed(2)} ${symbol}`;
                
                // Update minimum info
                const minAmount = MIN_WITHDRAWALS[symbol] || 0;
                if (minAmount > 0) {
                    minInfo.querySelector('.value').textContent = `${minAmount.toFixed(2)} ${symbol}`;
                    minInfo.style.display = 'flex';
                    amountInput.min = minAmount;
                    amountInput.placeholder = `Minimum: ${minAmount.toFixed(2)}`;
                } else {
                    minInfo.style.display = 'none';
                    amountInput.placeholder = 'Enter amount';
                }
                
                // Update amount info
                updateAmountInfo();
            }
            
            // Update fee and total info
            function updateAmountInfo() {
                const selectedOption = currencySelect.options[currencySelect.selectedIndex];
                const symbol = selectedOption.getAttribute('data-symbol') || '';
                const amount = parseFloat(amountInput.value) || 0;
                const feeRate = 0.02;
                const fee = amount * feeRate;
                const total = amount + fee;
                
                feeInfo.querySelector('.value').textContent = `${fee.toFixed(2)} ${symbol}`;
                totalInfo.querySelector('.value').textContent = `${total.toFixed(2)} ${symbol}`;
                
                // Show/hide error if any
                validateAmount();
            }
            
            // Validate amount
            function validateAmount() {
                const selectedOption = currencySelect.options[currencySelect.selectedIndex];
                const symbol = selectedOption.getAttribute('data-symbol') || '';
                const balance = parseFloat(selectedOption.getAttribute('data-balance') || '0');
                const amount = parseFloat(amountInput.value) || 0;
                const minAmount = MIN_WITHDRAWALS[symbol] || 0;
                const feeRate = 0.02;
                const fee = amount * feeRate;
                const total = amount + fee;
                
                let error = '';
                
                if (amount <= 0) {
                    error = 'Please enter a valid amount';
                } else if (minAmount > 0 && amount < minAmount) {
                    error = `Minimum withdrawal for ${symbol} is ${minAmount.toFixed(2)}`;
                } else if (total > balance) {
                    const maxAmount = balance / (1 + feeRate);
                    error = `Insufficient balance including 2% fee. Maximum: ${maxAmount.toFixed(2)} ${symbol}`;
                }
                
                if (error) {
                    amountError.textContent = error;
                    amountError.style.display = 'block';
                    return false;
                } else {
                    amountError.style.display = 'none';
                    return true;
                }
            }
            
            // Validate details
            function validateDetails() {
                const details = detailsInput.value.trim();
                
                if (!details) {
                    detailsError.textContent = 'Please provide withdrawal details';
                    detailsError.style.display = 'block';
                    return false;
                } else if (details.length < 10) {
                    detailsError.textContent = 'Please provide more detailed withdrawal information';
                    detailsError.style.display = 'block';
                    return false;
                } else {
                    detailsError.style.display = 'none';
                    return true;
                }
            }
            
            // Show PIN modal
            function showPinModal() {
                pinModal.classList.add('show');
                pinInput.value = '';
                pinError.style.display = 'none';
                setTimeout(() => pinInput.focus(), 100);
            }
            
            // Hide PIN modal
            function hidePinModal() {
                pinModal.classList.remove('show');
            }
            
            // Validate PIN
            function validatePin() {
                const pin = pinInput.value.trim();
                
                if (!/^\d{4}$/.test(pin)) {
                    pinError.textContent = 'Please enter a valid 4-digit PIN';
                    pinError.style.display = 'block';
                    return false;
                } else {
                    pinError.style.display = 'none';
                    return true;
                }
            }
            
            // Event listeners
            currencySelect.addEventListener('change', updateBalanceDisplay);
            amountInput.addEventListener('input', updateAmountInfo);
            amountInput.addEventListener('blur', validateAmount);
            detailsInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    detailsError.style.display = 'none';
                }
            });
            
            submitBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Validate all fields
                const isAmountValid = validateAmount();
                const isDetailsValid = validateDetails();
                
                if (isAmountValid && isDetailsValid) {
                    showPinModal();
                } else {
                    // Scroll to first error
                    if (!isAmountValid) {
                        amountInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else if (!isDetailsValid) {
                        detailsInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
            
            cancelPinBtn.addEventListener('click', hidePinModal);
            
            confirmPinBtn.addEventListener('click', function() {
                if (validatePin()) {
                    pinHidden.value = pinInput.value;
                    hidePinModal();
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    withdrawForm.submit();
                }
            });
            
            pinInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    confirmPinBtn.click();
                }
            });
            
            // Initialize
            updateBalanceDisplay();
        });
    </script>
</body>
</html>