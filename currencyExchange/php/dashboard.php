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
$message = '';
$show_notifications = false;
$wallet_distribution = [];
$total_mmk = 0;

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

// Ensure conversion_history table exists
$conn->query("CREATE TABLE IF NOT EXISTS conversion_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_currency_id INT NOT NULL,
    to_currency_id INT NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    converted_amount DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL,
    exchange_rate DECIMAL(24,8) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure required columns exist (for older databases created before these columns)
function ensureColumnExists($conn, $table, $column, $definition) {
    $sql = "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $exists = $row && intval($row['cnt']) > 0;
        if (!$exists) {
            $conn->query("ALTER TABLE `" . $conn->real_escape_string($table) . "` ADD COLUMN `" . $conn->real_escape_string($column) . "` " . $definition);
        }
    }
}

ensureColumnExists($conn, 'conversion_history', 'tax_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'conversion_history', 'exchange_rate', 'DECIMAL(24,8) NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'conversion_history', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
// Ensure critical foreign key/link columns and amounts exist (older installs)
ensureColumnExists($conn, 'conversion_history', 'from_currency_id', 'INT NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'conversion_history', 'to_currency_id', 'INT NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'conversion_history', 'amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
ensureColumnExists($conn, 'conversion_history', 'converted_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0');

// Handle currency conversion
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'convert_currency'
) {
    // CSRF validation
    $csrf_ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
    if (!$csrf_ok) {
        $message = "<div class='notification error show'>Security check failed. Please refresh the page and try again.</div>";
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
                        
                        // Ensure unified fees table exists and supports both conversion/withdrawal
                        $conn->query("CREATE TABLE IF NOT EXISTS fees (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            operation_type VARCHAR(20) NOT NULL DEFAULT 'conversion',
                            request_id INT NULL,
                            user_id INT NOT NULL,
                            from_currency_id INT NULL,
                            to_currency_id INT NULL,
                            currency_id INT NULL,
                            amount_converted DECIMAL(18,2) NOT NULL,
                            tax_amount DECIMAL(18,2) NOT NULL,
                            tax_rate DECIMAL(5,4) NOT NULL,
                            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX (operation_type), INDEX (user_id), INDEX (created_at)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                        // Record conversion fee for profit tracking (operation_type = conversion)
                        $stmt_fee = $conn->prepare("
                            INSERT INTO fees (operation_type, user_id, from_currency_id, to_currency_id, amount_converted, tax_amount, tax_rate)
                            VALUES ('conversion', ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt_fee->bind_param("iiiddd", $user_id, $from_currency, $to_currency, $amount, $tax_amount, $tax_rate);
                        $stmt_fee->execute();

                        // Record conversion history
                        $stmt_hist = $conn->prepare("INSERT INTO conversion_history (user_id, from_currency_id, to_currency_id, amount, converted_amount, tax_amount, exchange_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_hist->bind_param("iiidddd", $user_id, $from_currency, $to_currency, $amount, $converted_amount, $tax_amount, $exchange_rate);
                        $stmt_hist->execute();
                        
                        $conn->commit();
                        $message = "<div class='notification success show'><strong>Conversion Successful!</strong><br>Amount: " . number_format($amount, 2) . "<br>Tax (5%): " . number_format($tax_amount, 2) . "<br>You received: " . number_format($converted_amount, 2) . "</div>";
                    } else {
                        throw new Exception("Insufficient balance for conversion.");
                    }
                } else {
                    throw new Exception("Source wallet not found.");
                }
            } else {
                // No direct pair: try triangulation via MMK (e.g., USD->MMK and JPY->MMK)
                // 1) Resolve MMK currency_id
                $mmk_id = null;
                $stmt_mmk = $conn->prepare("SELECT currency_id FROM currencies WHERE UPPER(symbol) = 'MMK' LIMIT 1");
                if ($stmt_mmk) {
                    $stmt_mmk->execute();
                    $res_mmk = $stmt_mmk->get_result();
                    if ($row_mmk = $res_mmk->fetch_assoc()) {
                        $mmk_id = (int)$row_mmk['currency_id'];
                    }
                    $stmt_mmk->close();
                }

                if ($mmk_id) {
                    // 2) Fetch latest base->MMK and target->MMK rates
                    $rb = null; $rt = null;
                    $stmt_bm = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                    $stmt_tm = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                    if ($stmt_bm && $stmt_tm) {
                        // base -> MMK
                        $stmt_bm->bind_param("ii", $from_currency, $mmk_id);
                        $stmt_bm->execute();
                        $res_bm = $stmt_bm->get_result();
                        if ($row_bm = $res_bm->fetch_assoc()) { $rb = (float)$row_bm['rate']; }
                        $stmt_bm->close();

                        // target -> MMK
                        $stmt_tm->bind_param("ii", $to_currency, $mmk_id);
                        $stmt_tm->execute();
                        $res_tm = $stmt_tm->get_result();
                        if ($row_tm = $res_tm->fetch_assoc()) { $rt = (float)$row_tm['rate']; }
                        $stmt_tm->close();
                    }

                    if (!empty($rb) && !empty($rt) && $rb > 0 && $rt > 0) {
                        // base/target = (base/MMK) / (target/MMK)
                        $exchange_rate = $rb / $rt;

                        // Calculate conversion with 5% tax (same as direct path)
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

                                // Add tax to admin wallet (collected in source currency)
                                $stmt_admin_tax = $conn->prepare("
                                    INSERT INTO admin_wallet (admin_id, currency_id, balance) 
                                    VALUES (?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)
                                ");
                                $stmt_admin_tax->bind_param("iid", $admin_id, $from_currency, $tax_amount);
                                $stmt_admin_tax->execute();

                                // Ensure unified fees table exists
                                $conn->query("CREATE TABLE IF NOT EXISTS fees (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    operation_type VARCHAR(20) NOT NULL DEFAULT 'conversion',
                                    request_id INT NULL,
                                    user_id INT NOT NULL,
                                    from_currency_id INT NULL,
                                    to_currency_id INT NULL,
                                    currency_id INT NULL,
                                    amount_converted DECIMAL(18,2) NOT NULL,
                                    tax_amount DECIMAL(18,2) NOT NULL,
                                    tax_rate DECIMAL(5,4) NOT NULL,
                                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                    INDEX (operation_type), INDEX (user_id), INDEX (created_at)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                                // Record conversion fee for profit tracking (operation_type = conversion)
                                $stmt_fee = $conn->prepare("
                                    INSERT INTO fees (operation_type, user_id, from_currency_id, to_currency_id, amount_converted, tax_amount, tax_rate)
                                    VALUES ('conversion', ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt_fee->bind_param("iiiddd", $user_id, $from_currency, $to_currency, $amount, $tax_amount, $tax_rate);
                                $stmt_fee->execute();

                                // Record conversion history (triangulated)
                                $stmt_hist = $conn->prepare("INSERT INTO conversion_history (user_id, from_currency_id, to_currency_id, amount, converted_amount, tax_amount, exchange_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt_hist->bind_param("iiidddd", $user_id, $from_currency, $to_currency, $amount, $converted_amount, $tax_amount, $exchange_rate);
                                $stmt_hist->execute();

                                $conn->commit();
                                $message = "<div class='notification success show'><strong>Conversion Successful!</strong><br>Amount: " . number_format($amount, 2) . "<br>Tax (5%): " . number_format($tax_amount, 2) . "<br>You received: " . number_format($converted_amount, 2) . "</div>";
                            } else {
                                throw new Exception("Insufficient balance for conversion.");
                            }
                        } else {
                            throw new Exception("Source wallet not found.");
                        }
                    } else {
                        throw new Exception("Exchange rate not available for this currency pair.");
                    }
                } else {
                    throw new Exception("Exchange rate not available for this currency pair.");
                }
            }
            $stmt_rate->close();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='notification error show'>Conversion failed: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='notification error show'>Invalid conversion parameters.</div>";
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
        $message = "<div class='notification success show'>" . $message_text . "</div>";
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
                    // Convert to USD for total balance
if ($symbol !== 'USD') {
    // Special handling for MMK to avoid conversion issues
    if ($symbol === 'MMK') {
        // For MMK, use direct rate to USD
        $stmt_mmk_usd = $conn->prepare("
            SELECT rate FROM exchange_rates 
            WHERE base_currency_id = ? AND target_currency_id = (
                SELECT currency_id FROM currencies WHERE symbol = 'USD' LIMIT 1
            )
            ORDER BY timestamp DESC LIMIT 1
        ") or die($conn->error);
        $stmt_mmk_usd->bind_param("i", $row['currency_id']);
        $stmt_mmk_usd->execute();
        $mmk_usd_result = $stmt_mmk_usd->get_result();
        
        if ($mmk_usd_row = $mmk_usd_result->fetch_assoc()) {
            // Convert MMK to USD using direct rate
            $total_balance_usd += $balance / (float)$mmk_usd_row['rate'];
        } else {
            // Fallback: use inverse of USD->MMK rate
            $stmt_usd_mmk = $conn->prepare("
                SELECT rate FROM exchange_rates 
                WHERE base_currency_id = (
                    SELECT currency_id FROM currencies WHERE symbol = 'USD' LIMIT 1
                ) AND target_currency_id = ?
                ORDER BY timestamp DESC LIMIT 1
            ") or die($conn->error);
            $stmt_usd_mmk->bind_param("i", $row['currency_id']);
            $stmt_usd_mmk->execute();
            $usd_mmk_result = $stmt_usd_mmk->get_result();
            
            if ($usd_mmk_row = $usd_mmk_result->fetch_assoc()) {
                $usd_to_mmk_rate = (float)$usd_mmk_row['rate'];
                if ($usd_to_mmk_rate > 0) {
                    $total_balance_usd += $balance / $usd_to_mmk_rate;
                }
            }
            $stmt_usd_mmk->close();
        }
        $stmt_mmk_usd->close();
    } else {
        // For other currencies (JPY, THB), use existing logic
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
    }
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

        // Fetch recent conversion history (last 6) for this user
        $recent_conversions = [];
        if ($stmt_recent = $conn->prepare("SELECT ch.id, ch.amount, ch.converted_amount, ch.tax_amount, ch.exchange_rate, ch.created_at,
                                                  f.symbol AS from_symbol, t.symbol AS to_symbol
                                           FROM conversion_history ch
                                           JOIN currencies f ON f.currency_id = ch.from_currency_id
                                           JOIN currencies t ON t.currency_id = ch.to_currency_id
                                           WHERE ch.user_id = ?
                                           ORDER BY ch.id DESC
                                           LIMIT 6")) {
            $stmt_recent->bind_param("i", $user_id);
            $stmt_recent->execute();
            $res_recent = $stmt_recent->get_result();
            while ($row = $res_recent->fetch_assoc()) {
                $recent_conversions[] = $row;
            }
            $stmt_recent->close();
        }

        // Get user's transaction requests for the notification tab
        $notifications = [];
      $stmt_notifications = $conn->prepare("
    SELECT ucr.amount, ucr.transaction_type, ucr.status, c.symbol, ucr.request_id, ucr.request_timestamp, ucr.decision_timestamp, ucr.payment_channel, ucr.reject_reason
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
    // Close the database connection
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | ACCQURA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inria+Sans:wght@400;700&display=swap" rel="stylesheet">
    <!-- Add Chart.js for pie chart -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .content-section {
            display: none;
        }
        
        .content-section.active {
            display: block;
        }
        
        .notification {
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
        }
        
        .notification.show {
            opacity: 1;
            transform: translateX(-50%) translateY(10px);
        }
        
        .notification.success {
            background: rgba(16, 185, 129, 0.9);
            color: white;
        }
        
        .notification.error {
            background: rgba(239, 68, 68, 0.9);
            color: white;
        }
        
        .notification.info {
            background: rgba(59, 130, 246, 0.9);
            color: white;
        }
        
        .balance-card {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .balance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .balance-header h2 {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .currency-select {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .currency-select option {
            color: #333;
        }
        
        .balance-amount {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .balance-change {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
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
        
        .wallets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .wallet-card {
            background-color: white;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .wallet-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .wallet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .wallet-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f0f9f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2e7d32;
            font-size: 1.1rem;
        }
        
        .wallet-symbol {
            background-color: #f0f9f0;
            color: #2e7d32;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .wallet-name {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }
        
        .wallet-balance {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
        }
        
        /* Charts and Percentage Section */
.charts-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 25px;
}

.chart-card {
    background-color: white;
    border-radius: 12px;
    padding: 15px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e0e0e0;
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 220px; /* Reduced from 280px */
}

.chart-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px; /* Reduced from 15px */
    flex-shrink: 0;
}

.chart-icon {
    width: 32px; /* Reduced from 36px */
    height: 32px;
    border-radius: 50%;
    background-color: #f0f9f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2e7d32;
    font-size: 0.8rem;
}

.chart-header h3 {
    font-size: 0.95rem; /* Slightly smaller */
    color: #2d3748;
}

.chart-container-wrapper {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 150px; /* Reduced from 200px */
}

.chart-container {
    flex: 1;
    position: relative;
    width: 100%;
    height: 100%;
}

.percentage-list {
    flex: 1;
    overflow-y: auto;
    max-height: 160px; /* Reduced from 240px */
}

.percentage-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px; /* Reduced padding */
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.3s;
}

.percentage-item:hover {
    background-color: #f8f9fa;
}

.percentage-item:last-child {
    border-bottom: none;
}

.percentage-currency {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #2d3748;
    font-size: 0.85rem; /* Slightly smaller */
}

.currency-color {
    width: 10px; /* Smaller */
    height: 10px;
    border-radius: 50%;
}

.percentage-value {
    font-weight: 700;
    color: #2e7d32;
    font-size: 0.9rem; /* Slightly smaller */
}

/* Increased height for Recent Conversions & Exchange Rates */
.dashboard-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 25px;
    height: 380px; /* Increased from 320px */
}

.dashboard-card {
    background-color: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e0e0e0;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    flex-shrink: 0;
}

.card-icon {
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

.card-header h3 {
    font-size: 1rem;
    color: #2d3748;
}

.scrollable-content {
    flex: 1;
    overflow-y: auto;
    max-height: 300px; /* Increased from 240px */
}

/* Mobile responsiveness updates */
@media (max-width: 768px) {
    .charts-section {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .chart-card {
        min-height: 200px; /* Adjusted for mobile */
    }
    
    .chart-container-wrapper {
        min-height: 130px; /* Adjusted for mobile */
    }
    
    .dashboard-row {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .dashboard-card {
        height: 340px; /* Still taller on mobile */
    }
    
    .scrollable-content {
        max-height: 260px; /* Adjusted for mobile */
    }
}

@media (max-width: 480px) {
    .chart-card {
        min-height: 180px;
        padding: 12px;
    }
    
    .chart-container-wrapper {
        min-height: 120px;
    }
    
    .percentage-list {
        max-height: 140px;
    }
    
    .dashboard-card {
        height: 320px;
    }
    
    .scrollable-content {
        max-height: 240px;
    }
}
        .scrollable-content::-webkit-scrollbar {
            width: 6px;
        }
        
        .scrollable-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .scrollable-content::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .scrollable-content::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .conversion-list, .exchange-rate-list {
            list-style: none;
        }
        
        .conversion-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .conversion-item:last-child {
            border-bottom: none;
        }
        
        .conversion-details {
            flex: 1;
        }
        
        .conversion-currencies {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }
        
        .conversion-amount {
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        .conversion-meta {
            text-align: right;
        }
        
        .conversion-date {
            color: #6b7280;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }
        
        .conversion-rate {
            background-color: #f0f9f0;
            color: #2e7d32;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .exchange-rate-item {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
        }
        
        .exchange-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .exchange-currencies {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .currency-badge {
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .exchange-rate {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .exchange-time {
            color: #6b7280;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .history-btn {
            background-color: #4caf50;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.8rem;
        }
        
        .history-btn:hover {
            background-color: #43a047;
        }
        
        .converter-section {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
        }
        
        .converter-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .converter-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }
        
        .converter-header h3 {
            font-size: 1.2rem;
            color: #2d3748;
        }
        
        .converter-form {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .form-select, .form-input {
            width: 100%;
            padding: 10px 12px;
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
        
        .converter-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 40px;
        }
        
        .converter-arrow i {
            font-size: 1.2rem;
            color: #4caf50;
        }
        
        .result-card {
            background-color: white;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e0e0e0;
        }
        
        .result-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2e7d32;
            margin-bottom: 4px;
        }
        
        .result-label {
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        .converter-submit {
            grid-column: 1 / -1;
            margin-top: 15px;
        }
        
        .notifications-list {
            list-style: none;
        }
        
        .notification-item {
            background-color: white;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .notification-title {
            font-weight: 600;
            color: #2d3748;
            font-size: 0.95rem;
        }
        
        .notification-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .notification-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2d3748;
        }
        
        .notification-actions {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #6b7280;
            transition: all 0.3s;
            padding: 5px;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .action-btn:hover {
            color: #2e7d32;
            background-color: #f0f9f0;
        }
        
        .no-data {
            text-align: center;
            padding: 30px 15px;
            color: #6b7280;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .no-data p {
            font-size: 0.9rem;
        }
        
        .modal-overlay {
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
        }
        
        .modal {
            background-color: white;
            border-radius: 16px;
            padding: 25px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .modal-header h3 {
            color: #2d3748;
            font-size: 1.2rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }
        
        .modal-body table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .modal-body th, .modal-body td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }
        
        .modal-body th {
            background-color: #f8f9fa;
            color: #2d3748;
            font-weight: 600;
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
        
        /* Conversion Confirmation Modal */
        .confirmation-modal {
            max-width: 450px;
            width: 90%;
        }

        .confirmation-content {
            text-align: center;
            padding: 10px 0;
        }

        .confirmation-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 1.8rem;
        }

        .confirmation-details {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }

        .detail-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .detail-value {
            color: #2d3748;
            font-weight: 600;
            text-align: right;
        }

        .confirmation-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .confirmation-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* Loading Modal */
        .loading-modal {
            max-width: 350px;
            width: 90%;
        }

        .loading-content {
            text-align: center;
            padding: 20px 0;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #f0f9f0;
            border-top: 4px solid #4caf50;
            border-radius: 50%;
            margin: 0 auto 20px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .loading-subtext {
            color: #6b7280;
            font-size: 0.9rem;
        }

        /* Success Modal */
        .success-modal {
            max-width: 450px;
            width: 90%;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4caf50, #2e7d32);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 2.5rem;
            animation: successPop 0.5s cubic-bezier(0.68, -0.55, 0.27, 1.55);
        }

        @keyframes successPop {
            0% { transform: scale(0); }
            70% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .success-details {
            background-color: #f0f9f0;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            border: 1px solid #d1fae5;
        }

        .success-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #d1fae5;
        }

        .success-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .success-label {
            color: #065f46;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .success-value {
            color: #065f46;
            font-weight: 700;
            text-align: right;
        }

        .success-actions {
            margin-top: 20px;
        }

        .success-actions .btn {
            width: 100%;
            justify-content: center;
        }
        
        @media (max-width: 992px) {
            .dashboard-row {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .dashboard-card {
                height: 320px;
            }
            
            .converter-form {
                grid-template-columns: 1fr;
            }
            
            .converter-arrow {
                display: none;
            }
        }
        
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
            
            /* Mobile Navigation */
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
            
            .wallets-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .chart-card {
                min-height: 280px;
            }
            
            .chart-container-wrapper {
                min-height: 180px;
            }
            
            .action-buttons {
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
            
            .balance-amount {
                font-size: 1.5rem;
            }
            
            .wallet-balance {
                font-size: 1.2rem;
            }
            
            .chart-card {
                min-height: 260px;
                padding: 15px;
            }
            
            .chart-container-wrapper {
                min-height: 160px;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
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
        
        .exchange-footer {
            margin-top: 10px;
            text-align: center;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }

        /* Logout modal specific styling */
#logout-modal .confirmation-icon {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

#logout-modal .btn-primary {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    border: none;
}

#logout-modal .btn-primary:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

/* Reject Reason Styling */
.reject-reason {
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
    </style>
</head>
<body>
    <?php if (!empty($message)): ?>
        <?php echo $message; ?>
    <?php endif; ?>
    
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
                    <p>Your Personal Dashboard</p>
                </div>
            </div>
            <div class="header-actions">
                <button class="refresh-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="#" class="logout-btn" onclick="confirmLogout(event)">
    <i class="fas fa-sign-out-alt"></i> Logout
</a>
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
        
        <div class="nav-tabs" id="navTabs">
            <a href="#dashboard" class="nav-tab <?php echo !$show_notifications ? 'active' : ''; ?>" onclick="switchTab('dashboard'); hideMobileMenu();">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
            <a href="#notifications" class="nav-tab <?php echo $show_notifications ? 'active' : ''; ?>" onclick="switchTab('notifications'); hideMobileMenu();">
                <i class="fas fa-bell me-2"></i>Notifications
                <?php if (count($notifications) > 0): ?>
                    <span class="notification-badge"><?php echo count($notifications); ?></span>
                <?php endif; ?>
            </a>
            <a href="p2pTradeList.php" class="nav-tab">
                <i class="fas fa-exchange-alt me-2"></i>P2P Trade
            </a>
            <a href="p2pTransactionHistory.php" class="nav-tab">
                <i class="fas fa-history me-2"></i>P2P History
            </a>
            <a href="profile.php" class="nav-tab">
                <i class="fas fa-user me-2"></i>Profile
            </a>
        </div>
        
        <div class="dashboard-content">
            <!-- Dashboard Tab -->
            <div id="dashboard-section" class="content-section <?php echo !$show_notifications ? 'active' : ''; ?>">
                <div class="balance-card">
                    <div class="balance-header">
                        <h2>Total Balance</h2>
                        <select class="currency-select" id="currency-select">
                            <option value="USD">USD</option>
                            <?php foreach ($currencies as $currency): ?>
                                <?php if ($currency['symbol'] !== 'USD'): ?>
                                    <option value="<?php echo htmlspecialchars($currency['symbol']); ?>">
                                        <?php echo htmlspecialchars($currency['symbol']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="balance-amount" id="total-balance">
                        USD <?php echo number_format($total_balance_usd, 2); ?>
                    </div>
                    <div class="balance-change">Your total balance across all wallets</div>
                </div>
                
                <div class="action-buttons">
                    <a href="deposit_form.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Deposit
                    </a>
                    <a href="withdraw_form.php" class="btn btn-secondary">
                        <i class="fas fa-minus-circle"></i> Withdraw
                    </a>
                    <button class="btn btn-outline" onclick="toggleConverter()">
                        <i class="fas fa-exchange-alt"></i> Convert Currency
                    </button>
                </div>
                
                <!-- Wallets Section -->
                <h3 style="margin-bottom: 15px; color: #2d3748; font-size: 1.1rem;">Your Wallets</h3>
                <div class="wallets-grid">
                    <?php foreach ($wallets as $wallet): ?>
                        <div class="wallet-card">
                            <div class="wallet-header">
                                <div class="wallet-icon">
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
                                <span class="wallet-symbol"><?php echo htmlspecialchars($wallet['symbol']); ?></span>
                            </div>
                            <div class="wallet-name"><?php echo htmlspecialchars($wallet['name']); ?></div>
                            <div class="wallet-balance"><?php echo number_format($wallet['balance'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Charts and Percentage Section -->
<div class="charts-section">
    <!-- Currency Distribution Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <div class="chart-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <h3>Currency Distribution</h3>
        </div>
        <div class="chart-container-wrapper">
            <div class="chart-container">
                <?php if (!empty($wallet_distribution)): ?>
                    <canvas id="currencyChart"></canvas>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px 0; color: #6b7280;">
                        <i class="fas fa-chart-pie fa-2x mb-2"></i>
                        <p>No wallet data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Wallet Percentage -->
    <div class="chart-card">
        <div class="chart-header">
            <div class="chart-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <h3>Wallet Percentage</h3>
        </div>
        <div class="percentage-list">
            <?php 
            $total_mmk_value = 0;
            $wallet_percentages = [];
            
            foreach ($wallets as $wallet) {
                $amount_mmk = 0;
                $symbol = $wallet['symbol'];
                $balance = (float)$wallet['balance'];
                
                if ($symbol === 'MMK') {
                    $amount_mmk = $balance;
                } else {
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
                    'color' => ''
                ];
                
                // Updated colors for better visibility
                switch($symbol) {
                    case 'USD': $wallet_percentages[$symbol]['color'] = '#4caf50'; break; // Green
                    case 'MMK': $wallet_percentages[$symbol]['color'] = '#2196f3'; break; // Blue
                    case 'THB': $wallet_percentages[$symbol]['color'] = '#ff9800'; break; // Orange
                    case 'JPY': $wallet_percentages[$symbol]['color'] = '#9c27b0'; break; // Purple
                    default: $wallet_percentages[$symbol]['color'] = '#607d8b'; break; // Gray
                }
                
                $total_mmk_value += $amount_mmk;
            }
            
            if ($total_mmk_value > 0) {
                uasort($wallet_percentages, function($a, $b) {
                    return $b['amount_mmk'] <=> $a['amount_mmk'];
                });
                
                foreach ($wallet_percentages as $symbol => $data): 
                    $percentage = $total_mmk_value > 0 ? ($data['amount_mmk'] / $total_mmk_value) * 100 : 0;
                    $formatted_percentage = number_format($percentage, 2);
                    $formatted_balance = number_format($data['balance'], 2);
                    $formatted_mmk = number_format($data['amount_mmk'], 2);
                ?>
                    <div class="percentage-item" title="<?php echo htmlspecialchars("{$formatted_balance} {$symbol} • {$formatted_mmk} MMK"); ?>">
                        <div class="percentage-currency">
                            <div class="currency-color" style="background-color: <?php echo $data['color']; ?>"></div>
                            <span><?php echo htmlspecialchars($symbol); ?></span>
                        </div>
                        <div class="percentage-value">
                            <?php echo $formatted_percentage; ?>%
                        </div>
                    </div>
                <?php 
                endforeach; 
            } else {
                echo '<div style="text-align: center; padding: 20px 0; color: #6b7280;">
                    <i class="fas fa-percentage fa-2x mb-2"></i>
                    <p>No wallet data available</p>
                </div>';
            }
            ?>
        </div>
    </div>
</div>
                
                <!-- Currency Converter -->
                <div id="converter-section" class="converter-section" style="display: none;">
                    <div class="converter-header">
                        <div class="converter-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h3>Currency Converter</h3>
                    </div>
                    
                    <form id="conversionForm" class="converter-form">
                        <input type="hidden" name="action" value="convert_currency">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        
                        <div class="form-group">
                            <label>From Currency</label>
                            <select name="from_currency" id="fromCurrency" class="form-select" required onchange="updateConversion()">
                                <option value="">Select currency...</option>
                                <?php 
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
                            <small class="text-muted" id="availableBalance" style="display: block; margin-top: 5px; font-size: 0.8rem;"></small>
                        </div>
                        
                        <div class="converter-arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        
                        <div class="form-group">
                            <label>To Currency</label>
                            <select name="to_currency" id="toCurrency" class="form-select" required onchange="calculateConversion()">
                                <option value="">Select currency...</option>
                                <?php foreach ($currencies as $cur): ?>
                                    <option value="<?php echo (int)$cur['currency_id']; ?>" data-symbol="<?php echo htmlspecialchars($cur['symbol']); ?>">
                                        <?php echo htmlspecialchars($cur['symbol']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Amount to Convert</label>
                            <input type="number" step="0.01" name="amount" id="convertAmount" class="form-input" placeholder="0.00" required oninput="calculateConversion()">
                        </div>
                        
                        <div class="converter-arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        
                        <div class="form-group">
                            <label>You Will Receive</label>
                            <div class="result-card">
                                <div class="result-amount" id="convertedAmount">0.00</div>
                                <div class="result-label" id="targetSymbol">---</div>
                                <small class="text-muted" id="taxDisplay" style="font-size: 0.8rem;">Tax (5%): 0.00</small><br>
                                <small class="text-muted" id="exchangeRateDisplay" style="font-size: 0.8rem;">Select currencies to see rate</small>
                            </div>
                        </div>
                        
                        <div class="form-group converter-submit">
                            <button type="button" onclick="showConfirmationDialog()" class="btn btn-primary" style="width: 100%;" id="convertButton" disabled>
                                <i class="fas fa-exchange-alt me-2"></i>Convert Currency
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Recent Conversions & Exchange Rates -->
                <div class="dashboard-row">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon">
                                <i class="fas fa-exchange-alt"></i>
                            </div>
                            <h3>Recent Conversions</h3>
                        </div>
                        <div class="scrollable-content">
                            <?php if (!empty($recent_conversions)): ?>
                                <ul class="conversion-list">
                                    <?php 
                                    $conversion_count = 0;
                                    foreach ($recent_conversions as $rc): 
                                        if ($conversion_count >= 6) break;
                                        $conversion_count++;
                                    ?>
                                        <li class="conversion-item">
                                            <div class="conversion-details">
                                                <div class="conversion-currencies">
                                                    <?php echo htmlspecialchars($rc['from_symbol']); ?> → <?php echo htmlspecialchars($rc['to_symbol']); ?>
                                                </div>
                                                <div class="conversion-amount">
                                                    <?php echo number_format((float)$rc['amount'], 2); ?> <?php echo htmlspecialchars($rc['from_symbol']); ?> → 
                                                    <?php echo number_format((float)$rc['converted_amount'], 2); ?> <?php echo htmlspecialchars($rc['to_symbol']); ?>
                                                </div>
                                            </div>
                                            <div class="conversion-meta">
                                                <div class="conversion-date">
                                                    <?php echo date('M j, H:i', strtotime($rc['created_at'])); ?>
                                                </div>
                                                <span class="conversion-rate">
                                                    Rate: <?php echo number_format((float)$rc['exchange_rate'], 6); ?>
                                                </span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-exchange-alt"></i>
                                    <p>No conversions yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($recent_conversions) && count($recent_conversions) > 6): ?>
                            <div class="exchange-footer">
                                <small class="text-muted">Showing 6 of <?php echo count($recent_conversions); ?> conversions</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h3>Live Exchange Rates</h3>
                        </div>
                        <div class="scrollable-content">
                            <?php if (!empty($today_rates)): ?>
                                <ul class="exchange-rate-list">
                                    <?php 
                                    $displayed_pairs = [];
                                    $rate_count = 0;
                                    foreach ($today_rates as $rate): 
                                        if ($rate_count >= 6) break;
                                        $pair_key = $rate['base_currency_id'] . '-' . $rate['target_currency_id'];
                                        if (!in_array($pair_key, $displayed_pairs)):
                                            $displayed_pairs[] = $pair_key;
                                            $baseSym = $rate['base_symbol'] ?? '';
                                            $targetSym = $rate['target_symbol'] ?? '';
                                            if ($baseSym === 'MMK' && in_array($targetSym, ['USD','JPY','THB'], true)) {
                                                continue;
                                            }
                                            $fxSet = ['USD','JPY','THB'];
                                            $isCross = in_array($baseSym, $fxSet, true) && in_array($targetSym, $fxSet, true) && $baseSym !== $targetSym;
                                            $isCanonical = ($baseSym === 'USD' && $targetSym === 'JPY')
                                                       || ($baseSym === 'USD' && $targetSym === 'THB')
                                                       || ($baseSym === 'THB' && $targetSym === 'JPY');
                                            if ($isCross && !$isCanonical) {
                                                continue;
                                            }
                                            $rate_count++;
                                    ?>
                                        <li class="exchange-rate-item">
                                            <div class="exchange-header">
                                                <div class="exchange-currencies">
                                                    <span class="currency-badge"><?php echo htmlspecialchars($rate['base_symbol']); ?></span>
                                                    <i class="fas fa-arrow-right"></i>
                                                    <span class="currency-badge"><?php echo htmlspecialchars($rate['target_symbol']); ?></span>
                                                </div>
                                                <div class="exchange-time">
                                                    <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($rate['timestamp'])); ?>
                                                </div>
                                            </div>
                                            <div class="exchange-rate">
                                                <?php echo number_format($rate['rate'], 4); ?>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <div class="exchange-label" style="font-size: 0.85rem; color: #6b7280;">
                                                    1 <?php echo htmlspecialchars($rate['base_symbol']); ?> = <?php echo number_format($rate['rate'], 4); ?> <?php echo htmlspecialchars($rate['target_symbol']); ?>
                                                </div>
                                                <a href="dashboard.php?view_rate_history=1&base_currency=<?php echo $rate['base_currency_id']; ?>&target_currency=<?php echo $rate['target_currency_id']; ?>" 
                                                   class="history-btn">
                                                    <i class="fas fa-history"></i> History
                                                </a>
                                            </div>
                                        </li>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    
                                    // Derived USD->JPY and USD->THB
                                    $usd_to_mmk = null; $jpy_to_mmk = null; $thb_to_mmk = null;
                                    $usd_to_mmk_time = null; $jpy_to_mmk_time = null; $thb_to_mmk_time = null;
                                    foreach ($today_rates as $r) {
                                        $bs = $r['base_symbol'] ?? '';
                                        $ts = $r['target_symbol'] ?? '';
                                        if ($bs === 'USD' && $ts === 'MMK') { $usd_to_mmk = (float)$r['rate']; $usd_to_mmk_time = $r['timestamp']; }
                                        if ($bs === 'JPY' && $ts === 'MMK') { $jpy_to_mmk = (float)$r['rate']; $jpy_to_mmk_time = $r['timestamp']; }
                                        if ($bs === 'THB' && $ts === 'MMK') { $thb_to_mmk = (float)$r['rate']; $thb_to_mmk_time = $r['timestamp']; }
                                    }
                                    
                                    $symbol_to_id = [];
                                    foreach ($today_rates as $r) {
                                        if (!empty($r['base_symbol']) && !empty($r['base_currency_id'])) {
                                            $symbol_to_id[$r['base_symbol']] = (int)$r['base_currency_id'];
                                        }
                                        if (!empty($r['target_symbol']) && !empty($r['target_currency_id'])) {
                                            $symbol_to_id[$r['target_symbol']] = (int)$r['target_currency_id'];
                                        }
                                    }
                                    
                                    $derived_cards = [];
                                    if ($usd_to_mmk && $jpy_to_mmk && $jpy_to_mmk > 0) {
                                        $usd_to_jpy = $usd_to_mmk / $jpy_to_mmk;
                                        $derived_cards[] = [
                                            'base' => 'USD',
                                            'target' => 'JPY',
                                            'rate' => $usd_to_jpy,
                                            'time' => $usd_to_mmk_time ?: $jpy_to_mmk_time,
                                            'base_id' => $symbol_to_id['USD'] ?? null,
                                            'target_id' => $symbol_to_id['JPY'] ?? null,
                                        ];
                                    }
                                    if ($usd_to_mmk && $thb_to_mmk && $thb_to_mmk > 0) {
                                        $usd_to_thb = $usd_to_mmk / $thb_to_mmk;
                                        $derived_cards[] = [
                                            'base' => 'USD',
                                            'target' => 'THB',
                                            'rate' => $usd_to_thb,
                                            'time' => $usd_to_mmk_time ?: $thb_to_mmk_time,
                                            'base_id' => $symbol_to_id['USD'] ?? null,
                                            'target_id' => $symbol_to_id['THB'] ?? null,
                                        ];
                                    }
                                    if ($thb_to_mmk && $jpy_to_mmk && $jpy_to_mmk > 0) {
                                        $thb_to_jpy = $thb_to_mmk / $jpy_to_mmk;
                                        $derived_cards[] = [
                                            'base' => 'THB',
                                            'target' => 'JPY',
                                            'rate' => $thb_to_jpy,
                                            'time' => $thb_to_mmk_time ?: $jpy_to_mmk_time,
                                            'base_id' => $symbol_to_id['THB'] ?? null,
                                            'target_id' => $symbol_to_id['JPY'] ?? null,
                                        ];
                                    }
                                    
                                    foreach ($derived_cards as $card): 
                                        if ($rate_count >= 6) break;
                                        $bid = (int)($card['base_id'] ?? 0);
                                        $tid = (int)($card['target_id'] ?? 0);
                                        if ($bid <= 0 || $tid <= 0) { continue; }
                                        $pkey = $bid . '-' . $tid;
                                        if (in_array($pkey, $displayed_pairs, true)) { continue; }
                                        $displayed_pairs[] = $pkey;
                                        $rate_count++;
                                    ?>
                                        <li class="exchange-rate-item">
                                            <div class="exchange-header">
                                                <div class="exchange-currencies">
                                                    <span class="currency-badge"><?php echo htmlspecialchars($card['base']); ?></span>
                                                    <i class="fas fa-arrow-right"></i>
                                                    <span class="currency-badge"><?php echo htmlspecialchars($card['target']); ?></span>
                                                </div>
                                                <div class="exchange-time">
                                                    <i class="fas fa-clock"></i> <?php echo $card['time'] ? date('H:i', strtotime($card['time'])) : date('H:i'); ?>
                                                </div>
                                            </div>
                                            <div class="exchange-rate">
                                                <?php echo number_format($card['rate'], 4); ?>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <div class="exchange-label" style="font-size: 0.85rem; color: #6b7280;">
                                                    1 <?php echo htmlspecialchars($card['base']); ?> = <?php echo number_format($card['rate'], 4); ?> <?php echo htmlspecialchars($card['target']); ?>
                                                </div>
                                                <a href="dashboard.php?view_rate_history=1&base_currency=<?php echo (int)$bid; ?>&target_currency=<?php echo (int)$tid; ?>" 
                                                   class="history-btn">
                                                    <i class="fas fa-history"></i> History
                                                </a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-chart-line"></i>
                                    <p>No exchange rates available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($today_rates) && $rate_count > 6): ?>
                            <div class="exchange-footer">
                                <small class="text-muted">Showing 6 of <?php echo count($today_rates); ?> rates</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Notifications Tab -->
            <div id="notifications-section" class="content-section <?php echo $show_notifications ? 'active' : ''; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="color: #2d3748; font-size: 1.1rem;">Notifications</h3>
                    <?php if (!empty($notifications)): ?>
                        <a href="dashboard.php?clear_all=1&action=notifications" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.85rem;">
                            <i class="fas fa-trash"></i> Clear All
                        </a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($notifications)): ?>
                    <div class="no-data">
                        <i class="fas fa-bell"></i>
                        <p>You have no notifications.</p>
                    </div>
                <?php else: ?>
                    <ul class="notifications-list">
                        <?php foreach ($notifications as $notification): ?>
                           <li class="notification-item">
    <div class="notification-header">
        <div class="notification-title">
            <?php echo ucfirst(htmlspecialchars($notification['transaction_type'])); ?> Request
        </div>
        <span class="notification-status 
            <?php 
                switch ($notification['status']) {
                    case 'completed': echo 'status-completed'; break;
                    case 'rejected': echo 'status-rejected'; break;
                    case 'pending': echo 'status-pending'; break;
                }
            ?>">
            <?php echo ucfirst(htmlspecialchars($notification['status'])); ?>
        </span>
    </div>
    <div class="notification-body">
        <div>
            <div class="notification-amount">
                <?php echo number_format($notification['amount'], 2); ?> <?php echo htmlspecialchars($notification['symbol']); ?>
            </div>
            <div style="color: #6b7280; font-size: 0.85rem; margin-top: 5px;">
                Requested: <?php echo date('M j, Y H:i', strtotime($notification['request_timestamp'])); ?>
            </div>
            <?php if ($notification['status'] === 'rejected' && !empty($notification['reject_reason'])): ?>
                <div class="reject-reason" style="margin-top: 8px; padding: 8px 12px; background-color: #fee2e2; border-left: 3px solid #ef4444; border-radius: 4px;">
                    <div style="display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fas fa-exclamation-circle" style="color: #dc2626; margin-top: 2px;"></i>
                        <div>
                            <strong style="color: #dc2626; font-size: 0.85rem;">Rejection Reason:</strong>
                            <div style="color: #7f1d1d; font-size: 0.85rem; margin-top: 2px;">
                                <?php echo htmlspecialchars($notification['reject_reason']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="notification-actions">
            <?php if ($notification['status'] !== 'pending'): ?>
                <a href="dashboard.php?clear_notification_id=<?php echo (int)$notification['request_id']; ?>&user_id=<?php echo (int)$user_id; ?>&action=notifications" 
                   class="action-btn" title="Clear">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
            <button class="action-btn view-details" 
                    data-requested="<?php echo date('M j, Y H:i', strtotime($notification['request_timestamp'])); ?>"
                    data-decision="<?php echo !empty($notification['decision_timestamp']) ? date('M j, Y H:i', strtotime($notification['decision_timestamp'])) : '—'; ?>"
                    data-channel="<?php echo htmlspecialchars($notification['payment_channel'] ?? '—'); ?>"
                    data-reason="<?php echo !empty($notification['reject_reason']) ? htmlspecialchars($notification['reject_reason']) : '—'; ?>"
                    data-status="<?php echo htmlspecialchars($notification['status']); ?>"
                    title="Details">
                <i class="fas fa-info-circle"></i>
            </button>
        </div>
    </div>
</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Exchange Rate History Modal -->
    <?php if ($show_exchange_history && $selected_pair): ?>
    <div class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3>Exchange Rate History</h3>
                <a href="dashboard.php" class="modal-close">&times;</a>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 15px; color: #2d3748; font-weight: 600; font-size: 1rem;">
                    <i class="fas fa-chart-line me-2"></i>
                    <?php echo htmlspecialchars($selected_pair['base_symbol']); ?> / <?php echo htmlspecialchars($selected_pair['target_symbol']); ?>
                </p>
                
                <?php if (!empty($exchange_history)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Exchange Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exchange_history as $history): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i', strtotime($history['timestamp'])); ?></td>
                                    <td style="font-weight: 600;">
                                        1 <?php echo htmlspecialchars($history['base_symbol']); ?> = 
                                        <?php echo number_format($history['rate'], 4); ?> <?php echo htmlspecialchars($history['target_symbol']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data" style="padding: 20px 0;">
                        <i class="fas fa-chart-line"></i>
                        <p>No historical data available for this currency pair.</p>
                    </div>
                <?php endif; ?>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="dashboard.php" class="btn btn-secondary" style="padding: 8px 20px;">Close</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Notification Details Modal -->
 <!-- Notification Details Modal -->
<div id="details-modal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Notification Details</h3>
            <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom: 15px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-clock" style="color: #6b7280;"></i>
                    <span style="font-size: 0.9rem;">Requested: <span id="detail-requested">—</span></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-check-circle" style="color: #6b7280;"></i>
                    <span style="font-size: 0.9rem;">Decision: <span id="detail-decision">—</span></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <i class="fas fa-receipt" style="color: #6b7280;"></i>
                    <span style="font-size: 0.9rem;">Payment Channel: <span id="detail-channel">—</span></span>
                </div>
                <div id="detail-reason-container" style="display: none; background-color: #fee2e2; padding: 10px; border-radius: 6px; border-left: 3px solid #ef4444; margin-top: 10px;">
                    <div style="display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fas fa-exclamation-circle" style="color: #dc2626; margin-top: 2px;"></i>
                        <div>
                            <strong style="color: #dc2626; font-size: 0.85rem;">Rejection Reason:</strong>
                            <div style="color: #7f1d1d; font-size: 0.85rem; margin-top: 2px;" id="detail-reason">
                                —</div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="text-align: center;">
                <button class="btn btn-secondary" onclick="closeDetailsModal()" style="padding: 8px 20px;">Close</button>
            </div>
        </div>
    </div>
</div>
    
    <!-- Conversion Confirmation Modal -->
    <div id="confirmation-modal" class="modal-overlay" style="display: none;">
        <div class="modal confirmation-modal">
            <div class="modal-header">
                <h3>Confirm Conversion</h3>
                <button class="modal-close" onclick="closeConfirmationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirmation-content">
                    <div class="confirmation-icon">
                        <i class="fas fa-question"></i>
                    </div>
                    <h4 style="color: #2d3748; margin-bottom: 5px;">Are you sure?</h4>
                    <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 20px;">
                        Please review your conversion details before confirming.
                    </p>
                    
                    <div class="confirmation-details">
                        <div class="detail-row">
                            <span class="detail-label">From:</span>
                            <span class="detail-value" id="confirm-from">---</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">To:</span>
                            <span class="detail-value" id="confirm-to">---</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Amount:</span>
                            <span class="detail-value" id="confirm-amount">0.00</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Tax (5%):</span>
                            <span class="detail-value" id="confirm-tax">0.00</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">You Receive:</span>
                            <span class="detail-value" id="confirm-receive">0.00</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Exchange Rate:</span>
                            <span class="detail-value" id="confirm-rate">0.0000</span>
                        </div>
                    </div>
                    
                    <div class="confirmation-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeConfirmationModal()">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="confirmConversion()" id="confirm-convert-btn">
                            <i class="fas fa-check me-2"></i>Confirm & Convert
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loading-modal" class="modal-overlay" style="display: none;">
        <div class="modal loading-modal">
            <div class="modal-body">
                <div class="loading-content">
                    <div class="loading-spinner"></div>
                    <div class="loading-text">Processing Conversion...</div>
                    <div class="loading-subtext">Please wait while we process your transaction</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="success-modal" class="modal-overlay" style="display: none;">
        <div class="modal success-modal">
            <div class="modal-body">
                <div class="loading-content">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h4 style="color: #2e7d32; margin-bottom: 5px;">Conversion Successful!</h4>
                    <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 20px;">
                        Your currency conversion has been completed successfully.
                    </p>
                    
                    <div class="success-details">
                        <div class="success-row">
                            <span class="success-label">Converted Amount:</span>
                            <span class="success-value" id="success-amount">0.00</span>
                        </div>
                        <div class="success-row">
                            <span class="success-label">Tax Deducted (5%):</span>
                            <span class="success-value" id="success-tax">0.00</span>
                        </div>
                        <div class="success-row">
                            <span class="success-label">Amount Received:</span>
                            <span class="success-value" id="success-receive">0.00</span>
                        </div>
                        <div class="success-row">
                            <span class="success-label">Exchange Rate:</span>
                            <span class="success-value" id="success-rate">0.0000</span>
                        </div>
                    </div>
                    
                    <div class="success-actions">
                        <button type="button" class="btn btn-primary" onclick="closeSuccessModal()">
                            <i class="fas fa-check me-2"></i>Done
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
<div id="logout-modal" class="modal-overlay" style="display: none;">
    <div class="modal confirmation-modal">
        <div class="modal-body">
            <div class="confirmation-content">
                <div class="confirmation-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h4 style="color: #2d3748; margin-bottom: 5px;">Confirm Logout</h4>
                <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 20px;">
                    Are you sure you want to logout?<br>
                    You'll need to login again to access your account.
                </p>
                
                <div class="confirmation-actions">
                    <button type="button" class="btn btn-secondary" onclick="cancelLogout()">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <a href="logout.php" class="btn btn-primary" style="background: linear-gradient(135deg, #ef4444, #dc2626); border: none;">
                        <i class="fas fa-sign-out-alt me-2"></i>Yes, Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
    
    <?php
    // Prepare exchange rates for JavaScript
    $exchange_rates_js = [];
    if (!empty($today_rates)) {
        foreach ($today_rates as $rate) {
            $exchange_rates_js[] = [
                'from' => $rate['base_symbol'],
                'to' => $rate['target_symbol'],
                'rate' => (float)$rate['rate']
            ];
        }
    }
    ?>
   <script>
    // Exchange rates data from PHP (using the original format)
    const exchangeRates = <?php echo json_encode($today_rates); ?>;
    const totalBalanceUSD = <?php echo $total_balance_usd; ?>;
    const walletBalances = <?php 
        $balances_map = [];
        foreach ($wallets as $wallet) {
            $balances_map[$wallet['symbol']] = $wallet['balance'];
        }
        echo json_encode($balances_map);
    ?>;
    
    // Expose globally for other script blocks
    window.exchangeRates = exchangeRates;

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
    // Expose for other scripts (e.g., pie chart)
    window.mmkRates = mmkRates;

    // Conversion flow data
    let conversionData = {
        from_currency: '',
        to_currency: '',
        amount: 0,
        converted_amount: 0,
        tax_amount: 0,
        exchange_rate: 0,
        from_symbol: '',
        to_symbol: ''
    };
        
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
            
            if (!navTabs.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                navTabs.classList.remove('show');
            }
        });
        
        // Tab switching
        function switchTab(tabName) {
            // Update URL without reloading page
            if (tabName === 'notifications') {
                history.pushState(null, '', 'dashboard.php?action=notifications');
            } else {
                history.pushState(null, '', 'dashboard.php');
            }
            
            // Update active tab
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.remove('active');
            });
            
            document.querySelector(`a[href="#${tabName}"]`).classList.add('active');
            document.getElementById(`${tabName}-section`).classList.add('active');
        }
        
        // Toggle converter visibility
        function toggleConverter() {
            const converter = document.getElementById('converter-section');
            if (converter.style.display === 'none') {
                converter.style.display = 'block';
                converter.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                converter.style.display = 'none';
            }
        }
        
       // Currency converter functions
function updateConversion() {
    const fromSelect = document.getElementById('fromCurrency');
    const selectedOption = fromSelect.options[fromSelect.selectedIndex];
    const balance = selectedOption.getAttribute('data-balance');
    const symbol = selectedOption.getAttribute('data-symbol');
    
    if (balance) {
        document.getElementById('availableBalance').textContent = `Available: ${parseFloat(balance).toFixed(2)} ${symbol}`;
        document.getElementById('availableBalance').style.display = 'block';
    } else {
        document.getElementById('availableBalance').style.display = 'none';
    }
    
    calculateConversion();
}

// Enable/disable convert button based on form validity
function updateConvertButton() {
    const fromSel = document.getElementById('fromCurrency');
    const toSel = document.getElementById('toCurrency');
    const amount = document.getElementById('convertAmount').value;
    const convertBtn = document.getElementById('convertButton');
    
    if (fromSel.value && toSel.value && amount && parseFloat(amount) > 0 && fromSel.value !== toSel.value) {
        convertBtn.disabled = false;
    } else {
        convertBtn.disabled = true;
    }
}

// Attach event listeners to form inputs
document.getElementById('fromCurrency').addEventListener('change', updateConvertButton);
document.getElementById('toCurrency').addEventListener('change', updateConvertButton);
document.getElementById('convertAmount').addEventListener('input', updateConvertButton);

// Function to get exchange rate between two currencies
function getExchangeRate(fromCurrency, toCurrency) {
    if (fromCurrency === toCurrency) return 1;
    
    // Special handling for MMK conversion
    if (fromCurrency === 'MMK' && toCurrency === 'USD') {
        // Look for MMK -> USD rate
        const mmkToUsd = exchangeRates.find(r => r.base_symbol === 'MMK' && r.target_symbol === 'USD');
        if (mmkToUsd) return mmkToUsd.rate;
        
        // Look for USD -> MMK and invert it
        const usdToMmk = exchangeRates.find(r => r.base_symbol === 'USD' && r.target_symbol === 'MMK');
        if (usdToMmk) return 1 / usdToMmk.rate;
    }
    
    if (fromCurrency === 'USD' && toCurrency === 'MMK') {
        // Look for USD -> MMK rate
        const usdToMmk = exchangeRates.find(r => r.base_symbol === 'USD' && r.target_symbol === 'MMK');
        if (usdToMmk) return usdToMmk.rate;
        
        // Look for MMK -> USD and invert it
        const mmkToUsd = exchangeRates.find(r => r.base_symbol === 'MMK' && r.target_symbol === 'USD');
        if (mmkToUsd) return 1 / mmkToUsd.rate;
    }
    
    // Try direct rate
    const direct = exchangeRates.find(r => r.base_symbol === fromCurrency && r.target_symbol === toCurrency);
    if (direct) return direct.rate;
    
    // Try inverse rate
    const inverse = exchangeRates.find(r => r.base_symbol === toCurrency && r.target_symbol === fromCurrency);
    if (inverse) return 1 / inverse.rate;
    
    // Try via USD
    const toUSD = exchangeRates.find(r => r.base_symbol === fromCurrency && r.target_symbol === 'USD');
    const fromUSD = exchangeRates.find(r => r.base_symbol === 'USD' && r.target_symbol === toCurrency);
    
    if (toUSD && fromUSD) {
        return toUSD.rate * fromUSD.rate;
    }
    
    // If no direct or USD path, try any available path
    for (const rate of exchangeRates) {
        if (rate.target_symbol === toCurrency) {
            const firstLeg = getExchangeRate(fromCurrency, rate.base_symbol);
            if (firstLeg) {
                return firstLeg * rate.rate;
            }
        }
    }
    
    console.error(`No exchange rate found from ${fromCurrency} to ${toCurrency}`);
    return 1; // Default to 1 if no rate found
}

// Helper to get rate for conversion calculator
function getRate(fromSymbol, toSymbol) {
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
}

function calculateConversion() {
    const fromSel = document.getElementById('fromCurrency');
    const toSel = document.getElementById('toCurrency');
    const amount = parseFloat(document.getElementById('convertAmount').value) || 0;
    
    if (!fromSel || !toSel || amount <= 0) {
        document.getElementById('convertedAmount').textContent = '0.00';
        document.getElementById('taxDisplay').textContent = 'Tax (5%): 0.00';
        document.getElementById('exchangeRateDisplay').textContent = 'Select currencies to see rate';
        return;
    }
    
    const fromId = fromSel.value;
    const toId = toSel.value;
    if (!fromId || !toId) {
        document.getElementById('convertedAmount').textContent = '0.00';
        document.getElementById('taxDisplay').textContent = 'Tax (5%): 0.00';
        document.getElementById('exchangeRateDisplay').textContent = 'Select currencies to see rate';
        return;
    }
    
    const fromOpt = fromSel.options[fromSel.selectedIndex];
    const toOpt = toSel.options[toSel.selectedIndex];
    const fromSym = fromOpt ? (fromOpt.getAttribute('data-symbol') || '') : '';
    const toSym = toOpt ? (toOpt.getAttribute('data-symbol') || '') : '';

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
    
    // Update convert button state
    updateConvertButton();
}

// Show confirmation dialog
function showConfirmationDialog() {
    const form = document.getElementById('conversionForm');
    const fromSel = document.getElementById('fromCurrency');
    const toSel = document.getElementById('toCurrency');
    const amount = document.getElementById('convertAmount').value;
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Get selected options
    const fromOpt = fromSel.options[fromSel.selectedIndex];
    const toOpt = toSel.options[toSel.selectedIndex];
    
    if (!fromOpt || !toOpt) return;
    
    // Store conversion data
    conversionData.from_currency = fromSel.value;
    conversionData.to_currency = toSel.value;
    conversionData.amount = parseFloat(amount);
    conversionData.from_symbol = fromOpt.getAttribute('data-symbol');
    conversionData.to_symbol = toOpt.getAttribute('data-symbol');
    
    // Calculate conversion details
    const fromSym = conversionData.from_symbol;
    const toSym = conversionData.to_symbol;
    const fx = getRate(fromSym, toSym);
    
    if (fx && fx > 0) {
        const taxRate = 0.05;
        const tax = conversionData.amount * taxRate;
        const amountAfterTax = conversionData.amount * (1 - taxRate);
        const converted = amountAfterTax * fx;
        
        conversionData.converted_amount = converted;
        conversionData.tax_amount = tax;
        conversionData.exchange_rate = fx;
        
        // Update confirmation modal
        document.getElementById('confirm-from').textContent = `${conversionData.amount.toFixed(2)} ${fromSym}`;
        document.getElementById('confirm-to').textContent = `${converted.toFixed(2)} ${toSym}`;
        document.getElementById('confirm-amount').textContent = `${conversionData.amount.toFixed(2)} ${fromSym}`;
        document.getElementById('confirm-tax').textContent = `${tax.toFixed(2)} ${fromSym}`;
        document.getElementById('confirm-receive').textContent = `${converted.toFixed(2)} ${toSym}`;
        document.getElementById('confirm-rate').textContent = `1 ${fromSym} = ${fx.toFixed(4)} ${toSym}`;
        
        // Show confirmation modal
        document.getElementById('confirmation-modal').style.display = 'flex';
    }
}

// Close confirmation modal
function closeConfirmationModal() {
    document.getElementById('confirmation-modal').style.display = 'none';
}

// Confirm and process conversion
function confirmConversion() {
    // Close confirmation modal
    closeConfirmationModal();
    
    // Show loading modal
    document.getElementById('loading-modal').style.display = 'flex';
    
    // Disable confirm button to prevent double submission
    document.getElementById('confirm-convert-btn').disabled = true;
    
    // Prepare form data
    const form = document.getElementById('conversionForm');
    const formData = new FormData(form);
    
    // Add the calculated conversion data
    formData.append('from_currency', conversionData.from_currency);
    formData.append('to_currency', conversionData.to_currency);
    formData.append('amount', conversionData.amount);
    
    // Submit the form via AJAX
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Hide loading modal
        document.getElementById('loading-modal').style.display = 'none';
        
        // Re-enable confirm button
        document.getElementById('confirm-convert-btn').disabled = false;
        
        // Check if conversion was successful
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const successNotification = doc.querySelector('.notification.success');
        
        if (successNotification) {
            // Show success modal with details
            document.getElementById('success-amount').textContent = `${conversionData.amount.toFixed(2)} ${conversionData.from_symbol}`;
            document.getElementById('success-tax').textContent = `${conversionData.tax_amount.toFixed(2)} ${conversionData.from_symbol}`;
            document.getElementById('success-receive').textContent = `${conversionData.converted_amount.toFixed(2)} ${conversionData.to_symbol}`;
            document.getElementById('success-rate').textContent = `1 ${conversionData.from_symbol} = ${conversionData.exchange_rate.toFixed(4)} ${conversionData.to_symbol}`;
            
            document.getElementById('success-modal').style.display = 'flex';
            
            // Refresh page data after 2 seconds
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            // Show error from server response
            const errorNotification = doc.querySelector('.notification.error');
            if (errorNotification) {
                // Create and show error notification
                const errorDiv = document.createElement('div');
                errorDiv.className = 'notification error show';
                errorDiv.innerHTML = errorNotification.innerHTML;
                document.body.appendChild(errorDiv);
                
                // Auto-hide error after 5 seconds
                setTimeout(() => {
                    errorDiv.classList.remove('show');
                    setTimeout(() => {
                        if (errorDiv.parentNode) {
                            errorDiv.parentNode.removeChild(errorDiv);
                        }
                    }, 500);
                }, 5000);
            } else {
                // Generic error
                alert('Conversion failed. Please try again.');
            }
        }
    })
    .catch(error => {
        // Hide loading modal
        document.getElementById('loading-modal').style.display = 'none';
        
        // Re-enable confirm button
        document.getElementById('confirm-convert-btn').disabled = false;
        
        console.error('Error:', error);
        alert('An error occurred during conversion. Please try again.');
    });
}

// Close success modal
function closeSuccessModal() {
    document.getElementById('success-modal').style.display = 'none';
    // Refresh the page to update balances
    location.reload();
}

// Total balance currency conversion
document.getElementById('currency-select').addEventListener('change', function() {
    const selectedSymbol = this.value;
    const balanceElement = document.getElementById('total-balance');
    
    try {
        if (selectedSymbol === 'USD') {
            balanceElement.textContent = `USD ${totalBalanceUSD.toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            })}`;
            return;
        }
        
        // For MMK, calculate directly from wallet balances
        if (selectedSymbol === 'MMK') {
            let totalMMK = 0;
            
            // Calculate total in MMK by converting each currency
            Object.entries(walletBalances).forEach(([symbol, balance]) => {
                const balanceNum = parseFloat(balance) || 0;
                
                if (symbol === 'MMK') {
                    totalMMK += balanceNum;
                } else if (symbol === 'USD') {
                    const usdToMmkRate = getExchangeRate('USD', 'MMK');
                    totalMMK += balanceNum * usdToMmkRate;
                } else {
                    // For other currencies, convert to USD first, then to MMK
                    const toUSDRate = getExchangeRate(symbol, 'USD');
                    const usdToMmkRate = getExchangeRate('USD', 'MMK');
                    totalMMK += balanceNum * toUSDRate * usdToMmkRate;
                }
            });
            
            balanceElement.textContent = `MMK ${totalMMK.toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            })}`;
            return;
        }
        
        // For other currencies, use the original logic
        const rate = getExchangeRate('USD', selectedSymbol);
        if (rate) {
            const convertedAmount = totalBalanceUSD * rate;
            balanceElement.textContent = `${selectedSymbol} ${convertedAmount.toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            })}`;
        } else {
            console.error(`Could not convert USD to ${selectedSymbol}`);
            balanceElement.textContent = `USD ${totalBalanceUSD.toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            })}`;
        }
    } catch (error) {
        console.error('Error converting currency:', error);
        balanceElement.textContent = `USD ${totalBalanceUSD.toLocaleString('en-US', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        })}`;
    }
});
        
      // Initialize Pie Chart with responsive settings
function initializePieChart() {
    const chartCanvas = document.getElementById('currencyChart');
    if (!chartCanvas) return;
    
    const walletData = <?php echo json_encode($wallet_distribution); ?>;
    
    if (walletData && walletData.length > 0) {
        const labels = walletData.map(item => item.symbol);
        const data = walletData.map(item => item.amount_mmk);
        
        // Updated colors for better visibility
        const backgroundColors = [
            '#2196f3',
             // USD - Green
             '#4caf50', // MMK - Blue
             '#9c27b0' ,
            '#ff9800', // THB - Orange
            // JPY - Purple
        ];
        
        // Destroy existing chart if it exists
        if (chartCanvas.chart) {
            chartCanvas.chart.destroy();
        }
        
        const ctx = chartCanvas.getContext('2d');
        chartCanvas.chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: backgroundColors.slice(0, walletData.length),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 2, // Makes chart wider relative to height
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(2);
                                return `${label}: ${value.toLocaleString()} MMK (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '60%', // Slightly smaller hole for smaller container
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });
    }
}

// Initialize chart on page load
document.addEventListener('DOMContentLoaded', function() {
    initializePieChart();
    
    // Reinitialize chart on window resize for better responsiveness
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            initializePieChart();
        }, 250);
    });
});
        
        // Notification details modal
       // Notification details modal
document.querySelectorAll('.view-details').forEach(button => {
    button.addEventListener('click', function() {
        document.getElementById('detail-requested').textContent = this.getAttribute('data-requested');
        document.getElementById('detail-decision').textContent = this.getAttribute('data-decision');
        document.getElementById('detail-channel').textContent = this.getAttribute('data-channel');
        
        // Show/hide reject reason based on status
        const status = this.getAttribute('data-status');
        const reason = this.getAttribute('data-reason');
        const reasonContainer = document.getElementById('detail-reason-container');
        const reasonElement = document.getElementById('detail-reason');
        
        if (status === 'rejected' && reason !== '—') {
            reasonElement.textContent = reason;
            reasonContainer.style.display = 'block';
        } else {
            reasonContainer.style.display = 'none';
        }
        
        document.getElementById('details-modal').style.display = 'flex';
    });
});
        
        function closeDetailsModal() {
            document.getElementById('details-modal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        document.getElementById('details-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });
        
        document.getElementById('confirmation-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeConfirmationModal();
            }
        });
        
        document.getElementById('loading-modal').addEventListener('click', function(e) {
            // Don't close loading modal by clicking outside
            e.stopPropagation();
        });
        
        document.getElementById('success-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSuccessModal();
            }
        });
        
        // Auto-hide notifications after 5 seconds
        setTimeout(() => {
            const notifications = document.querySelectorAll('.notification.show');
            notifications.forEach(notification => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 500);
            });
        }, 5000);
        
        // Scroll to top when switching tabs
        const navTabs = document.querySelectorAll('.nav-tab');
        navTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                setTimeout(() => {
                    document.querySelector('.dashboard-content').scrollTop = 0;
                }, 100);
            });
        });

        // Logout Confirmation Functions
function confirmLogout(event) {
    event.preventDefault();
    event.stopPropagation();
    
    document.getElementById('logout-modal').style.display = 'flex';
}

function cancelLogout() {
    document.getElementById('logout-modal').style.display = 'none';
}

// Close logout modal when clicking outside
document.getElementById('logout-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        cancelLogout();
    }
});

// Also add ESC key support for closing the modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const logoutModal = document.getElementById('logout-modal');
        if (logoutModal.style.display === 'flex') {
            cancelLogout();
        }
    }
});
    </script>
    
    <!-- Real-time Ban Check -->
    <?php include 'ban_check_script.php'; ?>
</body>
</html>