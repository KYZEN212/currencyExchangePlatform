<?php
session_start();

// Check if the user is logged in. If not, redirect to a login page.
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Initialize commonly used variables early to avoid undefined notices
if (!isset($message)) { $message = ''; }
if (!isset($user_id)) { $user_id = null; }

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$message = '';
$trades = [];
$currencies = [];
$user_id = null;
$session_username = isset($_SESSION['username']) ? $_SESSION['username'] : null;
$session_userimage = $_SESSION['userimage'] ?? '';
$initials = strtoupper(substr($session_username, 0, 1));
// User PIN hash (for trade confirmations)
$pin_hash = null;

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check for database connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is banned
require_once 'check_user_ban.php';
// Email config (PHPMailer constants and helpers)
@require_once __DIR__ . '/email_config.php';

// Helper: determine if a pair is MMK <-> (USD|JPY|THB) and return [is_pair, foreign_symbol]
if (!function_exists('is_mmk_foreign_pair')) {
    function is_mmk_foreign_pair(string $symA, string $symB): array {
        $a = strtoupper($symA);
        $b = strtoupper($symB);
        $foreigns = ['USD','JPY','THB'];
        if ($a === 'MMK' && in_array($b, $foreigns, true)) return [true, $b];
        if ($b === 'MMK' && in_array($a, $foreigns, true)) return [true, $a];
        return [false, ''];
    }
}

// Helper: detect cross pair among USD/JPY/THB with no MMK involved
if (!function_exists('is_usd_jpy_thb_pair')) {
    function is_usd_jpy_thb_pair(string $symA, string $symB): bool {
        $a = strtoupper($symA); $b = strtoupper($symB);
        $set = ['USD','JPY','THB'];
        return in_array($a, $set, true) && in_array($b, $set, true) && $a !== 'MMK' && $b !== 'MMK' && $a !== $b;
    }
}

// Helper: derive cross rate using admin-effective MMK rates
// Returns rate for 1 $baseSym = X $quoteSym
if (!function_exists('get_cross_rate_from_mmk')) {
    function get_cross_rate_from_mmk(mysqli $conn, string $baseSym, string $quoteSym): ?float {
        $base = strtoupper($baseSym); $quote = strtoupper($quoteSym);
        if ($base === $quote) return 1.0;
        $set = ['USD','JPY','THB'];
        if (!in_array($base, $set, true) || !in_array($quote, $set, true)) return null;
        // Get 1 FOREIGN = X MMK for each
        $base_to_mmk  = get_latest_mmk_pair_rate($conn, $base);
        $quote_to_mmk = get_latest_mmk_pair_rate($conn, $quote);
        if ($base_to_mmk && $quote_to_mmk && $base_to_mmk > 0 && $quote_to_mmk > 0) {
            return $base_to_mmk / $quote_to_mmk;
        }
        return null;
    }
}

// Helper: fetch latest admin effective rate for 1 FOREIGN = X MMK for FOREIGN in (USD|JPY|THB)
// Tries: today exchange_rates, then history, then inverse from history
if (!function_exists('get_latest_mmk_pair_rate')) {
    function get_latest_mmk_pair_rate(mysqli $conn, string $foreignSymbol): ?float {
        $foreign = strtoupper($foreignSymbol);
        if (!in_array($foreign, ['USD','JPY','THB'], true)) return null;

        // Resolve IDs
        $stmt_ids = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN (?, 'MMK')");
        if (!$stmt_ids) return null;
        $stmt_ids->bind_param("s", $foreign);
        $stmt_ids->execute();
        $res = $stmt_ids->get_result();
        $fid = null; $mid = null;
        while ($row = $res->fetch_assoc()) {
            if (strtoupper($row['symbol']) === 'MMK') { $mid = (int)$row['currency_id']; }
            if (strtoupper($row['symbol']) === $foreign) { $fid = (int)$row['currency_id']; }
        }
        $stmt_ids->close();
        if (!$fid || !$mid) return null;

        // 1) Today from exchange_rates: base = FOREIGN, target = MMK -> 1 FOREIGN = X MMK
        $stmt_live = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? AND DATE(timestamp) = CURDATE() ORDER BY timestamp DESC LIMIT 1");
        if ($stmt_live) {
            $stmt_live->bind_param("ii", $fid, $mid);
            $stmt_live->execute();
            $r = $stmt_live->get_result()->fetch_assoc();
            $stmt_live->close();
            if ($r && ($val = (float)$r['rate']) > 0) return $val;
        }

        // 2) History direct FOREIGN->MMK
        $stmt_hist = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
        if ($stmt_hist) {
            $stmt_hist->bind_param("ii", $fid, $mid);
            $stmt_hist->execute();
            $r = $stmt_hist->get_result()->fetch_assoc();
            $stmt_hist->close();
            if ($r && ($val = (float)$r['rate']) > 0) return $val;
        }

        // 3) Inverse: MMK->FOREIGN from history
        $stmt_inv = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
        if ($stmt_inv) {
            $stmt_inv->bind_param("ii", $mid, $fid);
            $stmt_inv->execute();
            $r = $stmt_inv->get_result()->fetch_assoc();
            $stmt_inv->close();
            if ($r) { $inv = (float)$r['rate']; if ($inv > 0) return 1/$inv; }
        }

        return null;
    }
}

// Lightweight mail helper for P2P notifications using the same PHPMailer setup
if (!function_exists('p2p_send_email')) {
    function p2p_send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): bool {
        try {
            $phpmailerBase = __DIR__ . '/../phpmailer/src/';
            $paths = [
                $phpmailerBase . 'PHPMailer.php',
                $phpmailerBase . 'SMTP.php',
                $phpmailerBase . 'Exception.php',
            ];
            foreach ($paths as $p) { if (file_exists($p)) { require_once $p; } }
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) { return false; }
            if (!defined('GMAIL_USER') || !defined('GMAIL_APP_PASSWORD')) { return false; }
            if (GMAIL_USER === '' || GMAIL_APP_PASSWORD === '') { return false; }

            $from = defined('EMAIL_FROM') && EMAIL_FROM !== '' ? EMAIL_FROM : GMAIL_USER;

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = GMAIL_USER;
            $mail->Password = GMAIL_APP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom($from, 'Trading Notifications');
            $mail->addAddress($toEmail, $toName ?: $toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);

            return $mail->send();
        } catch (Throwable $e) {
            return false;
        }
    }
}

// Fetch all currencies for the dropdowns
try {
    $stmt = $conn->prepare("SELECT currency_id, name, symbol FROM currencies ORDER BY name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $currencies[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $message .= "<div class='notification error show'>An error occurred while fetching currencies: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Precompute latest admin-effective rates for 1 FOREIGN = X MMK to avoid DB access during rendering
$latest_mmk_rates = ['USD' => null, 'JPY' => null, 'THB' => null];
try {
    foreach (array_keys($latest_mmk_rates) as $sym) {
        $r = get_latest_mmk_pair_rate($conn, $sym);
        if ($r && $r > 0) { $latest_mmk_rates[$sym] = (float)$r; }
    }
} catch (Throwable $e) { /* ignore */ }

// Fetch user ID based on session username
if ($session_username) {
    $stmt_user = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt_user->bind_param("s", $session_username);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        $user_id = $user_data['user_id'];
    }
    $stmt_user->close();
}

// Helper: fetch user statistics
if (!function_exists('get_user_statistics')) {
    function get_user_statistics(mysqli $conn, int $user_id): array {
        $stats = [
            'total_trades' => 0,
            'success_rate' => 0,
            'member_since' => '',
            'completed_trades' => 0
        ];
        
        try {
            // Get total trades and completed trades
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_trades,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_trades
                FROM p2p_trades 
                WHERE seller_user_id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if ($row) {
                $stats['total_trades'] = (int)$row['total_trades'];
                $stats['completed_trades'] = (int)$row['completed_trades'];
                
                // Calculate success rate
                if ($stats['total_trades'] > 0) {
                    $stats['success_rate'] = round(($stats['completed_trades'] / $stats['total_trades']) * 100);
                } else {
                    $stats['success_rate'] = 0;
                }
            }
            
            // Get user join date
            $stmt_date = $conn->prepare("SELECT created_at FROM users WHERE user_id = ?");
            $stmt_date->bind_param("i", $user_id);
            $stmt_date->execute();
            $result_date = $stmt_date->get_result();
            $row_date = $result_date->fetch_assoc();
            $stmt_date->close();
            
            if ($row_date && !empty($row_date['created_at'])) {
                $join_date = new DateTime($row_date['created_at']);
                $today = new DateTime();
                $interval = $today->diff($join_date);
                
                // Format member duration
                if ($interval->y > 0) {
                    $stats['member_since'] = $interval->y . ' year' . ($interval->y > 1 ? 's' : '');
                } elseif ($interval->m > 0) {
                    $stats['member_since'] = $interval->m . ' month' . ($interval->m > 1 ? 's' : '');
                } elseif ($interval->d > 0) {
                    $stats['member_since'] = $interval->d . ' day' . ($interval->d > 1 ? 's' : '');
                } else {
                    $stats['member_since'] = 'Today';
                }
            }
            
        } catch (Exception $e) {
            // Log error if needed
            error_log("Error fetching user statistics: " . $e->getMessage());
        }
        
        return $stats;
    }
}

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

// Handle delete (cancel) trade by owner (now safely after initialization)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_trade'])) {
    if (!$user_id) {
        $message .= "<div class='notification error show'>You must be logged in to delete a trade.</div>";
    } else {
        $trade_id = (int)$_POST['trade_id'];
        // Only allow cancelling own open trades
        $stmt_chk = $conn->prepare("SELECT seller_user_id, status FROM p2p_trades WHERE trade_id = ?");
        $stmt_chk->bind_param("i", $trade_id);
        $stmt_chk->execute();
        $row = $stmt_chk->get_result()->fetch_assoc();
        $stmt_chk->close();

        if (!$row) {
            $message .= "<div class='notification error show'>Trade not found.</div>";
        } elseif ((int)$row['seller_user_id'] !== (int)$user_id) {
            $message .= "<div class='notification error show'>You can only delete your own trades.</div>";
        } elseif ($row['status'] !== 'open') {
            $message .= "<div class='notification error show'>Only open trades can be deleted.</div>";
        } else {
            // Hard delete the trade row from the table
            $stmt_del = $conn->prepare("DELETE FROM p2p_trades WHERE trade_id = ?");
            $stmt_del->bind_param("i", $trade_id);
            $stmt_del->execute();
            $stmt_del->close();

            header("Location: p2pTradeList.php?type=my_trades&msg=trade_deleted");
            exit();
        }
    }
}

// Handle form submission to create a new P2P trade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_trade'])) {
    if (!$user_id) {
        $message .= "<div class='notification error show'>You must be logged in to create a trade.</div>";
    } else {
        $trade_type = $_POST['trade_type']; // 'buy' or 'sell'
        $buy_currency_id = $_POST['buy_currency_id'];
        $sell_currency_id = $_POST['sell_currency_id'];
        $amount_bought = (float)$_POST['amount_bought'];
        $exchange_rate = (float)$_POST['exchange_rate'];
        $rate_ok = true;
        
        // Get currency symbols for USD/MMK detection
        $stmt_symbols = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE currency_id IN (?, ?)");
        $stmt_symbols->bind_param("ii", $buy_currency_id, $sell_currency_id);
        $stmt_symbols->execute();
        $result_symbols = $stmt_symbols->get_result();
        $symbols_data = [];
        while ($row = $result_symbols->fetch_assoc()) {
            $symbols_data[$row['currency_id']] = $row['symbol'];
        }
        $stmt_symbols->close();
        
        $buy_symbol = $symbols_data[$buy_currency_id] ?? '';
        $sell_symbol = $symbols_data[$sell_currency_id] ?? '';
        
        // Check if this is an MMK <-> (USD|JPY|THB) trade
        list($is_mmk_foreign_trade, $foreign_symbol) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
        // Validate exchange rate bounds for these trades based on admin daily rate: 1 FOREIGN = X MMK
        if ($is_mmk_foreign_trade) {
            // Find currency IDs for USD and MMK
            $admin_rate = get_latest_mmk_pair_rate($conn, $foreign_symbol); // 1 FOREIGN = X MMK
            // Asymmetric tolerance band: -5% (lower) and +20% (upper)
            $lower_tol = 0.05; // -5%
            $upper_tol = 0.20; // +20%
            if (!$admin_rate || $admin_rate <= 0) {
                // Temporary manual fallback when no admin sources exist
                // Keep USD default; for JPY/THB choose reasonable placeholders if needed
                if ($foreign_symbol === 'USD') { $admin_rate = 3987.37; }
                elseif ($foreign_symbol === 'JPY') { $admin_rate = 27.0; }
                else /* THB */ { $admin_rate = 110.0; }
            }
            $min_allowed = (int)round($admin_rate * (1 - $lower_tol));
            $max_allowed = (int)round($admin_rate * (1 + $upper_tol));
            if ($exchange_rate < $min_allowed || $exchange_rate > $max_allowed) {
                $rate_ok = false;
                $message .= "<div class='notification error show'>For {$foreign_symbol}/MMK trades, today's allowed rate is between " . number_format($min_allowed, 0) . " and " . number_format($max_allowed, 0) . " MMK per 1 {$foreign_symbol}.</div>";
            }
        }
        
        // Calculate amount_sold based on special-cases
        if ($is_mmk_foreign_trade) {
            if ($buy_symbol !== 'MMK') { // buying FOREIGN, paying MMK
                // Buying USD with MMK: amount_sold = amount_bought * exchange_rate
                $amount_sold = $amount_bought * $exchange_rate;
            } else {
                // Buying MMK with USD: amount_sold = amount_bought / exchange_rate
                $amount_sold = $amount_bought / $exchange_rate;
            }
        } else if (is_usd_jpy_thb_pair($buy_symbol, $sell_symbol)) {
            // Cross pair among USD/JPY/THB. Use fixed display orientations:
            // USD/JPY => 1 USD = X JPY, USD/THB => 1 USD = X THB, THB/JPY => 1 THB = X JPY.
            $lower_tol = 0.05; // -5%
            $upper_tol = 0.20; // +20%

            // Determine display orientation
            $disp_base = $buy_symbol; $disp_quote = $sell_symbol;
            if ((($buy_symbol === 'USD') && ($sell_symbol === 'JPY')) || (($buy_symbol === 'JPY') && ($sell_symbol === 'USD'))) {
                $disp_base = 'USD'; $disp_quote = 'JPY';
            } elseif ((($buy_symbol === 'USD') && ($sell_symbol === 'THB')) || (($buy_symbol === 'THB') && ($sell_symbol === 'USD'))) {
                $disp_base = 'USD'; $disp_quote = 'THB';
            } elseif ((($buy_symbol === 'THB') && ($sell_symbol === 'JPY')) || (($buy_symbol === 'JPY') && ($sell_symbol === 'THB'))) {
                $disp_base = 'THB'; $disp_quote = 'JPY';
            }

            // Compute admin cross using display orientation
            $admin_cross_ui = get_cross_rate_from_mmk($conn, $disp_base, $disp_quote);
            if (!$admin_cross_ui || $admin_cross_ui <= 0) {
                // Fallbacks to avoid empty ranges
                if ($disp_base === 'USD' && $disp_quote === 'JPY') { $admin_cross_ui = 150.00; }
                elseif ($disp_base === 'USD' && $disp_quote === 'THB') { $admin_cross_ui = 36.00; }
                elseif ($disp_base === 'THB' && $disp_quote === 'JPY') { $admin_cross_ui = 4.20; }
            }
            $min_allowed_ui = round($admin_cross_ui * (1 - $lower_tol), 2);
            $max_allowed_ui = round($admin_cross_ui * (1 + $upper_tol), 2);

            // Convert user-submitted rate to display orientation if needed
            $user_rate_ui = ($buy_symbol === $disp_base && $sell_symbol === $disp_quote)
                ? (float)$exchange_rate
                : ((float)$exchange_rate > 0 ? (1 / (float)$exchange_rate) : 0);

            if ($user_rate_ui < $min_allowed_ui || $user_rate_ui > $max_allowed_ui) {
                $rate_ok = false;
                $message .= "<div class='notification error show'>For {$disp_base}/{$disp_quote} trades, today's allowed rate is between " . number_format($min_allowed_ui, 2) . " and " . number_format($max_allowed_ui, 2) . " ({$disp_quote} per 1 {$disp_base}).</div>";
            }
            // amount_sold in SELL currency based on submitted orientation
            $amount_sold = $amount_bought * $exchange_rate;
        } else {
            // Original logic for other currency pairs
            if ($buy_currency_id == $sell_currency_id) {
                $amount_sold = $amount_bought;
            } else {
                // Fetch rates to USD for calculation
                $stmt_rates = $conn->prepare("SELECT currency_id, exchange_rate_to_usd FROM currencies WHERE currency_id IN (?, ?)");
                $stmt_rates->bind_param("ii", $buy_currency_id, $sell_currency_id);
                $stmt_rates->execute();
                $result_rates = $stmt_rates->get_result();
                $rates_data = [];
                while ($row = $result_rates->fetch_assoc()) {
                    $rates_data[$row['currency_id']] = $row['exchange_rate_to_usd'];
                }
                $stmt_rates->close();
                
                $buy_rate_to_usd = $rates_data[$buy_currency_id] ?? 0;
                $sell_rate_to_usd = $rates_data[$sell_currency_id] ?? 0;
                
                $is_buy_currency_stronger = $buy_rate_to_usd > $sell_rate_to_usd;
                
                if ($trade_type === 'sell') {
                    if ($is_buy_currency_stronger) {
                        $amount_sold = $amount_bought * $exchange_rate;
                    } else {
                        $amount_sold = $amount_bought / $exchange_rate;
                    }
                } else { // 'buy'
                    if ($is_buy_currency_stronger) {
                        $amount_sold = $amount_bought * $exchange_rate;
                    } else {
                        $amount_sold = $amount_bought / $exchange_rate;
                    }
                }
            }
        }

        // Check if the user has sufficient balance in the currency they are selling
        $required_amount = $amount_sold;
        $required_currency_id = $sell_currency_id;
        
        $can_create_trade = true;
        try {
            $stmt_check = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? AND currency_id = ?");
            $stmt_check->bind_param("ii", $user_id, $required_currency_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $wallet = $result_check->fetch_assoc();
            $stmt_check->close();

            if (!$wallet || $wallet['balance'] < $required_amount) {
                $can_create_trade = false;
                $message .= "<div class='notification error show'>Error: Insufficient balance to create this trade. You need " . number_format($required_amount, 0) . " of the selling currency.</div>";
            }
        } catch (Exception $e) {
            $message .= "<div class='notification error show'>Transaction failed: " . htmlspecialchars($e->getMessage()) . "</div>";
            $can_create_trade = false;
        }
        
        if ($can_create_trade && $rate_ok) {
            $conn->begin_transaction();
            try {
               
                $stmt_insert = $conn->prepare("
                    INSERT INTO p2p_trades (seller_user_id, trade_type, buy_currency_id, sell_currency_id, 
                    amount_bought, amount_sold, exchange_rate, remaining_amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
                ");
                // Normalize amounts: USD supports 2 decimals, MMK as integer
                $amount_bought_norm = ($buy_symbol === 'USD') ? round($amount_bought, 2) : (int)round($amount_bought);
                $amount_sold_norm   = ($sell_symbol === 'USD') ? round($amount_sold, 2)   : (int)round($amount_sold);
                // Store exchange_rate: integer for MMK pairs, higher precision (6 dp) for cross FX
                $exchange_rate_store = $is_mmk_foreign_trade ? (int)round($exchange_rate) : round($exchange_rate, 6);

                // Determine remaining_amount storage unit (front/display currency for USD/MMK SELL posts)
                if ($is_mmk_foreign_trade && $trade_type === 'sell') {
                    // Store remaining in SELL currency for USD/MMK SELL posts (front currency on card)
                    $remaining_amount = ($sell_symbol !== 'MMK') ? (float)$amount_sold_norm : (int)$amount_sold_norm;
                } else {
                    // Default: remaining in BUY currency
                    $remaining_amount = ($buy_symbol !== 'MMK') ? (float)$amount_bought_norm : (int)$amount_bought_norm;
                }

                
                $stmt_insert->bind_param("isiidddd", $user_id, $trade_type, $buy_currency_id, $sell_currency_id,
                        $amount_bought_norm, $amount_sold_norm, $exchange_rate_store, $remaining_amount);
                $stmt_insert->execute();
                $stmt_insert->close();

                $conn->commit();
                $message .= "<div class='notification success show'>Trade successfully created.</div>";
         
                header("Location: p2pTradeList.php?type=my_trades");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $message .= "<div class='notification error show'>An error occurred while creating the trade: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

// Handle form submission to edit an existing P2P trade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_trade'])) {
    if (!$user_id) {
        $message .= "<div class='notification error show'>You must be logged in to edit a trade.</div>";
    } else {
        $trade_id = $_POST['trade_id'];
        $trade_type = $_POST['trade_type'];
        $buy_currency_id = $_POST['buy_currency_id'];
        $sell_currency_id = $_POST['sell_currency_id'];
        $amount_bought = (float)$_POST['amount_bought'];
        $exchange_rate = (float)$_POST['exchange_rate'];
        $rate_ok = true;

        // Fetch symbols for USD/MMK detection
        $stmt_symbols = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE currency_id IN (?, ?)");
        $stmt_symbols->bind_param("ii", $buy_currency_id, $sell_currency_id);
        $stmt_symbols->execute();
        $result_symbols = $stmt_symbols->get_result();
        $symbols_data = [];
        while ($row = $result_symbols->fetch_assoc()) {
            $symbols_data[$row['currency_id']] = $row['symbol'];
        }
        $stmt_symbols->close();

        $buy_symbol = $symbols_data[$buy_currency_id] ?? '';
        $sell_symbol = $symbols_data[$sell_currency_id] ?? '';
        list($is_mmk_foreign_trade, $foreign_symbol) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
        if ($is_mmk_foreign_trade) {
            // Use latest admin USD/MMK rate with tolerance
            $admin_rate = get_latest_mmk_pair_rate($conn, $foreign_symbol);
            $lower_tol = 0.05; // -5%
            $upper_tol = 0.20; // +20%
            if (!$admin_rate || $admin_rate <= 0) {
                if ($foreign_symbol === 'USD') { $admin_rate = 3987.37; }
                elseif ($foreign_symbol === 'JPY') { $admin_rate = 27.0; }
                else /* THB */ { $admin_rate = 110.0; }
            }
            $min_allowed = (int)round($admin_rate * (1 - $lower_tol));
            $max_allowed = (int)round($admin_rate * (1 + $upper_tol));
            if ($exchange_rate < $min_allowed || $exchange_rate > $max_allowed) {
                $rate_ok = false;
                $message .= "<div class='notification error show'>For {$foreign_symbol}/MMK trades, today's allowed rate is between " . number_format($min_allowed, 0) . " and " . number_format($max_allowed, 0) . " MMK per 1 {$foreign_symbol}.</div>";
            }
        }

        // Calculate amount_sold with USD/MMK special-case (same as creation)
        if ($buy_currency_id == $sell_currency_id) {
            $amount_sold = $amount_bought;
        } else if ($is_mmk_foreign_trade) {
            // For USD/MMK: always interpret exchange_rate as 1 USD = X MMK
            if ($buy_symbol !== 'MMK') {
                // Buying USD, selling MMK -> pay MMK
                $amount_sold = $amount_bought * $exchange_rate;
            } else {
                // Buying MMK, selling USD -> pay USD
                $amount_sold = $amount_bought / $exchange_rate;
            }
        } else if (is_usd_jpy_thb_pair($buy_symbol, $sell_symbol)) {
            // Cross FX pairs: interpret exchange_rate as 1 BUY = X SELL
            $amount_sold = $amount_bought * $exchange_rate;
        } else {
            // Other pairs: determine stronger currency for ratio direction
            $stmt_rates = $conn->prepare("SELECT currency_id, exchange_rate_to_usd FROM currencies WHERE currency_id IN (?, ?)");
            $stmt_rates->bind_param("ii", $buy_currency_id, $sell_currency_id);
            $stmt_rates->execute();
            $result_rates = $stmt_rates->get_result();
            $rates_data = [];
            while ($row = $result_rates->fetch_assoc()) {
                $rates_data[$row['currency_id']] = $row['exchange_rate_to_usd'];
            }
            $stmt_rates->close();

            $buy_rate_to_usd = $rates_data[$buy_currency_id] ?? 0;
            $sell_rate_to_usd = $rates_data[$sell_currency_id] ?? 0;
            $is_buy_currency_stronger = $buy_rate_to_usd > $sell_rate_to_usd;

            if ($trade_type === 'sell') {
                $amount_sold = $is_buy_currency_stronger ? ($amount_bought * $exchange_rate)
                                                         : ($amount_bought / $exchange_rate);
            } else { // 'buy'
                $amount_sold = $is_buy_currency_stronger ? ($amount_bought * $exchange_rate)
                                                         : ($amount_bought / $exchange_rate);
            }
        }

        // Do not enforce wallet balance on edit. Funds are validated/deducted only on acceptance.
        $can_update_trade = true;

        if ($can_update_trade && $rate_ok) {
            $conn->begin_transaction();
            try {
                // REMOVED: No longer deduct/return funds when updating trade
                // Funds will only be deducted when trade is accepted

                // Update the trade record in the database
                $stmt_update = $conn->prepare("
                    UPDATE p2p_trades SET
                    trade_type = ?,
                    buy_currency_id = ?,
                    sell_currency_id = ?,
                    amount_bought = ?,
                    amount_sold = ?,
                    exchange_rate = ?,
                    remaining_amount = ?
                    WHERE trade_id = ? AND seller_user_id = ?
                ");
                // Normalize amounts (USD supports cents; MMK integer)
                $amount_bought_norm = ($buy_symbol === 'USD') ? round($amount_bought, 2) : (int)round($amount_bought);
                $amount_sold_norm   = ($sell_symbol === 'USD') ? round($amount_sold, 2)   : (int)round($amount_sold);
                // Store exchange_rate: integer for MMK pairs, higher precision (6 dp) for cross FX
                $exchange_rate_store  = $is_mmk_foreign_trade ? (int)round($exchange_rate) : round($exchange_rate, 6);

                // Determine remaining unit (SELL for USD/MMK SELL posts; else BUY)
                if ($is_mmk_foreign_trade && $trade_type === 'sell') {
                    $remaining_amount = ($sell_symbol === 'USD') ? (float)$amount_sold_norm : (int)$amount_sold_norm;
                } else {
                    $remaining_amount = ($buy_symbol === 'USD') ? (float)$amount_bought_norm : (int)$amount_bought_norm;
                }

                // Bind types: use double for exchange_rate to support decimals on non-MMK pairs
                $stmt_update->bind_param("siidddiii", $trade_type, $buy_currency_id, $sell_currency_id, $amount_bought_norm, $amount_sold_norm, $exchange_rate_store, $remaining_amount, $trade_id, $user_id);
                $stmt_update->execute();
                $stmt_update->close();

                $conn->commit();
                $message .= "<div class='notification success show'>Trade successfully updated.</div>";
                // Redirect to My Trades to show the updated listing
                header("Location: p2pTradeList.php?type=my_trades");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $message .= "<div class='notification error show'>An error occurred while updating the trade: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

// Handle partial trade acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['partial_accept_trade'])) {
    if (!$user_id) {
        $message .= "<div class='notification error show'>You must be logged in to accept a trade.</div>";
    } else {
        $trade_id = $_POST['trade_id'];
        $partial_amount = (float)($_POST['partial_amount'] ?? 0);
        $partial_amount_unit = strtoupper(trim($_POST['partial_amount_unit'] ?? 'BUY')); // BUY or SELL

        // Verify 4-digit PIN before proceeding
        $entered_pin = $_POST['trade_pin'] ?? '';
        $pin_ok = true;
        if (empty($pin_hash)) {
            $message .= "<div class='notification error show'>Security PIN not set. Please contact support.</div>";
            $pin_ok = false;
        } elseif (!preg_match('/^\d{4}$/', $entered_pin)) {
            $message .= "<div class='notification error show'>Please enter your 4-digit PIN.</div>";
            $pin_ok = false;
        } elseif (!password_verify($entered_pin, $pin_hash)) {
            $message .= "<div class='notification error show'>Incorrect PIN.</div>";
            $pin_ok = false;
        }

        // Fetch the trade details (only open trades not owned by current user)
        $stmt_trade = $conn->prepare("
            SELECT
                pt.seller_user_id,
                pt.trade_type,
                pt.buy_currency_id,
                pt.sell_currency_id,
                pt.amount_bought,
                pt.amount_sold,
                pt.exchange_rate,
                pt.remaining_amount,
                u.username AS seller_username,
                bc.symbol AS buy_currency_symbol,
                sc.symbol AS sell_currency_symbol
            FROM p2p_trades pt
            JOIN users u ON pt.seller_user_id = u.user_id
            JOIN currencies bc ON pt.buy_currency_id = bc.currency_id
            JOIN currencies sc ON pt.sell_currency_id = sc.currency_id
            WHERE pt.trade_id = ? AND pt.status = 'open' AND pt.seller_user_id != ?
        ");
        $stmt_trade->bind_param("ii", $trade_id, $user_id);
        $stmt_trade->execute();
        $result_trade = $stmt_trade->get_result();
        $trade = $result_trade->fetch_assoc();
        $stmt_trade->close();

        // Explicit validations for clarity
        if (!$trade) {
            // Diagnose why it failed (own trade? closed? not found?)
            $stmt_diag = $conn->prepare("SELECT seller_user_id, status FROM p2p_trades WHERE trade_id = ?");
            $stmt_diag->bind_param("i", $trade_id);
            $stmt_diag->execute();
            $diag = $stmt_diag->get_result()->fetch_assoc();
            $stmt_diag->close();

            if ($diag) {
                if ((int)$diag['seller_user_id'] === (int)$user_id) {
                    $message .= "<div class='notification error show'>You cannot accept your own trade.</div>";
                } elseif ($diag['status'] !== 'open') {
                    $message .= "<div class='notification error show'>This trade is no longer open.</div>";
                } else {
                    $message .= "<div class='notification error show'>Trade not found (unexpected filter mismatch).</div>";
                }
            } else {
                $message .= "<div class='notification error show'>Trade not found.</div>";
            }
        } elseif (!$pin_ok) {
            // PIN error already messaged
        } elseif ($partial_amount <= 0) {
            $message .= "<div class='notification error show'>Error: Invalid partial amount.</div>";
        } else {
            $seller_id = (int)$trade['seller_user_id'];
            $trade_type = $trade['trade_type'];

            $buy_symbol = $trade['buy_currency_symbol'];
            $sell_symbol = $trade['sell_currency_symbol'];
            list($is_mmk_foreign, $foreign_symbol) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);

                           
            // Calculate partial amounts with explicit unit handling
            // amount_bought is ALWAYS in buy currency
            // amount_sold is ALWAYS in sell currency
            if ($is_mmk_foreign) {
                // Interpret stored exchange_rate as 1 FOREIGN = X MMK
                if ($partial_amount_unit === 'SELL') {
                    // User entered SELL currency amount
                    if ($sell_symbol !== 'MMK' && $buy_symbol === 'MMK') {
                        // SELL=FOREIGN, BUY=MMK
                        $partial_amount_sold   = $partial_amount; // FOREIGN
                        $partial_amount_bought = $partial_amount * (float)$trade['exchange_rate']; // MMK
                    } else {
                        // SELL=MMK, BUY=FOREIGN
                        $partial_amount_sold   = $partial_amount; // MMK
                        $partial_amount_bought = ($partial_amount > 0 && (float)$trade['exchange_rate'] > 0)
                            ? $partial_amount / (float)$trade['exchange_rate'] : 0; // FOREIGN
                    }
                } else { // BUY unit
                    if ($buy_symbol !== 'MMK' && $sell_symbol === 'MMK') {
                        // BUY=FOREIGN, SELL=MMK
                        $partial_amount_bought = $partial_amount; // FOREIGN
                        $partial_amount_sold   = $partial_amount * (float)$trade['exchange_rate']; // MMK
                    } else {
                        // BUY=MMK, SELL=FOREIGN
                        $partial_amount_bought = $partial_amount; // MMK
                        $partial_amount_sold   = ($partial_amount > 0 && (float)$trade['exchange_rate'] > 0)
                            ? $partial_amount / (float)$trade['exchange_rate'] : 0; // FOREIGN
                    }
                }
            } else if (is_usd_jpy_thb_pair($buy_symbol, $sell_symbol)) {
                // Cross FX among USD/JPY/THB: backend stores 1 BUY = X SELL
                if ($partial_amount_unit === 'SELL') {
                    // Input is SELL currency; scale back to BUY using posted ratio
                    $ratio = ($trade['amount_sold'] > 0) ? ($partial_amount / (float)$trade['amount_sold']) : 0;
                    $partial_amount_sold   = $partial_amount; // SELL
                    $partial_amount_bought = (float)$trade['amount_bought'] * $ratio; // BUY
                } else {
                    // Input is BUY currency (default)
                    $ratio = ($trade['amount_bought'] > 0) ? ($partial_amount / (float)$trade['amount_bought']) : 0;
                    $partial_amount_bought = $partial_amount; // BUY
                    $partial_amount_sold   = (float)$trade['amount_sold'] * $ratio; // SELL
                }
            } else {
                // Other pairs: use proportional split; respect unit
                if ($partial_amount_unit === 'SELL') {
                    $ratio = ($trade['amount_sold'] > 0) ? ($partial_amount / (float)$trade['amount_sold']) : 0;
                    $partial_amount_sold   = $partial_amount; // SELL
                    $partial_amount_bought = (float)$trade['amount_bought'] * $ratio; // BUY
                } else {
                    $ratio = ($trade['amount_bought'] > 0) ? ($partial_amount / (float)$trade['amount_bought']) : 0;
                    $partial_amount_bought = $partial_amount; // BUY
                    $partial_amount_sold   = (float)$trade['amount_sold'] * $ratio; // SELL
                }
            }

            
            if ($buy_symbol === 'USD') {
                $partial_amount_bought = round($partial_amount_bought, 2);
            } else {
                $partial_amount_bought = (int)round($partial_amount_bought);
            }
            if ($sell_symbol === 'USD') {
                $partial_amount_sold = round($partial_amount_sold, 2);
            } else {
                $partial_amount_sold = (int)round($partial_amount_sold);
            }

            // Ensure the requested partial does not exceed remaining, respecting storage unit
            $remaining_available = (float)$trade['remaining_amount'];
            $remaining_in_sell = $is_mmk_foreign && ($trade_type === 'sell');
            $epsilon = 0.0001; // tolerance for float rounding

            if ($remaining_in_sell) {
                // Compare in SELL currency for USD/MMK SELL posts
                $lhs = ($sell_symbol === 'USD') ? round($partial_amount_sold, 2) : (int)round($partial_amount_sold);
                $rhs = ($sell_symbol === 'USD') ? round($remaining_available, 2) : (int)round($remaining_available);
            } else {
                // Compare in BUY currency for all other cases
                $lhs = ($buy_symbol === 'USD') ? round($partial_amount_bought, 2) : (int)round($partial_amount_bought);
                $rhs = ($buy_symbol === 'USD') ? round($remaining_available, 2) : (int)round($remaining_available);
            }

            if (($lhs - $rhs) > $epsilon) {
                $message .= "<div class='notification error show'>Error: Requested amount exceeds available amount.</div>";
            } else {
                // Define currency IDs
                $buy_currency_id  = (int)$trade['buy_currency_id'];
                $sell_currency_id = (int)$trade['sell_currency_id'];
                $exchange_rate    = (float)$trade['exchange_rate'];

                // Map roles and currencies
                if ($trade_type === 'sell') {
                    // Acceptor buys from seller
                    // Acceptor pays BUY currency, receives SELL currency
                    $payer_id             = $user_id;              // acceptor
                    $payer_currency_id    = $buy_currency_id;      // pay in buy currency
                    $payer_amount         = $partial_amount_bought;

                    $receiver_id          = $seller_id;            // seller receives buy currency
                    $receiver_currency_id = $buy_currency_id;

                    $deliverer_id         = $seller_id;            // seller delivers sell currency
                    $deliverer_currency_id= $sell_currency_id;
                    $deliverer_amount     = $partial_amount_sold;

                    $receiver_goods_id          = $user_id;        // acceptor receives sell currency
                    $receiver_goods_currency_id = $sell_currency_id;

                    $buyer_id = $user_id;                          // for history
                } else {
                    // Trade type is 'buy' - poster buys; acceptor sells to poster
                    // Poster pays SELL currency; acceptor delivers BUY currency
                    $payer_id             = $seller_id;            // poster (buyer) pays
                    $payer_currency_id    = $sell_currency_id;     // pay in sell currency
                    $payer_amount         = $partial_amount_sold;

                    $receiver_id          = $user_id;              // acceptor receives sell currency
                    $receiver_currency_id = $sell_currency_id;

                    $deliverer_id         = $user_id;              // acceptor delivers buy currency
                    $deliverer_currency_id= $buy_currency_id;
                    $deliverer_amount     = $partial_amount_bought;

                    $receiver_goods_id          = $seller_id;      // poster receives buy currency
                    $receiver_goods_currency_id = $buy_currency_id;

                    $buyer_id = $user_id;                          // keep buyer_user_id as acceptor for consistency with prior code
                }

                // Check balances for both payer and deliverer
                $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? AND currency_id = ?");
                $stmt->bind_param("ii", $payer_id, $payer_currency_id);
                $stmt->execute();
                $payer_wallet = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? AND currency_id = ?");
                $stmt->bind_param("ii", $deliverer_id, $deliverer_currency_id);
                $stmt->execute();
                $deliverer_wallet = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($payer_wallet && $payer_wallet['balance'] >= $payer_amount &&
                    $deliverer_wallet && $deliverer_wallet['balance'] >= $deliverer_amount) {

                    $conn->begin_transaction();
                    try {
                        // 1. Payer pays
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $payer_amount, $payer_id, $payer_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 2. Receiver receives the payment
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $payer_amount, $receiver_id, $receiver_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 3. Deliverer delivers the goods
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $deliverer_amount, $deliverer_id, $deliverer_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 4. Receiver receives the goods
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $deliverer_amount, $receiver_goods_id, $receiver_goods_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // Insert into trade history
                        $insertHistoryQuery = "INSERT INTO p2p_trade_history (
                            trade_id, buyer_user_id, seller_user_id, buy_currency_id, 
                            sell_currency_id, amount_bought, amount_sold, exchange_rate
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmtHistory = $conn->prepare($insertHistoryQuery);
                        $stmtHistory->bind_param(
                            "iiiiiddd",
                            $trade_id, $buyer_id, $seller_id, $buy_currency_id,
                            $sell_currency_id, $partial_amount_bought, $partial_amount_sold, $exchange_rate
                        );
                        $stmtHistory->execute();
                        $stmtHistory->close();

                        // Update remaining: for USD/MMK SELL posts, remaining is stored in SELL currency; otherwise in BUY currency
                        $remaining_in_sell = ($is_mmk_foreign && $trade_type === 'sell');
                        if ($remaining_in_sell) {
                            // Decrement by partial_amount_sold (SELL currency)
                            if ($sell_symbol === 'USD') {
                                $new_remaining_amount = round((float)$trade['remaining_amount'] - (float)$partial_amount_sold, 2);
                                if ($new_remaining_amount > 0) {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET remaining_amount = ? WHERE trade_id = ?");
                                    $stmt->bind_param("di", $new_remaining_amount, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                } else {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET status = 'completed', buyer_user_id = ?, remaining_amount = 0 WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $buyer_id, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            } else {
                                $new_remaining_amount = (int)$trade['remaining_amount'] - (int)$partial_amount_sold;
                                if ($new_remaining_amount > 0) {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET remaining_amount = ? WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $new_remaining_amount, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                } else {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET status = 'completed', buyer_user_id = ?, remaining_amount = 0 WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $buyer_id, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            }
                        } else {
                            // Remaining stored in BUY currency (default); decrement by partial_amount_bought (BUY currency)
                            if ($buy_symbol === 'USD') {
                                $new_remaining_amount = round((float)$trade['remaining_amount'] - (float)$partial_amount_bought, 2);
                                if ($new_remaining_amount > 0) {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET remaining_amount = ? WHERE trade_id = ?");
                                    $stmt->bind_param("di", $new_remaining_amount, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                } else {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET status = 'completed', buyer_user_id = ?, remaining_amount = 0 WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $buyer_id, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            } else {
                                $new_remaining_amount = (int)$trade['remaining_amount'] - (int)$partial_amount_bought;
                                if ($new_remaining_amount > 0) {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET remaining_amount = ? WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $new_remaining_amount, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                } else {
                                    $stmt = $conn->prepare("UPDATE p2p_trades SET status = 'completed', buyer_user_id = ?, remaining_amount = 0 WHERE trade_id = ?");
                                    $stmt->bind_param("ii", $buyer_id, $trade_id);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            }
                        }

                        $conn->commit();

                        // Send notification emails (best-effort; ignore failures)
                        try {
                            // Fetch emails for current user (acceptor) and seller
                            $stmtU = $conn->prepare("SELECT username, email FROM users WHERE user_id IN (?, ?)");
                            $stmtU->bind_param("ii", $user_id, $seller_id);
                            $stmtU->execute();
                            $resU = $stmtU->get_result();
                            $userMap = [];
                            while ($u = $resU->fetch_assoc()) {
                                $userMap[$u['username']] = $u; // might not be keyed by id; fallback below
                            }
                            $stmtU->close();

                            // Safer: fetch by id separately
                            $acceptorU = null; $sellerU = null;
                            $stmtOne = $conn->prepare("SELECT username, email FROM users WHERE user_id = ?");
                            $stmtOne->bind_param("i", $user_id);
                            $stmtOne->execute();
                            $acceptorU = $stmtOne->get_result()->fetch_assoc();
                            $stmtOne->close();

                            $stmtTwo = $conn->prepare("SELECT username, email FROM users WHERE user_id = ?");
                            $stmtTwo->bind_param("i", $seller_id);
                            $stmtTwo->execute();
                            $sellerU = $stmtTwo->get_result()->fetch_assoc();
                            $stmtTwo->close();

                            $subj = 'P2P Trade Partial Completed';
                            $pair = htmlspecialchars($buy_symbol . '/' . $sell_symbol);
                            // Numeric strings
                            $buyAmtStr  = ($buy_symbol === 'USD') ? number_format($partial_amount_bought, 2) : number_format((float)$partial_amount_bought, 0);
                            $sellAmtStr = ($sell_symbol === 'USD') ? number_format($partial_amount_sold, 2) : number_format((float)$partial_amount_sold, 0);

                            // Acceptor email: received SELL, paid BUY
                            $acceptorHtml = '<p>Your partial P2P trade has completed.</p>' .
                                    '<p>Pair: <strong>' . $pair . '</strong></p>' .
                                    '<p>You Received: <strong>' . $sellAmtStr . ' ' . htmlspecialchars($sell_symbol) . '</strong></p>' .
                                    '<p>You Paid: <strong>' . $buyAmtStr . ' ' . htmlspecialchars($buy_symbol) . '</strong></p>' .
                                    '<p>Rate: <strong>' . htmlspecialchars((string)$exchange_rate) . '</strong></p>' .
                                    '<p>Trade ID: <strong>' . htmlspecialchars((string)$trade_id) . '</strong></p>';

                            // Seller (poster) email: received BUY, paid SELL
                            $sellerHtml = '<p>Your partial P2P trade has completed.</p>' .
                                    '<p>Pair: <strong>' . $pair . '</strong></p>' .
                                    '<p>You Received: <strong>' . $buyAmtStr . ' ' . htmlspecialchars($buy_symbol) . '</strong></p>' .
                                    '<p>You Paid: <strong>' . $sellAmtStr . ' ' . htmlspecialchars($sell_symbol) . '</strong></p>' .
                                    '<p>Rate: <strong>' . htmlspecialchars((string)$exchange_rate) . '</strong></p>' .
                                    '<p>Trade ID: <strong>' . htmlspecialchars((string)$trade_id) . '</strong></p>';

                            if ($acceptorU && !empty($acceptorU['email'])) {
                                @p2p_send_email($acceptorU['email'], $acceptorU['username'] ?? '', $subj, $acceptorHtml);
                            }
                            if ($sellerU && !empty($sellerU['email'])) {
                                @p2p_send_email($sellerU['email'], $sellerU['username'] ?? '', $subj, $sellerHtml);
                            }
                        } catch (Throwable $e) { /* ignore mail errors */ }

                        $message .= "<div class='notification success show'>Partial trade successfully completed!</div>";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message .= "<div class='notification error show'>An error occurred during the transaction: " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                } else {
                    // Show detailed insufficient reasons
                    $insufficient = [];
                    if (!$payer_wallet || $payer_wallet['balance'] < $payer_amount) {
                        $payer_symbol = ($payer_currency_id === $buy_currency_id) ? $buy_symbol : $sell_symbol;
                        $insufficient[] = "Payer needs " . number_format($payer_amount, 2) . " " . $payer_symbol . " but has " . number_format($payer_wallet['balance'] ?? 0, 2) . " " . $payer_symbol;
                    }
                    if (!$deliverer_wallet || $deliverer_wallet['balance'] < $deliverer_amount) {
                        $deliverer_symbol = ($deliverer_currency_id === $buy_currency_id) ? $buy_symbol : $sell_symbol;
                        $insufficient[] = "Deliverer needs " . number_format($deliverer_amount, 2) . " " . $deliverer_symbol . " but has " . number_format($deliverer_wallet['balance'] ?? 0, 2) . " " . $deliverer_symbol;
                    }
                    $message .= "<div class='notification error show'>Error: Insufficient balance - " . implode("; ", $insufficient) . ".</div>";
                }
            } // end guard else (requested partial <= remaining)
        }
    }
}

// Handle a user accepting a full trade - FIXED FOR SELL TYPE TRADES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_trade'])) {
    if (!$user_id) {
        $message .= "<div class='notification error show'>You must be logged in to accept a trade.</div>";
    } else {
        $trade_id = $_POST['trade_id'];

        // Verify 4-digit PIN before proceeding
        $entered_pin = $_POST['trade_pin'] ?? '';
        $pin_ok = true;
        if (empty($pin_hash)) {
            $message .= "<div class='notification error show'>Security PIN not set. Please contact support.</div>";
            $pin_ok = false;
        } elseif (!preg_match('/^\d{4}$/', $entered_pin)) {
            $message .= "<div class='notification error show'>Please enter your 4-digit PIN.</div>";
            $pin_ok = false;
        } elseif (!password_verify($entered_pin, $pin_hash)) {
            $message .= "<div class='notification error show'>Incorrect PIN.</div>";
            $pin_ok = false;
        }

        // Fetch the trade details (only open trades not owned by current user)
        $stmt_trade = $conn->prepare("
            SELECT
                pt.seller_user_id,
                pt.trade_type,
                pt.buy_currency_id,
                pt.sell_currency_id,
                pt.amount_bought,
                pt.amount_sold,
                pt.exchange_rate,
                pt.remaining_amount,
                u.username AS seller_username,
                bc.symbol AS buy_currency_symbol,
                sc.symbol AS sell_currency_symbol
            FROM p2p_trades pt
            JOIN users u ON pt.seller_user_id = u.user_id
            JOIN currencies bc ON pt.buy_currency_id = bc.currency_id
            JOIN currencies sc ON pt.sell_currency_id = sc.currency_id
            WHERE pt.trade_id = ? AND pt.status = 'open' AND pt.seller_user_id != ?
        ");
        $stmt_trade->bind_param("ii", $trade_id, $user_id);
        $stmt_trade->execute();
        $result_trade = $stmt_trade->get_result();
        $trade = $result_trade->fetch_assoc();
        $stmt_trade->close();

        // Diagnostics if not found
        if (!$trade) {
            $stmt_diag = $conn->prepare("SELECT seller_user_id, status FROM p2p_trades WHERE trade_id = ?");
            $stmt_diag->bind_param("i", $trade_id);
            $stmt_diag->execute();
            $diag = $stmt_diag->get_result()->fetch_assoc();
            $stmt_diag->close();

            if ($diag) {
                if ((int)$diag['seller_user_id'] === (int)$user_id) {
                    $message .= "<div class='notification error show'>You cannot accept your own trade.</div>";
                } elseif ($diag['status'] !== 'open') {
                    $message .= "<div class='notification error show'>This trade is no longer open.</div>";
                } else {
                    $message .= "<div class='notification error show'>Trade not found (unexpected filter mismatch).</div>";
                }
            } else {
                $message .= "<div class='notification error show'>Trade not found.</div>";
            }
            // done
        } elseif (!$pin_ok) {
            // PIN error already messaged
        } else {
            $seller_id = (int)$trade['seller_user_id'];
            $trade_type = $trade['trade_type'];

            $buy_symbol = $trade['buy_currency_symbol'];
            $sell_symbol = $trade['sell_currency_symbol'];
            // Generalize to MMK <-> (USD|JPY|THB)
            list($is_mmk_foreign_full, $foreign_symbol_full) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);

            // Determine how to interpret remaining based on storage rule
            // For MMK-FOREIGN SELL posts, remaining is stored in SELL currency; otherwise BUY currency
            $remaining_in_sell = ($is_mmk_foreign_full && $trade_type === 'sell');

            // Use remaining_amount with correct unit if available; else fallback to original amount_bought
            if ($trade['remaining_amount'] !== NULL && (float)$trade['remaining_amount'] > 0) {
                if ($remaining_in_sell) {
                    // Remaining stored in SELL currency for MMK-FOREIGN SELL posts; convert to BUY currency
                    if ($buy_symbol === 'MMK' && $sell_symbol !== 'MMK') {
                        // Buy MMK, SELL is FOREIGN; buy amount (MMK) = remaining_foreign * rate
                        $amount_to_buy = (int)round(((float)$trade['remaining_amount']) * (float)$trade['exchange_rate']);
                    } else {
                        // Buy FOREIGN, SELL is MMK; buy amount (FOREIGN) = remaining_mmk / rate
                        $amount_to_buy = ($buy_symbol === 'USD')
                            ? round(((float)$trade['remaining_amount']) / (float)$trade['exchange_rate'], 2)
                            : (int)round(((float)$trade['remaining_amount']) / (float)$trade['exchange_rate']);
                    }
                } else {
                    // remaining stored in BUY currency (default)
                    if ($buy_symbol === 'USD') {
                        $amount_to_buy = round((float)$trade['remaining_amount'], 2);
                    } else {
                        $amount_to_buy = (int)$trade['remaining_amount'];
                    }
                }
            } else {
                if ($buy_symbol === 'USD') {
                    $amount_to_buy = round((float)$trade['amount_bought'], 2);
                } else {
                    $amount_to_buy = (int)$trade['amount_bought'];
                }
            }

            // Compute amount_to_sell (in sell currency)
            if ($is_mmk_foreign_full) {
                // Interpret exchange_rate as: 1 FOREIGN = X MMK
                if ($trade_type === 'sell') {
                    // Acceptor buys from seller: pays BUY, receives SELL
                    if ($buy_symbol === 'MMK' && $sell_symbol !== 'MMK') {
                        // Post: Sell FOREIGN for MMK -> amount_to_buy is MMK; amount_to_sell (FOREIGN) = MMK / rate
                        $amount_to_sold = round($amount_to_buy / $trade['exchange_rate'], ($sell_symbol === 'USD') ? 2 : 0);
                    } else {
                        // Post: Sell MMK for FOREIGN -> amount_to_buy is FOREIGN; amount_to_sell (MMK) = FOREIGN * rate
                        $amount_to_sold = (int)round($amount_to_buy * $trade['exchange_rate']);
                    }
                } else {
                    // Poster buys; acceptor sells to poster
                    if ($buy_symbol !== 'MMK' && $sell_symbol === 'MMK') {
                        // Post: Buy FOREIGN with MMK -> amount_to_buy is FOREIGN; amount_to_sell (MMK) = FOREIGN * rate
                        $amount_to_sold = (int)round($amount_to_buy * $trade['exchange_rate']);
                    } else {
                        // Post: Buy MMK with FOREIGN -> amount_to_buy is MMK; amount_to_sell (FOREIGN) = MMK / rate
                        $amount_to_sold = round($amount_to_buy / $trade['exchange_rate'], ($sell_symbol === 'USD') ? 2 : 0);
                    }
                }
            } else {
                // Non USD/MMK: scale proportionally
                $ratio = $amount_to_buy / (float)$trade['amount_bought'];
                $amount_to_sold = (int)round($trade['amount_sold'] * $ratio);
            }

            $buy_currency_id  = (int)$trade['buy_currency_id'];
            $sell_currency_id = (int)$trade['sell_currency_id'];
            $exchange_rate    = (float)$trade['exchange_rate'];

            // Mapping
            if ($trade_type === 'sell') {
                // Acceptor buys from seller: pays BUY currency, receives SELL currency
                $payer_id          = $user_id;
                $payer_currency_id = $buy_currency_id;   // pay in BUY currency
                $payer_amount      = $amount_to_buy;     // in BUY currency

                $receiver_id          = $seller_id;
                $receiver_currency_id = $buy_currency_id;
                $receiver_amount      = $amount_to_buy;

                $buyer_id = $user_id; // acceptor is buyer
            } else {
                // Poster buys; acceptor sells to poster (keep original buy logic)
                $payer_id          = $seller_id;
                $payer_currency_id = $sell_currency_id;
                $payer_amount      = $amount_to_sold;

                $receiver_id          = $user_id;
                $receiver_currency_id = $buy_currency_id;
                $receiver_amount      = $amount_to_buy;

                $buyer_id = $user_id;
            }

            // Balance check for payer
            $stmt = $conn->prepare("SELECT balance FROM wallets WHERE user_id = ? AND currency_id = ?");
            $stmt->bind_param("ii", $payer_id, $payer_currency_id);
            $stmt->execute();
            $payer_wallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($payer_wallet && $payer_wallet['balance'] >= $payer_amount) {
                $conn->begin_transaction();
                try {
                    if ($trade_type === 'sell') {
                        // SELL POST - acceptor buys

                        // 1. Payer (acceptor) pays BUY currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $payer_amount, $payer_id, $payer_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 2. Seller receives BUY currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $receiver_amount, $receiver_id, $receiver_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 3. Seller delivers SELL currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $amount_to_sold, $seller_id, $sell_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 4. Buyer (acceptor) receives SELL currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $amount_to_sold, $user_id, $sell_currency_id);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // BUY POST - acceptor sells (original sequence)

                        // 1. Payer pays the selling currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $payer_amount, $payer_id, $payer_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 2. Receiver receives the selling currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $payer_amount, $receiver_id, $payer_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 3. Receiver pays the buying currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $receiver_amount, $receiver_id, $receiver_currency_id);
                        $stmt->execute();
                        $stmt->close();

                        // 4. Payer receives the buying currency
                        $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ? AND currency_id = ?");
                        $stmt->bind_param("dii", $receiver_amount, $payer_id, $receiver_currency_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Insert into trade history
                    $insertHistoryQuery = "INSERT INTO p2p_trade_history (
                        trade_id, buyer_user_id, seller_user_id, buy_currency_id, 
                        sell_currency_id, amount_bought, amount_sold, exchange_rate
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtHistory = $conn->prepare($insertHistoryQuery);
                    $stmtHistory->bind_param(
                        "iiiiiddd", 
                        $trade_id, $buyer_id, $seller_id, $buy_currency_id, 
                        $sell_currency_id, $amount_to_buy, $amount_to_sold, $exchange_rate
                    );
                    $stmtHistory->execute();
                    $stmtHistory->close();

                    // Complete trade
                    $stmt_update_trade = $conn->prepare("UPDATE p2p_trades SET status = 'completed', buyer_user_id = ?, remaining_amount = 0 WHERE trade_id = ?");
                    $stmt_update_trade->bind_param("ii", $buyer_id, $trade_id);
                    $stmt_update_trade->execute();
                    $stmt_update_trade->close();

                    $conn->commit();

                    // Send notification emails (best-effort; ignore failures)
                    try {
                        // Fetch emails for current user (acceptor/buyer) and seller
                        $stmtOne = $conn->prepare("SELECT username, email FROM users WHERE user_id = ?");
                        $stmtOne->bind_param("i", $user_id);
                        $stmtOne->execute();
                        $acceptorU = $stmtOne->get_result()->fetch_assoc();
                        $stmtOne->close();

                        $stmtTwo = $conn->prepare("SELECT username, email FROM users WHERE user_id = ?");
                        $stmtTwo->bind_param("i", $seller_id);
                        $stmtTwo->execute();
                        $sellerU = $stmtTwo->get_result()->fetch_assoc();
                        $stmtTwo->close();

                        $subj = 'P2P Trade Completed';
                        $pair = htmlspecialchars($buy_symbol . '/' . $sell_symbol);
                        // Numeric strings
                        $buyAmtStr  = ($buy_symbol === 'USD') ? number_format($amount_to_buy, 2)  : number_format((float)$amount_to_buy, 0);
                        $sellAmtStr = ($sell_symbol === 'USD') ? number_format($amount_to_sold, 2) : number_format((float)$amount_to_sold, 0);

                        // Acceptor email: received SELL, paid BUY
                        $acceptorHtml = '<p>Your P2P trade has completed.</p>' .
                                '<p>Pair: <strong>' . $pair . '</strong></p>' .
                                '<p>You Received: <strong>' . $sellAmtStr . ' ' . htmlspecialchars($sell_symbol) . '</strong></p>' .
                                '<p>You Paid: <strong>' . $buyAmtStr . ' ' . htmlspecialchars($buy_symbol) . '</strong></p>' .
                                '<p>Rate: <strong>' . htmlspecialchars((string)$exchange_rate) . '</strong></p>' .
                                '<p>Trade ID: <strong>' . htmlspecialchars((string)$trade_id) . '</strong></p>';

                        // Seller email: received BUY, paid SELL
                        $sellerHtml = '<p>Your P2P trade has completed.</p>' .
                                '<p>Pair: <strong>' . $pair . '</strong></p>' .
                                '<p>You Received: <strong>' . $buyAmtStr . ' ' . htmlspecialchars($buy_symbol) . '</strong></p>' .
                                '<p>You Paid: <strong>' . $sellAmtStr . ' ' . htmlspecialchars($sell_symbol) . '</strong></p>' .
                                '<p>Rate: <strong>' . htmlspecialchars((string)$exchange_rate) . '</strong></p>' .
                                '<p>Trade ID: <strong>' . htmlspecialchars((string)$trade_id) . '</strong></p>';

                        if ($acceptorU && !empty($acceptorU['email'])) {
                            @p2p_send_email($acceptorU['email'], $acceptorU['username'] ?? '', $subj, $acceptorHtml);
                        }
                        if ($sellerU && !empty($sellerU['email'])) {
                            @p2p_send_email($sellerU['email'], $sellerU['username'] ?? '', $subj, $sellerHtml);
                        }
                    } catch (Throwable $e) { /* ignore mail errors */ }

                    $message .= "<div class='notification success show'>Trade successfully completed! You traded " . number_format($amount_to_buy, 0) . " " . htmlspecialchars($trade['buy_currency_symbol']) . " with " . htmlspecialchars($trade['seller_username']) . ".</div>";

                } catch (Exception $e) {
                    $conn->rollback();
                    $message .= "<div class='notification error show'>An error occurred during the transaction: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                // Use correct payer currency in the message
                $payer_symbol = ($payer_currency_id === $buy_currency_id) ? $buy_symbol : $sell_symbol;
                $message .= "<div class='notification error show'>Error: Insufficient balance to complete this trade. You need " . number_format($payer_amount, 2) . " " . $payer_symbol . " but only have " . number_format($payer_wallet['balance'], 2) . " " . $payer_symbol . ".</div>";
            }
        }
    }
}
// Determine which currency to filter by
$type = isset($_GET['type']) && in_array($_GET['type'], ['buy', 'sell', 'my_trades']) ? $_GET['type'] : 'buy';
$target_currency_id = isset($_GET['currency']) ? intval($_GET['currency']) : null;
$params = [];
$param_types = '';

// Read optional unified search filter (matches username OR wallet address)
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_param = '';
if (!empty($search_query)) {
    $search_param = '%' . $search_query . '%';
}

// In p2pTradeList.php, update the SQL query:
$sql = "
    SELECT 
        t.trade_id,
        t.trade_type,
        t.amount_bought, 
        t.amount_sold, 
        t.exchange_rate,
        t.remaining_amount,
        u.username AS seller_username,
        u.user_walletAddress AS seller_wallet_address,
        u.profile_image AS seller_profile_image,
        u.created_at AS seller_join_date,  -- ADD THIS
        buy_curr.symbol AS buy_currency_symbol,
        buy_curr.name AS buy_currency_name,
        sell_curr.symbol AS sell_currency_symbol,
        sell_curr.name AS sell_currency_name,
        t.buy_currency_id,
        t.sell_currency_id,
        u.user_id AS seller_user_id  -- ADD THIS if not already present
    FROM p2p_trades t
    JOIN users u ON t.seller_user_id = u.user_id
    JOIN currencies buy_curr ON t.buy_currency_id = buy_curr.currency_id
    JOIN currencies sell_curr ON t.sell_currency_id = sell_curr.currency_id
    WHERE t.status = 'open'
";

// Add a condition based on the type
if ($type === 'my_trades') {
    $sql .= " AND t.seller_user_id = ?";
    $param_types .= 'i';
    $params[] = $user_id;
} else if ($type === 'buy') { // Show 'sell' trades
    $sql .= " AND t.trade_type = 'sell' AND t.seller_user_id != ?";
    $param_types .= 'i';
    $params[] = $user_id;
} else if ($type === 'sell') { // Show 'buy' trades
    $sql .= " AND t.trade_type = 'buy' AND t.seller_user_id != ?";
    $param_types .= 'i';
    $params[] = $user_id;
}

// Add search condition if search query is provided
if (!empty($search_param)) {
    $sql .= " AND (u.username LIKE ? OR u.user_walletAddress LIKE ?)";
    $param_types .= 'ss';
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($target_currency_id) {
    if ($type === 'buy') {
        $sql .= " AND t.buy_currency_id = ?";
        $param_types .= 'i';
        $params[] = $target_currency_id;
    } else if ($type === 'sell') {
        $sql .= " AND t.sell_currency_id = ?";
        $param_types .= 'i';
        $params[] = $target_currency_id;
    } else if ($type === 'my_trades') {
        // For my_trades, we need to filter by both user_id and currency
        $sql .= " AND (t.buy_currency_id = ? OR t.sell_currency_id = ?)";
        $param_types .= 'ii';
        $params[] = $target_currency_id;
        $params[] = $target_currency_id;
    }
}

$sql .= " ORDER BY t.trade_id DESC";

try {
    $stmt = $conn->prepare($sql);
    if (!empty($param_types)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trades[] = $row;
    }
    $stmt->close();
    
    // Pre-calculate statistics for all unique sellers
    $user_stats_cache = [];
    foreach ($trades as $trade) {
        $seller_id = $trade['seller_user_id'] ?? null;
        if ($seller_id && !isset($user_stats_cache[$seller_id])) {
            $user_stats_cache[$seller_id] = get_user_statistics($conn, $seller_id);
        }
    }
    
} catch (Exception $e) {
    $message .= "<div class='notification error show'>An error occurred while fetching data: " . htmlspecialchars($e->getMessage()) . "</div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>P2P Trade | ACCQURA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inria+Sans:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        /* Trade Listings Styles */
        .trades-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .trades-header h2 {
            font-size: 1.5rem;
            color: #2d3748;
            font-weight: 700;
        }
        
        .filters-container {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .type-tabs {
            display: flex;
            background-color: #f8f9fa;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        
        .type-tab {
            padding: 8px 20px;
            text-decoration: none;
            color: #6b7280;
            font-weight: 600;
            transition: all 0.3s;
            border-right: 1px solid #e0e0e0;
        }
        
        .type-tab:last-child {
            border-right: none;
        }
        
        .type-tab:hover {
            background-color: #e9ecef;
            color: #2e7d32;
        }
        
        .type-tab.active {
            background-color: #4caf50;
            color: white;
        }
        
        .search-filter {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-input {
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.9rem;
            min-width: 200px;
        }
        
        .currency-select {
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.9rem;
            background-color: white;
        }
        
        .create-trade-btn {
            background-color: #4caf50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .create-trade-btn:hover {
            background-color: #43a047;
            transform: translateY(-2px);
        }

        /* Hover effect for progress bar */
.trade-card:hover .progress-fill {
    filter: brightness(1.1);
}

/* Tooltip for progress bar on hover */
.progress-bar {
    position: relative;
}

.progress-bar:hover::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background-color: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    white-space: nowrap;
    z-index: 10;
    margin-bottom: 5px;
}
        
        /* Trade Cards Grid - Updated for 3 columns */
.trades-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr); /* 3 equal columns */
    gap: 15px; /* Reduced gap for tighter layout */
    margin-bottom: 30px;
}

@media (max-width: 1100px) {
    .trades-grid {
        grid-template-columns: repeat(2, 1fr); /* 2 columns on medium screens */
    }
}

@media (max-width: 768px) {
    .trades-grid {
        grid-template-columns: 1fr; /* 1 column on mobile */
    }
}

/* Smaller Trade Cards */
.trade-card {
    background-color: white;
    border-radius: 10px; /* Slightly smaller radius */
    padding: 15px; /* Reduced padding */
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05); /* Lighter shadow */
    border: 1px solid #e0e0e0;
    transition: all 0.3s;
    position: relative;
    min-height: 220px; /* Reduced height */
    display: flex;
    flex-direction: column;
}

.trade-card:hover {
    transform: translateY(-2px); /* Smaller hover effect */
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}

/* Smaller trade header */
.trade-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px; /* Reduced margin */
}

.trade-poster {
    font-size: 0.8rem; /* Smaller font */
    color: #6b7280;
    line-height: 1.2;
}

/* Smaller amounts */
.trade-amount {
    font-size: 1.2rem; /* Reduced from 1.5rem */
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 3px; /* Reduced */
}

.trade-pair {
    font-size: 0.9rem; /* Smaller font */
    color: #6b7280;
    margin-bottom: 5px;
}

.trade-rate {
    font-size: 0.8rem; /* Smaller font */
    color: #4caf50;
    font-weight: 600;
    margin-bottom: 8px;
}

/* New Progress Bar Styles */
.trade-progress-container {
    margin: 8px 0 10px 0;
}

.progress-label {
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 4px;
    display: flex;
    justify-content: space-between;
}

.progress-label span {
    font-weight: 600;
}

.progress-bar {
    height: 6px; /* Slimmer progress bar */
    background-color: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease;
}

.progress-fill.available {
    background-color: #10b981; /* Green for available */
}

.progress-fill.sold {
    background-color: #ef4444; /* Red for sold */
}



/* Smaller action buttons */
.trade-actions-full {
    display: flex;
    gap: 8px; /* Reduced gap */
    margin-top: 12px;
    flex-wrap: wrap;
}

.action-btn {
    flex: 1;
    padding: 8px 10px; /* Smaller padding */
    border-radius: 6px; /* Smaller radius */
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    border: none;
    text-align: center;
    text-decoration: none;
    font-size: 0.8rem; /* Smaller font */
    min-width: 0; /* Allow buttons to shrink */
}

/* Make wallet chip even smaller */
.wallet-chip {
    font-size: 0.7rem; /* Smaller font */
    color: #6b7280;
    background-color: #f8f9fa;
    padding: 2px 6px; /* Smaller padding */
    border-radius: 10px; /* Smaller radius */
    border: 1px solid #e0e0e0;
    cursor: pointer;
    transition: all 0.3s;
    margin-left: 8px; /* Reduced margin */
}

/* Delete button size adjustment */
.delete-btn {
    background-color: #ef4444;
    color: white;
    border: none;
    width: 24px; /* Smaller */
    height: 24px; /* Smaller */
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 0.9rem;
}
        
        .delete-btn:hover {
            background-color: #dc2626;
            transform: scale(1.1);
        }
        
        .trade-details {
            margin-bottom: 15px;
        }
        
        .trade-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .trade-pair {
            font-size: 1.1rem;
            color: #6b7280;
        }
        
        .trade-rate {
            font-size: 0.9rem;
            color: #4caf50;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .trade-remaining {
            font-size: 0.85rem;
            color: #f59e0b;
            background-color: #fef3c7;
            padding: 5px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .trade-actions-full {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .action-btn {
            flex: 1;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-align: center;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .buy-btn {
            background-color: #4caf50;
            color: white;
        }
        
        .buy-btn:hover {
            background-color: #43a047;
        }
        
        .sell-btn {
            background-color: #ef4444;
            color: white;
        }
        
        .sell-btn:hover {
            background-color: #dc2626;
        }
        
        .partial-btn {
            background-color: #3b82f6;
            color: white;
        }
        
        .partial-btn:hover {
            background-color: #2563eb;
        }
        
        .manage-btn {
            background-color: #f59e0b;
            color: white;
        }
        
        .manage-btn:hover {
            background-color: #d97706;
        }
        
        /* Wallet Chip */
        .wallet-chip {
            font-size: 0.75rem;
            color: #6b7280;
            background-color: #f8f9fa;
            padding: 3px 8px;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 10px;
        }
        
        .wallet-chip:hover {
            background-color: #e9ecef;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background-color: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .close-button {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }
        
        .close-button:hover {
            color: #333;
        }
        
        /* PIN Modal */
        .pin-modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .pin-modal-content {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
        }
        
        /* Notifications */
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
        
        /* No Data */
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
            
            .trades-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .filters-container {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }
            
            .type-tabs {
                width: 100%;
                justify-content: center;
            }
            
            .search-filter {
                width: 100%;
                flex-direction: column;
            }
            
            .search-input, .currency-select {
                width: 100%;
            }
            
            .trades-grid {
                grid-template-columns: 1fr;
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
            
            .trade-actions-full {
                flex-direction: column;
            }

            @media (max-width: 480px) {
    .trades-grid {
        gap: 10px;
    }
    
    .trade-card {
        padding: 12px;
        min-height: 200px;
    }
    
    .trade-amount {
        font-size: 1.1rem;
    }
    
    .trade-actions-full {
        flex-direction: column;
    }
    
    .action-btn {
        padding: 10px 12px; /* Slightly larger for mobile touch */
    }
}
        }

        /* Add to your existing CSS */
.trade-poster-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.seller-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    overflow: hidden;
    background-color: #f0f9f0;
    border: 2px solid #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.seller-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.seller-avatar span {
    font-weight: 700;
    font-size: 0.9rem;
    color: #2e7d32;
}

/* Adjust trade header layout */
.trade-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}

/* Profile Image Modal */
.profile-image-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.profile-image-modal.active {
    opacity: 1;
    visibility: visible;
}

.profile-image-modal-content {
    background-color: white;
    border-radius: 16px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    position: relative;
    transform: translateY(-20px);
    transition: transform 0.3s ease;
}

.profile-image-modal.active .profile-image-modal-content {
    transform: translateY(0);
}

.profile-image-close {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 28px;
    color: #666;
    cursor: pointer;
    background: none;
    border: none;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.3s;
}

.profile-image-close:hover {
    background-color: #f0f0f0;
    color: #333;
}

.profile-image-container {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    margin: 0 auto 20px;
    overflow: hidden;
    border: 4px solid #4caf50;
    background-color: #f0f9f0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.profile-image-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-image-container span {
    font-size: 3rem;
    font-weight: 700;
    color: #2e7d32;
}

/* Add to existing .seller-avatar style */
.seller-avatar {
    transition: all 0.3s ease;
}

.seller-avatar:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.profile-username {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 5px;
}

.profile-role {
    font-size: 0.9rem;
    color: #6b7280;
    margin-bottom: 20px;
}

.profile-stats {
    display: flex;
    justify-content: space-around;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.profile-stat {
    text-align: center;
}

.profile-stat-value {
    font-size: 1.2rem;
    font-weight: 700;
    color: #4caf50;
    display: block;
}

.profile-stat-label {
    font-size: 0.8rem;
    color: #6b7280;
    margin-top: 5px;
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
                    <p>P2P Trade Listings</p>
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
            <a href="p2pTradeList.php" class="nav-tab active">
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
            <!-- Trade Listings Header -->
            <div class="trades-header">
                <h2>
                    <?php 
                        if ($type === 'buy') echo 'Buy Crypto';
                        elseif ($type === 'sell') echo 'Sell Crypto';
                        else echo 'My Trades';
                    ?>
                </h2>
                <div class="filters-container">
                    <div class="type-tabs">
                        <a href="?type=buy<?php echo $target_currency_id ? '&currency=' . $target_currency_id : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                           class="type-tab <?php echo $type === 'buy' ? 'active' : ''; ?>">
                           Buy
                        </a>
                        <a href="?type=sell<?php echo $target_currency_id ? '&currency=' . $target_currency_id : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                           class="type-tab <?php echo $type === 'sell' ? 'active' : ''; ?>">
                           Sell
                        </a>
                        <a href="?type=my_trades<?php echo $target_currency_id ? '&currency=' . $target_currency_id : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                           class="type-tab <?php echo $type === 'my_trades' ? 'active' : ''; ?>">
                           My Trades
                        </a>
                    </div>
                    
                    <form method="GET" class="search-filter">
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Search by username or wallet" class="search-input">
                        <select name="currency" class="currency-select" onchange="this.form.submit()">
                            <option value="">All Currencies</option>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>" 
                                    <?php echo ($target_currency_id == $currency['currency_id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($currency['symbol']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="window.location.href='p2pTradeList.php?type=<?php echo urlencode($type); ?>'" 
                                class="refresh-btn" style="background-color: #f8f9fa; color: #2d3748;">
                            <i class="fas fa-redo"></i>
                        </button>
                    </form>
                    
                    <button type="button" id="open-modal" class="create-trade-btn">
                        <i class="fas fa-plus-circle"></i> Create New Trade
                    </button>
                </div>
            </div>
            
            <!-- Trade Listings Grid -->
            <div class="trades-grid">
                <?php if (empty($trades)): ?>
                    <div class="no-data" style="grid-column: 1 / -1;">
                        <i class="fas fa-exchange-alt"></i>
                        <p>No active trades found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($trades as $trade): ?>
                        <?php 
                           $seller_profile_image = '';
        $seller_initials = strtoupper(substr($trade['seller_username'], 0, 1));
        
        if (!empty($trade['seller_profile_image'])) {
            $seller_image_name = $trade['seller_profile_image'];
            $upload_dir = __DIR__ . '/uploads/';
            
            // Check for exact filename match
            if (file_exists($upload_dir . $seller_image_name)) {
                $seller_profile_image = 'uploads/' . $seller_image_name;
            } else {
                // Try to find with different extensions
                $base_name = pathinfo($seller_image_name, PATHINFO_FILENAME);
                $extensions = ['jpg','jpeg','png','gif','webp'];
                foreach ($extensions as $ext) {
                    if (file_exists($upload_dir . $base_name . '.' . $ext)) {
                        $seller_profile_image = 'uploads/' . $base_name . '.' . $ext;
                        break;
                    }
                }
            }
        }
                            // Prepare masked wallet address
                            $addrRaw = $trade['seller_wallet_address'] ?? '';
                            $maskedAddr = '';
                            if (!empty($addrRaw)) {
                                $len = strlen($addrRaw);
                                if ($len > 12) {
                                    $maskedAddr = substr($addrRaw, 0, 6) . 'xxxx' . substr($addrRaw, -6);
                                } else {
                                    $maskedAddr = $addrRaw;
                                }
                            }
                        ?>
                        <div class="trade-card <?php echo ($trade['trade_type'] === 'sell') ? 'sell' : 'buy'; ?>">

                           <!-- Corrected structure -->
<div class="trade-header">
    <div style="display: flex; align-items: flex-start; justify-content: space-between; width: 100%;">
        <div class="trade-poster-info" style="display: flex; align-items: center; gap: 10px; flex: 1;">
          <?php 
// Get user statistics from cache
$user_stats = $user_stats_cache[$trade['seller_user_id']] ?? [
    'total_trades' => 0,
    'success_rate' => 0,
    'member_since' => 'Today'
];
?>

<div class="seller-avatar" 
     style="width: 35px; height: 35px; border-radius: 50%; overflow: hidden; background-color: #f0f9f0; border: 2px solid #e0e0e0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; cursor: pointer;"
     onclick="showProfileImageModal(
         '<?php echo htmlspecialchars($seller_profile_image ?: ''); ?>', 
         '<?php echo htmlspecialchars($trade['seller_username']); ?>', 
         '<?php echo htmlspecialchars($seller_initials); ?>',
         <?php echo $user_stats['total_trades']; ?>,
         <?php echo $user_stats['success_rate']; ?>,
         '<?php echo htmlspecialchars($user_stats['member_since']); ?>'
     )">
    <?php if (!empty($seller_profile_image)): ?>
        <img src="<?php echo htmlspecialchars($seller_profile_image); ?>" 
             alt="<?php echo htmlspecialchars($trade['seller_username']); ?>" 
             style="width: 100%; height: 100%; object-fit: cover;">
    <?php else: ?>
        <span style="font-weight: 700; font-size: 0.9rem; color: #2e7d32;">
            <?php echo htmlspecialchars($seller_initials); ?>
        </span>
    <?php endif; ?>
</div>
            <div style="min-width: 0; flex: 1;">
                <div class="trade-poster" style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <span>Posted by: <strong><?php echo htmlspecialchars($trade['seller_username']); ?></strong></span>
                    <?php if (!empty($maskedAddr)): ?>
                        <span class="wallet-chip" 
                              onclick="showWalletModal('<?php echo htmlspecialchars($addrRaw, ENT_QUOTES); ?>')">
                            Wallet: <?php echo htmlspecialchars($maskedAddr); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($type === 'my_trades'): ?>
            <div style="flex-shrink: 0; margin-left: 10px;">
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="trade_id" value="<?php echo htmlspecialchars($trade['trade_id']); ?>">
                    <button type="submit" name="delete_trade" title="Delete trade"
                            onclick="return confirm('Are you sure you want to cancel this trade?');"
                            class="delete-btn">
                        &times;
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
                            
                            <div class="trade-details">
                                <?php 
                                    $buy_symbol = htmlspecialchars($trade['buy_currency_symbol']);
                                    $sell_symbol = htmlspecialchars($trade['sell_currency_symbol']);
                                    list($is_mmk_foreign_disp_card, $foreign_sym_card) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
                                    
                                    if ($trade['trade_type'] === 'sell'): // Seller's post 
                                        if ($is_mmk_foreign_disp_card):
                                            if ($buy_symbol === 'MMK' && $sell_symbol !== 'MMK') {
                                                $foreign_amount = ($trade['amount_bought'] > 0 && $trade['exchange_rate'] > 0)
                                                    ? ($trade['amount_bought'] / $trade['exchange_rate'])
                                                    : 0;
                                                $display_amount_sold = ($sell_symbol === 'USD') ? number_format($foreign_amount, 2) : number_format((int)round($foreign_amount), 0);
                                ?>
                                                <div class="trade-amount">
                                                    Buy <?php echo $display_amount_sold; ?> <?php echo $sell_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo number_format($trade['amount_bought'], 0); ?> <?php echo $buy_symbol; ?>
                                                </div>
                                <?php
                                            } else {
                                ?>
                                                <div class="trade-amount">
                                                    Buy <?php echo number_format($trade['amount_sold'], 0); ?> <?php echo $sell_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo number_format($trade['amount_bought'], 0); ?> <?php echo $buy_symbol; ?>
                                                </div>
                                <?php
                                            }
                                        else:
                                            $fx_set = ['USD','JPY','THB'];
                                            if (in_array($buy_symbol, $fx_set, true) && in_array($sell_symbol, $fx_set, true) && $buy_symbol !== $sell_symbol) {
                                                $sell_fmt = ($sell_symbol === 'USD') ? number_format((float)$trade['amount_sold'], 2) : number_format((int)round($trade['amount_sold']), 0);
                                                $buy_fmt  = ($buy_symbol === 'USD') ? number_format((float)$trade['amount_bought'], 2) : number_format((int)round($trade['amount_bought']), 0);
                                ?>
                                                <div class="trade-amount">
                                                    Buy <?php echo $sell_fmt; ?> <?php echo $sell_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo $buy_fmt; ?> <?php echo $buy_symbol; ?>
                                                </div>
                                <?php
                                            } else {
                                ?>
                                                <div class="trade-amount">
                                                    Buy <?php echo number_format($trade['amount_bought'], 0); ?> <?php echo $buy_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo number_format($trade['amount_sold'], 0); ?> <?php echo $sell_symbol; ?>
                                                </div>
                                <?php
                                            }
                                        endif;
                                    else: /* Buyer's post */
                                        list($is_mmk_foreign_card, $foreign_card_symbol) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
                                        if ($is_mmk_foreign_card):
                                            if ($sell_symbol === 'MMK' && $buy_symbol !== 'MMK') {
                                                $display_amount_sold = number_format((int)round(($trade['amount_bought'] > 0 && $trade['exchange_rate'] > 0)
                                                    ? ($trade['amount_bought'] * $trade['exchange_rate']) : 0), 0);
                                            } elseif ($buy_symbol === 'MMK' && $sell_symbol !== 'MMK') {
                                                $val = ($trade['amount_bought'] > 0 && $trade['exchange_rate'] > 0)
                                                    ? ($trade['amount_bought'] / $trade['exchange_rate']) : 0;
                                                $display_amount_sold = ($sell_symbol === 'USD') ? number_format($val, 2) : number_format((int)round($val), 0);
                                            } else {
                                                $display_amount_sold = number_format($trade['amount_sold'], 0);
                                            }
                                ?>
                                            <div class="trade-amount">
                                                Sell <?php echo number_format($trade['amount_bought'], 0); ?> <?php echo $buy_symbol; ?>
                                            </div>
                                            <div class="trade-pair">
                                                for <?php echo $display_amount_sold; ?> <?php echo $sell_symbol; ?>
                                            </div>
                                <?php
                                        else:
                                            $fx_set2 = ['USD','JPY','THB'];
                                            if (in_array($buy_symbol, $fx_set2, true) && in_array($sell_symbol, $fx_set2, true) && $buy_symbol !== $sell_symbol) {
                                                $buy_fmt  = ($buy_symbol === 'USD') ? number_format((float)$trade['amount_bought'], 2) : number_format((int)round($trade['amount_bought']), 0);
                                                $sell_fmt = ($sell_symbol === 'USD') ? number_format((float)$trade['amount_sold'], 2) : number_format((int)round($trade['amount_sold']), 0);
                                ?>
                                                <div class="trade-amount">
                                                    Sell <?php echo $buy_fmt; ?> <?php echo $buy_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo $sell_fmt; ?> <?php echo $sell_symbol; ?>
                                                </div>
                                <?php
                                            } else {
                                                $display_amount_sold = ($sell_symbol === 'USD' && $buy_symbol === 'MMK')
                                                    ? number_format(($trade['amount_bought'] > 0 && $trade['exchange_rate'] > 0) ? ($trade['amount_bought'] / $trade['exchange_rate']) : 0, 2)
                                                    : number_format($trade['amount_sold'], 0);
                                ?>
                                                <div class="trade-amount">
                                                    Sell <?php echo number_format($trade['amount_bought'], 0); ?> <?php echo $buy_symbol; ?>
                                                </div>
                                                <div class="trade-pair">
                                                    for <?php echo $display_amount_sold; ?> <?php echo $sell_symbol; ?>
                                                </div>
                                <?php 
                                            }
                                        endif;
                                    endif; 
                                ?>
                                
                                <!-- Exchange Rate -->
                                <div class="trade-rate">
                                    <?php
                                        $rate_to_display = 'N/A';
                                        list($is_mmk_foreign_disp, $foreign_disp) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
                                        
                                        if ($is_mmk_foreign_disp) {
                                            $base_rate = !empty($trade['exchange_rate']) && (float)$trade['exchange_rate'] > 0
                                                ? (float)$trade['exchange_rate']
                                                : (isset($latest_mmk_rates[$foreign_disp]) && $latest_mmk_rates[$foreign_disp] > 0 ? (float)$latest_mmk_rates[$foreign_disp] : null);
                                            if ($base_rate && $base_rate > 0) {
                                                $rate_to_display = "1 {$foreign_disp} = " . number_format($base_rate, 0) . " MMK";
                                            }
                                        } else {
                                            $set_fx = ['USD','JPY','THB'];
                                            if (in_array($buy_symbol, $set_fx, true) && in_array($sell_symbol, $set_fx, true) && $buy_symbol !== $sell_symbol) {
                                                $base = $buy_symbol; $quote = $sell_symbol;
                                                if ((($buy_symbol === 'USD') && ($sell_symbol === 'JPY')) || (($buy_symbol === 'JPY') && ($sell_symbol === 'USD'))) {
                                                    $base = 'USD'; $quote = 'JPY';
                                                } elseif ((($buy_symbol === 'USD') && ($sell_symbol === 'THB')) || (($buy_symbol === 'THB') && ($sell_symbol === 'USD'))) {
                                                    $base = 'USD'; $quote = 'THB';
                                                } elseif ((($buy_symbol === 'THB') && ($sell_symbol === 'JPY')) || (($buy_symbol === 'JPY') && ($sell_symbol === 'THB'))) {
                                                    $base = 'THB'; $quote = 'JPY';
                                                }
                                                
                                                $er = (float)$trade['exchange_rate'];
                                                if ($er > 0) {
                                                    if ($buy_symbol === $base && $sell_symbol === $quote) {
                                                        $rate_ui = $er;
                                                    } else {
                                                        $rate_ui = 1.0 / $er;
                                                    }
                                                }
                                                
                                                if ($er <= 0) {
                                                    $ab = (float)$trade['amount_bought'];
                                                    $as = (float)$trade['amount_sold'];
                                                    if ($ab > 0 && $as > 0) {
                                                        if ($buy_symbol === $base && $sell_symbol === $quote) {
                                                            $rate_ui = $as / $ab;
                                                        } elseif ($buy_symbol === $quote && $sell_symbol === $base) {
                                                            $rate_ui = $ab / $as;
                                                        }
                                                    }
                                                }
                                                
                                                if (isset($rate_ui) && $rate_ui > 0) {
                                                    $decimals = in_array($quote, ['JPY','THB'], true) ? 0 : 2;
                                                    $rate_to_display = "1 {$base} = " . number_format($rate_ui, $decimals) . " {$quote}";
                                                }
                                            } else {
                                                if ($trade['amount_sold'] > 0) {
                                                    $rate = $trade['amount_bought'] / $trade['amount_sold'];
                                                    $rate_to_display = "1 {$sell_symbol} = " . number_format($rate, 2) . " {$buy_symbol}";
                                                }
                                            }
                                        }
                                        echo $rate_to_display;
                                    ?>
                                </div>
                                
                                <!-- Remaining Amount -->
                               <!-- Remaining Amount -->
<?php
    list($is_mmk_foreign_card2, $foreign_card_symbol2) = is_mmk_foreign_pair($buy_symbol, $sell_symbol);
    $remaining_in_sell = (($buy_symbol === 'MMK' && $sell_symbol === 'USD') || $is_mmk_foreign_card2) && $trade['trade_type'] === 'sell';
    $rem_val = (float)$trade['remaining_amount'];
    
    if ($remaining_in_sell) {
        $full_val = (float)$trade['amount_sold'];
        $epsilon = 0.001;
        $show_remaining = ($rem_val + $epsilon) < $full_val;
    } else {
        $full_val = (float)$trade['amount_bought'];
        $epsilon = ($buy_symbol === 'USD') ? 0.001 : 0.5;
        $show_remaining = ($rem_val + $epsilon) < $full_val;
    }
    
    // Calculate progress percentages
    if ($remaining_in_sell) {
        $total_amount = (float)$trade['amount_sold'];
        $remaining_amount = (float)$trade['remaining_amount'];
    } else {
        $total_amount = (float)$trade['amount_bought'];
        $remaining_amount = (float)$trade['remaining_amount'];
    }
    
    // Ensure we have valid numbers
    if ($total_amount <= 0) $total_amount = 1;
    $available_percentage = ($remaining_amount / $total_amount) * 100;
    $sold_percentage = 100 - $available_percentage;
    
    // Format amounts for display
    $total_formatted = $total_amount;
    $remaining_formatted = $remaining_amount;
    
    // Determine which currency to display
    if ($remaining_in_sell) {
        $currency_symbol = $sell_symbol;
        if ($sell_symbol === 'USD') {
            $total_formatted = number_format($total_amount, 2);
            $remaining_formatted = number_format($remaining_amount, 2);
        } else {
            $total_formatted = number_format($total_amount, 0);
            $remaining_formatted = number_format($remaining_amount, 0);
        }
    } else {
        $currency_symbol = $buy_symbol;
        if ($buy_symbol === 'USD') {
            $total_formatted = number_format($total_amount, 2);
            $remaining_formatted = number_format($remaining_amount, 2);
        } else {
            $total_formatted = number_format($total_amount, 0);
            $remaining_formatted = number_format($remaining_amount, 0);
        }
    }
?>
<!-- Progress Bar -->
<div class="trade-progress-container">
    <div class="progress-label">
        <span>Available: <?php echo $remaining_formatted; ?> <?php echo $currency_symbol; ?></span>
        <span>Total: <?php echo $total_formatted; ?> <?php echo $currency_symbol; ?></span>
    </div>
    <div class="progress-bar" data-tooltip="<?php echo $remaining_formatted; ?> <?php echo $currency_symbol; ?> available (<?php echo round($available_percentage, 1); ?>%)">
    <div class="progress-fill available" style="width: <?php echo max(0, min(100, $available_percentage)); ?>%"></div>
    <div class="progress-fill sold" style="width: <?php echo max(0, min(100, $sold_percentage)); ?>%"></div>
</div>
</div>


                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="trade-actions-full">
                                <?php if ($type === 'my_trades'): ?>
                                    <button type="button" 
                                            class="action-btn manage-btn manage-trade-btn"
                                            data-trade-id="<?php echo htmlspecialchars($trade['trade_id']); ?>"
                                            data-trade-type="<?php echo htmlspecialchars($trade['trade_type']); ?>"
                                            data-buy-currency-id="<?php echo htmlspecialchars($trade['buy_currency_id']); ?>"
                                            data-sell-currency-id="<?php echo htmlspecialchars($trade['sell_currency_id']); ?>"
                                            data-buy-currency-symbol="<?php echo htmlspecialchars($trade['buy_currency_symbol']); ?>"
                                            data-sell-currency-symbol="<?php echo htmlspecialchars($trade['sell_currency_symbol']); ?>"
                                            data-amount-bought="<?php echo htmlspecialchars($trade['amount_bought']); ?>"
                                            data-amount-sold="<?php echo htmlspecialchars($trade['amount_sold']); ?>"
                                            data-exchange-rate="<?php echo htmlspecialchars($trade['exchange_rate']); ?>">
                                        <i class="fas fa-edit me-1"></i> Manage Trade
                                    </button>
                                <?php else: ?>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="trade_id" value="<?php echo htmlspecialchars($trade['trade_id']); ?>">
                                        <button type="submit" name="accept_trade" 
                                                class="action-btn <?php echo ($type === 'sell') ? 'sell-btn' : 'buy-btn'; ?>">
                                            <?php echo ($type === 'sell') ? 'Sell All' : 'Buy All'; ?>
                                        </button>
                                    </form>
                                    <?php if ($trade['remaining_amount'] > 0): ?>
                                        <button type="button"
                                                class="action-btn partial-btn partial-trade-btn"
                                                data-trade-id="<?php echo htmlspecialchars($trade['trade_id']); ?>"
                                                data-trade-type="<?php echo htmlspecialchars($trade['trade_type']); ?>"
                                                data-buy-currency-symbol="<?php echo htmlspecialchars($trade['buy_currency_symbol']); ?>"
                                                data-sell-currency-symbol="<?php echo htmlspecialchars($trade['sell_currency_symbol']); ?>"
                                                data-exchange-rate="<?php echo htmlspecialchars($trade['exchange_rate']); ?>"
                                                data-remaining-amount="<?php echo htmlspecialchars($trade['remaining_amount']); ?>"
                                                data-amount-bought="<?php echo htmlspecialchars($trade['amount_bought']); ?>"
                                                data-amount-sold="<?php echo htmlspecialchars($trade['amount_sold']); ?>">
                                            <?php echo ($type === 'sell') ? 'Sell Partial' : 'Buy Partial'; ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create/Edit Trade Modal -->
    <div id="trade-modal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 id="modal-title" class="text-2xl font-bold text-gray-800 mb-6 text-center">Create New Trade</h2>
            <form method="POST" class="space-y-4" id="trade-form">
                <input type="hidden" name="trade_id" id="trade_id">
                
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">I want to...</label>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="radio" id="type_sell" name="trade_type" value="sell" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                            <label for="type_sell" class="ml-2 block text-sm font-medium text-gray-700">Sell Post</label>
                        </div>
                        <div class="flex items-center">
                            <input type="radio" id="type_buy" name="trade_type" value="buy" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                            <label for="type_buy" class="ml-2 block text-sm font-medium text-gray-700">Buy Post</label>
                        </div>
                    </div>
                </div>

                <div>
                    <label id="label_sell_currency" for="sell_currency_id" class="block text-sm font-medium text-gray-700">Currency to Sell</label>
                    <select id="sell_currency_id" name="sell_currency_id" required class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>"
                            data-symbol="<?php echo htmlspecialchars($currency['symbol']); ?>">
                        <?php echo htmlspecialchars($currency['symbol'] . ' - ' . $currency['name']); ?>
                     </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label id="label_buy_currency" for="buy_currency_id" class="block text-sm font-medium text-gray-700">Currency to Buy</label>
                    <select id="buy_currency_id" name="buy_currency_id" required class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>"
                                    data-symbol="<?php echo htmlspecialchars($currency['symbol']); ?>">
                                <?php echo htmlspecialchars($currency['symbol'] . ' - ' . $currency['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label id="label_amount_bought" for="amount_bought" class="block text-sm font-medium text-gray-700">Amount to Buy</label>
                    <input type="number" id="amount_bought" name="amount_bought" step="1" min="0" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                </div>
                
                <div>
                    <label id="label_exchange_rate" for="exchange_rate" class="block text-sm font-medium text-gray-700">Exchange Rate (1 <span id="base-symbol"></span> = ? <span id="quote-symbol"></span>)</label>
                    <?php
                    // Pre-compute dynamic placeholder for today's USD/MMK band (-5%/+15%)
                    $ex_placeholder = '';
                    try {
                        $usd_id_ph = null; $mmk_id_ph = null; $admin_rate_ph = null;
                        $stmt_ids_ph = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN ('USD','MMK')");
                        if ($stmt_ids_ph) {
                            $stmt_ids_ph->execute();
                            $res_ids_ph = $stmt_ids_ph->get_result();
                            while ($rph = $res_ids_ph->fetch_assoc()) {
                                if ($rph['symbol'] === 'USD') $usd_id_ph = (int)$rph['currency_id'];
                                if ($rph['symbol'] === 'MMK') $mmk_id_ph = (int)$rph['currency_id'];
                            }
                            $stmt_ids_ph->close();
                        }
                        if ($usd_id_ph && $mmk_id_ph) {
                            // Try USD->MMK, then inverse
                            $stmt_rate_ph = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                            if ($stmt_rate_ph) {
                                $stmt_rate_ph->bind_param("ii", $usd_id_ph, $mmk_id_ph);
                                $stmt_rate_ph->execute();
                                $res_rate_ph = $stmt_rate_ph->get_result();
                                if ($rowph = $res_rate_ph->fetch_assoc()) { $admin_rate_ph = (float)$rowph['rate']; }
                                $stmt_rate_ph->close();
                            }
                            if (!$admin_rate_ph) {
                                $stmt_inv_ph = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                                if ($stmt_inv_ph) {
                                    $stmt_inv_ph->bind_param("ii", $mmk_id_ph, $usd_id_ph);
                                    $stmt_inv_ph->execute();
                                    $res_inv_ph = $stmt_inv_ph->get_result();
                                    if ($row2ph = $res_inv_ph->fetch_assoc()) {
                                        $inv_rate_ph = (float)$row2ph['rate'];
                                        if ($inv_rate_ph > 0) $admin_rate_ph = 1 / $inv_rate_ph;
                                    }
                                    $stmt_inv_ph->close();
                                }
                            }
                            // No further fallback; leave placeholder using history/inverse only
                        }
                        if (!$admin_rate_ph || $admin_rate_ph <= 0) {
                            $admin_rate_ph = 3987.37; // temporary manual fallback
                        }
                        $min_ph = (int)round($admin_rate_ph * (1 - 0.05));
                        $max_ph = (int)round($admin_rate_ph * (1 + 0.20));
                        // Full guidance text as placeholder
                        $ex_placeholder = 'Allowed today: 1 USD = ' . number_format($min_ph, 0) . ' – ' . number_format($max_ph, 0) . ' MMK';
                    } catch (Throwable $e) { /* ignore */ }
                    ?>
                    <input type="number" id="exchange_rate" name="exchange_rate" step="1" min="0" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2" placeholder="<?php echo htmlspecialchars($ex_placeholder); ?>">
                </div>

                <div>
                    <label id="label_amount_sold" for="amount_sold_display" class="block text-sm font-medium text-gray-700">You Will Sell</label>
                    <input type="text" id="amount_sold_display" disabled class="mt-1 block w-full rounded-md border-gray-300 bg-gray-50 text-gray-500 shadow-sm sm:text-sm p-2">
                </div>

                <button type="submit" name="submit_trade" id="submit-button" class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors shadow-md">
                    Create Trade
                </button>
            </form>
        </div>
    </div>

    <!-- Partial Trade Modal -->
    <div id="partial-trade-modal" class="modal">
        <div class="modal-content">
            <span class="close-button partial-close">&times;</span>
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Partial Trade</h2>
            <form method="POST" class="space-y-4" id="partial-trade-form">
                <input type="hidden" name="trade_id" id="partial_trade_id">
                <input type="hidden" name="trade_pin" id="trade_pin_hidden">
                <input type="hidden" name="partial_amount_unit" id="partial_amount_unit" value="BUY">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">
                        Available Amount: 
                        <span id="available-amount"></span> 
                        <span id="available-currency"></span>
                    </label>
                </div>
                
                <div>
                    <label id="partial-amount-label" for="partial_amount" class="block text-sm font-medium text-gray-700">
                        Amount You Want to Buy
                    </label>
                    <input type="number" id="partial_amount" name="partial_amount" step="1" min="1" required 
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                </div>
                
                <div>
                    <label id="payment-label" class="block text-sm font-medium text-gray-700">
                        <span id="payment-label-text">You Will Pay:</span> 
                        <span id="partial-payment-amount">0</span> 
                        <span id="payment-currency"></span>
                    </label>
                </div>

                <button type="submit" name="partial_accept_trade" 
                        class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors shadow-md">
                    Confirm Partial Trade
                </button>
            </form>
        </div>
    </div>

    <!-- PIN Modal for trade confirmations -->
    <div id="pinConfirmModal" class="pin-modal">
        <div class="pin-modal-content">
            <h3 class="text-lg font-semibold text-gray-800 mb-2">Enter Security PIN</h3>
            <p class="text-sm text-gray-500 mb-4">Type the 4-digit PIN you created during registration to confirm this trade.</p>
            <input type="password" id="pinConfirmInput" inputmode="numeric" pattern="\d{4}" maxlength="4" minlength="4" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 mb-3" placeholder="••••" />
            <div id="pinConfirmError" class="text-red-600 text-sm mb-3 hidden">Please enter a valid 4-digit PIN.</div>
            <div class="flex gap-2 justify-end">
                <button id="pinConfirmCancel" class="px-4 py-2 rounded-lg border text-gray-700">Cancel</button>
                <button id="pinConfirmOk" class="px-4 py-2 rounded-lg bg-blue-600 text-white">Confirm</button>
            </div>
        </div>
    </div>
    <!-- Profile Image Modal -->
<div id="profileImageModal" class="profile-image-modal">
    <div class="profile-image-modal-content">
        <button class="profile-image-close" onclick="closeProfileImageModal()">&times;</button>
        
        <div class="profile-image-container" id="profileModalImageContainer">
            <!-- Image or initials will be inserted here -->
        </div>
        
        <div class="profile-username" id="profileModalUsername"></div>
        <div class="profile-role">Trade Poster</div>
        
        <div class="profile-stats">
            <div class="profile-stat">
                <span class="profile-stat-value" id="profileTradeCount">0</span>
                <span class="profile-stat-label">Trades</span>
            </div>
            <div class="profile-stat">
                <span class="profile-stat-value" id="profileSuccessRate">100%</span>
                <span class="profile-stat-label">Success</span>
            </div>
            <div class="profile-stat">
                <span class="profile-stat-value" id="profileMemberSince">Today</span>
                <span class="profile-stat-label">Member</span>
            </div>
        </div>
    </div>
</div>
    <!-- Wallet Modal Script -->
    <script>
        (function(){
            if (window.__walletModalInit) return; window.__walletModalInit = true;
            window.showWalletModal = function(addr){
                try {
                    var existing = document.getElementById('wallet-modal-overlay');
                    if (existing) existing.remove();
                    var overlay = document.createElement('div');
                    overlay.id = 'wallet-modal-overlay';
                    overlay.style.position = 'fixed';
                    overlay.style.inset = '0';
                    overlay.style.background = 'rgba(0,0,0,0.4)';
                    overlay.style.zIndex = '1000';
                    overlay.style.display = 'flex';
                    overlay.style.alignItems = 'center';
                    overlay.style.justifyContent = 'center';

                    var box = document.createElement('div');
                    box.style.background = '#fff';
                    box.style.borderRadius = '12px';
                    box.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
                    box.style.padding = '16px';
                    box.style.width = 'min(90%, 420px)';
                    box.style.position = 'relative';

                    var close = document.createElement('button');
                    close.textContent = '×';
                    close.setAttribute('aria-label','Close');
                    close.style.position = 'absolute';
                    close.style.top = '8px';
                    close.style.right = '12px';
                    close.style.fontSize = '20px';
                    close.style.color = '#64748b';
                    close.style.cursor = 'pointer';
                    close.style.background = 'transparent';
                    close.style.border = 'none';
                    close.onclick = function(){ document.body.removeChild(overlay); };

                    var title = document.createElement('div');
                    title.textContent = 'Wallet Address';
                    title.style.fontWeight = '700';
                    title.style.marginBottom = '8px';

                    var addrEl = document.createElement('div');
                    addrEl.textContent = addr || '';
                    addrEl.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace';
                    addrEl.style.fontSize = '12px';
                    addrEl.style.wordBreak = 'break-all';
                    addrEl.style.padding = '8px';
                    addrEl.style.background = '#f8fafc';
                    addrEl.style.border = '1px solid #e2e8f0';
                    addrEl.style.borderRadius = '6px';

                    var actions = document.createElement('div');
                    actions.style.marginTop = '12px';
                    actions.style.display = 'flex';
                    actions.style.gap = '8px';

                    var copyBtn = document.createElement('button');
                    copyBtn.textContent = 'Copy';
                    copyBtn.style.background = '#2563eb';
                    copyBtn.style.color = '#fff';
                    copyBtn.style.padding = '6px 10px';
                    copyBtn.style.borderRadius = '6px';
                    copyBtn.style.fontSize = '12px';
                    copyBtn.style.cursor = 'pointer';
                    copyBtn.style.border = 'none';
                    copyBtn.onclick = function(){
                        try { navigator.clipboard.writeText(addr || ''); copyBtn.textContent = 'Copied!'; setTimeout(function(){ copyBtn.textContent = 'Copy'; }, 1200);} catch(e){}
                    };

                    box.appendChild(close);
                    box.appendChild(title);
                    box.appendChild(addrEl);
                    box.appendChild(actions);
                    actions.appendChild(copyBtn);
                    overlay.appendChild(box);
                    overlay.addEventListener('click', function(ev){ if (ev.target === overlay) { document.body.removeChild(overlay); } });
                    document.addEventListener('keydown', function esc(e){ if (e.key==='Escape'){ if (overlay && overlay.parentNode){ overlay.parentNode.removeChild(overlay);} document.removeEventListener('keydown', esc); }});
                    document.body.appendChild(overlay);
                } catch(_){}
            };
        })();
    </script>
    
    <!-- JavaScript for Modals and Interactions -->
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

        <?php
        // Compute latest admin USD->MMK rate (prefer today's live rate) to show dynamic allowed range hint
        $usd_mmk_admin_rate = null;
        try {
            // Find USD and MMK IDs
            $usd_id = null; $mmk_id = null;
            $stmt_ids = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN ('USD','MMK')");
            if ($stmt_ids) {
                $stmt_ids->execute();
                $res_ids = $stmt_ids->get_result();
                while ($r = $res_ids->fetch_assoc()) {
                    if ($r['symbol'] === 'USD') $usd_id = (int)$r['currency_id'];
                    if ($r['symbol'] === 'MMK') $mmk_id = (int)$r['currency_id'];
                }
                $stmt_ids->close();
            }
            if ($usd_id && $mmk_id) {
                // 1) Today's live
                $stmt_live = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? AND DATE(timestamp) = CURDATE() ORDER BY timestamp DESC LIMIT 1");
                if ($stmt_live) {
                    $stmt_live->bind_param("ii", $usd_id, $mmk_id);
                    $stmt_live->execute();
                    $live_res = $stmt_live->get_result();
                    if ($row = $live_res->fetch_assoc()) { $usd_mmk_admin_rate = (float)$row['rate']; }
                    $stmt_live->close();
                }
                // 2) History direct
                if (!$usd_mmk_admin_rate) {
                    $stmt_rate = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                    if ($stmt_rate) {
                        $stmt_rate->bind_param("ii", $usd_id, $mmk_id);
                        $stmt_rate->execute();
                        $rate_res = $stmt_rate->get_result();
                        if ($r = $rate_res->fetch_assoc()) { $usd_mmk_admin_rate = (float)$r['rate']; }
                        $stmt_rate->close();
                    }
                }
                // 3) Inverse history
                if (!$usd_mmk_admin_rate) {
                    $stmt_inv = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
                    if ($stmt_inv) {
                        $stmt_inv->bind_param("ii", $mmk_id, $usd_id);
                        $stmt_inv->execute();
                        $inv_res = $stmt_inv->get_result();
                        if ($r2 = $inv_res->fetch_assoc()) {
                            $inv_rate = (float)$r2['rate'];
                            if ($inv_rate > 0) $usd_mmk_admin_rate = 1 / $inv_rate;
                        }
                        $stmt_inv->close();
                    }
                }
                // 4) currencies fallback
                if (!$usd_mmk_admin_rate) {
                    $stmt_cur = $conn->prepare("SELECT symbol, exchange_rate_to_usd FROM currencies WHERE symbol IN ('USD','MMK')");
                    if ($stmt_cur) {
                        $stmt_cur->execute();
                        $res_cur = $stmt_cur->get_result();
                        $mmk_to_usd = null;
                        while ($crow = $res_cur->fetch_assoc()) {
                            if ($crow['symbol'] === 'MMK') { $mmk_to_usd = (float)$crow['exchange_rate_to_usd']; }
                        }
                        $stmt_cur->close();
                        if ($mmk_to_usd && $mmk_to_usd > 0) { $usd_mmk_admin_rate = 1 / $mmk_to_usd; }
                    }
                }
            }
        } catch (Throwable $e) { /* ignore */ }
        ?>
        const usdMmkAdminRate = <?php echo ($usd_mmk_admin_rate !== null && $usd_mmk_admin_rate > 0) ? json_encode($usd_mmk_admin_rate) : '3987.37'; ?>;
        // Inject admin-marked MMK rates for USD/JPY/THB. Fallbacks only if missing.
        const adminMmkRates = (function(){
            const server = <?php echo json_encode($latest_mmk_rates, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?> || {};
            return {
                USD: (typeof server.USD === 'number' && server.USD > 0) ? server.USD : usdMmkAdminRate,
                JPY: (typeof server.JPY === 'number' && server.JPY > 0) ? server.JPY : 27,
                THB: (typeof server.THB === 'number' && server.THB > 0) ? server.THB : 110,
            };
        })();
        const LOWER_TOL = 0.05; // -5%
        const UPPER_TOL = 0.20; // +20%

        function setExchangeRateHint() {
            const hint = document.getElementById('exchange-rate-range-hint');
            if (!hint) return;
            if (usdMmkAdminRate && usdMmkAdminRate > 0) {
                const minAllowed = Math.round(usdMmkAdminRate * (1 - LOWER_TOL));
                const maxAllowed = Math.round(usdMmkAdminRate * (1 + UPPER_TOL));
                hint.textContent = `Allowed today: 1 USD = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
            } else {
                hint.textContent = '';
            }
        }

    
        
        // Modal functionality (keep existing JavaScript functions from original)
        const modal = document.getElementById("trade-modal");
        const partialModal = document.getElementById("partial-trade-modal");
        const openModalBtn = document.getElementById("open-modal");
        const closeModalBtn = document.getElementsByClassName("close-button")[0];
        const partialCloseBtn = document.getElementsByClassName("partial-close")[0];
        const manageTradeBtns = document.querySelectorAll('.manage-trade-btn');
        const partialTradeBtns = document.querySelectorAll('.partial-trade-btn');
        const tradeForm = document.getElementById('trade-form');
        const partialTradeForm = document.getElementById('partial-trade-form');

        // Elements used for dynamic rate hint and labels
        const buySel = document.getElementById('buy_currency_id');
        const sellSel = document.getElementById('sell_currency_id');
        const baseSymEl = document.getElementById('base-symbol');
        const quoteSymEl = document.getElementById('quote-symbol');

        function isMmkForeignPair(buySym, sellSym) {
            const foreigns = ['USD','JPY','THB'];
            if (buySym === 'MMK' && foreigns.includes(sellSym)) return { ok: true, foreign: sellSym };
            if (sellSym === 'MMK' && foreigns.includes(buySym)) return { ok: true, foreign: buySym };
            return { ok: false, foreign: '' };
        }

        function getSelectedSymbols() {
            const buyOpt  = buySel ? buySel.options[buySel.selectedIndex] : null;
            const sellOpt = sellSel ? sellSel.options[sellSel.selectedIndex] : null;
            const buySym  = buyOpt ? (buyOpt.getAttribute('data-symbol') || buyOpt.textContent.trim().split(' - ')[0]) : '';
            const sellSym = sellOpt ? (sellOpt.getAttribute('data-symbol') || sellOpt.textContent.trim().split(' - ')[0]) : '';
            return { buySym, sellSym };
        }

        function updateSymbolsLabel() {
            const { buySym, sellSym } = getSelectedSymbols();
            // For MMK-FOREIGN pairs, always show label as 1 FOREIGN = ? MMK
            const pairInfo = isMmkForeignPair(buySym, sellSym);
            // Special overrides
            const isUsdJpy = (buySym === 'USD' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'USD');
            const isUsdThb = (buySym === 'USD' && sellSym === 'THB') || (buySym === 'THB' && sellSym === 'USD');
            const isThbJpy = (buySym === 'THB' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'THB');
            if (isUsdJpy) {
                if (baseSymEl) baseSymEl.textContent = 'USD';
                if (quoteSymEl) quoteSymEl.textContent = 'JPY';
            } else if (isUsdThb) {
                if (baseSymEl) baseSymEl.textContent = 'USD';
                if (quoteSymEl) quoteSymEl.textContent = 'THB';
            } else if (isThbJpy) {
                if (baseSymEl) baseSymEl.textContent = 'THB';
                if (quoteSymEl) quoteSymEl.textContent = 'JPY';
            } else if (pairInfo.ok) {
                if (baseSymEl) baseSymEl.textContent = pairInfo.foreign;
                if (quoteSymEl) quoteSymEl.textContent = 'MMK';
            } else {
                if (baseSymEl) baseSymEl.textContent = buySym || '';
                if (quoteSymEl) quoteSymEl.textContent = sellSym || '';
            }
        }

        function updateRateHintVisibility() {
            const { buySym, sellSym } = getSelectedSymbols();
            const isUsdMmk = (buySym === 'USD' && sellSym === 'MMK') || (buySym === 'MMK' && sellSym === 'USD');
            const pairInfo = isMmkForeignPair(buySym, sellSym);
            const erInput = document.getElementById('exchange_rate');
            if (!erInput) return;
            // Helper: update hint text element
            const hint = document.getElementById('exchange-rate-range-hint');
            const FX_SET = ['USD','JPY','THB'];
            const isCrossFX = FX_SET.includes(buySym) && FX_SET.includes(sellSym) && buySym !== 'MMK' && sellSym !== 'MMK' && buySym !== sellSym;
            if (isUsdMmk) {
                const baseRate = adminMmkRates.USD;
                if (typeof baseRate === 'number' && baseRate > 0) {
                    const minAllowed = Math.round(baseRate * (1 - LOWER_TOL));
                    const maxAllowed = Math.round(baseRate * (1 + UPPER_TOL));
                    erInput.setAttribute('min', String(minAllowed));
                    erInput.setAttribute('max', String(maxAllowed));
                    erInput.setAttribute('step', '1');
                    erInput.placeholder = `Allowed today: 1 USD = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
                    const v = parseInt(erInput.value) || 0;
                    if (v && (v < minAllowed || v > maxAllowed)) {
                        erInput.setCustomValidity(`For USD/MMK trades, today's allowed rate is between ${minAllowed.toLocaleString()} and ${maxAllowed.toLocaleString()} MMK per 1 USD`);
                    } else {
                        erInput.setCustomValidity('');
                    }
                    if (hint) {
                        if (v && (v < minAllowed || v > maxAllowed)) {
                            hint.textContent = `For USD/MMK trades, today's allowed rate is between ${minAllowed.toLocaleString()} and ${maxAllowed.toLocaleString()} MMK per 1 USD.`;
                            hint.classList.add('text-danger');
                        } else {
                            hint.textContent = `Allowed today: 1 USD = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
                            hint.classList.remove('text-danger');
                        }
                    }
                }
            } else if (pairInfo.ok && pairInfo.foreign !== 'USD') {
                const foreign = pairInfo.foreign;
                const baseRate = adminMmkRates[foreign];
                if (typeof baseRate === 'number' && baseRate > 0) {
                    const minAllowed = Math.round(baseRate * (1 - LOWER_TOL));
                    const maxAllowed = Math.round(baseRate * (1 + UPPER_TOL));
                    erInput.setAttribute('min', String(minAllowed));
                    erInput.setAttribute('max', String(maxAllowed));
                    erInput.setAttribute('step', '1');
                    erInput.placeholder = `Allowed today: 1 ${foreign} = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
                    const v = parseInt(erInput.value) || 0;
                    if (v && (v < minAllowed || v > maxAllowed)) {
                        erInput.setCustomValidity(`For ${foreign}/MMK trades, today's allowed rate is between ${minAllowed.toLocaleString()} and ${maxAllowed.toLocaleString()} MMK per 1 ${foreign}`);
                    } else {
                        erInput.setCustomValidity('');
                    }
                    if (hint) {
                        if (v && (v < minAllowed || v > maxAllowed)) {
                            hint.textContent = `For ${foreign}/MMK trades, today's allowed rate is between ${minAllowed.toLocaleString()} and ${maxAllowed.toLocaleString()} MMK per 1 ${foreign}.`;
                            hint.classList.add('text-danger');
                        } else {
                            hint.textContent = `Allowed today: 1 ${foreign} = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
                            hint.classList.remove('text-danger');
                        }
                    }
                }
            } else if (isCrossFX) {
                // Cross FX (USD/JPY/THB): always show range in fixed orientations
                // USD<->JPY => 1 USD = ... JPY
                // USD<->THB => 1 USD = ... THB
                // THB<->JPY => 1 THB = ... JPY
                let baseSym = buySym;
                let quoteSym = sellSym;
                if ((buySym === 'USD' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'USD')) {
                    baseSym = 'USD';
                    quoteSym = 'JPY';
                } else if ((buySym === 'USD' && sellSym === 'THB') || (buySym === 'THB' && sellSym === 'USD')) {
                    baseSym = 'USD';
                    quoteSym = 'THB';
                } else if ((buySym === 'THB' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'THB')) {
                    baseSym = 'THB';
                    quoteSym = 'JPY';
                }

                const baseToMmk = adminMmkRates[baseSym];
                const quoteToMmk = adminMmkRates[quoteSym];
                if (typeof baseToMmk === 'number' && baseToMmk > 0 && typeof quoteToMmk === 'number' && quoteToMmk > 0) {
                    const cross = baseToMmk / quoteToMmk; // 1 baseSym = X quoteSym
                    const minAllowed = +(cross * (1 - LOWER_TOL)).toFixed(2);
                    const maxAllowed = +(cross * (1 + UPPER_TOL)).toFixed(2);
                    erInput.removeAttribute('min'); // allow decimals; enforce via customValidity instead
                    erInput.removeAttribute('max');
                    erInput.setAttribute('step', '0.01');
                    erInput.placeholder = `Allowed today: 1 ${baseSym} = ${minAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} – ${maxAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} ${quoteSym}`;
                    const v = parseFloat(erInput.value) || 0;
                    if (v && (v < minAllowed || v > maxAllowed)) {
                        erInput.setCustomValidity(`For ${baseSym}/${quoteSym} trades, today's allowed rate is between ${minAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} and ${maxAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} ${quoteSym} per 1 ${baseSym}`);
                    } else {
                        erInput.setCustomValidity('');
                    }
                    if (hint) {
                        if (v && (v < minAllowed || v > maxAllowed)) {
                            hint.textContent = `For ${baseSym}/${quoteSym} trades, today's allowed rate is between ${minAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} and ${maxAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} ${quoteSym} per 1 ${baseSym}.`;
                            hint.classList.add('text-danger');
                        } else {
                            hint.textContent = `Allowed today: 1 ${baseSym} = ${minAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} – ${maxAllowed.toLocaleString(undefined, {maximumFractionDigits:2})} ${quoteSym}`;
                            hint.classList.remove('text-danger');
                        }
                    }
                } else {
                    // Fallback: clear hint if no admin MMK rates available
                    if (hint) { hint.textContent = ''; hint.classList.remove('text-danger'); }
                    erInput.setAttribute('step', '0.01');
                    erInput.setCustomValidity('');
                }
            } else {
                // Non MMK-FOREIGN pairs: no specific band
                erInput.setAttribute('min', '0');
                erInput.removeAttribute('max');
                erInput.setCustomValidity('');
                if (hint) { hint.textContent = ''; hint.classList.remove('text-danger'); }
            }
        }

        function onCurrencyChange() {
            updateSymbolsLabel();
            updateRateHintVisibility();
            // If switching to USD/MMK, any MMK-FOREIGN, or cross USD/JPY/THB, clear value so the dynamic placeholder shows
            const { buySym, sellSym } = getSelectedSymbols();
            const isUsdMmk = (buySym === 'USD' && sellSym === 'MMK') || (buySym === 'MMK' && sellSym === 'USD');
            const pairInfo = isMmkForeignPair(buySym, sellSym);
            const isCrossFX = ['USD','JPY','THB'].includes(buySym) && ['USD','JPY','THB'].includes(sellSym) && buySym !== sellSym && buySym !== 'MMK' && sellSym !== 'MMK';
            if (isUsdMmk || pairInfo.ok || isCrossFX) {
                const erInput = document.getElementById('exchange_rate');
                if (erInput) erInput.value = '';
            }
        }

        // Hook up events
        if (buySel) buySel.addEventListener('change', onCurrencyChange);
        if (sellSel) sellSel.addEventListener('change', onCurrencyChange);

        // Initialize when modal is opened
        if (openModalBtn) {
            openModalBtn.addEventListener('click', () => {
                // Default pair to USD/MMK on open to show dynamic placeholder
                if (buySel && sellSel) {
                    // Set buy=USD if present
                    for (let i = 0; i < buySel.options.length; i++) {
                        const opt = buySel.options[i];
                        const sym = opt.getAttribute('data-symbol') || opt.textContent.trim().split(' - ')[0];
                        if (sym === 'USD') { buySel.selectedIndex = i; break; }
                    }
                    // Set sell=MMK if present
                    for (let j = 0; j < sellSel.options.length; j++) {
                        const opt = sellSel.options[j];
                        const sym = opt.getAttribute('data-symbol') || opt.textContent.trim().split(' - ')[0];
                        if (sym === 'MMK') { sellSel.selectedIndex = j; break; }
                    }
                }
                // Clear any prefilled exchange rate so placeholder is visible
                const erInput = document.getElementById('exchange_rate');
                if (erInput) {
                    erInput.value = '';
                }
                updateSymbolsLabel();
                updateRateHintVisibility();
            });
        }

        // Before submitting, invert displayed rate when label orientation differs from BUY->SELL expected by backend
        if (tradeForm) {
            tradeForm.addEventListener('submit', () => {
                const { buySym, sellSym } = getSelectedSymbols();
                const isJpyUsd = (buySym === 'JPY' && sellSym === 'USD');
                const isThbUsd = (buySym === 'THB' && sellSym === 'USD');
                const isJpyThb = (buySym === 'JPY' && sellSym === 'THB');
                const rateInput = document.getElementById('exchange_rate');
                if (isJpyUsd && rateInput) {
                    const shownUsdJpy = parseFloat(rateInput.value);
                    if (shownUsdJpy && shownUsdJpy > 0) {
                        // backend expects 1 BUY(=JPY) = X SELL(=USD)
                        const jpyUsd = +(1 / shownUsdJpy).toFixed(6);
                        rateInput.value = String(jpyUsd);
                    }
                }
                if (isThbUsd && rateInput) {
                    const shownUsdThb = parseFloat(rateInput.value);
                    if (shownUsdThb && shownUsdThb > 0) {
                        // backend expects 1 BUY(=THB) = X SELL(=USD)
                        const thbUsd = +(1 / shownUsdThb).toFixed(6);
                        rateInput.value = String(thbUsd);
                    }
                }
                if (isJpyThb && rateInput) {
                    const shownThbJpy = parseFloat(rateInput.value);
                    if (shownThbJpy && shownThbJpy > 0) {
                        // backend expects 1 BUY(=JPY) = X SELL(=THB), but UI shows 1 THB = ? JPY
                        const jpyThb = +(1 / shownThbJpy).toFixed(6);
                        rateInput.value = String(jpyThb);
                    }
                }
            });
        }

        // Also initialize once on page load (if modal is already visible)
        updateSymbolsLabel();
        updateRateHintVisibility();
        const modalTitle = document.getElementById('modal-title');
        const tradeIdInput = document.getElementById('trade_id');
        const partialTradeIdInput = document.getElementById('partial_trade_id');
        const submitButton = document.getElementById('submit-button');
        const partialAmountInput = document.getElementById('partial_amount');
        const availableAmountSpan = document.getElementById('available-amount');
        const availableCurrencySpan = document.getElementById('available-currency');
        const paymentAmountSpan = document.getElementById('partial-payment-amount');
        const paymentCurrencySpan = document.getElementById('payment-currency');

        // PIN modal elements
        const pinModal = document.getElementById('pinConfirmModal');
        const pinInput = document.getElementById('pinConfirmInput');
        const pinError = document.getElementById('pinConfirmError');
        const pinCancel = document.getElementById('pinConfirmCancel');
        const pinOk = document.getElementById('pinConfirmOk');
        let pendingForm = null;

        function openPinModal() {
            pinModal.classList.remove('hidden');
            pinModal.classList.add('flex');
            pinInput.value = '';
            pinError.classList.add('hidden');
            setTimeout(() => pinInput && pinInput.focus(), 50);
        }
        function closePinModal() {
            pinModal.classList.add('hidden');
            pinModal.classList.remove('flex');
        }
        function isValidPin(v) { return /^\d{4}$/.test(v); }

        if (pinCancel) {
            pinCancel.addEventListener('click', (e) => { e.preventDefault(); pendingForm = null; closePinModal(); });
        }
        if (pinOk) {
            pinOk.addEventListener('click', (e) => {
                e.preventDefault();
                const val = (pinInput.value || '').trim();
                if (!isValidPin(val)) {
                    pinError.textContent = 'Please enter a valid 4-digit PIN.';
                    pinError.classList.remove('hidden');
                    pinInput && pinInput.focus();
                    return;
                }
                if (pendingForm) {
                    // ensure hidden field exists with name trade_pin
                    let hiddenPin = pendingForm.querySelector('input[name="trade_pin"]');
                    if (!hiddenPin) {
                        hiddenPin = document.createElement('input');
                        hiddenPin.type = 'hidden';
                        hiddenPin.name = 'trade_pin';
                        pendingForm.appendChild(hiddenPin);
                    }
                    hiddenPin.value = val;

                    // ensure the handler flag is included since form.submit() omits clicked button name
                    // Detect which action this form intends
                    let actionName = null;
                    // Priority: look for an explicit submit button with name
                    const submitBtnWithName = pendingForm.querySelector('button[name]');
                    if (submitBtnWithName && submitBtnWithName.getAttribute('name')) {
                        actionName = submitBtnWithName.getAttribute('name');
                    }
                    // Fallback by form id
                    if (!actionName) {
                        if (pendingForm.id === 'partial-trade-form') actionName = 'partial_accept_trade';
                    }
                    // As a final fallback, if the form contains a button named accept_trade/partial_accept_trade
                    if (!actionName) {
                        if (pendingForm.querySelector('button[name="accept_trade"]')) actionName = 'accept_trade';
                        else if (pendingForm.querySelector('button[name="partial_accept_trade"]')) actionName = 'partial_accept_trade';
                    }
                    if (actionName) {
                        let hiddenAction = pendingForm.querySelector(`input[type="hidden"][name="${actionName}"]`);
                        if (!hiddenAction) {
                            hiddenAction = document.createElement('input');
                            hiddenAction.type = 'hidden';
                            hiddenAction.name = actionName;
                            hiddenAction.value = '1';
                            pendingForm.appendChild(hiddenAction);
                        }
                    }

                    closePinModal();
                    // Use programmatic submit (won't trigger submit listeners again)
                    pendingForm.submit();
                    pendingForm = null;
                }
            });
        }
        pinInput && pinInput.addEventListener('keyup', (e) => { if (e.key === 'Enter') { pinOk.click(); } });

        // Intercept full accept (Buy/Sell All) buttons to require PIN
        document.querySelectorAll('form button[name="accept_trade"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                pendingForm = btn.closest('form');
                openPinModal();
            });
        });

        // Intercept partial confirm to require PIN
        if (partialTradeForm) {
            partialTradeForm.addEventListener('submit', (e) => {
                // Close the partial trade modal immediately
                if (partialModal) {
                    partialModal.style.display = 'none';
                }
                // If PIN not yet provided, open modal
                const existingPin = partialTradeForm.querySelector('input[name="trade_pin"]');
                if (!existingPin || !(existingPin.value || '').trim()) {
                    e.preventDefault();
                    pendingForm = partialTradeForm;
                    openPinModal();
                }
            });
        }

        // This function handles opening the modal and initializing the form
        if (openModalBtn) {
            openModalBtn.onclick = function() {
                modal.style.display = "flex";
                // Reset form for new trade
                modalTitle.textContent = "Create New Trade";
                submitButton.textContent = "Create Trade";
                submitButton.name = "submit_trade";
                tradeIdInput.value = "";
                tradeForm.reset();
                // Initialize the form labels and symbols when the modal is opened
                updateFormLabelsAndCalculation();
            }
        }
        
        if (closeModalBtn) {
            closeModalBtn.onclick = function() {
                modal.style.display = "none";
            }
        }

        if (partialCloseBtn) {
            partialCloseBtn.onclick = function() {
                partialModal.style.display = "none";
            }
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
            if (event.target == partialModal) {
                partialModal.style.display = "none";
            }
        }

        // Function to handle the "Manage Trade" button clicks
        manageTradeBtns.forEach(button => {
            button.addEventListener('click', function() {
                const trade = this.dataset;

                if (!modal || !tradeForm) return;
                
                // Set modal title and button for editing
                if (modalTitle) modalTitle.textContent = "Edit Trade";
                if (submitButton) {
                    submitButton.textContent = "Update Trade";
                    submitButton.name = "update_trade";
                }
                
                // Pre-populate the form fields
                const typeRadio = document.getElementById('type_' + trade.tradeType);
                const buySelect = document.getElementById('buy_currency_id');
                const sellSelect = document.getElementById('sell_currency_id');
                const amountBoughtEl = document.getElementById('amount_bought');
                const exchangeRateEl = document.getElementById('exchange_rate');

                if (tradeIdInput) tradeIdInput.value = trade.tradeId || '';
                if (typeRadio) typeRadio.checked = true;
                if (buySelect) buySelect.value = trade.buyCurrencyId || '';
                if (sellSelect) sellSelect.value = trade.sellCurrencyId || '';
                if (exchangeRateEl) exchangeRateEl.value = trade.exchangeRate || '';

                // For cross FX pairs, the UI uses fixed orientations different from backend storage (1 BUY = X SELL).
                // Adjust the displayed rate to match UI orientation so users edit in the expected format.
                (function adjustRateForCrossFXUI(){
                    if (!buySelect || !sellSelect || !exchangeRateEl) return;
                    const buyOpt  = buySelect.options[buySelect.selectedIndex];
                    const sellOpt = sellSelect.options[sellSelect.selectedIndex];
                    if (!buyOpt || !sellOpt) return;
                    const buySym  = buyOpt.getAttribute('data-symbol')  || buyOpt.textContent.trim().split(' - ')[0];
                    const sellSym = sellOpt.getAttribute('data-symbol') || sellOpt.textContent.trim().split(' - ')[0];
                    const FX = ['USD','JPY','THB'];
                    const isCross = FX.includes(buySym) && FX.includes(sellSym) && buySym !== sellSym;
                    if (!isCross) return;
                    // UI orientation rules
                    let base = buySym, quote = sellSym;
                    if ((buySym === 'USD' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'USD')) { base = 'USD'; quote = 'JPY'; }
                    else if ((buySym === 'USD' && sellSym === 'THB') || (buySym === 'THB' && sellSym === 'USD')) { base = 'USD'; quote = 'THB'; }
                    else if ((buySym === 'THB' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'THB')) { base = 'THB'; quote = 'JPY'; }
                    const stored = parseFloat(trade.exchangeRate || '');
                    if (!stored || !(stored > 0)) return;
                    // Backend stores 1 BUY = X SELL. If BUY/SELL equals UI base/quote, no change.
                    // If reversed, display the inverse.
                    if (!(buySym === base && sellSym === quote)) {
                        const inv = 1 / stored;
                        exchangeRateEl.value = String(+inv.toFixed(6));
                    }
                })();

                // Prefill amount: if current amount_bought is zero/empty but we have amount_sold and exchange_rate,
                // infer amount_bought = amount_sold * exchange_rate (common for USD→MMK SELL posts)
                if (amountBoughtEl) {
                    let val = trade.amountBought || '';
                    const amountBoughtNum = parseFloat(val);
                    const amountSoldNum = parseFloat(trade.amountSold || '');
                    const rateNum = parseFloat(trade.exchangeRate || '');
                    if ((!isFinite(amountBoughtNum) || amountBoughtNum <= 0) && isFinite(amountSoldNum) && amountSoldNum > 0 && isFinite(rateNum) && rateNum > 0) {
                        const inferred = amountSoldNum * rateNum;
                        val = String(Math.round(inferred));
                    }
                    amountBoughtEl.value = val;
                }

                // Open the modal first
                modal.style.display = "flex";

                // Dispatch events so listeners recalc labels and computed fields
                const changeEvt = new Event('change', { bubbles: true });
                const inputEvt  = new Event('input', { bubbles: true });
                if (typeRadio) typeRadio.dispatchEvent(changeEvt);
                if (buySelect) buySelect.dispatchEvent(changeEvt);
                if (sellSelect) sellSelect.dispatchEvent(changeEvt);

                // Re-apply exchange rate after change events (which may clear the field)
                if (exchangeRateEl) {
                    exchangeRateEl.value = trade.exchangeRate || '';
                    // Adjust for cross FX UI orientation again after labels updated
                    (function adjustRateForCrossFXUIAfter(){
                        const buyOpt  = buySelect ? buySelect.options[buySelect.selectedIndex] : null;
                        const sellOpt = sellSelect ? sellSelect.options[sellSelect.selectedIndex] : null;
                        if (!buyOpt || !sellOpt) return;
                        const buySym  = buyOpt.getAttribute('data-symbol')  || buyOpt.textContent.trim().split(' - ')[0];
                        const sellSym = sellOpt.getAttribute('data-symbol') || sellOpt.textContent.trim().split(' - ')[0];
                        const FX = ['USD','JPY','THB'];
                        const isCross = FX.includes(buySym) && FX.includes(sellSym) && buySym !== sellSym;
                        if (!isCross) return;
                        let base = buySym, quote = sellSym;
                        if ((buySym === 'USD' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'USD')) { base = 'USD'; quote = 'JPY'; }
                        else if ((buySym === 'USD' && sellSym === 'THB') || (buySym === 'THB' && sellSym === 'USD')) { base = 'USD'; quote = 'THB'; }
                        else if ((buySym === 'THB' && sellSym === 'JPY') || (buySym === 'JPY' && sellSym === 'THB')) { base = 'THB'; quote = 'JPY'; }
                        const stored = parseFloat(trade.exchangeRate || '');
                        if (!stored || !(stored > 0)) return;
                        if (!(buySym === base && sellSym === quote)) {
                            const inv = 1 / stored;
                            exchangeRateEl.value = String(+inv.toFixed(6));
                        }
                    })();
                }

                if (amountBoughtEl) amountBoughtEl.dispatchEvent(inputEvt);

                // Safety: if amount is still zero/missing, infer from amountSold * exchangeRate
                if (amountBoughtEl) {
                    const currentVal = parseFloat(amountBoughtEl.value || '');
                    const soldNum = parseFloat(trade.amountSold || '');
                    const rateNum = parseFloat(trade.exchangeRate || '');
                    if ((!isFinite(currentVal) || currentVal <= 0) && isFinite(soldNum) && soldNum > 0 && isFinite(rateNum) && rateNum > 0) {
                        const inferred = soldNum * rateNum;
                        amountBoughtEl.value = String(Math.round(inferred));
                        amountBoughtEl.dispatchEvent(inputEvt);
                    }
                }
                if (exchangeRateEl) exchangeRateEl.dispatchEvent(inputEvt);

                // Ensure calculations run immediately
                if (typeof updateFormLabelsAndCalculation === 'function') {
                    updateFormLabelsAndCalculation();
                }
                if (typeof updateTradeCalculation === 'function') {
                    updateTradeCalculation();
                }
            });
        });

        // Function to handle the "Partial Trade" button clicks
partialTradeBtns.forEach(button => {
    button.addEventListener('click', function() {
        const trade = this.dataset;
        partialTradeIdInput.value = trade.tradeId;
        
        // Update labels depending on trade type
const partialAmountLabel = document.getElementById('partial-amount-label');
const paymentLabelText = document.getElementById('payment-label-text');
if (partialAmountLabel && paymentLabelText) {
  if (trade.tradeType === 'buy') {
    partialAmountLabel.textContent = 'Amount You Want to Sell';
    paymentLabelText.textContent = 'You Will Receive:';
  } else {
    partialAmountLabel.textContent = 'Amount You Want to Buy';
    paymentLabelText.textContent = 'You Will Pay:';
  }
}
        const exchangeRate = parseFloat(trade.exchangeRate);
        const remaining = parseFloat(trade.remainingAmount);
        // Track which unit the input uses (BUY or SELL)
        let inputIsSell = false;
        let availableForInput = 0;

        // Default payment currency to sell currency initially
        paymentCurrencySpan.textContent = trade.sellCurrencySymbol;

        const foreigns = ['USD','JPY','THB'];
        const isMmkForeign = (trade.buyCurrencySymbol === 'MMK' && foreigns.includes(trade.sellCurrencySymbol)) ||
                             (trade.sellCurrencySymbol === 'MMK' && foreigns.includes(trade.buyCurrencySymbol));
        const isCrossFX = foreigns.includes(trade.buyCurrencySymbol) && foreigns.includes(trade.sellCurrencySymbol) && trade.buyCurrencySymbol !== trade.sellCurrencySymbol;

        if (isCrossFX) {
          // Cross FX pairs (USD/JPY/THB)
          const ab = parseFloat(trade.amountBought || '0');
          const as = parseFloat(trade.amountSold || '0');
          const er = parseFloat(trade.exchangeRate || '0'); // backend stores 1 BUY = X SELL
          // Remaining is stored in BUY currency by default for cross FX
          const remBuy = isFinite(remaining) ? remaining : 0;

          if (trade.tradeType === 'sell') {
            // SELL posts: input and available should be in SELL currency
            // Convert BUY-remaining to SELL using exchange rate (1 BUY = X SELL)
            let remSell = 0;
            if (isFinite(remBuy) && remBuy > 0 && isFinite(er) && er > 0) {
              remSell = remBuy * er;
            } else if (isFinite(as) && as > 0 && isFinite(ab) && ab > 0) {
              // Fallback via proportion if needed
              remSell = remBuy * (as / ab);
            }
            // Set input controls for SELL currency
            inputIsSell = true;
            availableForInput = remSell;
            const maxAvailSell = Math.max(availableForInput, 0);
            availableCurrencySpan.textContent = trade.sellCurrencySymbol;
            if (trade.sellCurrencySymbol === 'USD') {
              availableAmountSpan.textContent = (isFinite(maxAvailSell) ? maxAvailSell : 0).toFixed(2);
              partialAmountInput.setAttribute('step', '0.01');
              partialAmountInput.setAttribute('min', '0.01');
              partialAmountInput.setAttribute('max', (isFinite(maxAvailSell) ? maxAvailSell : 0).toFixed(2));
            } else {
              availableAmountSpan.textContent = Math.round(isFinite(maxAvailSell) ? maxAvailSell : 0).toLocaleString();
              partialAmountInput.setAttribute('step', '1');
              partialAmountInput.setAttribute('min', '1');
              partialAmountInput.setAttribute('max', String(Math.round(isFinite(maxAvailSell) ? maxAvailSell : 0)));
            }
            // Acceptor pays in BUY currency
            paymentCurrencySpan.textContent = trade.buyCurrencySymbol;
          } else {
            // BUY posts: input and available remain in BUY currency
            inputIsSell = false;
            availableForInput = remBuy;
            const maxAvail = Math.max(availableForInput, 0);
            availableCurrencySpan.textContent = trade.buyCurrencySymbol;
            const buyIsUSD = trade.buyCurrencySymbol === 'USD';
            if (buyIsUSD) {
              availableAmountSpan.textContent = (isFinite(maxAvail) ? maxAvail : 0).toFixed(2);
              partialAmountInput.setAttribute('step', '0.01');
              partialAmountInput.setAttribute('min', '0.01');
              partialAmountInput.setAttribute('max', (isFinite(maxAvail) ? maxAvail : 0).toFixed(2));
            } else {
              availableAmountSpan.textContent = Math.round(isFinite(maxAvail) ? maxAvail : 0).toLocaleString();
              partialAmountInput.setAttribute('step', '1');
              partialAmountInput.setAttribute('min', '1');
              partialAmountInput.setAttribute('max', String(Math.round(isFinite(maxAvail) ? maxAvail : 0)));
            }
            paymentCurrencySpan.textContent = trade.sellCurrencySymbol;
          }
        } else if (trade.tradeType === 'sell') {
          // Acceptor buys from seller
          if (isMmkForeign) {
            // For MMK-FOREIGN SELL posts, remaining is stored in SELL currency; input should be in SELL currency
            inputIsSell = true;
            availableForInput = remaining;
            availableCurrencySpan.textContent = trade.sellCurrencySymbol;
            paymentCurrencySpan.textContent = trade.buyCurrencySymbol; // acceptor pays BUY currency
          } else {
            // Other pairs: remaining is in BUY currency by default
            inputIsSell = false;
            availableForInput = remaining;
            availableCurrencySpan.textContent = trade.buyCurrencySymbol;
            paymentCurrencySpan.textContent = trade.sellCurrencySymbol;
          }
        } else {
          // tradeType === 'buy' -> acceptor sells to poster; remaining stored in BUY currency
          inputIsSell = false;
          availableForInput = remaining;
          availableCurrencySpan.textContent = trade.buyCurrencySymbol;
          paymentCurrencySpan.textContent = trade.sellCurrencySymbol;
        }

// Default display if not cross FX already handled
if (!isCrossFX) {
  availableAmountSpan.textContent = availableForInput.toLocaleString();
  partialAmountInput.setAttribute('max', Math.max(availableForInput, 0));
}
partialAmountInput.value = '';

// Persist which unit the user is entering (SELL vs BUY)
const unitHidden = document.getElementById('partial_amount_unit');
if (unitHidden) unitHidden.value = inputIsSell ? 'SELL' : 'BUY';

const confirmBtn = partialTradeForm.querySelector('button[type="submit"]');
// Disable confirm if nothing is available
if (availableForInput <= 0) {
  confirmBtn.disabled = true;
  confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
} else {
  confirmBtn.disabled = false;
  confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
}

      function calculatePaymentAmount() {
  const partialAmount = parseFloat(partialAmountInput.value) || 0;
  const maxAmount = parseFloat(partialAmountInput.getAttribute('max')) || 0;

  // Clamp to [0, max]
  if (partialAmount > maxAmount) {
    partialAmountInput.value = maxAmount;
  }
  const effectiveAmount = Math.max(0, Math.min(maxAmount, parseFloat(partialAmountInput.value) || 0));

  // Compute paymentAmount using effectiveAmount instead of partialAmount
  let paymentAmount = 0;
  const exchangeRate = parseFloat(trade.exchangeRate);
  const foreignsCalc = ['USD','JPY','THB'];
  const isMmkForeignCalc = (trade.buyCurrencySymbol === 'MMK' && foreignsCalc.includes(trade.sellCurrencySymbol)) ||
                           (trade.sellCurrencySymbol === 'MMK' && foreignsCalc.includes(trade.buyCurrencySymbol));

  if (isMmkForeignCalc) {
    // Interpret exchange_rate as 1 FOREIGN = X MMK
    if (trade.tradeType === 'sell') {
      if (trade.buyCurrencySymbol === 'MMK' && foreignsCalc.includes(trade.sellCurrencySymbol)) {
        // Post: Sell FOREIGN for MMK -> input is FOREIGN; pay MMK = FOREIGN * rate
        paymentAmount = effectiveAmount * exchangeRate;
        paymentCurrencySpan.textContent = 'MMK';
      } else {
        // Post: Sell MMK for FOREIGN -> input is MMK; pay FOREIGN = MMK / rate
        paymentAmount = effectiveAmount / exchangeRate;
        paymentCurrencySpan.textContent = trade.sellCurrencySymbol; // FOREIGN
      }
    } else {
      // tradeType === 'buy' (poster buys)
      if (foreignsCalc.includes(trade.buyCurrencySymbol) && trade.sellCurrencySymbol === 'MMK') {
        // Post: Buy FOREIGN with MMK -> input is FOREIGN; pay MMK = FOREIGN * rate
        paymentAmount = effectiveAmount * exchangeRate;
        paymentCurrencySpan.textContent = 'MMK';
      } else {
        // Post: Buy MMK with FOREIGN -> input is MMK; pay FOREIGN = MMK / rate
        paymentAmount = effectiveAmount / exchangeRate;
        paymentCurrencySpan.textContent = trade.sellCurrencySymbol; // FOREIGN
      }
    }
  } else {
    // Non MMK-FOREIGN pairs (cross FX and others):
    const abAll = parseFloat(trade.amountBought) || 0; // posted BUY amount
    const asAll = parseFloat(trade.amountSold) || 0;   // posted SELL amount
    if (isCrossFX && trade.tradeType === 'sell' && inputIsSell) {
      // Input is in SELL currency. Acceptor pays BUY currency.
      const ratio = (asAll > 0 ? (effectiveAmount / asAll) : 0);
      paymentAmount = abAll * ratio;
      paymentCurrencySpan.textContent = trade.buyCurrencySymbol;
    } else {
      // Default: input is in BUY currency; payer pays SELL currency
      const ratio = (abAll > 0 ? (effectiveAmount / abAll) : 0);
      paymentAmount = asAll * ratio;
      paymentCurrencySpan.textContent = trade.sellCurrencySymbol;
    }
  }

  // Normalize payment currency label deterministically
  let desiredPaymentSymbol = paymentCurrencySpan.textContent || trade.sellCurrencySymbol;
  if (isMmkForeignCalc) {
    if (trade.tradeType === 'buy') {
      // For BUY posts on MMK-FOREIGN, payer is acceptor in SELL currency (FOREIGN)
      desiredPaymentSymbol = trade.sellCurrencySymbol;
    } else {
      // For SELL posts on MMK-FOREIGN, payer is acceptor in BUY currency (MMK or FOREIGN)
      desiredPaymentSymbol = trade.buyCurrencySymbol;
    }
  } else if (isCrossFX && trade.tradeType === 'sell' && inputIsSell) {
    // Cross FX SELL: payer is in BUY currency
    desiredPaymentSymbol = trade.buyCurrencySymbol;
  } else {
    desiredPaymentSymbol = trade.sellCurrencySymbol;
  }
  paymentCurrencySpan.textContent = desiredPaymentSymbol;

  // Display formatting: for USD show 2 decimals, for MMK show integer
  const paymentCurrency = (paymentCurrencySpan.textContent || '').trim();
  if (paymentCurrency === 'USD') {
    const formatted = (isFinite(paymentAmount) ? paymentAmount : 0).toFixed(2);
    paymentAmountSpan.textContent = formatted;
  } else {
    const rounded = Math.round(isFinite(paymentAmount) ? paymentAmount : 0);
    paymentAmountSpan.textContent = rounded.toLocaleString();
  }

  // Toggle submit availability
  const confirmBtn = partialTradeForm.querySelector('button[type="submit"]');
  const canSubmit = (effectiveAmount > 0 && maxAmount > 0);
  confirmBtn.disabled = !canSubmit;
  confirmBtn.classList.toggle('opacity-50', !canSubmit);
  confirmBtn.classList.toggle('cursor-not-allowed', !canSubmit);
}
        partialAmountInput.removeEventListener('input', calculatePaymentAmount);
        partialAmountInput.removeEventListener('keyup', calculatePaymentAmount);

        partialAmountInput.addEventListener('input', calculatePaymentAmount);
        partialAmountInput.addEventListener('keyup', calculatePaymentAmount);

        calculatePaymentAmount();

        partialModal.style.display = "flex";
    });
});

// Dynamic form labels and calculation
        const tradeTypeRadios = document.querySelectorAll('input[name="trade_type"]');
        const buyCurrencySelect = document.getElementById('buy_currency_id');
        const sellCurrencySelect = document.getElementById('sell_currency_id');
        const amountBoughtInput = document.getElementById('amount_bought');
        const exchangeRateInput = document.getElementById('exchange_rate');
        const amountSoldDisplay = document.getElementById('amount_sold_display');
        const baseSymbolSpan = document.getElementById('base-symbol');
        const quoteSymbolSpan = document.getElementById('quote-symbol');

        function updateFormLabelsAndCalculation() {
    const selectedType = document.querySelector('input[name="trade_type"]:checked').value;
    const selectedBuyOption = buyCurrencySelect.options[buyCurrencySelect.selectedIndex];
    const selectedSellOption = sellCurrencySelect.options[sellCurrencySelect.selectedIndex];
    
    if (!selectedBuyOption || !selectedSellOption) return;

    const buySymbol = selectedBuyOption.getAttribute('data-symbol');
    const sellSymbol = selectedSellOption.getAttribute('data-symbol');
    const buyRateToUSD = parseFloat(selectedBuyOption.getAttribute('data-rate-to-usd'));
    const sellRateToUSD = parseFloat(selectedSellOption.getAttribute('data-rate-to-usd'));

    if (selectedType === 'sell') {
        document.getElementById('label_sell_currency').textContent = 'Currency to Sell';
        document.getElementById('label_buy_currency').textContent = 'Currency to Buy';
        document.getElementById('label_amount_bought').textContent = `Amount to Buy (${buySymbol})`;
        document.getElementById('label_amount_sold').textContent = `You Will Sell (${sellSymbol})`;
    } else { // type === 'buy'
        document.getElementById('label_sell_currency').textContent = 'Currency I Will Pay';
        document.getElementById('label_buy_currency').textContent = 'Currency I Want';
        document.getElementById('label_amount_bought').textContent = `Amount I Want (${buySymbol})`;
        document.getElementById('label_amount_sold').textContent = `I Will Pay (${sellSymbol})`;
    }
    
    // Special case: USD↔JPY must always display as 1 USD = ? JPY
    const isUSDJPYPair = (buySymbol === 'USD' && sellSymbol === 'JPY') || (buySymbol === 'JPY' && sellSymbol === 'USD');
    const isUSDTHBPair = (buySymbol === 'USD' && sellSymbol === 'THB') || (buySymbol === 'THB' && sellSymbol === 'USD');
    const isTHBJPYPair = (buySymbol === 'THB' && sellSymbol === 'JPY') || (buySymbol === 'JPY' && sellSymbol === 'THB');
    if (isUSDJPYPair) {
        baseSymbolSpan.textContent = 'USD';
        quoteSymbolSpan.textContent = 'JPY';
        document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 USD = ? JPY)`;
    } else if (isUSDTHBPair) {
        baseSymbolSpan.textContent = 'USD';
        quoteSymbolSpan.textContent = 'THB';
        document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 USD = ? THB)`;
    } else if (isTHBJPYPair) {
        baseSymbolSpan.textContent = 'THB';
        quoteSymbolSpan.textContent = 'JPY';
        document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 THB = ? JPY)`;
    }
    // Check if the currencies are USD and MMK
    const isUSDMMKPair = 
        (buySymbol === 'USD' && sellSymbol === 'MMK') || 
        (buySymbol === 'MMK' && sellSymbol === 'USD');
    
    if (!isUSDJPYPair && !isUSDTHBPair && !isTHBJPYPair && isUSDMMKPair) {
        // Always show as 1 USD = X MMK
        baseSymbolSpan.textContent = 'USD';
        quoteSymbolSpan.textContent = 'MMK';
        document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 USD = ? MMK)`;
        // Labels remain consistent with trade type (no special override)
        if (selectedType === 'sell') {
            document.getElementById('label_amount_bought').textContent = `Amount to Buy (${buySymbol})`;
            document.getElementById('label_amount_sold').textContent = `You Will Sell (${sellSymbol})`;
        } else {
            document.getElementById('label_amount_bought').textContent = `Amount I Want (${buySymbol})`;
            document.getElementById('label_amount_sold').textContent = `I Will Pay (${sellSymbol})`;
        }
    } 
    else if (!isUSDJPYPair && !isUSDTHBPair && !isTHBJPYPair && ((buySymbol === 'MMK' && ['USD','JPY','THB'].includes(sellSymbol)) || (sellSymbol === 'MMK' && ['USD','JPY','THB'].includes(buySymbol)))) {
        // MMK-FOREIGN pairs: always show as 1 FOREIGN = ? MMK
        const foreign = (buySymbol === 'MMK') ? sellSymbol : buySymbol;
        baseSymbolSpan.textContent = foreign;
        quoteSymbolSpan.textContent = 'MMK';
        document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 ${foreign} = ? MMK)`;
    }
    // Handle the case where both currencies are the same
    else if (!isUSDJPYPair && !isUSDTHBPair && !isTHBJPYPair && buyCurrencySelect.value === sellCurrencySelect.value) {
        baseSymbolSpan.textContent = '';
        quoteSymbolSpan.textContent = '';
        document.getElementById('label_exchange_rate').textContent = 'Exchange Rate';
    } else {
        if (!isUSDJPYPair && !isUSDTHBPair && !isTHBJPYPair) {
            // Determine the stronger currency for the user-friendly label
            if (buyRateToUSD > sellRateToUSD) {
                baseSymbolSpan.textContent = buySymbol;
                quoteSymbolSpan.textContent = sellSymbol;
            } else {
                baseSymbolSpan.textContent = sellSymbol;
                quoteSymbolSpan.textContent = buySymbol;
            }
            document.getElementById('label_exchange_rate').textContent = `Exchange Rate (1 ${baseSymbolSpan.textContent} = ? ${quoteSymbolSpan.textContent})`;
        }
    }

    // Set amount input step: USD supports cents (based on BUY currency only)
    const amountInput = document.getElementById('amount_bought');
    if (amountInput) {
        if (buySymbol === 'USD') {
            amountInput.setAttribute('step', '0.01');
            amountInput.setAttribute('min', '0.01');
        } else {
            amountInput.setAttribute('step', '1');
            amountInput.setAttribute('min', '1');
        }
    }

    updateTradeCalculation();
}

        function updateTradeCalculation() {
            const selectedType = document.querySelector('input[name="trade_type"]:checked').value;
            const buyCurrencySelect = document.getElementById('buy_currency_id');
            const sellCurrencySelect = document.getElementById('sell_currency_id');
            const amountBoughtInput = document.getElementById('amount_bought');
            const exchangeRateInput = document.getElementById('exchange_rate');
            const amountSoldDisplay = document.getElementById('amount_sold_display');

            const amountBought = parseFloat(amountBoughtInput.value) || 0;
            const exchangeRate = parseFloat(exchangeRateInput.value) || 0;

            const selectedBuyOption = buyCurrencySelect.options[buyCurrencySelect.selectedIndex];
            const selectedSellOption = sellCurrencySelect.options[sellCurrencySelect.selectedIndex];
            
            // Fallback if no option is selected
            if (!selectedBuyOption || !selectedSellOption) {
                return;
            }

            const buySymbol = selectedBuyOption.getAttribute('data-symbol');
            const sellSymbol = selectedSellOption.getAttribute('data-symbol');
            const buyRateToUSD = parseFloat(selectedBuyOption.getAttribute('data-rate-to-usd'));
            const sellRateToUSD = parseFloat(selectedSellOption.getAttribute('data-rate-to-usd'));

            // Apply client-side constraints for USD/MMK exchange rate
            const isUSDMMKPairForInputs = 
                (buySymbol === 'USD' && sellSymbol === 'MMK') || 
                (buySymbol === 'MMK' && sellSymbol === 'USD');
            if (isUSDMMKPairForInputs) {
                // Use ADMIN marked rate consistently
                const baseRate = adminMmkRates.USD;
                if (typeof baseRate === 'number' && baseRate > 0) {
                    const minAllowed = Math.round(baseRate * (1 - LOWER_TOL));
                    const maxAllowed = Math.round(baseRate * (1 + UPPER_TOL));
                    exchangeRateInput.setAttribute('min', String(minAllowed));
                    exchangeRateInput.setAttribute('max', String(maxAllowed));
                    exchangeRateInput.setAttribute('step', '1');
                    exchangeRateInput.placeholder = `Allowed today: 1 USD = ${minAllowed.toLocaleString()} – ${maxAllowed.toLocaleString()} MMK`;
                    const erVal = parseInt(exchangeRateInput.value) || 0;
                    if (erVal < minAllowed || erVal > maxAllowed) {
                        exchangeRateInput.setCustomValidity(`For USD/MMK trades, today's allowed rate is between ${minAllowed.toLocaleString()} and ${maxAllowed.toLocaleString()} MMK per 1 USD`);
                    } else {
                        exchangeRateInput.setCustomValidity('');
                    }
                }
            } else {
                // Leave server-side placeholder visible; only relax constraints
                exchangeRateInput.setAttribute('min', '0');
                exchangeRateInput.removeAttribute('max');
                exchangeRateInput.setCustomValidity('');
            }

            let amountSold = 0;
            
            // Check if the currencies are the same
            if (buyCurrencySelect.value === sellCurrencySelect.value) {
                amountSold = amountBought;
            } else {
                // Check if the currencies are USD and MMK
                const isUSDMMKPair = 
                    (buySymbol === 'USD' && sellSymbol === 'MMK') || 
                    (buySymbol === 'MMK' && sellSymbol === 'USD');
                
                if (isUSDMMKPair) {
                    // For USD/MMK pair, always calculate based on 1 USD = X MMK
                    if (buySymbol === 'USD') {
                        // Buying USD, selling MMK -> amountSold in MMK
                        amountSold = amountBought * exchangeRate;
                    } else {
                        // Buying MMK, selling USD -> amountSold in USD
                        amountSold = amountBought / exchangeRate;
                    }
                } else {
                    // Cross FX among USD/JPY/THB: interpret rate by fixed label orientation
                    const FX = ['USD','JPY','THB'];
                    const isCrossFX = FX.includes(buySymbol) && FX.includes(sellSymbol) && buySymbol !== sellSymbol;
                    if (isCrossFX) {
                        // Determine fixed label base/quote used in the UI
                        let baseSym = buySymbol, quoteSym = sellSymbol;
                        if ((buySymbol === 'USD' && sellSymbol === 'JPY') || (buySymbol === 'JPY' && sellSymbol === 'USD')) {
                            baseSym = 'USD'; quoteSym = 'JPY';
                        } else if ((buySymbol === 'USD' && sellSymbol === 'THB') || (buySymbol === 'THB' && sellSymbol === 'USD')) {
                            baseSym = 'USD'; quoteSym = 'THB';
                        } else if ((buySymbol === 'THB' && sellSymbol === 'JPY') || (buySymbol === 'JPY' && sellSymbol === 'THB')) {
                            baseSym = 'THB'; quoteSym = 'JPY';
                        }

                        // exchangeRate input is 1 baseSym = X quoteSym
                        if (buySymbol === baseSym && sellSymbol === quoteSym) {
                            // 1 BUY = X SELL
                            amountSold = amountBought * exchangeRate;
                        } else if (buySymbol === quoteSym && sellSymbol === baseSym) {
                            // 1 SELL(base) = X BUY(quote) -> need inverse for 1 BUY = X SELL
                            amountSold = amountBought / (exchangeRate || 1);
                        } else {
                            // Safety fallback (shouldn't happen): use proportional rule
                            amountSold = amountBought * exchangeRate;
                        }
                    } else {
                        // MMK-FOREIGN pairs should use the same rule as USD/MMK: rate is 1 FOREIGN = X MMK
                        const isMMKForeign = (buySymbol === 'MMK' && ['USD','JPY','THB'].includes(sellSymbol)) ||
                                              (sellSymbol === 'MMK' && ['USD','JPY','THB'].includes(buySymbol));
                        if (isMMKForeign) {
                            if (sellSymbol === 'MMK') {
                                // Selling MMK, buying FOREIGN => pay MMK = amount_bought * rate
                                amountSold = amountBought * exchangeRate;
                            } else {
                                // Selling FOREIGN, buying MMK => pay FOREIGN = amount_bought / rate
                                amountSold = amountBought / exchangeRate;
                            }
                        } else {
                            // Fallback for other pairs: maintain original stronger-weaker logic
                            const isBuyCurrencyStronger = buyRateToUSD > sellRateToUSD;
                            if (selectedType === 'sell') {
                                amountSold = isBuyCurrencyStronger ? (amountBought * exchangeRate) : (amountBought / exchangeRate);
                            } else { // 'buy'
                                amountSold = isBuyCurrencyStronger ? (amountBought * exchangeRate) : (amountBought / exchangeRate);
                            }
                        }
                    }
                }
            }
            // Display formatting for the read-only field: if sell currency is USD, show 2 decimals; else integer for MMK; others 2 decimals
            if (sellSymbol === 'USD') {
                amountSoldDisplay.value = (isFinite(amountSold) ? Number(amountSold) : 0).toFixed(2);
            } else if (sellSymbol === 'MMK') {
                amountSoldDisplay.value = Math.round(isFinite(amountSold) ? Number(amountSold) : 0);
            } else {
                amountSoldDisplay.value = (isFinite(amountSold) ? Number(amountSold) : 0).toFixed(2);
            }
        }

        // No special submit-time conversion; amount_bought remains in BUY currency consistently
        if (amountBoughtInput && exchangeRateInput && buyCurrencySelect && sellCurrencySelect) {
            amountBoughtInput.addEventListener('input', updateTradeCalculation);
            exchangeRateInput.addEventListener('input', updateTradeCalculation);
            // Update the range hint/error live while typing
            exchangeRateInput.addEventListener('input', updateRateHintVisibility);
            buyCurrencySelect.addEventListener('change', updateFormLabelsAndCalculation);
            sellCurrencySelect.addEventListener('change', updateFormLabelsAndCalculation);
            
            tradeTypeRadios.forEach(radio => {
                radio.addEventListener('change', updateFormLabelsAndCalculation);
            });
            
            // Initial call to set symbols and labels on page load
            updateFormLabelsAndCalculation();
        }

        // Profile Image Modal Functions
let currentProfileImage = '';
let currentProfileUsername = '';
let currentProfileInitials = '';
// Profile Image Modal Functions
function showProfileImageModal(imageSrc, username, initials, tradeCount, successRate, memberSince) {
    const modal = document.getElementById('profileImageModal');
    const imageContainer = document.getElementById('profileModalImageContainer');
    const usernameElement = document.getElementById('profileModalUsername');
    
    // Clear previous content
    imageContainer.innerHTML = '';
    
    // Set username
    usernameElement.textContent = username;
    
    // Set image or initials
    if (imageSrc && imageSrc.trim() !== '') {
        const img = document.createElement('img');
        img.src = imageSrc;
        img.alt = username;
        img.style.width = '100%';
        img.style.height = '100%';
        img.style.objectFit = 'cover';
        imageContainer.appendChild(img);
    } else {
        const initialsSpan = document.createElement('span');
        initialsSpan.textContent = initials;
        initialsSpan.style.fontSize = '3rem';
        initialsSpan.style.fontWeight = '700';
        initialsSpan.style.color = '#2e7d32';
        imageContainer.appendChild(initialsSpan);
    }
    
    // Set real statistics
    document.getElementById('profileTradeCount').textContent = tradeCount;
    document.getElementById('profileSuccessRate').textContent = successRate + '%';
    document.getElementById('profileMemberSince').textContent = memberSince;
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Prevent scrolling
}

function closeProfileImageModal() {
    const modal = document.getElementById('profileImageModal');
    modal.classList.remove('active');
    document.body.style.overflow = ''; // Re-enable scrolling
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('profileImageModal');
    if (event.target === modal) {
        closeProfileImageModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    const modal = document.getElementById('profileImageModal');
    if (event.key === 'Escape' && modal.classList.contains('active')) {
        closeProfileImageModal();
    }
});

// Optional: Function to load user stats (you can implement this if you have the data)
function loadUserStats(username) {
    // This is a placeholder - implement based on your database structure
    // You could make an AJAX request to fetch user statistics
    
    // For now, just set some placeholder values
    document.getElementById('profileTradeCount').textContent = '--';
    document.getElementById('profileSuccessRate').textContent = '--%';
    document.getElementById('profileMemberSince').textContent = '--';
}

// In showProfileImageModal function:
if (tradeCount === "0") {
    document.getElementById('profileTradeCount').textContent = 'No trades';
    document.getElementById('profileSuccessRate').textContent = '--';
} else {
    document.getElementById('profileTradeCount').textContent = tradeCount;
    document.getElementById('profileSuccessRate').textContent = successRate + '%';
}
       
    </script>
    
    <!-- Real-time Ban Check -->
    <?php include 'ban_check_script.php'; ?>
</body>
</html>