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
    <title>User Dashboard | ACCUQURA</title>
    <link rel="icon" type="image/x-icon" href="/static/favicon.ico">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #8b76e6;
            --primary-light: #a594f3;
            --primary-dark: #6a5acd;
            --secondary: #8086ff;
            --accent: #95dfff;
            --light: #f8f4fc;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #f1f5f9;
            --purple:  #8b76e6;
            --white: #ffffff;
            --shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            --radius: 10px;
            --navradius:20px;
            --transition: all 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f8f7ff;
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.5;
            font-size: 0.9rem;
            overflow-x: hidden;
        }
        
        /* Navigation Bar */
        .navbar {
            background: var(--purple);
            padding: 0 30px;
            box-shadow: var(--shadow);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 100;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .nav-container {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 60px;
        }
        
        .logo {
            color: var(--white);
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .nav-menu {
            display: flex;
            list-style: none;
            margin-bottom: 0;
        }
        
        .nav-item {
            margin-left: 20px;
        }
        
        .nav-link {
            color: var(--white);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: var(--navradius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            font-size: 0.85rem;
        }
        
        .nav-link:hover, .nav-link.active {
            background-color: var(--light);
            color: var(--primary);
        }
        
        .nav-link i {
            margin-right: 6px;
            font-size: 1rem;
        }
        
        .notification-badge {
            background-color: var(--danger);
            color: var(--white);
            border-radius: 50%;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            margin-left: 6px;
        }
        
        .dashboard {
            width: 100%;
            min-height: calc(100vh - 60px);
            padding: 20px;
            margin-top: 60px;
        }
        
        /* Section Styles */
        .section {
            display: none;
        }
        
        .section.active {
            display: block;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto auto auto 1fr;
            gap: 20px;
            height: 100%;
            max-width: 100%;
        }
        
        /* User Profile */
         .user-profile {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, var(--accent) 0%, var(--secondary) 100%);
            padding: 20px 25px;
            border-radius: var(--radius);
            color: var(--white);
        }
        
        .profile-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        .profile-info h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .profile-info p {
            color: var(--light);
            font-size: 0.8rem;
        }
        
        /* Main Stats */
        .main-stats {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border-top: 3px solid transparent;
            border: 3px solid var(--light-gray);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card.total-balance {
            border-left-color: var(--accent);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 160px;
            background: linear-gradient(135deg, #f8fbff 0%, var(--white) 100%);
        }
        
        .currency-selector {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        
        .currency-selector select {
            padding: 8px 12px;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.85rem;
            background: var(--white);
            color: var(--dark);
            box-shadow: var(--shadow);
        }
        
        .stat-card.successful-trades {
            border-top-color: var(--primary);
        }
        
        .stat-card.pending {
            border-top-color: var(--warning);
        }
        
        .stat-title {
            font-size: 0.8rem;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray);
        }
        
        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .total-balance .total-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 10px 0;
            color: var(--primary);
        }
        
        /* Currency Balances Section */
        .currency-balances {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        .currency-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border-top: 3px solid transparent;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 140px;
            border: 3px solid var(--light-gray);
        }
        
        .currency-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .currency-card.usd {
            border-top-color: var(--accent);
        }
        
        .currency-card.mmk {
            border-top-color: var(--secondary);
        }
        
        .currency-card.thb {
            border-top-color: var(--primary);
        }
        
        .currency-card.jpy {
            border-top-color: var(--warning);
        }
        
        .currency-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .currency-card.usd .currency-icon {
            color: var(--accent);
        }
        
        .currency-card.mmk .currency-icon {
            color: var(--secondary);
        }
        
        .currency-card.thb .currency-icon {
            color: var(--primary);
        }
        
        .currency-card.jpy .currency-icon {
            color: var(--warning);
        }
        
        .currency-name {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        .currency-amount {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .chart-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 2px solid var(--light-gray);
        }
        
        .chart-section h2 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .chart-container {
            height: 200px;
            position: relative;
        }
        
        .performance-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 2px solid var(--light-gray);
        }
        
        .performance-section h2 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .performance-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .performance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 6px;
            background: var(--light);
            transition: var(--transition);
            border: 1px solid var(--light-gray);
        }
        
        .performance-item:hover {
            background: var(--accent);
        }
        
        .currency {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .currency i {
            color: var(--primary);
        }
        
        .percentage {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .actions-section {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .action-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid var(--light-gray);
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            border-color: var(--primary);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .action-card i {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        .action-card.deposit i {
            color: var(--success);
        }
        
        .action-card.withdraw i {
            color: var(--warning);
        }
        
        .action-card.convert i {
            color: var(--primary);
        }
        
        .action-card h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .action-card p {
            color: var(--gray);
            font-size: 0.8rem;
        }
        
        .exchange-section {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .exchange-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 2px solid var(--light-gray);
        }
        
        .exchange-card:hover {
            border-color: var(--primary);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .exchange-card h3 {
            margin-bottom: 12px;
            color: var(--dark);
            font-size: 1rem;
            font-weight: 600;
        }
        
        .divider {
            height: 1px;
            background: var(--light-gray);
            margin: 15px 0;
        }
        
        /* Exchange Rate Table */
        .exchange-rate-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .exchange-rate-table th {
            text-align: left;
            padding: 12px 15px;
            border-bottom: 2px solid var(--light-gray);
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
        }
        
        .exchange-rate-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.85rem;
        }
        
        .exchange-rate-table tr:last-child td {
            border-bottom: none;
        }
        
        .exchange-rate-table tr:hover {
            background: var(--light);
        }
        
        .rate-change {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .rate-change.up {
            color: var(--success);
        }
        
        .rate-change.down {
            color: var(--danger);
        }
        
        /* Action Forms */
        .action-form {
            background: var(--white);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: none;
            border: 2px solid var(--light-gray);
            grid-column: 1 / -1;
            position: relative;
        }
        
        .form-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            justify-content: space-between;
        }
        
        .form-title-section {
            display: flex;
            align-items: center;
        }
        
        .form-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: var(--white);
            font-size: 1.2rem;
        }
        
        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .close-icon {
            background: none;
            border: none;
            color: var(--gray);
            font-size: 1.2rem;
            cursor: pointer;
            transition: var(--transition);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-icon:hover {
            background: var(--light-gray);
            color: var(--dark);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.85rem;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(139, 118, 230, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: var(--white);
            border: none;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            opacity: 0.9;
        }
        
        /* Notifications Section */
        .notifications-section {
            background: var(--white);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border: 2px solid var(--light-gray);
        }
        
        .notification-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-item:hover {
            background: var(--light);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--white);
            font-size: 1.2rem;
            border: 1px solid var(--light-gray);
        }
        
        .notification-icon.deposit {
            background: var(--success);
        }
        
        .notification-icon.withdraw {
            background: var(--warning);
        }
        
        .notification-icon.trade {
            background: var(--primary);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--dark);
        }
        
        .notification-desc {
            color: var(--gray);
            font-size: 0.85rem;
        }
        
        .notification-time {
            color: var(--gray);
            font-size: 0.8rem;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .main-stats {
                grid-template-columns: 1fr;
            }
            
            .currency-balances {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-section {
                grid-template-columns: 1fr;
            }
            
            .exchange-section {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .history-table {
                display: block;
                overflow-x: auto;
            }
            
            .nav-menu {
                flex-direction: column;
                position: absolute;
                top: 60px;
                left: 0;
                width: 100%;
                background: var(--purple);
                padding: 10px 0;
                display: none;
            }
            
            .nav-menu.show {
                display: flex;
            }
            
            .nav-item {
                margin: 5px 0;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
        }
        
        @media (max-width: 576px) {
            .currency-balances {
                grid-template-columns: 1fr;
            }
            
            .dashboard {
                padding: 15px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-avatar {
                margin-right: 0;
                margin-bottom: 15px;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Alert Styles */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: rgba(74, 222, 128, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background-color: rgba(248, 113, 113, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        .alert-info {
            background-color: rgba(149, 223, 255, 0.1);
            border-color: var(--accent);
            color: var(--accent);
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">
                <i class="fas fa-exchange-alt"></i>
                ACCUQURA
            </div>
            
            <button class="mobile-menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" class="nav-link <?php echo !$show_notifications ? 'active' : ''; ?>" onclick="showSection('dashboard')">
                        <i class="fas fa-chart-line"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="p2pTradeList.php" class="nav-link">
                        <i class="fas fa-exchange-alt"></i> P2P Trade
                    </a>
                </li>
                <li class="nav-item">
                    <a href="p2pTransactionHistory.php" class="nav-link">
                        <i class="fas fa-history"></i> P2P History
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user"></i> Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link <?php echo $show_notifications ? 'active' : ''; ?>" onclick="showSection('notifications')">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="dashboard">
        <!-- Dashboard Section -->
        <div id="dashboard" class="section <?php echo !$show_notifications ? 'active' : ''; ?>">
            <div class="dashboard-grid">
                <!-- User Profile Section -->
                <div class="user-profile">
                    <div class="profile-circle">
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
                            <img src="<?php echo htmlspecialchars($image_path_card); ?>" alt="User" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                        <?php else: ?>
                            <?php echo htmlspecialchars($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h2>Welcome, <?php echo htmlspecialchars($session_username); ?>!</h2>
                        <p>Your Personal Dashboard</p>
                    </div>
                </div>
            
                <!-- Main Stats - Single Total Balance Section -->
                <div class="main-stats">
                    <div class="stat-card total-balance">
                        <div class="currency-selector">
                            <select id="balanceCurrency" onchange="updateBalanceCurrency()">
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
                        <div class="stat-title">Total Balance</div>
                        <div class="total-value" id="totalBalanceValue">$<?php echo number_format($total_balance_usd, 2); ?></div>
                    </div>
                    
                    <div class="stat-card successful-trades">
                        <div class="stat-title">Successful Trades</div>
                        <div class="stat-value"><?php 
                            // Count successful trades from transaction history
                            $stmt_trades = $conn->prepare("SELECT COUNT(*) as count FROM conversion_history WHERE user_id = ?");
                            $stmt_trades->bind_param("i", $user_id);
                            $stmt_trades->execute();
                            $result_trades = $stmt_trades->get_result();
                            $trades_count = $result_trades->fetch_assoc()['count'];
                            $stmt_trades->close();
                            echo $trades_count;
                        ?></div>
                    </div>
                    
                    <div class="stat-card pending">
                        <div class="stat-title">Pending</div>
                        <div class="stat-value"><?php 
                            // Count pending notifications
                            $pending_count = 0;
                            foreach ($notifications as $notification) {
                                if ($notification['status'] === 'pending') {
                                    $pending_count++;
                                }
                            }
                            echo $pending_count;
                        ?></div>
                    </div>
                </div>
                
                <!-- Currency Balances Section -->
                <div class="currency-balances">
                    <?php foreach ($wallets as $wallet): ?>
                        <div class="currency-card <?php echo strtolower($wallet['symbol']); ?>">
                            <div class="currency-icon">
                                <?php 
                                    $icon_class = '';
                                    switch($wallet['symbol']) {
                                        case 'USD': $icon_class = 'fas fa-dollar-sign'; break;
                                        case 'MMK': $icon_class = 'fas fa-money-bill-wave'; break;
                                        case 'THB': $icon_class = 'fas fa-baht-sign'; break;
                                        case 'JPY': $icon_class = 'fas fa-yen-sign'; break;
                                        default: $icon_class = 'fas fa-coins'; break;
                                    }
                                ?>
                                <i class="<?php echo $icon_class; ?>"></i>
                            </div>
                            <div class="currency-name"><?php echo htmlspecialchars($wallet['symbol']); ?></div>
                            <div class="currency-amount"><?php echo number_format($wallet['balance'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="chart-section">
                    <h2>Currency Distribution</h2>
                    <div class="chart-container">
                        <canvas id="currencyChart"></canvas>
                    </div>
                </div>
                
                <div class="performance-section">
                    <h2>Wallet Percentage</h2>
                    <div class="performance-list">
                        <?php 
                        // Calculate total MMK value of all wallets
                        $total_mmk_value = 0;
                        $wallet_percentages = [];
                        
                        // First pass: calculate total MMK value
                        foreach ($wallets as $wallet) {
                            $amount_mmk = 0;
                            $symbol = $wallet['symbol'];
                            $balance = (float)$wallet['balance'];
                            
                            if ($symbol === 'MMK') {
                                $amount_mmk = $balance;
                            } else {
                                // Find the wallet in distribution data which has the MMK conversion
                                foreach ($wallet_distribution as $dist) {
                                    if ($dist['symbol'] === $symbol) {
                                        $amount_mmk = $dist['amount_mmk'];
                                        break;
                                    }
                                }
                            }
                            
                            $wallet_percentages[$symbol] = [
                                'amount_mmk' => $amount_mmk,
                                'balance' => $balance,
                                'icon_class' => ''
                            ];
                            
                            // Set icon class
                            switch($symbol) {
                                case 'USD': $wallet_percentages[$symbol]['icon_class'] = 'fas fa-dollar-sign'; break;
                                case 'MMK': $wallet_percentages[$symbol]['icon_class'] = 'fas fa-money-bill-wave'; break;
                                case 'THB': $wallet_percentages[$symbol]['icon_class'] = 'fas fa-baht-sign'; break;
                                case 'JPY': $wallet_percentages[$symbol]['icon_class'] = 'fas fa-yen-sign'; break;
                                default: $wallet_percentages[$symbol]['icon_class'] = 'fas fa-coins'; break;
                            }
                            
                            $total_mmk_value += $amount_mmk;
                        }
                        
                        // Second pass: calculate and display percentages
                        if ($total_mmk_value > 0) {
                            // Sort by MMK value (descending)
                            uasort($wallet_percentages, function($a, $b) {
                                return $b['amount_mmk'] <=> $a['amount_mmk'];
                            });
                            
                            foreach ($wallet_percentages as $symbol => $data): 
                                $percentage = $total_mmk_value > 0 ? ($data['amount_mmk'] / $total_mmk_value) * 100 : 0;
                                $formatted_percentage = number_format($percentage, 2);
                                $formatted_balance = number_format($data['balance'], 2);
                                $formatted_mmk = number_format($data['amount_mmk'], 2);
                                $tooltip = "{$formatted_balance} {$symbol} • {$formatted_mmk} MMK";
                            ?>
                                <div class="performance-item" title="<?php echo htmlspecialchars($tooltip); ?>">
                                    <div class="currency">
                                        <i class="<?php echo $data['icon_class']; ?>"></i>
                                        <?php echo htmlspecialchars($symbol); ?>
                                    </div>
                                    <div class="percentage">
                                        <?php echo $formatted_percentage; ?>%
                                    </div>
                                </div>
                            <?php 
                            endforeach; 
                        } else {
                            echo '<div class="text-center py-3 text-muted">No wallet data available</div>';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="actions-section">
                    <div class="action-card deposit" onclick="window.location.href='deposit_form.php'">
                        <i class="fas fa-plus-circle"></i>
                        <h3>Deposit</h3>
                        <p>Add funds to your account</p>
                    </div>
                    <div class="action-card withdraw" onclick="window.location.href='withdraw_form.php'">
                        <i class="fas fa-minus-circle"></i>
                        <h3>Withdraw</h3>
                        <p>Transfer funds to your bank</p>
                    </div>
                    <div class="action-card convert" onclick="showForm('convert')">
                        <i class="fas fa-exchange-alt"></i>
                        <h3>Convert</h3>
                        <p>Exchange between currencies</p>
                    </div>
                </div>
                
                <!-- Convert Form -->
                <div id="convertForm" class="action-form">
                    <div class="form-header">
                        <div class="form-title-section">
                            <div class="form-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <div class="form-title">Convert Currency</div>
                        </div>
                        <button class="close-icon" onclick="hideForms()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <?php if (!empty($message)): ?>
                        <?php echo $message; ?>
                    <?php endif; ?>
                    <form method="POST" action="dashboard.php">
                        <input type="hidden" name="action" value="convert_currency">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">From Currency</label>
                                <select class="form-control" id="fromCurrency" name="from_currency" required onchange="updateConversion()">
                                    <option value="">Select currency</option>
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
                            </div>
                            <div class="form-group">
                                <label class="form-label">To Currency</label>
                                <select class="form-control" id="toCurrency" name="to_currency" required onchange="calculateConversion()">
                                    <option value="">Select currency</option>
                                    <?php foreach ($currencies as $cur): ?>
                                        <option value="<?php echo (int)$cur['currency_id']; ?>" data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>">
                                            <?php echo htmlspecialchars($cur['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" class="form-control" id="convertAmount" name="amount" placeholder="Enter amount" step="0.01" min="0" oninput="calculateConversion()" required>
                            <small id="availableBalance" class="text-muted"></small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">You Will Receive (After 5% Tax)</label>
                            <div class="form-control" style="background-color: var(--light); font-weight: 600;">
                                <span id="convertedAmount">0.00</span> 
                                <span id="targetCurrency">---</span>
                            </div>
                            <small style="color: var(--gray); font-size: 0.8rem;" id="exchangeRateInfo">Exchange rate: --</small>
                        </div>
                        <button type="submit" class="submit-btn">Convert Currency</button>
                    </form>
                </div>
                
                <div class="exchange-section">
                    <div class="exchange-card">
                        <h3>Exchange Rates</h3>
                        <div class="divider"></div>
                        <table class="exchange-rate-table">
                            <thead>
                                <tr>
                                    <th>Currency Pair</th>
                                    <th>Rate</th>
                                    <th>Last Updated</th>
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
                                        // Format the timestamp for display
                                        $formatted_timestamp = date('M j, Y H:i', strtotime($timestamp));
                                ?>
                                <tr>
                                    <td><?php echo $base; ?>/<?php echo $target; ?></td>
                                    <td><?php echo number_format((float)$rate, 4, '.', ''); ?></td>
                                    <td><?php echo $formatted_timestamp; ?></td>
                                </tr>
                                <?php 
                                    }
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="exchange-card">
                        <h3>Recent Transactions</h3>
                        <div class="divider"></div>
                        <div class="performance-list">
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
                                LIMIT 3
                            ") or die($conn->error);
                            $stmt->bind_param("i", $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $recent_conversions = $result->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();

                            if (count($recent_conversions) > 0):
                                foreach ($recent_conversions as $conversion):
                            ?>
                            <div class="performance-item">
                                <div class="currency">
                                    <i class="fas fa-exchange-alt"></i> <?php echo $conversion['from_symbol']; ?> to <?php echo $conversion['to_symbol']; ?>
                                </div>
                                <div class="percentage">
                                    <?php echo number_format($conversion['amount'], 2); ?> <?php echo $conversion['from_symbol']; ?> → 
                                    <?php echo number_format($conversion['converted_amount'], 2); ?> <?php echo $conversion['to_symbol']; ?>
                                </div>
                            </div>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <div class="performance-item">
                                <div class="currency">
                                    <i class="fas fa-exchange-alt"></i> No recent transactions
                                </div>
                                <div class="percentage">---</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notifications Section -->
        <div id="notifications" class="section <?php echo $show_notifications ? 'active' : ''; ?>">
            <div class="notifications-section">
                <div class="form-header">
                    <div class="form-title-section">
                        <div class="form-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="form-title">Notifications</div>
                    </div>
                    <a href="dashboard.php?clear_all=1&action=notifications" class="btn btn-sm btn-secondary">Clear All</a>
                </div>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-bell-slash fa-2x text-muted mb-3"></i>
                            <p class="text-muted">You have no notifications.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-icon 
                                    <?php
                                        switch ($notification['transaction_type']) {
                                            case 'deposit': echo 'deposit'; break;
                                            case 'withdraw': echo 'withdraw'; break;
                                            default: echo 'trade'; break;
                                        }
                                    ?>">
                                    <i class="fas 
                                        <?php
                                            switch ($notification['transaction_type']) {
                                                case 'deposit': echo 'fa-plus-circle'; break;
                                                case 'withdraw': echo 'fa-minus-circle'; break;
                                                default: echo 'fa-exchange-alt'; break;
                                            }
                                        ?>"></i>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title">
                                        <?php echo ucfirst($notification['transaction_type']); ?> Request - 
                                        <span class="
                                            <?php
                                                switch ($notification['status']) {
                                                    case 'completed': echo 'text-success'; break;
                                                    case 'rejected': echo 'text-danger'; break;
                                                    case 'pending': echo 'text-warning'; break;
                                                    default: echo 'text-secondary'; break;
                                                }
                                            ?>">
                                            <?php echo ucfirst($notification['status']); ?>
                                        </span>
                                    </div>
                                    <div class="notification-desc">
                                        Amount: <?php echo number_format($notification['amount'], 2); ?> <?php echo $notification['symbol']; ?>
                                    </div>
                                </div>
                                <?php if ($notification['status'] !== 'pending'): ?>
                                    <a class="clear-single-notification" 
                                       href="dashboard.php?clear_notification_id=<?php echo (int)$notification['request_id']; ?>&user_id=<?php echo (int)$user_id; ?>&action=notifications">
                                        <i class="fas fa-times text-muted"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ========== SECTION MANAGEMENT FUNCTIONS ==========
        
        // Show section function
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all nav links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Show the selected section
            document.getElementById(sectionId).classList.add('active');
            
            // Add active class to the clicked nav link
            event.target.classList.add('active');
            
            // Hide any open forms
            hideForms();
            
            // Update URL without page reload
            const url = new URL(window.location);
            if (sectionId === 'notifications') {
                url.searchParams.set('action', 'notifications');
            } else {
                url.searchParams.delete('action');
            }
            window.history.pushState({}, '', url);
        }

        // ========== FORM MANAGEMENT FUNCTIONS ==========
        
        // Show form function
        function showForm(formType) {
            // Hide all forms first
            hideForms();
            
            // Show the selected form
            document.getElementById(formType + 'Form').style.display = 'block';
            
            // Scroll to the form
            document.getElementById(formType + 'Form').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }

        // Hide all forms
        function hideForms() {
            document.getElementById('convertForm').style.display = 'none';
        }

        // ========== CURRENCY CONVERSION FUNCTIONS ==========
        
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

        // Update conversion info
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
        
        // Calculate conversion
        function calculateConversion() {
            const fromSel = document.getElementById('fromCurrency');
            const toSel = document.getElementById('toCurrency');
            const amount = parseFloat(document.getElementById('convertAmount').value) || 0;
            
            if (!fromSel || !toSel || amount <= 0) {
                document.getElementById('convertedAmount').textContent = '0.00';
                document.getElementById('targetCurrency').textContent = '---';
                document.getElementById('exchangeRateInfo').textContent = 'Exchange rate: --';
                return;
            }
            
            const fromId = fromSel.value;
            const toId = toSel.value;
            if (!fromId || !toId) {
                document.getElementById('convertedAmount').textContent = '0.00';
                document.getElementById('targetCurrency').textContent = '---';
                document.getElementById('exchangeRateInfo').textContent = 'Exchange rate: --';
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
                document.getElementById('targetCurrency').textContent = toSym || '---';
                document.getElementById('exchangeRateInfo').textContent = 
                    `Exchange rate: 1 ${fromSym || ''} = ${fx.toFixed(4)} ${toSym || ''} (After 5% tax)`;
            } else {
                document.getElementById('convertedAmount').textContent = '---';
                document.getElementById('targetCurrency').textContent = toSym || '---';
                document.getElementById('exchangeRateInfo').textContent = 'Exchange rate not available';
            }
        }

        // Update balance currency
        function updateBalanceCurrency() {
            const currencySelect = document.getElementById('balanceCurrency');
            const selectedCurrency = currencySelect.value;
            
            // Wallet balances data from PHP
            const walletBalances = <?php 
                $balances_map = [];
                foreach ($wallets as $w) {
                    $balances_map[$w['symbol']] = (float)$w['balance'];
                }
                echo json_encode($balances_map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            ?>;
            
            // Sum all wallet balances converted into selectedSymbol
            let totalInSelected = 0;
            Object.entries(walletBalances).forEach(([sym, bal]) => {
                const balance = parseFloat(bal) || 0;
                const rate = getRate(sym, selectedCurrency);
                if (rate !== null) {
                    totalInSelected += balance * rate;
                } else if (sym === selectedCurrency) {
                    totalInSelected += balance; // at least add same-currency if no rate object shape
                }
            });

            document.getElementById('totalBalanceValue').textContent = 
                `${selectedCurrency} ${totalInSelected.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        // ========== CHART INITIALIZATION ==========
        
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Currency Distribution Chart (Pie Chart)
            const currencyCtx = document.getElementById('currencyChart').getContext('2d');
            
            // Prepare data for chart
            const walletData = <?php echo json_encode($wallet_distribution); ?>;
            const labels = walletData.map(item => item.symbol);
            const data = walletData.map(item => item.amount_mmk);
            const backgroundColors = [
                '#95dfff', // USD - accent
                '#8086ff', // MMK - secondary
                '#8b76e6', // THB - primary
                '#fbbf24'  // JPY - warning
            ];
            
            // If no data, show placeholder
            if (walletData.length === 0) {
                document.getElementById('currencyChart').parentElement.innerHTML = 
                    '<div class="text-center py-4"><i class="fas fa-wallet text-muted mb-2" style="font-size: 2rem;"></i><p class="text-muted">No wallet data available</p></div>';
                return;
            }
            
            const currencyChart = new Chart(currencyCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColors.slice(0, walletData.length),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: {
                                    size: 10
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            // Mobile menu toggle
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            const navMenu = document.querySelector('.nav-menu');
            
            if (mobileMenuToggle && navMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    navMenu.classList.toggle('show');
                });
            }
            
            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.nav-container')) {
                    navMenu.classList.remove('show');
                }
            });
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