<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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
$session_userimage = $_SESSION['userimage'] ?? '';
$initials = strtoupper(substr($session_username, 0, 1));
$transactions = [];

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is banned
require_once 'check_user_ban.php';

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

// Detect a timestamp/datetime column
$history_time_col = null;
try {
    $stmtCol = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'p2p_trade_history' AND DATA_TYPE IN ('timestamp','datetime') ORDER BY ORDINAL_POSITION");
    $stmtCol->bind_param("s", $dbname);
    $stmtCol->execute();
    $colRes = $stmtCol->get_result();
    if ($col = $colRes->fetch_assoc()) {
        $history_time_col = $col['COLUMN_NAME'];
    }
    $stmtCol->close();
} catch (Exception $e) {}

if ($history_time_col) {
    $timeSelect = "h.`$history_time_col` AS fill_timestamp";
    $orderBy    = "ORDER BY h.`$history_time_col` DESC";
} else {
    $timeSelect = "t.timestamp AS fill_timestamp";
    $orderBy    = "ORDER BY t.timestamp DESC";
}

try {
    // Fetch the user's P2P transaction history
    $sql = "
        SELECT 
            h.amount_bought,
            h.amount_sold,
            h.exchange_rate,
            $timeSelect,
            t.trade_type AS trade_type,
            h.buyer_user_id,
            h.seller_user_id,
            b_u.username AS buyer_username,
            s_u.username AS seller_username,
            bc.symbol AS buy_currency_symbol,
            sc.symbol AS sell_currency_symbol
        FROM p2p_trade_history h
        JOIN p2p_trades t ON h.trade_id = t.trade_id
        JOIN users b_u ON h.buyer_user_id = b_u.user_id
        JOIN users s_u ON h.seller_user_id = s_u.user_id
        JOIN currencies bc ON h.buy_currency_id = bc.currency_id
        JOIN currencies sc ON h.sell_currency_id = sc.currency_id
        WHERE (h.buyer_user_id = ? OR h.seller_user_id = ?)
        $orderBy
    ";
    $stmt_transactions = $conn->prepare($sql);
    $stmt_transactions->bind_param("ii", $user_id, $user_id);
    $stmt_transactions->execute();
    $result_transactions = $stmt_transactions->get_result();
    
    while ($row = $result_transactions->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    $stmt_transactions->close();

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
} finally {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>P2P History | ACCQURA</title>
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
        
        /* Notification Badge */
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
        
        /* History Table Styles */
        .history-table-container {
            margin-top: 20px;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e0e0;
        }
        
        .history-table th {
            background-color: #f8f9fa;
            color: #2d3748;
            font-weight: 600;
            padding: 15px 20px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .history-table td {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            color: #2d3748;
        }
        
        .history-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .no-data p {
            font-size: 1rem;
        }
        
        /* Responsive Design */
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
            
            .history-table {
                display: block;
                overflow-x: auto;
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
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="user-info">
                <div class="user-avatar">
                    <?php
                        $image_name = $session_userimage;
                        $upload_dir = __DIR__ . '/uploads/';
                        $image_path = '';
                        if (!empty($image_name)) {
                            if (file_exists($upload_dir . $image_name)) {
                                $image_path = 'uploads/' . $image_name;
                            } else {
                                $base_name = pathinfo($image_name, PATHINFO_FILENAME);
                                $extensions = ['jpg','jpeg','png','gif','webp'];
                                foreach ($extensions as $ext) {
                                    if (file_exists($upload_dir . $base_name . '.' . $ext)) {
                                        $image_path = 'uploads/' . $base_name . '.' . $ext;
                                        break;
                                    }
                                }
                            }
                        }
                        if (!empty($image_path)):
                    ?>
                        <img src="<?php echo htmlspecialchars($image_path); ?>" alt="User">
                    <?php else: ?>
                        <span><?php echo htmlspecialchars($initials); ?></span>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <h1>Welcome, <?php echo htmlspecialchars($session_username); ?>!</h1>
                    <p>P2P Transaction History</p>
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
            <a href="p2pTransactionHistory.php" class="nav-tab active">
                <i class="fas fa-history me-2"></i>P2P History
            </a>
            <a href="profile.php" class="nav-tab">
                <i class="fas fa-user me-2"></i>Profile
            </a>
        </div>
        
        <div class="dashboard-content">
            <div class="history-table-container">
                <?php if (empty($transactions)): ?>
                    <div class="no-data">
                        <i class="fas fa-history"></i>
                        <p>You have no P2P transactions yet.</p>
                    </div>
                <?php else: ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Transaction</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Exchange Rate</th>
                                <th>For</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td>
                                        <?php 
                                            $buyerId    = (int)$transaction['buyer_user_id'];
                                            $sellerId   = (int)$transaction['seller_user_id'];
                                            $buyerName  = htmlspecialchars($transaction['buyer_username']);
                                            $sellerName = htmlspecialchars($transaction['seller_username']);
                                            $tradeType  = strtolower((string)$transaction['trade_type']);

                                            $youAreAcceptor = ($buyerId === (int)$user_id);
                                            $youArePoster   = ($sellerId === (int)$user_id);

                                            if ($buyerId === $sellerId || (!$youAreAcceptor && !$youArePoster)) {
                                                echo "Self-trade";
                                            } else if ($youArePoster) {
                                                echo $tradeType === 'sell' ? "You sold to {$buyerName}" : "You bought from {$buyerName}";
                                            } else {
                                                echo $tradeType === 'sell' ? "You bought from {$sellerName}" : "You sold to {$sellerName}";
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $buy_sym  = htmlspecialchars($transaction['buy_currency_symbol']);
                                            $sell_sym = htmlspecialchars($transaction['sell_currency_symbol']);
                                            if (strtolower($transaction['trade_type']) === 'buy') {
                                                echo "Sell {$buy_sym} for {$sell_sym}";
                                            } else {
                                                echo "Buy {$sell_sym} for {$buy_sym}";
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            echo number_format($transaction['amount_sold'], 2) . " " . htmlspecialchars($transaction['sell_currency_symbol']);
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $buy_symbol = htmlspecialchars($transaction['buy_currency_symbol']);
                                            $sell_symbol = htmlspecialchars($transaction['sell_currency_symbol']);
                                            $is_usd_mmk = (($buy_symbol === 'USD' && $sell_symbol === 'MMK') || ($buy_symbol === 'MMK' && $sell_symbol === 'USD'));

                                            if ($is_usd_mmk) {
                                                $exactRate = (float)$transaction['exchange_rate'];
                                                echo "1 USD = " . number_format($exactRate, 0) . " MMK";
                                            } else if ($transaction['amount_sold'] > 0 && $transaction['amount_bought'] > 0) {
                                                $buy_amount = $transaction['amount_bought'];
                                                $sell_amount = $transaction['amount_sold'];

                                                if ($buy_amount < $sell_amount) {
                                                    $exchangeRate = $sell_amount / $buy_amount;
                                                    echo "1 {$buy_symbol} = " . number_format($exchangeRate, 2) . " {$sell_symbol}";
                                                } else {
                                                    $exchangeRate = $buy_amount / $sell_amount;
                                                    echo "1 {$sell_symbol} = " . number_format($exchangeRate, 2) . " {$buy_symbol}";
                                                }
                                            } else {
                                                echo "N/A";
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            echo number_format($transaction['amount_bought'], 2) . " " . htmlspecialchars($transaction['buy_currency_symbol']);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['fill_timestamp']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
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
            
            if (navTabs && mobileMenuToggle) {
                if (!navTabs.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                    navTabs.classList.remove('show');
                }
            }
        });
    </script>
    
    <!-- Real-time Ban Check -->
    <?php include 'ban_check_script.php'; ?>
</body>
</html>