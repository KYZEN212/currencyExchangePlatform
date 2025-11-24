<?php
session_start();
// Disable caching to ensure fresh dashboard data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Load configuration (database credentials, etc.)
require_once 'config.php';

// Initialize CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    // If not, redirect to the login page
    header("Location: login.html");
    exit();
}

// Get the logged-in user's username from the session
$session_username = $_SESSION['username'];
$session_userimage = $_SESSION['userimage'] ?? '';
$initials = strtoupper(substr($session_username, 0, 1));
$wallets = [];
$currencies = [];
$total_balance_usd = 0;
$wallet_distribution = [];
$total_mmk = 0;
$message = '';
$show_notifications = false;

// Connect to the database (uses variables from config.php)
$conn = new mysqli($servername, $username, $password, $dbname);

// Check for database connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Include the updated currency functions
require_once 'currency.php';

// Check if user is banned
require_once 'check_user_ban.php';

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
if (!$user_id) {
    die("User not found.");
}

// Handle currency conversion
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'convert_currency'
) {
    // CSRF validation
    $csrf_ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
    if (!$csrf_ok) {
        $message = "<div class='alert alert-danger'>Security check failed. Please refresh the page and try again.</div>";
    } else {
    $from_currency = intval($_POST['from_currency']);
    $to_currency = intval($_POST['to_currency']);
    $amount = floatval($_POST['amount']);
    
    if ($from_currency > 0 && $to_currency > 0 && $amount > 0 && $from_currency !== $to_currency) {
        $conn->begin_transaction();
        try {
            // Get exchange rate
            $stmt_rate = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ?");
            $stmt_rate->bind_param("ii", $from_currency, $to_currency);
            $stmt_rate->execute();
            $rate_result = $stmt_rate->get_result();
            
            if ($rate_row = $rate_result->fetch_assoc()) {
                $exchange_rate = $rate_row['rate'];
                
                // Calculate conversion with 5% tax
                $tax_rate = 0.05; // 5% tax
                $tax_amount = $amount * $tax_rate;
                $amount_after_tax = $amount * (1 - $tax_rate);
                $converted_amount = $amount_after_tax * $exchange_rate;
                $admin_id = 1; // Admin ID
                
                // Check if user has sufficient balance
                $stmt_check = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? AND currency_id = ?");
                $stmt_check->bind_param("ii", $user_id, $from_currency);
                $stmt_check->execute();
                $check_result = $stmt_check->get_result();
                
                if ($check_row = $check_result->fetch_assoc()) {
                    if ($check_row['balance'] >= $amount) {
                        // Deduct from source currency
                        $stmt_deduct = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt_deduct->bind_param("dii", $amount, $user_id, $from_currency);
                        $stmt_deduct->execute();
                        
                        // Add to target currency
                        $stmt_add = $conn->prepare("
                            INSERT INTO wallets (user_id, currency_id, balance) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)
                        ");
                        $stmt_add->bind_param("iid", $user_id, $to_currency, $converted_amount);
                        $stmt_add->execute();
                        
                        // Add tax to admin wallet
                        $stmt_admin_tax = $conn->prepare("
                            INSERT INTO admin_wallet (admin_id, currency_id, balance) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)
                        ");
                        $stmt_admin_tax->bind_param("iid", $admin_id, $from_currency, $tax_amount);
                        $stmt_admin_tax->execute();
                        
                        // Record conversion fee for profit tracking
                        $stmt_fee = $conn->prepare("
                            INSERT INTO conversion_fees (user_id, from_currency_id, to_currency_id, amount_converted, tax_amount, tax_rate)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ") or die($conn->error);
                        $stmt_fee->bind_param("iiiddd", $user_id, $from_currency, $to_currency, $amount, $tax_amount, $tax_rate);
                        $stmt_fee->execute();
                        
                        // Record the conversion in history
                        $stmt_history = $conn->prepare("
                            INSERT INTO conversion_history 
                            (user_id, from_currency, to_currency, amount, converted_amount, rate, fee)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ") or die($conn->error);
                        $conversion_rate = $exchange_rate * (1 - $tax_rate); // Rate after fees
                        $stmt_history->bind_param("iissddd", 
                            $user_id, 
                            $from_currency, 
                            $to_currency, 
                            $amount, 
                            $converted_amount,
                            $conversion_rate,
                            $tax_amount
                        );
                        $stmt_history->execute();
                        $stmt_history->close();
                        
                        $conn->commit();
                        $message = "<div class='alert alert-success'><strong>Conversion Successful!</strong><br>Amount: " . number_format($amount, 4, '.', '') . "<br>Tax (5%): " . number_format($tax_amount, 4, '.', '') . "<br>You received: " . number_format($converted_amount, 4, '.', '') . "</div>";
                    } else {
                        throw new Exception("Insufficient balance for conversion.");
                    }
                } else {
                    throw new Exception("Source wallet not found.");
                }
            } else {
                throw new Exception("Exchange rate not available for this currency pair.");
            }
            $stmt_rate->close();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Conversion failed: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Invalid conversion parameters.</div>";
    }
    }
}

// Handle clearing a single notification
if (isset($_GET['clear_notification_id']) && isset($_GET['user_id'])) {
    $request_id = intval($_GET['clear_notification_id']);
    $user_id_from_url = intval($_GET['user_id']);
    
    if ($user_id === $user_id_from_url) {
        // First, check the status of the request
        $stmt_check_status = $conn->prepare("SELECT status FROM user_currency_requests WHERE request_id = ? AND user_id = ?");
        $stmt_check_status->bind_param("ii", $request_id, $user_id);
        $stmt_check_status->execute();
        $result_status = $stmt_check_status->get_result();
        $notification = $result_status->fetch_assoc();
        $stmt_check_status->close();
        
        // Only update if the status is NOT 'pending'
        if ($notification && $notification['status'] !== 'pending') {
            $stmt_update = $conn->prepare("UPDATE user_currency_requests SET is_cleared = 1 WHERE request_id = ? AND user_id = ?");
            $stmt_update->bind_param("ii", $request_id, $user_id);
            $stmt_update->execute();
            $stmt_update->close();
            header("Location: dashboard.php?action=notifications&message=" . urlencode("Notification cleared successfully."));
            exit();
        } else {
             header("Location: dashboard.php?action=notifications&message=" . urlencode("Pending notifications cannot be cleared."));
             exit();
        }
    }
}

// Handle clearing all notifications
if (isset($_GET['clear_all'])) {
    // Only update notifications that are not 'pending'
    $stmt_update_all = $conn->prepare("UPDATE user_currency_requests SET is_cleared = 1 WHERE user_id = ? AND status != 'pending'");
    $stmt_update_all->bind_param("i", $user_id);
    $stmt_update_all->execute();
    $stmt_update_all->close();
    header("Location: dashboard.php?action=notifications&message=" . urlencode("All non-pending notifications cleared successfully."));
    exit();
}

// Check for message in URL parameter (sanitize to avoid XSS)
if (isset($_GET['message'])) {
    $message_text = filter_input(INPUT_GET, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
    if ($message_text !== '') {
        $message = "<div class='alert alert-info'>" . $message_text . "</div>";
    }
}

// If arriving with action=notifications, show notifications view by default
if (isset($_GET['action']) && $_GET['action'] === 'notifications') {
    $show_notifications = true;
}

// Get today's exchange rates
$today_rates = getTodayExchangeRates($conn);

// Get available currency pairs
$currency_pairs = getAvailableCurrencyPairs($conn);

// Handle exchange rate history view
$show_exchange_history = false;
$exchange_history = [];
$selected_pair = null;

if (isset($_GET['view_rate_history']) && isset($_GET['base_currency']) && isset($_GET['target_currency'])) {
    $base_currency_id = intval($_GET['base_currency']);
    $target_currency_id = intval($_GET['target_currency']);
    $exchange_history = getExchangeRateHistory($conn, $base_currency_id, $target_currency_id);
    $show_exchange_history = true;
    
    // Find the selected pair details
    foreach ($currency_pairs as $pair) {
        if ($pair['base_currency_id'] == $base_currency_id && $pair['target_currency_id'] == $target_currency_id) {
            $selected_pair = $pair;
            break;
        }
    }
    // If pair not present in available pairs (e.g., derived USD/JPY, USD/THB, JPY/THB), look up symbols directly
    if (!$selected_pair) {
        $stmt_syms = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE currency_id IN (?, ?)");
        if ($stmt_syms) {
            $stmt_syms->bind_param("ii", $base_currency_id, $target_currency_id);
            $stmt_syms->execute();
            $res_syms = $stmt_syms->get_result();
            $base_sym = ''; $target_sym = '';
            while ($row = $res_syms->fetch_assoc()) {
                if ((int)$row['currency_id'] === $base_currency_id) { $base_sym = $row['symbol']; }
                if ((int)$row['currency_id'] === $target_currency_id) { $target_sym = $row['symbol']; }
            }
            $stmt_syms->close();
            if ($base_sym !== '' && $target_sym !== '') {
                $selected_pair = [
                    'base_currency_id' => $base_currency_id,
                    'target_currency_id' => $target_currency_id,
                    'base_symbol' => $base_sym,
                    'target_symbol' => $target_sym,
                ];
            }
        }
    }

    // If no direct history exists and this is a derived non-MMK pair among USD/JPY/THB, build synthetic history from FOREIGN->MMK histories
    if (empty($exchange_history) && $selected_pair) {
        $bs = strtoupper($selected_pair['base_symbol'] ?? '');
        $ts = strtoupper($selected_pair['target_symbol'] ?? '');
        $set = ['USD','JPY','THB'];
        if (in_array($bs, $set, true) && in_array($ts, $set, true) && $bs !== 'MMK' && $ts !== 'MMK') {
            // Resolve IDs for symbols and MMK
            $need = [$bs, $ts, 'MMK'];
            $ids = [];
            $placeholders = implode(',', array_fill(0, count($need), '?'));
            $types = str_repeat('s', count($need));
            $stmt_ids = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN ($placeholders)");
            if ($stmt_ids) {
                $stmt_ids->bind_param($types, ...$need);
                $stmt_ids->execute();
                $res_ids = $stmt_ids->get_result();
                while ($r = $res_ids->fetch_assoc()) { $ids[strtoupper($r['symbol'])] = (int)$r['currency_id']; }
                $stmt_ids->close();
            }
            if (!empty($ids[$bs]) && !empty($ids[$ts]) && !empty($ids['MMK'])) {
                $bid = $ids[$bs];
                $tid = $ids[$ts];
                $mmk = $ids['MMK'];
                // Fetch histories: base->MMK and target->MMK
                $hist_base_mmk = getExchangeRateHistory($conn, $bid, $mmk);
                $hist_target_mmk = getExchangeRateHistory($conn, $tid, $mmk);
                // Build synthetic by pairing entries by index (assuming admin updates in similar order/time)
                $n = min(count($hist_base_mmk), count($hist_target_mmk), 20); // up to 20 entries
                $synthetic = [];
                for ($i = 0; $i < $n; $i++) {
                    $hb = $hist_base_mmk[$i];
                    $ht = $hist_target_mmk[$i];
                    $rb = (float)($hb['rate'] ?? 0);
                    $rt = (float)($ht['rate'] ?? 0);
                    if ($rb > 0 && $rt > 0) {
                        $rate = $rb / $rt; // (BASE/MMK) / (TARGET/MMK) = BASE/TARGET
                        $ts_min = min(strtotime($hb['timestamp']), strtotime($ht['timestamp']));
                        $synthetic[] = [
                            'rate' => $rate,
                            'timestamp' => date('Y-m-d H:i:s', $ts_min),
                            'base_symbol' => $bs,
                            'target_symbol' => $ts,
                        ];
                    }
                }
                if (!empty($synthetic)) {
                    $exchange_history = $synthetic;
                }
            }
        }
    }
}

try {
    // First, find the user_id based on the session username
    $stmt_user = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt_user->bind_param("s", $session_username);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
        $user_id = $user['user_id'];

        // Get user's wallet balances for MMK, USD, JPY, THB (show 0 for missing wallets)
        $total_balance_usd = 0;
        $wanted = ['MMK','USD','JPY','THB'];
        $placeholders = implode(',', array_fill(0, count($wanted), '?'));
        $types = str_repeat('s', count($wanted));
        $sql_wallets = "
            SELECT 
                COALESCE(w.balance, 0) AS balance,
                c.symbol,
                c.name,
                c.currency_id
            FROM currencies c
            LEFT JOIN wallets w 
                ON w.currency_id = c.currency_id AND w.user_id = ?
            WHERE c.symbol IN ($placeholders)
            ORDER BY FIELD(c.symbol, 'MMK','USD','JPY','THB')
        ";
        if ($stmt_wallets = $conn->prepare($sql_wallets)) {
            // bind: user_id then symbols
            $bindTypes = 'i' . $types;
            $params = array_merge([$user_id], $wanted);
            // dynamic bind
            $stmt_wallets->bind_param($bindTypes, ...$params);
            $stmt_wallets->execute();
            $result_wallets = $stmt_wallets->get_result();
            while ($row = $result_wallets->fetch_assoc()) {
                $wallets[] = $row;
                // Convert to USD and MMK for distribution chart
                if ($row['balance'] > 0) {
                    $balance = (float)$row['balance'];
                    $symbol = $row['symbol'];
                    $amount_mmk = 0;

                    if ($symbol === 'MMK') {
                        $amount_mmk = $balance;
                        $total_balance_usd += $balance / 4000; // Assuming 1 USD = 4000 MMK for USD conversion
                    } else {
                        // Get rate to MMK
                        $stmt_mmk = $conn->prepare("
                            SELECT rate FROM exchange_rates 
                            WHERE base_currency_id = ? AND target_currency_id = (
                                SELECT currency_id FROM currencies WHERE symbol = 'MMK' LIMIT 1
                            )
                            ORDER BY timestamp DESC LIMIT 1
                        ") or die($conn->error);
                        $stmt_mmk->bind_param("i", $row['currency_id']);
                        $stmt_mmk->execute();
                        $mmk_result = $stmt_mmk->get_result();
                        
                        if ($mmk_row = $mmk_result->fetch_assoc()) {
                            $amount_mmk = $balance * (float)$mmk_row['rate'];
                        } else if ($symbol === 'USD') {
                            // Fallback: If no direct rate, use fixed rate for USD to MMK
                            $amount_mmk = $balance * 4000; // 1 USD = 4000 MMK
                        } else if ($symbol === 'THB') {
                            // Fallback: If no direct rate, use fixed rate for THB to MMK
                            $amount_mmk = $balance * 30; // 1 THB = 30 MMK
                        } else if ($symbol === 'JPY') {
                            // Fallback: If no direct rate, use fixed rate for JPY to MMK
                            $amount_mmk = $balance * 27; // 1 JPY = 27 MMK
                        }
                        $stmt_mmk->close();

                        // Convert to USD for total balance
                        if ($symbol !== 'USD') {
                            $stmt_rate = $conn->prepare("
                                SELECT rate FROM exchange_rates 
                                WHERE base_currency_id = ? AND target_currency_id = 2
                                ORDER BY timestamp DESC LIMIT 1
                            ") or die($conn->error);
                            $stmt_rate->bind_param("i", $row['currency_id']);
                            $stmt_rate->execute();
                            $rate_result = $stmt_rate->get_result();
                            if ($rate_row = $rate_result->fetch_assoc()) {
                                $total_balance_usd += $balance * (float)$rate_row['rate'];
                            }
                            $stmt_rate->close();
                        } else {
                            $total_balance_usd += $balance;
                        }
                    }

                    if ($amount_mmk > 0) {
                        $wallet_distribution[] = [
                            'symbol' => $symbol,
                            'balance' => $balance,
                            'amount_mmk' => $amount_mmk,
                            'name' => $row['name']
                        ];
                        $total_mmk += $amount_mmk;
                    }
                }
            }
            $stmt_wallets->close();
        }

        // Check if wallet is empty
        $wallet_is_empty = empty($wallets) || $total_balance_usd == 0;

        // Get all currencies for the dropdown
        $stmt_currencies = $conn->prepare("SELECT currency_id, symbol, name FROM currencies");
        $stmt_currencies->execute();
        $result_currencies = $stmt_currencies->get_result();
        while ($row = $result_currencies->fetch_assoc()) {
            $currencies[] = $row;
        }
        $stmt_currencies->close();

        // Get user's transaction requests for the notification tab
        $notifications = [];
        $stmt_notifications = $conn->prepare("
            SELECT ucr.amount, ucr.transaction_type, ucr.status, c.symbol, ucr.request_id
            FROM user_currency_requests ucr
            JOIN currencies c ON ucr.currency_id = c.currency_id
            WHERE ucr.user_id = ? AND ucr.is_cleared = 0
            ORDER BY ucr.request_id DESC
        ");
        $stmt_notifications->bind_param("i", $user_id);
        $stmt_notifications->execute();
        $result_notifications = $stmt_notifications->get_result();

        while ($row = $result_notifications->fetch_assoc()) {
            $notifications[] = $row;
        }
        $stmt_notifications->close();

    } else {
        // User not found, handle error or redirect
        header("Location: login.html");
        exit();
    }
} catch (Exception $e) {
    // Handle exceptions
    die("Error: " . $e->getMessage());
} finally {
    // Don't close the connection here, we'll close it at the end of the file
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard | FX Nexus</title>
    <link rel="icon" type="image/x-icon" href="/static/favicon.ico">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #60a5fa;
            --bg-light: #f1f5f9;
            --text-dark: #1f2937;
            --text-light: #f9fafb;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            padding-top: 56px; /* Space for the fixed navbar */
        }

        .gradient-bg {
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
        }

        .text-3d {
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        .navbar-brand img {
            height: 30px;
        }

        .btn-outline-light {
            border-color: rgba(255, 255, 255, 0.5);
        }

        .btn-outline-light:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .table-responsive {
            border-radius: 1rem;
            overflow-x: auto;
        }
        
        /* Dashboard specific styles */
        .dashboard-container {
            /* max-width: 1000px; */
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff !important;
            transform: translateY(-2px);
        }
        .navbar {
            padding: 1rem 0;
            background: linear-gradient(105deg, rgba(37, 99, 235, 0.9), rgba(37, 99, 235, 0.9)) !important; /* solid blue */
            backdrop-filter: blur(12px); /* glass effect */
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); /* subtle border */
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.4); /* depth */
            transition: background 0.3s ease;
        }

        .nav-link {
            font-weight: 500;
            color: #f8fafc !important;
            /* padding: 0.5rem 1rem !important; */
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            border-radius: 0.5rem;
        }

        /* Update this block in your CSS */
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #f8fafc !important;
            transform: translateY(-2px);
        }

        /* Active state for navbar links */
        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff !important;
        }

        /* Add this new rule to your CSS to target the specific nav-link and override the hover effect */
        a.nav-link:hover .userprofile {
            background-color: initial;
            border: initial; 
        }
        
        .userprofile{
            margin-left: 20px;
            /* This will remove any default hover styles on the profile circle. */
            background-color: none;
            border: none;
        }

        .navbar-brand span {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, #38bdf8, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Exchange rates table styling */
        .exchange-table {
            font-size: 0.9rem;
        }
        
        .exchange-table th {
            border-top: none;
            font-weight: 600;
            color: #6b7280;
        }
        
        .exchange-table td {
            vertical-align: middle;
        }
        
        .rate-badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.75rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                padding-top: 60px;
            }
            .navbar-brand {
                font-size: 1.25rem;
            }
            .exchange-table {
                font-size: 0.8rem;
            }
        }
    
        /* --- Custom Fixes --- */

        /* Notifications link background white */
        #show-notifications-btn {
            display: flex;
            align-items: center;
            border-radius: 0.5rem;
            padding: 6px 12px;
        }

        /* Ensure notification bell is aligned with nav links */
        #show-notifications-btn i {
            vertical-align: middle;
            margin-right: 4px;
        }

        /* Move notification circle/avatar closer to right edge */
        .navbar .ms-3 {
            margin-left: 0.5rem !important; /* tighter */
        }
        /* Reduce generic left margins within navbar for tighter spacing */
        .navbar .ms-2 { margin-left: 0.25rem !important; }

        /* Spacing between navbar items (tighter) */
        .navbar-nav .nav-item {
            margin-left: 0.75rem;
        }

    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark gradient-bg shadow-sm fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="https://static.photos/finance/200x200/1" alt="FX Nexus Logo" class="me-2">
                <span class="fw-bold">FX Nexus</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link<?php echo $show_notifications ? '' : ' active'; ?>" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="p2pTradeList.php">P2P Trade</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="p2pTransactionHistory.php">P2P History</a>
                    </li>
                    
                </ul>
                <div class="ms-3 d-flex">
                    <a id="show-notifications-btn" class="nav-link position-relative<?php echo $show_notifications ? ' active' : ''; ?>" href="dashboard.php?action=notifications">
                        <i class="fas fa-bell"></i> Notifications
                        <span id="notification-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"></span>
                    </a>
                    <a class="btn btn-outline-light border-0 fw-bold" href="logout.php">Logout</a>
                    <a href="profile.php" class="d-flex align-items-center justify-content-center rounded-circle text-decoration-none ms-2" style="width:38px; height:38px; overflow:hidden; background:#e5e7eb; color:#111827;">
                        <?php
                            $image_name_nav = $session_userimage;
                            $upload_dir_nav = __DIR__ . '/uploads/';
                            $image_path_nav = '';
                            if (!empty($image_name_nav)) {
                                if (file_exists($upload_dir_nav . $image_name_nav)) {
                                    $image_path_nav = 'uploads/' . $image_name_nav;
                                } else {
                                    $base_name_nav = pathinfo($image_name_nav, PATHINFO_FILENAME);
                                    $extensions_nav = ['jpg','jpeg','png','gif','webp'];
                                    foreach ($extensions_nav as $ext_nav) {
                                        if (file_exists($upload_dir_nav . $base_name_nav . '.' . $ext_nav)) {
                                            $image_path_nav = 'uploads/' . $base_name_nav . '.' . $ext_nav;
                                            break;
                                        }
                                    }
                                }
                            }
                            if (!empty($image_path_nav)):
                        ?>
                            <img src="<?php echo htmlspecialchars($image_path_nav); ?>" alt="User" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <span style="font-weight:700; font-size:0.9rem; line-height:38px; width:100%; text-align:center; color:#111827;">
                                <?php echo htmlspecialchars($initials); ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container dashboard-container mx-auto py-5 mt-5">
        <div class="card p-4">
            <div class="d-flex align-items-center mb-4">
                <div class="rounded-circle me-3 d-flex align-items-center justify-content-center" style="height: 48px; width: 48px; overflow:hidden; background:#e5e7eb;">
                    <?php
                        $image_name_card = $session_userimage;
                        $upload_dir_card = __DIR__ . '/uploads/';
                        $image_path_card = '';
                        if (!empty($image_name_card)) {
                            if (file_exists($upload_dir_card . $image_name_card)) {
                                $image_path_card = 'uploads/' . $image_name_card;
                            } else {
                                $base_name_card = pathinfo($image_name_card, PATHINFO_FILENAME);
                                $extensions_card = ['jpg','jpeg','png','gif','webp'];
                                foreach ($extensions_card as $ext_card) {
                                    if (file_exists($upload_dir_card . $base_name_card . '.' . $ext_card)) {
                                        $image_path_card = 'uploads/' . $base_name_card . '.' . $ext_card;
                                        break;
                                    }
                                }
                            }
                        }
                        if (!empty($image_path_card)):
                    ?>
                        <img src="<?php echo htmlspecialchars($image_path_card); ?>" alt="User" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <span style="font-weight:700; font-size:0.9rem; line-height:48px; width:100%; text-align:center; color:#111827;">
                            <?php echo htmlspecialchars($initials); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="h5 fw-bold text-dark mb-0">Welcome, <?php echo htmlspecialchars($session_username); ?>!</h2>
                    <p class="text-muted mb-0">Your Personal Dashboard</p>
                </div>
            </div>
    
            <hr class="my-4">
    
            <?php if (!empty($message)): ?>
                <?php echo $message; ?>
            <?php endif; ?>
    
            <div id="dashboard-content" class="<?php echo $show_notifications ? 'd-none' : ''; ?>">
                <!-- Total Balance Card -->
                <div class="card border-0 shadow-lg mb-4" style="border-radius: 1.25rem; overflow: hidden;">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: rgba(59, 130, 246, 0.15);">
                                    <i class="fas fa-wallet" style="font-size: 1.4rem; color: rgb(59, 130, 246);"></i>
                                </div>
                                <div>
                                    <h3 class="h6 fw-bold text-dark mb-0">Total Balance</h3>
                                    <p class="text-muted mb-0" style="font-size: 0.85rem;">Your wallet overview</p>
                                </div>
                            </div>
                            <div>
                                <select id="currency-select" class="form-select border-2 fw-semibold" style="border-radius: 0.75rem; font-size: 1rem; padding: 0.5rem 0.8rem;">
                                    <option value="USD" selected>USD</option>
                                    <?php foreach ($currencies as $currency): ?>
                                        <?php if ($currency['symbol'] !== 'USD'): ?>
                                            <option value="<?php echo htmlspecialchars($currency['symbol']); ?>">
                                                <?php echo htmlspecialchars($currency['symbol']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <h2 class="display-6 fw-bold text-dark mb-0" id="total-balance">
                                USD <?php echo number_format($total_balance_usd, 2); ?>
                            </h2>
                        </div>
                    </div>
                </div>
    
                <!-- Wallet Distribution Chart -->
                <div class="card border-0 shadow-lg mb-4" style="border-radius: 1.25rem; overflow: hidden;">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-dark mb-3">Wallet Distribution (MMK Value)</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="walletDistributionChart" height="250"></canvas>
                            </div>
                            <div class="col-md-6 d-flex align-items-center">
                                <div id="wallet-legend" class="w-100"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Wallet Cards -->
                <div class="row g-3 mb-4">
                    <?php foreach ($wallets as $wallet): ?>
                        <div class="col-md-6 col-lg-6">
                            <div class="card border-0 shadow-sm h-100" style="border-radius: 1rem;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 55px; height: 55px; background-color: rgba(59, 130, 246, 0.15);">
                                            <i class="fas fa-coins" style="font-size: 1.6rem; color: rgb(59, 130, 246);"></i>
                                        </div>
                                        <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.9rem;"><?php echo htmlspecialchars($wallet['symbol']); ?></span>
                                    </div>
                                    <h4 class="text-muted mb-2" style="font-size: 0.9rem;"><?php echo htmlspecialchars($wallet['name']); ?></h4>
                                    <h3 class="h4 fw-bold text-dark mb-0"><?php echo number_format($wallet['balance'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
    
                <!-- Action Buttons -->
                <div class="d-flex gap-3 mb-4">
                    <a href="deposit_form.php" class="btn btn-primary btn-lg px-4 py-2 shadow-sm" style="border-radius: 0.75rem; font-weight: 600;">
                        <i class="fas fa-plus-circle me-2"></i>Deposit
                    </a>
                    <a href="withdraw_form.php" class="btn btn-danger btn-lg px-4 py-2 shadow-sm" style="border-radius: 0.75rem;">
                        <i class="fas fa-minus-circle me-2"></i>Withdraw
                    </a>
                    <button onclick="toggleConverter()" class="btn btn-outline-primary btn-lg px-4 py-2 shadow-sm" style="border-radius: 0.75rem; font-weight: 600;">
                        <i class="fas fa-exchange-alt me-2"></i>Convert
                    </button>
                </div>

                <!-- Currency Conversion Section -->
                <div id="converterSection" class="card p-0 border-0 shadow-lg overflow-hidden position-relative mb-5" style="border-radius: 1.5rem; display: none;">
                    <div class="position-relative" style="background: rgb(59, 130, 246); padding: 2rem;">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i class="fas fa-exchange-alt text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div class="text-white">
                                <h4 class="fw-bold mb-1" style="font-size: 1.5rem;">Currency Converter</h4>
                                <p class="mb-0 opacity-75" style="font-size: 0.9rem;">Exchange between your wallet currencies</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4">
                        <?php if (!empty($message)): ?>
                            <?php echo $message; ?>
                        <?php endif; ?>
                        <form method="POST" action="dashboard.php" class="row g-4">
                            <input type="hidden" name="action" value="convert_currency">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            
                            <div class="col-md-5">
                                <label class="form-label fw-bold">From Currency</label>
                                <select name="from_currency" id="fromCurrency" class="form-select form-select-lg" required onchange="updateConversion()">
                                    <option value="">Select currency...</option>
                                    <?php 
                                    // Map balances by currency_id for quick lookup
                                    $balanceById = [];
                                    foreach ($wallets as $w) { $balanceById[$w['currency_id']] = $w['balance']; }
                                    foreach ($currencies as $cur): 
                                        $bal = $balanceById[$cur['currency_id']] ?? 0;
                                    ?>
                                        <option value="<?php echo (int)$cur['currency_id']; ?>" data-balance="<?php echo (float)$bal; ?>" data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>">
                                            <?php echo htmlspecialchars($cur['symbol']); ?><?php echo isset($balanceById[$cur['currency_id']]) ? (' (Balance: ' . number_format($bal, 2) . ')') : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="mt-3">
                                    <label class="form-label fw-bold">Amount to Convert</label>
                                    <input type="number" step="0.01" name="amount" id="convertAmount" class="form-control form-control-lg" placeholder="0.00" required oninput="calculateConversion()">
                                    <small class="text-muted" id="availableBalance"></small>
                                </div>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-center justify-content-center">
                                <div class="bg-gradient rounded-circle d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; background: linear-gradient(135deg, #667eea, #764ba2);">
                                    <i class="fas fa-arrow-right text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                            
                            <div class="col-md-5">
                                <label class="form-label fw-bold">To Currency</label>
                                <select name="to_currency" id="toCurrency" class="form-select form-select-lg" required onchange="calculateConversion()">
                                    <option value="">Select currency...</option>
                                    <?php foreach ($currencies as $cur): ?>
                                        <option value="<?php echo (int)$cur['currency_id']; ?>" data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>">
                                            <?php echo htmlspecialchars($cur['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="mt-3">
                                    <label class="form-label fw-bold">You Will Receive (After 5% Tax)</label>
                                    <div class="card bg-light p-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="h4 mb-0 fw-bold text-success" id="convertedAmount">0.00</span>
                                            <span class="text-muted" id="targetSymbol">---</span>
                                        </div>
                                        <small class="text-danger mt-1" id="taxDisplay">Tax: 0.00</small><br>
                                        <small class="text-muted" id="exchangeRateDisplay">Select currencies to see rate</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold" style="border-radius: 0.75rem; padding: 1rem;">
                                    <i class="fas fa-exchange-alt me-2"></i>Convert Currency
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    // Exchange rates data from PHP
                    const exchangeRates = <?php echo json_encode($today_rates); ?>;
                    
                    // Build admin MMK map for USD/JPY/THB used for cross rates in converter
                    const mmkRates = (() => {
                        const out = { USD: null, JPY: null, THB: null };
                        try {
                            (exchangeRates || []).forEach(r => {
                                if (!r) return;
                                const b = r.base_symbol, t = r.target_symbol;
                                if (t === 'MMK' && (b === 'USD' || b === 'JPY' || b === 'THB')) {
                                    const v = parseFloat(r.rate);
                                    if (!isNaN(v) && v > 0) out[b] = v;
                                }
                            });
                        } catch (_) {}
                        return out;
                    })();

                    // Toggle converter visibility with smooth animation
                    function toggleConverter() {
                        const converter = document.getElementById('converterSection');
                        if (converter.style.display === 'none') {
                            converter.style.display = 'block';
                            converter.style.opacity = '0';
                            converter.style.transform = 'translateY(-20px)';
                            setTimeout(() => {
                                converter.style.transition = 'all 0.4s ease-out';
                                converter.style.opacity = '1';
                                converter.style.transform = 'translateY(0)';
                            }, 10);
                            // Scroll to converter
                            setTimeout(() => {
                                converter.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }, 100);
                        } else {
                            converter.style.opacity = '0';
                            converter.style.transform = 'translateY(-20px)';
                            setTimeout(() => {
                                converter.style.display = 'none';
                            }, 400);
                        }
                    }
                    
                    function updateConversion() {
                        const fromSelect = document.getElementById('fromCurrency');
                        const selectedOption = fromSelect.options[fromSelect.selectedIndex];
                        const balance = selectedOption.getAttribute('data-balance');
                        const symbol = selectedOption.getAttribute('data-symbol');
                        
                        if (balance) {
                            document.getElementById('availableBalance').textContent = `Available: ${parseFloat(balance).toFixed(2)} ${symbol}`;
                        } else {
                            document.getElementById('availableBalance').textContent = '';
                        }
                        
                        calculateConversion();
                    }
                    
                    function calculateConversion() {
                        const fromSel = document.getElementById('fromCurrency');
                        const toSel = document.getElementById('toCurrency');
                        const amount = parseFloat(document.getElementById('convertAmount').value) || 0;
                        if (!fromSel || !toSel || amount <= 0) {
                            document.getElementById('convertedAmount').textContent = '0.00';
                            document.getElementById('taxDisplay').textContent = 'Tax: 0.00';
                            document.getElementById('exchangeRateDisplay').textContent = 'Select currencies to see rate';
                            return;
                        }
                        const fromId = fromSel.value;
                        const toId = toSel.value;
                        if (!fromId || !toId) {
                            document.getElementById('convertedAmount').textContent = '0.00';
                            document.getElementById('taxDisplay').textContent = 'Tax: 0.00';
                            document.getElementById('exchangeRateDisplay').textContent = 'Select currencies to see rate';
                            return;
                        }
                        const fromOpt = fromSel.options[fromSel.selectedIndex];
                        const toOpt = toSel.options[toSel.selectedIndex];
                        const fromSym = fromOpt ? (fromOpt.getAttribute('data-symbol') || '') : '';
                        const toSym = toOpt ? (toOpt.getAttribute('data-symbol') || '') : '';

                        // Helper using existing exchangeRates; prefers direct/inverse; then admin cross via MMK for USD<->JPY/THB and JPY<->THB; then bridge via USD
                        const getRate = (fromSymbol, toSymbol) => {
                            if (!fromSymbol || !toSymbol) return null;
                            if (fromSymbol === toSymbol) return 1;
                            // Direct
                            const direct = (exchangeRates || []).find(r => r.base_symbol === fromSymbol && r.target_symbol === toSymbol);
                            if (direct && direct.rate) return parseFloat(direct.rate);
                            // Inverse
                            const inv = (exchangeRates || []).find(r => r.base_symbol === toSymbol && r.target_symbol === fromSymbol);
                            if (inv && inv.rate) { const v = parseFloat(inv.rate); if (v) return 1 / v; }
                            // Admin-consistent USD<->JPY/THB cross via MMK
                            const foreigns = ['JPY','THB'];
                            if ((fromSymbol === 'USD' && foreigns.includes(toSymbol)) || (toSymbol === 'USD' && foreigns.includes(fromSymbol))) {
                                const usdMmk = mmkRates.USD;
                                const f = (fromSymbol === 'USD') ? toSymbol : fromSymbol; // JPY or THB
                                const fMmk = mmkRates[f];
                                if (typeof usdMmk === 'number' && usdMmk > 0 && typeof fMmk === 'number' && fMmk > 0) {
                                    const usd_to_f = usdMmk / fMmk; // 1 USD = X f
                                    return (fromSymbol === 'USD') ? usd_to_f : (1 / usd_to_f);
                                }
                            }
                            // Admin-consistent JPY<->THB cross via MMK
                            if ((fromSymbol === 'JPY' && toSymbol === 'THB') || (fromSymbol === 'THB' && toSymbol === 'JPY')) {
                                const jpyMmk = mmkRates.JPY;
                                const thbMmk = mmkRates.THB;
                                if (typeof jpyMmk === 'number' && jpyMmk > 0 && typeof thbMmk === 'number' && thbMmk > 0) {
                                    // 1 JPY = (JPY/MMK) / (THB/MMK) THB
                                    const jpy_to_thb = jpyMmk / thbMmk;
                                    return (fromSymbol === 'JPY') ? jpy_to_thb : (1 / jpy_to_thb);
                                }
                            }
                            // Bridge via USD
                            const USD = 'USD';
                            const toUSD = (exchangeRates || []).find(r => r.base_symbol === fromSymbol && r.target_symbol === USD);
                            const fromUSD = (exchangeRates || []).find(r => r.base_symbol === USD && r.target_symbol === toSymbol);
                            let rateToUSD = null, rateFromUSD = null;
                            if (toUSD && toUSD.rate) rateToUSD = parseFloat(toUSD.rate);
                            if (!rateToUSD) {
                                const invToUSD = (exchangeRates || []).find(r => r.base_symbol === USD && r.target_symbol === fromSymbol);
                                if (invToUSD && invToUSD.rate) { const v = parseFloat(invToUSD.rate); if (v) rateToUSD = 1 / v; }
                            }
                            if (fromUSD && fromUSD.rate) rateFromUSD = parseFloat(fromUSD.rate);
                            if (!rateFromUSD) {
                                const invFromUSD = (exchangeRates || []).find(r => r.base_symbol === toSymbol && r.target_symbol === USD);
                                if (invFromUSD && invFromUSD.rate) { const v = parseFloat(invFromUSD.rate); if (v) rateFromUSD = 1 / v; }
                            }
                            if (rateToUSD !== null && rateFromUSD !== null) return rateToUSD * rateFromUSD;
                            return null;
                        };

                        const fx = getRate(fromSym, toSym);
                        if (fx && fx > 0) {
                            const taxRate = 0.05; // 5% tax
                            const tax = amount * taxRate;
                            const amountAfterTax = amount * (1 - taxRate);
                            const converted = amountAfterTax * fx;
                            document.getElementById('convertedAmount').textContent = converted.toFixed(2);
                            document.getElementById('targetSymbol').textContent = toSym || '---';
                            document.getElementById('taxDisplay').textContent = `Tax (5%): ${tax.toFixed(2)} ${fromSym || ''}`;
                            document.getElementById('exchangeRateDisplay').textContent = `Rate: 1 ${fromSym || ''} = ${fx.toFixed(4)} ${toSym || ''}`;
                        } else {
                            document.getElementById('convertedAmount').textContent = '---';
                            document.getElementById('targetSymbol').textContent = toSym || '---';
                            document.getElementById('taxDisplay').textContent = 'Tax: ---';
                            document.getElementById('exchangeRateDisplay').textContent = 'Exchange rate not available';
                        }
                    }
                </script>

                <!-- Exchange Rates Table -->
                <div class="card p-0 mt-5 border-0 shadow-lg overflow-hidden position-relative" style="border-radius: 1.5rem;">
                    <!-- Blue Header -->
                    <div class="position-relative" style="background: rgb(59, 130, 246); padding: 1.5rem 2rem;">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-white bg-opacity-25 rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                    <i class="fas fa-exchange-alt text-white" style="font-size: 1.5rem;"></i>
                                </div>
                                <div class="text-white">
                                    <h4 class="fw-bold mb-1" style="font-size: 1.5rem;">Exchange Rates</h4>
                                    <p class="mb-0 opacity-75" style="font-size: 0.9rem;">Real-time market rates • Updated by admin</p>
                                </div>
                            </div>
                            <button onclick="location.reload()" class="btn btn-light btn-sm d-flex align-items-center gap-2">
                                <i class="fas fa-sync-alt"></i>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4 py-3 fw-semibold text-uppercase small text-muted">Currency Pairs</th>
                                        <th class="text-end pe-4 py-3 fw-semibold text-uppercase small text-muted">Exchange Rate</th>
                                        <th class="text-center pe-4 py-3 fw-semibold text-uppercase small text-muted">Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // First, get all base rates to MMK
                                    $base_currencies = ['USD', 'THB', 'JPY', 'MMK'];
                                    $rates_to_mmk = [];
                                    
                                    // Get direct rates to MMK first
                                    foreach ($base_currencies as $currency) {
                                        if ($currency === 'MMK') {
                                            $rates_to_mmk['MMK'] = [
                                                'rate' => 1,
                                                'timestamp' => date('Y-m-d H:i:s')
                                            ];
                                            continue;
                                        }
                                        
                                        $stmt = $conn->prepare("
                                            SELECT er.rate, c1.name as base_name, c2.name as target_name, er.timestamp as last_updated
                                            FROM exchange_rates er
                                            JOIN currencies c1 ON er.base_currency_id = c1.currency_id
                                            JOIN currencies c2 ON er.target_currency_id = c2.currency_id
                                            WHERE c1.symbol = ? AND c2.symbol = 'MMK'
                                            ORDER BY er.timestamp DESC 
                                            LIMIT 1
                                        ") or die($conn->error);
                                        
                                        $stmt->bind_param("s", $currency);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        
                                        if ($row = $result->fetch_assoc()) {
                                            $rates_to_mmk[$currency] = [
                                                'rate' => $row['rate'],
                                                'timestamp' => $row['last_updated']
                                            ];
                                        }
                                        $stmt->close();
                                    }
                                    
                                    // Define specific currency pairs to show
                                    $currency_pairs = [
                                        ['USD', 'MMK'],
                                        ['THB', 'MMK'],
                                        ['JPY', 'MMK'],
                                        ['USD', 'JPY'],
                                        ['USD', 'THB'],
                                        ['THB', 'JPY']
                                    ];
                                    
                                    // Get currency names
                                    $currency_names = [];
                                    $stmt = $conn->prepare("SELECT symbol, name FROM currencies WHERE symbol IN ('USD', 'THB', 'JPY', 'MMK')");
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    while ($row = $result->fetch_assoc()) {
                                        $currency_names[$row['symbol']] = $row['name'];
                                    }
                                    $stmt->close();
                                    
                                    // Display all pairs
                                    foreach ($currency_pairs as $pair): 
                                        list($base, $target) = $pair;
                                        $base_name = $currency_names[$base] ?? $base;
                                        $target_name = $currency_names[$target] ?? $target;
                                        
                                        // Calculate rate through MMK if direct rate doesn't exist
                                        if (isset($rates_to_mmk[$base]['rate']) && isset($rates_to_mmk[$target]['rate'])) {
                                            // Calculate rate as (target/MMK) / (base/MMK) = target/base
                                            $rate = ($rates_to_mmk[$base]['rate'] > 0 && $rates_to_mmk[$target]['rate'] > 0) ? 
                                                   $rates_to_mmk[$base]['rate'] / $rates_to_mmk[$target]['rate'] : 0;
                                            
                                            // Get the most recent timestamp between the two rates
                                            $timestamp1 = isset($rates_to_mmk[$base]['timestamp']) ? strtotime($rates_to_mmk[$base]['timestamp']) : 0;
                                            $timestamp2 = isset($rates_to_mmk[$target]['timestamp']) ? strtotime($rates_to_mmk[$target]['timestamp']) : 0;
                                            $timestamp = $timestamp1 > $timestamp2 ? $rates_to_mmk[$base]['timestamp'] : $rates_to_mmk[$target]['timestamp'];
                                            // Calculate inverse rate (1/rate) for the sell column
                                            $inverse_rate = $rate > 0 ? 1 / $rate : 0;
                                            // Format the timestamp for display
                                            $formatted_timestamp = date('M j, Y H:i', strtotime($timestamp));
                                    ?>
                                    <tr>
                                        <td class="ps-4 py-3">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                    <span class="text-primary fw-bold"><?php echo $base; ?></span>
                                                </div>
                                                <div>
                                                    <div class="fw-medium"><?php echo $base_name; ?> <span class="text-muted">(<?php echo $base; ?>)</span></div>
                                                    <div class="text-muted small">to <?php echo $target_name; ?> (<?php echo $target; ?>)</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4 py-3">
                                            <div class="fw-bold">1 <?php echo $base; ?> = <?php echo number_format((float)$rate, 4, '.', ''); ?> <?php echo $target; ?></div>
                                            <?php if ($inverse_rate !== 'N/A'): ?>
                                            <div class="text-muted small">1 <?php echo $target; ?> = <?php echo number_format((float)$inverse_rate, 4, '.', ''); ?> <?php echo $base; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center pe-4 py-3">
                                            <div class="badge bg-light text-dark">
                                                <i class="far fa-clock me-1"></i> <?php echo $formatted_timestamp; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php 
                                        }
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Recent Conversion History -->
                        <div class="card border-0 shadow-lg mt-4" style="border-radius: 1.25rem; overflow: hidden;">
                            <div class="card-body p-4">
                                <h5 class="fw-bold text-dark mb-4">Recent Conversions</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4 py-3 fw-semibold text-uppercase small text-muted">Date & Time</th>
                                                <th class="text-start pe-4 py-3 fw-semibold text-uppercase small text-muted">Conversion</th>
                                                <th class="text-end pe-4 py-3 fw-semibold text-uppercase small text-muted">Amount</th>
                                                <th class="text-end pe-4 py-3 fw-semibold text-uppercase small text-muted">Rate</th>
                                                <th class="text-end pe-4 py-3 fw-semibold text-uppercase small text-muted">Fee</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Fetch recent conversion history
                                            $recent_conversions = [];
                                            $stmt = $conn->prepare("
                                                SELECT ch.*, 
                                                       c1.symbol as from_symbol, 
                                                       c2.symbol as to_symbol
                                                FROM conversion_history ch
                                                JOIN currencies c1 ON c1.currency_id = ch.from_currency
                                                JOIN currencies c2 ON c2.currency_id = ch.to_currency
                                                WHERE ch.user_id = ?
                                                ORDER BY ch.timestamp DESC
                                                LIMIT 10
                                            ") or die($conn->error);
                                            $stmt->bind_param("i", $user_id);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            $recent_conversions = $result->fetch_all(MYSQLI_ASSOC);
                                            $stmt->close();

                                            if (count($recent_conversions) > 0):
                                                foreach ($recent_conversions as $conversion):
                                                    $formatted_date = date('M j, Y H:i', strtotime($conversion['timestamp']));
                                            ?>
                                            <tr>
                                                <td class="ps-4 py-3">
                                                    <div class="text-muted small"><?php echo $formatted_date; ?></div>
                                                </td>
                                                <td class="text-start">
                                                    <div class="fw-medium">
                                                        <?php echo $conversion['from_symbol']; ?> 
                                                        <i class="fas fa-arrow-right mx-2 text-muted"></i> 
                                                        <?php echo $conversion['to_symbol']; ?>
                                                    </div>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="fw-medium">
                                                        <?php echo number_format($conversion['amount'], 4, '.', ''); ?> 
                                                        <span class="text-muted"><?php echo $conversion['from_symbol']; ?></span>
                                                        <i class="fas fa-arrow-right mx-1 text-muted small"></i>
                                                        <?php echo number_format($conversion['converted_amount'], 4, '.', ''); ?> 
                                                        <span class="text-muted"><?php echo $conversion['to_symbol']; ?></span>
                                                    </div>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <span class="badge bg-light text-dark">
                                                        1 <?php echo $conversion['from_symbol']; ?> = <?php echo number_format($conversion['rate'], 4, '.', ''); ?> <?php echo $conversion['to_symbol']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <?php if ($conversion['fee'] > 0): ?>
                                                        <span class="text-danger small">-<?php echo number_format($conversion['fee'], 4, '.', ''); ?> <?php echo $conversion['from_symbol']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php 
                                                endforeach;
                                            else:
                                            ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    <i class="fas fa-exchange-alt fa-2x mb-2 d-block"></i>
                                                    No conversion history found
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Exchange Rate History Modal -->
                <?php if ($show_exchange_history && $selected_pair): ?>
                <div class="modal fade show" style="display: block; background-color: rgba(0,0,0,0.5);" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-chart-line me-2"></i>
                                    Exchange Rate History: 
                                    <?php echo htmlspecialchars($selected_pair['base_symbol']); ?> / <?php echo htmlspecialchars($selected_pair['target_symbol']); ?>
                                </h5>
                                <a href="dashboard.php" class="btn-close"></a>
                            </div>
                            <div class="modal-body">
                                <?php if (!empty($exchange_history)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Day</th>
                                                    <th>Date & Time</th>
                                                    <th>Exchange Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($exchange_history as $history): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?php echo date('l', strtotime($history['timestamp'])); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo date('M j, Y H:i', strtotime($history['timestamp'])); ?></td>
                                                        <td class="fw-bold">
                                                            1 <?php echo htmlspecialchars($history['base_symbol']); ?> = 
                                                            <?php echo number_format($history['rate'], 4); ?> <?php echo htmlspecialchars($history['target_symbol']); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No historical data available for this currency pair.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <a href="dashboard.php" class="btn btn-secondary">Close</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
    
            <div id="notifications-page" class="<?php echo $show_notifications ? '' : 'd-none'; ?>">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <a href="dashboard.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                        <h4 class="h5 fw-semibold text-dark mb-0">Notifications</h4>
                    </div>
                    <a id="clear-all-notifications" href="dashboard.php?clear_all=1&action=notifications" class="btn btn-sm btn-secondary">
                        Clear All
                    </a>
                </div>
                <ul id="notification-list" class="list-unstyled">
                    <?php if (empty($notifications)): ?>
                        <li class="text-muted text-center py-4">You have no notifications.</li>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <li class="p-3 mb-3 rounded shadow-sm border
                                <?php
                                    switch ($notification['status']) {
                                        case 'completed':
                                            echo 'bg-success-subtle border-success';
                                            break;
                                        case 'rejected':
                                            echo 'bg-danger-subtle border-danger';
                                            break;
                                        case 'pending':
                                            echo 'bg-warning-subtle border-warning';
                                            break;
                                        default:
                                            echo 'bg-light border-secondary';
                                            break;
                                    }
                                ?>" data-request-id="<?php echo htmlspecialchars($notification['request_id']); ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="fw-medium text-dark">
                                        <span class="text-capitalize"><?php echo htmlspecialchars($notification['transaction_type']); ?> Request</span> for
                                        <span class="fw-bold"><?php echo number_format($notification['amount'], 2); ?> <?php echo htmlspecialchars($notification['symbol']); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="text-sm fw-semibold
                                            <?php
                                                switch ($notification['status']) {
                                                    case 'completed':
                                                        echo 'text-success';
                                                        break;
                                                    case 'rejected':
                                                        echo 'text-danger';
                                                        break;
                                                    case 'pending':
                                                        echo 'text-warning';
                                                        break;
                                                    default:
                                                        echo 'text-secondary';
                                                        break;
                                                }
                                            ?>">
                                            Status: <?php echo htmlspecialchars(ucfirst($notification['status'])); ?>
                                        </span>
                                        <?php if ($notification['status'] !== 'pending'): ?>
                                            <a class="clear-single-notification btn btn-sm btn-link text-secondary" 
                                               href="dashboard.php?clear_notification_id=<?php echo (int)$notification['request_id']; ?>&user_id=<?php echo (int)$user_id; ?>&action=notifications"
                                               aria-label="Clear notification">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- AOS Animation JS -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init();
        
        // Initialize wallet distribution chart
        function initWalletChart() {
            const ctx = document.getElementById('walletDistributionChart').getContext('2d');
            const walletData = <?php echo json_encode($wallet_distribution); ?>;
            const totalMmk = <?php echo $total_mmk; ?>;

            if (walletData.length === 0 || totalMmk === 0) {
                document.getElementById('walletDistributionChart').parentElement.innerHTML = 
                    '<div class="text-center py-4"><i class="fas fa-wallet text-muted mb-2" style="font-size: 2rem;"></i><p class="text-muted">No wallet data available</p></div>';
                return;
            }

            const colors = [
                'rgba(59, 130, 246, 0.8)',  // Blue
                'rgba(16, 185, 129, 0.8)',  // Green
                'rgba(245, 158, 11, 0.8)',  // Yellow
                'rgba(239, 68, 68, 0.8)',   // Red
                'rgba(139, 92, 246, 0.8)',  // Purple
                'rgba(20, 184, 166, 0.8)'   // Teal
            ];

            const labels = walletData.map(item => item.symbol);
            const data = walletData.map(item => (item.amount_mmk / totalMmk * 100).toFixed(2));
            const amounts = walletData.map(item => item.amount_mmk.toLocaleString('en') + ' MMK');
            const backgroundColors = colors.slice(0, walletData.length);

            // Generate legend HTML
            let legendHtml = '<div class="d-flex flex-column gap-2">';
            walletData.forEach((item, index) => {
                const percentage = ((item.amount_mmk / totalMmk) * 100).toFixed(2);
                legendHtml += `
                    <div class="d-flex align-items-center mb-2">
                        <div style="width: 16px; height: 16px; background-color: ${colors[index]}; border-radius: 3px; margin-right: 10px;"></div>
                        <div class="d-flex justify-content-between w-100">
                            <span class="fw-medium">${item.symbol} (${item.name})</span>
                            <span class="ms-2 text-end">${percentage}%</span>
                        </div>
                    </div>
                    <div class="text-muted small mb-2" style="margin-left: 26px;">
                        ${item.balance.toLocaleString('en', {maximumFractionDigits: 8})} ${item.symbol} • ${item.amount_mmk.toLocaleString('en')} MMK
                    </div>
                `;
            });
            legendHtml += '</div>';
            document.getElementById('wallet-legend').innerHTML = legendHtml;

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColors,
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const amount = amounts[context.dataIndex] || '';
                                    return `${label}: ${value}% (${amount})`;
                                }
                            }
                        }
                    },
                    cutout: '70%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize wallet chart
            initWalletChart();
            const notificationsPage = document.getElementById('notifications-page');
            const dashboardContent = document.getElementById('dashboard-content');

            // Bind elements used for total balance currency switching
            const currencySelect = document.getElementById('currency-select');
            const totalBalanceEl = document.getElementById('total-balance');
            
            const showNotificationsBtn = document.getElementById('show-notifications-btn');
            const notificationCountElement = document.getElementById('notification-count');
            const clearAllNotificationsButton = document.getElementById('clear-all-notifications');

            // Provide required runtime values from PHP to avoid ReferenceErrors
            const notificationCount = <?php echo isset($notifications) ? (int)count($notifications) : 0; ?>;
            const userId = <?php echo isset($user_id) ? (int)$user_id : 0; ?>;
            // No JS nav toggling; navigation is URL-driven
            
            // Wallet balances by currency (generated safely via JSON)
            const walletBalances = <?php 
                $balances_map = [];
                foreach ($wallets as $w) {
                    $balances_map[$w['symbol']] = (float)$w['balance'];
                }
                echo json_encode($balances_map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            ?>;

            // Only show badge on dashboard view, not on notifications page
            if (!<?php echo $show_notifications ? 'true' : 'false'; ?> && notificationCount > 0) {
                if (notificationCountElement) {
                    notificationCountElement.textContent = notificationCount;
                    notificationCountElement.classList.remove('d-none');
                }
            } else {
                if (notificationCountElement) notificationCountElement.classList.add('d-none');
            }
            
            // Badge setup only; clearing uses URL redirects handled server-side

            // Build admin MMK cross map from today's exchangeRates
            const mmkRates = (() => {
                const out = { USD: null, JPY: null, THB: null };
                try {
                    exchangeRates.forEach(r => {
                        if (!r || !r.base_symbol || !r.target_symbol) return;
                        const b = r.base_symbol, t = r.target_symbol;
                        if (t === 'MMK' && ['USD','JPY','THB'].includes(b)) {
                            const val = parseFloat(r.rate);
                            if (!isNaN(val) && val > 0) out[b] = val;
                        }
                    });
                } catch (_) {}
                return out;
            })();

            // Get conversion rate from fromSymbol -> toSymbol using exchangeRates and admin cross via MMK
            const getRate = (fromSymbol, toSymbol) => {
                if (fromSymbol === toSymbol) return 1;
                // Prefer admin-consistent cross via MMK for USD<->JPY and USD<->THB
                const foreigns = ['JPY','THB'];
                if ((fromSymbol === 'USD' && foreigns.includes(toSymbol)) ||
                    (toSymbol === 'USD' && foreigns.includes(fromSymbol))) {
                    const A = 'USD';
                    const B = (fromSymbol === 'USD') ? toSymbol : fromSymbol; // JPY or THB
                    const usdMmk = mmkRates.USD;
                    const bMmk = mmkRates[B];
                    if (typeof usdMmk === 'number' && usdMmk > 0 && typeof bMmk === 'number' && bMmk > 0) {
                        const usd_to_B = usdMmk / bMmk; // 1 USD = X B
                        return (fromSymbol === 'USD') ? usd_to_B : (1 / usd_to_B);
                    }
                }
                // Try direct rate
                const direct = exchangeRates.find(r => r.base_symbol === fromSymbol && r.target_symbol === toSymbol);
                if (direct && direct.rate) return parseFloat(direct.rate);
                // Try inverse rate
                const inverse = exchangeRates.find(r => r.base_symbol === toSymbol && r.target_symbol === fromSymbol);
                if (inverse && inverse.rate) {
                    const inv = parseFloat(inverse.rate);
                    if (inv !== 0) return 1 / inv;
                }
                // Try bridging via USD if available
                const USD = 'USD';
                const toUSD = exchangeRates.find(r => r.base_symbol === fromSymbol && r.target_symbol === USD);
                const fromUSD = exchangeRates.find(r => r.base_symbol === USD && r.target_symbol === toSymbol);
                let rateToUSD = null, rateFromUSD = null;
                if (toUSD && toUSD.rate) rateToUSD = parseFloat(toUSD.rate);
                if (!rateToUSD) {
                    // Attempt inverse for USD bridge leg
                    const invToUSD = exchangeRates.find(r => r.base_symbol === USD && r.target_symbol === fromSymbol);
                    if (invToUSD && invToUSD.rate) {
                        const invVal = parseFloat(invToUSD.rate);
                        if (invVal !== 0) rateToUSD = 1 / invVal;
                    }
                }
                if (fromUSD && fromUSD.rate) rateFromUSD = parseFloat(fromUSD.rate);
                if (!rateFromUSD) {
                    const invFromUSD = exchangeRates.find(r => r.base_symbol === toSymbol && r.target_symbol === USD);
                    if (invFromUSD && invFromUSD.rate) {
                        const invVal = parseFloat(invFromUSD.rate);
                        if (invVal !== 0) rateFromUSD = 1 / invVal;
                    }
                }
                if (rateToUSD !== null && rateFromUSD !== null) {
                    return rateToUSD * rateFromUSD;
                }
                return null;
            };

            const updateBalance = () => {
                if (!currencySelect || !totalBalanceEl) return;
                const selectedSymbol = currencySelect.value;

                // Sum all wallet balances converted into selectedSymbol
                let totalInSelected = 0;
                Object.entries(walletBalances).forEach(([sym, bal]) => {
                    const balance = parseFloat(bal) || 0;
                    const rate = getRate(sym, selectedSymbol);
                    if (rate !== null) {
                        totalInSelected += balance * rate;
                    } else if (sym === selectedSymbol) {
                        totalInSelected += balance; // at least add same-currency if no rate object shape
                    }
                });

                totalBalanceEl.textContent = `${selectedSymbol} ${totalInSelected.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            };

            if (currencySelect) {
                currencySelect.addEventListener('change', updateBalance);
                // Trigger once on load to reflect the selected currency (e.g., MMK)
                updateBalance();
            }

            if (clearAllNotificationsButton) {
                clearAllNotificationsButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    window.location.href = `dashboard.php?clear_all=1&action=notifications`;
                });
            }
            
            const singleClearButtons = document.querySelectorAll('.clear-single-notification');
            singleClearButtons.forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const listItem = e.target.closest('li');
                    const requestId = listItem.dataset.requestId;
                    window.location.href = `dashboard.php?clear_notification_id=${requestId}&user_id=${userId}&action=notifications`;
                });
            });

            // Close modal when clicking outside
            const modal = document.querySelector('.modal');
            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        window.location.href = 'dashboard.php';
                    }
                });
            }
        });
    </script>
    
    <!-- Real-time Ban Check -->
    <?php 
    include 'ban_check_script.php'; 
    
    // Close the database connection here, after all database operations are done
    if (isset($conn) && $conn) {
        $conn->close();
    }
    ?>
</body>
</html>