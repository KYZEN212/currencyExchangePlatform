<?php
// Disable caching to ensure fresh dashboard data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();

// Database credentials (standardize with config.php)
require_once 'config.php';
// Map config.php vars to local names used below
$db_username = $username;
$db_password = $password;

$message = '';
$show_requests = false;
$show_wallet = false;
$show_history = false;
$show_all_requests = false;
$show_deposit = false;
$show_fees = false;
$show_exchange_rate = false;

// Include the bank logic, currency logic, and exchange API
require_once 'bank.php';
require_once 'currency.php';
require_once 'exchange_api.php';

// Check if the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    $admin_username = 'admin';
    $admin_password = 'admin_password';

    if (isset($_POST['admin_username']) && isset($_POST['admin_password'])) {
        if ($_POST['admin_username'] === $admin_username && $_POST['admin_password'] === $admin_password) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = 1;
            header("Location: admin.php");
            exit();
        } else {
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Incorrect admin credentials.</div>";
        }
    }
    // Delete a single bank deposit history row (guarded)
    elseif (
        isset($_POST['action']) && $_POST['action'] === 'delete_bank_history'
        && isset($_POST['history_id'])
        && isset($conn)
    ) {
        $hid = intval($_POST['history_id']);
        $stmt_del = $conn->prepare("DELETE FROM bank_deposit_history WHERE id = ? LIMIT 1");
        if ($stmt_del) {
            $stmt_del->bind_param("i", $hid);
            $stmt_del->execute();
            $stmt_del->close();
        }
        // Redirect back and preserve selected bank_date if provided
        $qs = [];
        if (!empty($_POST['bank_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['bank_date'])) { $qs['bank_date'] = $_POST['bank_date']; }
        header('Location: admin.php' . (!empty($qs) ? ('?' . http_build_query($qs)) : ''));
        exit();
    }
    
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
            body { font-family: "Inter", sans-serif; }
        </style>
    </head>
    <body class="bg-gray-100 flex items-center justify-center min-h-screen">
        <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-sm">
            <h2 class="text-2xl font-bold mb-6 text-center text-gray-800">Admin Login</h2>
            ' . $message . '
            <form method="POST">
                <input type="text" name="admin_username" placeholder="Username" class="w-full p-3 mb-4 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="password" name="admin_password" placeholder="Password" class="w-full p-3 mb-6 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition-colors">Log In</button>
            </form>
        </div>
    </body>
    </html>
    ';
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Helper functions (keeping existing ones)
function updateWalletBalance($conn, $user_id, $currency_id, $amount_change) {
    if ($amount_change >= 0) {
        $stmt = $conn->prepare("
            INSERT INTO wallets (user_id, currency_id, balance) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)
        ");
        $stmt->bind_param("iid", $user_id, $currency_id, $amount_change);
        return $stmt->execute();
    } else {
        $stmt_insert = $conn->prepare("INSERT IGNORE INTO wallets (user_id, currency_id, balance) VALUES (?, ?, 0)");
        $stmt_insert->bind_param("ii", $user_id, $currency_id);
        $stmt_insert->execute();

        $required_balance = abs($amount_change);
        $stmt_update = $conn->prepare("
            UPDATE wallets SET balance = balance + ?
            WHERE user_id = ? AND currency_id = ? AND balance >= ?
        ");
        $stmt_update->bind_param("diid", $amount_change, $user_id, $currency_id, $required_balance);
        $stmt_update->execute();
        return $stmt_update->affected_rows > 0;
    }
}

function updateAdminWalletBalance($conn, $admin_id, $currency_id, $amount_change) {
    // Ensure wallet row exists (no-op if already present)
    $stmt_insert = $conn->prepare("
        INSERT INTO admin_wallet (admin_id, currency_id, balance) VALUES (?, ?, 0)
        ON DUPLICATE KEY UPDATE balance = balance + 0
    ");
    $stmt_insert->bind_param("ii", $admin_id, $currency_id);
    $stmt_insert->execute();

    if ($amount_change >= 0) {
        $stmt = $conn->prepare("
            UPDATE admin_wallet SET balance = balance + ?
            WHERE admin_id = ? AND currency_id = ?
        ");
        $stmt->bind_param("dii", $amount_change, $admin_id, $currency_id);
        return $stmt->execute();
    } else {
        $amount_to_subtract = abs($amount_change);
        $stmt = $conn->prepare("
            UPDATE admin_wallet SET balance = balance - ?
            WHERE admin_id = ? AND currency_id = ? AND balance >= ?
        ");
        $stmt->bind_param("diid", $amount_to_subtract, $admin_id, $currency_id, $amount_to_subtract);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}

function getCurrencySymbol($conn, $currency_id) {
    $stmt = $conn->prepare("SELECT symbol FROM currencies WHERE currency_id = ?");
    $stmt->bind_param("i", $currency_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['symbol'] : 'N/A';
}

function recordTransaction($conn, $user_id, $admin_id, $currency_id, $amount, $type, $user_payment_id = NULL, $proof_of_screenshot = NULL) {
    $type = strtolower($type);
    if ($type === 'admin_deposit' || $type === 'admin_withdrawal') {
        $stmt = $conn->prepare("INSERT INTO admin_transactions (admin_id, currency_id, amount, type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iids", $admin_id, $currency_id, $amount, $type);
    } else {
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, admin_id, currency_id, amount, type, user_payment_id, proof_of_screenshot) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidsss", $user_id, $admin_id, $currency_id, $amount, $type, $user_payment_id, $proof_of_screenshot);
    }
    
    $result = $stmt->execute();
    return $result;
}

function reconcileAdminBalance($conn, $admin_id) {
    $conn->begin_transaction();
    try {
        $calculated_balances = [];
        $stmt = $conn->prepare("SELECT currency_id, amount, type FROM transactions");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $currency_id = $row['currency_id'];
            $amount = $row['amount'];
            $type = $row['type'];

            if (!isset($calculated_balances[$currency_id])) {
                $calculated_balances[$currency_id] = 0;
            }

            if ($type === 'deposit') {
                $calculated_balances[$currency_id] -= $amount;
            } elseif ($type === 'withdrawal') {
                $calculated_balances[$currency_id] += $amount;
            }
        }
        
        $stmt = $conn->prepare("SELECT currency_id, amount, type FROM admin_transactions WHERE admin_id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $currency_id = $row['currency_id'];
            $amount = $row['amount'];
            $type = $row['type'];

            if (!isset($calculated_balances[$currency_id])) {
                $calculated_balances[$currency_id] = 0;
            }

            if ($type === 'admin_deposit') {
                $calculated_balances[$currency_id] += $amount;
            } elseif ($type === 'admin_withdrawal') {
                $calculated_balances[$currency_id] -= $amount;
            }
        }

        $stmt_clear = $conn->prepare("DELETE FROM admin_wallet WHERE admin_id = ?");
        $stmt_clear->bind_param("i", $admin_id);
        $stmt_clear->execute();

        $stmt_insert = $conn->prepare("INSERT INTO admin_wallet (admin_id, currency_id, balance) VALUES (?, ?, ?)");
        foreach ($calculated_balances as $currency_id => $balance) {
            $stmt_insert->bind_param("iid", $admin_id, $currency_id, $balance);
            $stmt_insert->execute();
        }

        $conn->commit();
        return "Admin wallet balance has been successfully reconciled based on all transaction history.";
    } catch (Exception $e) {
        $conn->rollback();
        return "Error during balance reconciliation: " . $e->getMessage();
    }
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'confirm' && isset($_POST['request_id'])) {
        $request_id = $_POST['request_id'];
        $conn->begin_transaction();
        try {
            $stmt_fetch = $conn->prepare("SELECT * FROM user_currency_requests WHERE request_id = ?");
            $stmt_fetch->bind_param("i", $request_id);
            $stmt_fetch->execute();
            $request_data = $stmt_fetch->get_result()->fetch_assoc();

            if ($request_data) {
                $user_id = $request_data['user_id'];
                $currency_id = $request_data['currency_id'];
                $amount = $request_data['amount'];
                $transaction_type = $request_data['transaction_type'];
                
                // Pre-check admin funds for deposit and show a friendly error in-place if insufficient
                if ($transaction_type === 'deposit') {
                    $stmt_bal = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                    if ($stmt_bal) {
                        $stmt_bal->bind_param("ii", $admin_id, $currency_id);
                        $stmt_bal->execute();
                        $res_bal = $stmt_bal->get_result()->fetch_assoc();
                        $stmt_bal->close();
                        $current_balance = $res_bal && isset($res_bal['balance']) ? (float)$res_bal['balance'] : 0.0;
                        if ($current_balance < (float)$amount) {
                            $conn->rollback();
                            $message = "Insufficient admin funds. Available: " . number_format($current_balance, 2) . " < Required: " . number_format((float)$amount, 2);
                            // Stop further processing of this request confirmation; dashboard remains visible
                            throw new Exception($message);
                        }
                    }
                }

                if ($transaction_type === 'deposit') {
                    $user_amount_change = $amount;
                    $admin_amount_change = -$amount;

                    // Deduct admin first to enforce sufficient funds (all within the same transaction)
                    if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $admin_amount_change)) {
                        throw new Exception("Insufficient admin currency for deposit transaction.");
                    }

                    if (!updateWalletBalance($conn, $user_id, $currency_id, $user_amount_change)) {
                        throw new Exception("Failed to update user's wallet for deposit.");
                    }

                    if (!recordTransaction($conn, $user_id, $admin_id, $currency_id, $amount, 'deposit', $request_data['user_payment_id'], $request_data['proof_of_screenshot'])) {
                        throw new Exception("Failed to record transaction.");
                    }
                    
                } else {
                    $user_amount_change = -$amount;
                    $admin_amount_change = $amount;

                    if (!updateWalletBalance($conn, $user_id, $currency_id, $user_amount_change)) {
                        throw new Exception("User has insufficient currency for withdrawal.");
                    }

                    if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $admin_amount_change)) {
                        throw new Exception("Failed to update admin's wallet for withdrawal.");
                    }

                    if (!recordTransaction($conn, $user_id, $admin_id, $currency_id, $amount, 'withdrawal')) {
                        throw new Exception("Failed to record transaction.");
                    }
                }
                $status = 'completed';
                // Do not auto-clear notifications; let the user clear them from their dashboard
                $stmt_update = $conn->prepare("UPDATE user_currency_requests SET status = ?, admin_id = ?, decision_timestamp = NOW() WHERE request_id = ?");
                $stmt_update->bind_param("sii", $status, $admin_id, $request_id);
                if (!$stmt_update->execute()) {
                    throw new Exception("Failed to update request status.");
                }
                $message = "Request #{$request_id} has been completed successfully.";
                $conn->commit();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error confirming request: " . $e->getMessage();
            header("Location: admin.php?view=requests&message=" . urlencode($message));
            exit();
        }
    } elseif ($_POST['action'] === 'reject' && isset($_POST['request_id'])) {
        $request_id = $_POST['request_id'];
        $status = 'rejected';
        $stmt_update = $conn->prepare("UPDATE user_currency_requests SET status = ?, admin_id = ?, decision_timestamp = NOW() WHERE request_id = ?");
        $stmt_update->bind_param("sii", $status, $admin_id, $request_id);
        $stmt_update->execute();
        
        $message = "Request #{$request_id} has been rejected.";
        header("Location: admin.php?view=requests&message=" . urlencode($message));
        exit();
    } elseif ($_POST['action'] === 'run_fix') {
        $message = reconcileAdminBalance($conn, $admin_id);
    } elseif ($_POST['action'] === 'admin_deposit' && isset($_POST['currency_id']) && isset($_POST['amount'])) {
        $currency_id = $_POST['currency_id'];
        $amount = $_POST['amount'];
    
        if ($amount <= 0) {
            $message = "Deposit amount must be positive.";
        } else {
            $conn->begin_transaction();
            try {
                // Create bank_deposit_history table if not exists
                $conn->query("CREATE TABLE IF NOT EXISTS bank_deposit_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NULL,
                    currency_id INT NOT NULL,
                    previous_balance DECIMAL(18,2) NOT NULL,
                    after_balance DECIMAL(18,2) NOT NULL,
                    amount DECIMAL(18,2) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Fetch previous admin wallet balance
                $prev_balance = 0.00;
                $stmt_prev = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                $stmt_prev->bind_param("ii", $admin_id, $currency_id);
                $stmt_prev->execute();
                $res_prev = $stmt_prev->get_result()->fetch_assoc();
                if ($res_prev && isset($res_prev['balance'])) { $prev_balance = (float)$res_prev['balance']; }
                $stmt_prev->close();

                if (!updateBankBalance($conn, $currency_id, -$amount)) {
                    throw new Exception("Insufficient bank funds for this deposit.");
                }
            
                if (!updateAdminWalletBalance($conn, $admin_id, $currency_id, $amount)) {
                    throw new Exception("Failed to update admin's wallet.");
                }
            
                if (!recordTransaction($conn, NULL, $admin_id, $currency_id, $amount, 'admin_deposit')) {
                    throw new Exception("Failed to record transaction.");
                }
            
                // Fetch new admin wallet balance and record in history
                $after_balance = $prev_balance + (float)$amount;
                $stmt_after = $conn->prepare("SELECT balance FROM admin_wallet WHERE admin_id = ? AND currency_id = ?");
                $stmt_after->bind_param("ii", $admin_id, $currency_id);
                $stmt_after->execute();
                $res_after = $stmt_after->get_result()->fetch_assoc();
                if ($res_after && isset($res_after['balance'])) { $after_balance = (float)$res_after['balance']; }
                $stmt_after->close();

                $stmt_hist = $conn->prepare("INSERT INTO bank_deposit_history (admin_id, currency_id, previous_balance, after_balance, amount) VALUES (?, ?, ?, ?, ?)");
                if ($stmt_hist) {
                    $stmt_hist->bind_param("iiddd", $admin_id, $currency_id, $prev_balance, $after_balance, $amount);
                    $stmt_hist->execute();
                    $stmt_hist->close();
                }

                $conn->commit();
                $message = "Successfully deposited " . number_format($amount, 2) . " " . getCurrencySymbol($conn, $currency_id) . " to your wallet.";
                // Keep dashboard view; no popup for success (only errors trigger the modal)
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error depositing funds: " . $e->getMessage();
                // Keep dashboard view; the centered popup will display over the dashboard
            }
        }
    }
    // Save exchange rate markup settings (multi-currencies) and optional refresh
    elseif ($_POST['action'] === 'save_settings') {
        $global_mode = isset($_POST['global_mode']) ? $_POST['global_mode'] : 'percent';
        $global_value = isset($_POST['global_value']) && $_POST['global_value'] !== '' ? floatval($_POST['global_value']) : 0.0;
        $mmk_percent = isset($_POST['mmk_percent']) && $_POST['mmk_percent'] !== '' ? floatval($_POST['mmk_percent']) : 0.0;
        $thb_percent = isset($_POST['thb_percent']) && $_POST['thb_percent'] !== '' ? floatval($_POST['thb_percent']) : 0.0;
        $jpy_percent = isset($_POST['jpy_percent']) && $_POST['jpy_percent'] !== '' ? floatval($_POST['jpy_percent']) : 0.0;
        $refresh_rates = isset($_POST['refresh_rates']) && $_POST['refresh_rates'] == '1';

        $settings = er_load_settings();
        $settings['global'] = ['mode' => $global_mode, 'value' => $global_value];
        if (!isset($settings['targets'])) { $settings['targets'] = []; }
        $settings['targets']['MMK'] = ['mode' => 'percent', 'value' => $mmk_percent];
        $settings['targets']['THB'] = ['mode' => 'percent', 'value' => $thb_percent];
        $settings['targets']['JPY'] = ['mode' => 'percent', 'value' => $jpy_percent];

        $saved = er_save_settings($settings);
        if ($saved && $refresh_rates) {
            // Map symbols -> ids
            $currencies = [];
            $rs = $conn->query("SELECT currency_id, symbol FROM currencies");
            if ($rs) {
                while ($row = $rs->fetch_assoc()) { $currencies[$row['symbol']] = (int)$row['currency_id']; }
            }
            $pairs = [
                ['from' => 'THB', 'to' => 'MMK'],
                ['from' => 'MMK', 'to' => 'THB'],
                ['from' => 'JPY', 'to' => 'MMK'],
                ['from' => 'MMK', 'to' => 'JPY']
            ];
            $ok = true;
            $conn->begin_transaction();
            foreach ($pairs as $p) {
                if (!isset($currencies[$p['from']]) || !isset($currencies[$p['to']])) continue;
                list($live, $eff) = er_get_effective_rate($p['from'], $p['to']);
                if ($eff === null) { $ok = false; break; }
                if (!addExchangeRate($conn, $currencies[$p['from']], $currencies[$p['to']], $eff)) { $ok = false; break; }
            }
            if ($ok) { $conn->commit(); $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Settings saved and key MMK pairs updated.</div>"; }
            else { $conn->rollback(); $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Settings saved, but failed to refresh some pairs.</div>"; }
        } elseif ($saved) {
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Settings saved successfully.</div>";
        } else {
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to save settings.</div>";
        }
    }
    // Sync exchange rate (supports symbol-based inputs and adds inverse) with fallback to id-based
    elseif ($_POST['action'] === 'sync_from_api') {
        $base_code = '';
        $target_code = '';
        // direct MMK buttons: base_currency is symbol
        if (isset($_POST['base_currency']) && !ctype_digit((string)$_POST['base_currency'])) {
            $base_code = strtoupper(trim($_POST['base_currency']));
            $target_code = 'MMK';
        } elseif (!empty($_POST['use_custom']) && isset($_POST['custom_base']) && isset($_POST['custom_target'])) {
            $base_code = strtoupper(trim($_POST['custom_base']));
            $target_code = strtoupper(trim($_POST['custom_target']));
        }

        if ($base_code && $target_code && $base_code !== $target_code) {
            $stmt = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN (?, ?)");
            $stmt->bind_param("ss", $base_code, $target_code);
            $stmt->execute();
            $res = $stmt->get_result();
            $map = [];
            while ($r = $res->fetch_assoc()) { $map[$r['symbol']] = (int)$r['currency_id']; }
            $stmt->close();
            if (isset($map[$base_code]) && isset($map[$target_code])) {
                list($live, $eff) = er_get_effective_rate($base_code, $target_code);
                if ($eff !== null) {
                    if (addExchangeRate($conn, $map[$base_code], $map[$target_code], $eff)) {
                        // also add inverse
                        addExchangeRate($conn, $map[$target_code], $map[$base_code], 1/$eff);
                        $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Synced: 1 {$base_code} = " . number_format($eff, 4) . " {$target_code} (inverse " . number_format(1/$eff, 6) . ")</div>";
                    } else {
                        $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Fetched rate but failed to store.</div>";
                    }
                } else {
                    $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to fetch live rate for {$base_code} → {$target_code}.</div>";
                }
            } else {
                $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Invalid currencies selected.</div>";
            }
        } else {
            // Fallback: original id-based flow
            $base_id = isset($_POST['base_currency']) ? intval($_POST['base_currency']) : 0;
            $target_id = isset($_POST['target_currency']) ? intval($_POST['target_currency']) : 0;
            if ($base_id > 0 && $target_id > 0 && $base_id !== $target_id) {
                $stmt = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE currency_id IN (?, ?)");
                $stmt->bind_param("ii", $base_id, $target_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $symbols = [];
                while ($r = $res->fetch_assoc()) { $symbols[$r['currency_id']] = $r['symbol']; }
                $stmt->close();
                if (isset($symbols[$base_id]) && isset($symbols[$target_id])) {
                    list($live, $eff) = er_get_effective_rate($symbols[$base_id], $symbols[$target_id]);
                    if ($eff !== null) {
                        if (addExchangeRate($conn, $base_id, $target_id, $eff)) {
                            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative' role='alert'>Synced from API: 1 " . htmlspecialchars($symbols[$base_id]) . " = " . number_format($eff, 4) . " " . htmlspecialchars($symbols[$target_id]) . " (with markup applied)</div>";
                        } else {
                            $message = "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Fetched rate but failed to store (may already exist).</div>";
                        }
                    } else {
                        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Failed to fetch live rate from API.</div>";
                    }
                } else {
                    $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>Invalid currencies selected.</div>";
                }
            } else {
                $message = "<div class='bg-red-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative' role='alert'>Please choose different base and target currencies.</div>";
            }
        }
    }
}

// Check for message in URL parameter
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

// Check the view parameter
if (isset($_GET['view'])) {
    if ($_GET['view'] === 'requests') {
        $show_requests = true;
    } elseif ($_GET['view'] === 'wallet') {
        $show_wallet = true;
    } elseif ($_GET['view'] === 'history') {
        $show_history = true;
    } elseif ($_GET['view'] === 'deposit') {
        $show_deposit = true;
    } elseif ($_GET['view'] === 'fees') {
        $show_fees = true;
    } elseif ($_GET['view'] === 'exchange_rate') {
        $show_exchange_rate = true;
    }
}

// Fetch all available currencies
$currencies = [];
$stmt_currencies = $conn->prepare("SELECT currency_id, symbol FROM currencies");
$stmt_currencies->execute();
$result_currencies = $stmt_currencies->get_result();
while ($row = $result_currencies->fetch_assoc()) {
    $currencies[] = $row;
}
$stmt_currencies->close();

// Auto-sync only MMK-target pairs for today's rates (keeps admin from forgetting)
try {
    $autoSyncedMMK = 0;
    if (function_exists('getAvailableCurrencyPairs')) {
        $pairs = getAvailableCurrencyPairs($conn);
        if (!empty($pairs)) {
            $stmt_has_today = $conn->prepare("SELECT 1 FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? AND DATE(timestamp) = CURDATE() LIMIT 1");
            if ($stmt_has_today) {
                foreach ($pairs as $p) {
                    if (!isset($p['target_symbol']) || strtoupper($p['target_symbol']) !== 'MMK') continue;
                    $baseId = (int)$p['base_currency_id'];
                    $targetId = (int)$p['target_currency_id'];
                    $stmt_has_today->bind_param('ii', $baseId, $targetId);
                    $stmt_has_today->execute();
                    $has = $stmt_has_today->get_result()->fetch_row();
                    if ($has) continue;
                    // Fetch effective rate with current settings (includes any saved MMK markup)
                    list($live, $eff) = er_get_effective_rate($p['base_symbol'], $p['target_symbol']);
                    if ($eff !== null) {
                        if (addExchangeRate($conn, $baseId, $targetId, $eff)) { $autoSyncedMMK++; }
                    }
                }
                $stmt_has_today->close();
            }
        }
    }
    if ($autoSyncedMMK > 0) {
        $message = "<div class='bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded relative' role='alert'>Auto-synced today's MMK rates for " . intval($autoSyncedMMK) . " pair(s).</div>" . $message;
    }
} catch (Throwable $e) { /* ignore auto-sync failures */ }

// Fetch all exchange rates
$exchange_rates = [];
$stmt_rates = $conn->prepare("
    SELECT 
        er.*, 
        c1.symbol AS base_symbol, 
        c2.symbol AS target_symbol
    FROM exchange_rates er
    JOIN currencies c1 ON er.base_currency_id = c1.currency_id
    JOIN currencies c2 ON er.target_currency_id = c2.currency_id
");
$stmt_rates->execute();
$result_rates = $stmt_rates->get_result();
while ($row = $result_rates->fetch_assoc()) {
    $row['rate_ts'] = isset($row['updated_at']) && $row['updated_at']
        ? $row['updated_at']
        : (isset($row['created_at']) && $row['created_at']
            ? $row['created_at']
            : (isset($row['timestamp']) ? $row['timestamp'] : null));
    $exchange_rates[] = $row;
}
$stmt_rates->close();

// Load exchange rate markup settings
$rate_settings = er_load_settings();

// Append derived cross rates (via MMK) into $exchange_rates so they show together
try {
    // Build a quick lookup map from already-fetched rates
    $rate_map = [];
    foreach ($exchange_rates as $r) {
        $key = strtoupper(($r['base_symbol'] ?? '')) . '>' . strtoupper(($r['target_symbol'] ?? ''));
        $rate_map[$key] = [
            'rate' => isset($r['rate']) ? (float)$r['rate'] : null,
            'ts'   => isset($r['rate_ts']) ? $r['rate_ts'] : null
        ];
    }

    // Helper to get source rate and timestamp from map
    $get = function($base, $target) use ($rate_map) {
        $k = strtoupper($base) . '>' . strtoupper($target);
        return $rate_map[$k] ?? ['rate' => null, 'ts' => null];
    };

    // Compute derived using MMK as pivot
    $pairs = [];
    $usd_mmk = $get('USD','MMK');
    $jpy_mmk = $get('JPY','MMK');
    $thb_mmk = $get('THB','MMK');

    // USD ↔ JPY
    if (!empty($usd_mmk['rate']) && !empty($jpy_mmk['rate']) && $jpy_mmk['rate'] > 0) {
        $rate = $usd_mmk['rate'] / $jpy_mmk['rate'];
        $ts   = $usd_mmk['ts'] ?: $jpy_mmk['ts'];
        $pairs[] = ['base_symbol' => 'USD', 'target_symbol' => 'JPY', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'JPY', 'target_symbol' => 'USD', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }
    // USD ↔ THB
    if (!empty($usd_mmk['rate']) && !empty($thb_mmk['rate']) && $thb_mmk['rate'] > 0) {
        $rate = $usd_mmk['rate'] / $thb_mmk['rate'];
        $ts   = $usd_mmk['ts'] ?: $thb_mmk['ts'];
        $pairs[] = ['base_symbol' => 'USD', 'target_symbol' => 'THB', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'THB', 'target_symbol' => 'USD', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }
    // THB ↔ JPY
    if (!empty($thb_mmk['rate']) && !empty($jpy_mmk['rate']) && $jpy_mmk['rate'] > 0) {
        $rate = $thb_mmk['rate'] / $jpy_mmk['rate'];
        $ts   = $thb_mmk['ts'] ?: $jpy_mmk['ts'];
        $pairs[] = ['base_symbol' => 'THB', 'target_symbol' => 'JPY', 'rate' => $rate, 'rate_ts' => $ts];
        $pairs[] = ['base_symbol' => 'JPY', 'target_symbol' => 'THB', 'rate' => (1/$rate), 'rate_ts' => $ts];
    }

    // Avoid duplicating if DB already contains such pairs
    foreach ($pairs as $p) {
        $k = $p['base_symbol'] . '>' . $p['target_symbol'];
        if (!isset($rate_map[$k])) { $exchange_rates[] = $p; }
    }
} catch (Throwable $e) { /* ignore derive failures */ }

// Build live preview for 4 key currencies to MMK
$live_preview = [];
try {
    // Map symbols for quick existence checks
    $sym_map = [];
    $rs_c = $conn->query("SELECT currency_id, symbol FROM currencies");
    if ($rs_c) {
        while ($r = $rs_c->fetch_assoc()) { $sym_map[strtoupper($r['symbol'])] = (int)$r['currency_id']; }
    }
    $bases = ['USD','EUR','THB','JPY'];
    $target = 'MMK';
    foreach ($bases as $base) {
        if (!isset($sym_map[$base]) || !isset($sym_map[$target])) continue;
        list($live, $eff) = er_get_effective_rate($base, $target);
        if ($live !== null && $eff !== null) {
            $live_preview[] = [
                'base' => $base,
                'target' => $target,
                'live' => $live,
                'effective' => $eff
            ];
        }
    }
} catch (Throwable $e) { /* ignore preview errors */ }

// Fetch total users
$total_users = 0;
$stmt_total_users = $conn->prepare("SELECT COUNT(*) AS total FROM users");
$stmt_total_users->execute();
$result_total_users = $stmt_total_users->get_result()->fetch_assoc();
$total_users = $result_total_users['total'];
$stmt_total_users->close();

// Fetch active users based on login times (last 24 hours)
// Ensure table exists (safe guard if app was updated recently)
$conn->query("CREATE TABLE IF NOT EXISTS user_login_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$active_users = 0;
$stmt_active_users = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS active FROM user_login_events WHERE login_at >= NOW() - INTERVAL 1 DAY");
if ($stmt_active_users) {
    $stmt_active_users->execute();
    $res = $stmt_active_users->get_result();
    if ($res) { $row = $res->fetch_assoc(); $active_users = isset($row['active']) ? (int)$row['active'] : 0; }
    $stmt_active_users->close();
}

// Fetch all users
$users = [];
$stmt_users = $conn->prepare("SELECT user_id, username, email FROM users ORDER BY username ASC");
$stmt_users->execute();
$result_users = $stmt_users->get_result();
while ($row = $result_users->fetch_assoc()) {
    $users[] = $row;
}
$stmt_users->close();

// Fetch bank balances
$bank_accounts = [];
$stmt_bank_accounts = $conn->prepare("SELECT b.*, c.symbol FROM bank_accounts b JOIN currencies c ON b.currency_id = c.currency_id");
$stmt_bank_accounts->execute();
$result_bank_accounts = $stmt_bank_accounts->get_result();
while ($row = $result_bank_accounts->fetch_assoc()) {
    $bank_accounts[] = $row;
}
$stmt_bank_accounts->close();

// Fetch pending requests
$pending_requests = [];

$stmt_requests = $conn->prepare("
    SELECT uc.*, u.username, c.symbol 
    FROM user_currency_requests uc
    JOIN users u ON uc.user_id = u.user_id
    JOIN currencies c ON uc.currency_id = c.currency_id
    WHERE uc.status = 'pending'
    ORDER BY uc.request_timestamp DESC
");
$stmt_requests->execute();
$result_requests = $stmt_requests->get_result();
while ($row = $result_requests->fetch_assoc()) {
    $pending_requests[] = $row;
}
$pending_count = count($pending_requests);
$stmt_requests->close();

// When viewing notifications, mark current pending as seen
if ($show_requests) {
    $_SESSION['seen_pending_count'] = $pending_count;
}
// Compute unseen badge count based on last seen
$badge_count = $pending_count - (isset($_SESSION['seen_pending_count']) ? (int)$_SESSION['seen_pending_count'] : 0);
if ($badge_count < 0) { $badge_count = 0; }

// Fetch all requests history
$all_requests_history = [];
$stmt_all_requests = $conn->prepare("
    SELECT uc.*, u.username, c.symbol 
    FROM user_currency_requests uc
    JOIN users u ON uc.user_id = u.user_id
    JOIN currencies c ON uc.currency_id = c.currency_id
    ORDER BY uc.request_timestamp DESC
");
$stmt_all_requests->execute();
$result_all_requests = $stmt_all_requests->get_result();
while ($row = $result_all_requests->fetch_assoc()) {
    $all_requests_history[] = $row;
}
$stmt_all_requests->close();

// Fetch admin wallet balances
$admin_wallets = [];
$stmt_admin_wallet = $conn->prepare("
    SELECT w.balance, c.symbol 
    FROM admin_wallet w 
    JOIN currencies c ON w.currency_id = c.currency_id 
    WHERE w.admin_id = ?
");
$stmt_admin_wallet->bind_param("i", $admin_id);
$stmt_admin_wallet->execute();
$result_admin_wallet = $stmt_admin_wallet->get_result();
while ($row = $result_admin_wallet->fetch_assoc()) {
    $admin_wallets[] = $row;
}
$stmt_admin_wallet->close();

// Fetch transaction history (limit 10, optional type filter) — include related request metadata if available
$transaction_history = [];
$tx_type = (isset($_GET['tx_type']) && in_array(strtolower($_GET['tx_type']), ['deposit','withdrawal'])) ? strtolower($_GET['tx_type']) : null;
if ($tx_type) {
    $stmt_history = $conn->prepare("
        SELECT * FROM (
            SELECT 
                t.transaction_id AS id,
                t.user_id,
                u.username,
                t.type,
                t.amount,
                c.symbol,
                t.timestamp,
                lu.request_timestamp AS ucr_request_timestamp,
                lu.decision_timestamp AS ucr_decision_timestamp,
                lu.payment_channel AS ucr_payment_channel,
                t.proof_of_screenshot,
                'approved' AS approval_status
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            JOIN currencies c ON t.currency_id = c.currency_id
            LEFT JOIN (
                SELECT u1.user_payment_id, u1.request_timestamp, u1.decision_timestamp, u1.payment_channel
                FROM user_currency_requests u1
                JOIN (
                    SELECT user_payment_id, MAX(COALESCE(decision_timestamp, request_timestamp)) AS max_ts
                    FROM user_currency_requests
                    GROUP BY user_payment_id
                ) m
                  ON m.user_payment_id = u1.user_payment_id
                 AND COALESCE(u1.decision_timestamp, u1.request_timestamp) = m.max_ts
            ) lu
              ON (t.user_payment_id IS NOT NULL AND t.user_payment_id <> '' AND lu.user_payment_id = t.user_payment_id)
            WHERE LOWER(t.type) = ?
            UNION ALL
            SELECT 
                ucr.request_id AS id,
                ucr.user_id,
                u.username,
                ucr.transaction_type AS type,
                ucr.amount,
                c.symbol,
                COALESCE(ucr.decision_timestamp, ucr.request_timestamp) AS timestamp,
                ucr.request_timestamp AS ucr_request_timestamp,
                ucr.decision_timestamp AS ucr_decision_timestamp,
                ucr.payment_channel AS ucr_payment_channel,
                ucr.proof_of_screenshot,
                'rejected' AS approval_status
            FROM user_currency_requests ucr
            JOIN users u ON ucr.user_id = u.user_id
            JOIN currencies c ON ucr.currency_id = c.currency_id
            WHERE ucr.status = 'rejected' AND LOWER(ucr.transaction_type) = ?
        ) x
        ORDER BY x.timestamp DESC
        LIMIT 10
    ");
    $stmt_history->bind_param("ss", $tx_type, $tx_type);
} else {
    $stmt_history = $conn->prepare("
        SELECT * FROM (
            SELECT 
                t.transaction_id AS id,
                t.user_id,
                u.username,
                t.type,
                t.amount,
                c.symbol,
                t.timestamp,
                lu.request_timestamp AS ucr_request_timestamp,
                lu.decision_timestamp AS ucr_decision_timestamp,
                lu.payment_channel AS ucr_payment_channel,
                t.proof_of_screenshot,
                'approved' AS approval_status
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            JOIN currencies c ON t.currency_id = c.currency_id
            LEFT JOIN (
                SELECT u1.user_payment_id, u1.request_timestamp, u1.decision_timestamp, u1.payment_channel
                FROM user_currency_requests u1
                JOIN (
                    SELECT user_payment_id, MAX(COALESCE(decision_timestamp, request_timestamp)) AS max_ts
                    FROM user_currency_requests
                    GROUP BY user_payment_id
                ) m
                  ON m.user_payment_id = u1.user_payment_id
                 AND COALESCE(u1.decision_timestamp, u1.request_timestamp) = m.max_ts
            ) lu
              ON (t.user_payment_id IS NOT NULL AND t.user_payment_id <> '' AND lu.user_payment_id = t.user_payment_id)
            UNION ALL
            SELECT 
                ucr.request_id AS id,
                ucr.user_id,
                u.username,
                ucr.transaction_type AS type,
                ucr.amount,
                c.symbol,
                COALESCE(ucr.decision_timestamp, ucr.request_timestamp) AS timestamp,
                ucr.request_timestamp AS ucr_request_timestamp,
                ucr.decision_timestamp AS ucr_decision_timestamp,
                ucr.payment_channel AS ucr_payment_channel,
                ucr.proof_of_screenshot,
                'rejected' AS approval_status
            FROM user_currency_requests ucr
            JOIN users u ON ucr.user_id = u.user_id
            JOIN currencies c ON ucr.currency_id = c.currency_id
            WHERE ucr.status = 'rejected'
        ) x
        ORDER BY x.timestamp DESC
        LIMIT 10
    ");
}
$stmt_history->execute();
$result_history = $stmt_history->get_result();
while ($row = $result_history->fetch_assoc()) { $transaction_history[] = $row; }
$stmt_history->close();

// Fetch admin deposit history
$admin_deposit_history = [];
$stmt_admin_deposit_history = $conn->prepare("
    SELECT at.*, c.symbol
    FROM admin_transactions at
    JOIN currencies c ON at.currency_id = c.currency_id
    WHERE at.admin_id = ?
    ORDER BY at.timestamp DESC
");
$stmt_admin_deposit_history->bind_param("i", $admin_id);
$stmt_admin_deposit_history->execute();
$result_admin_deposit_history = $stmt_admin_deposit_history->get_result();
while ($row = $result_admin_deposit_history->fetch_assoc()) {
    $admin_deposit_history[] = $row;
}
$stmt_admin_deposit_history->close();

// Fetch bank deposit history (admin deposits from bank to wallet)
$bank_deposit_history = [];
$bank_date_filter = isset($_GET['bank_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bank_date']) ? $_GET['bank_date'] : null;
// Ensure table exists before selecting (safe check)
$tbl_exists_rs = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '" . $conn->real_escape_string($dbname) . "' AND table_name = 'bank_deposit_history'");
if ($tbl_exists_rs && $tbl_exists_rs->num_rows > 0) {
    if ($bank_date_filter) {
        $stmt_bhist = $conn->prepare("SELECT h.*, c.symbol FROM bank_deposit_history h JOIN currencies c ON h.currency_id = c.currency_id WHERE DATE(h.created_at) = ? ORDER BY h.created_at DESC LIMIT 200");
        $stmt_bhist->bind_param("s", $bank_date_filter);
    } else {
        $stmt_bhist = $conn->prepare("SELECT h.*, c.symbol FROM bank_deposit_history h JOIN currencies c ON h.currency_id = c.currency_id ORDER BY h.created_at DESC LIMIT 200");
    }
    if ($stmt_bhist) {
        $stmt_bhist->execute();
        $res_bhist = $stmt_bhist->get_result();
        while ($row = $res_bhist->fetch_assoc()) { $bank_deposit_history[] = $row; }
        $stmt_bhist->close();
    }
}

// Fetch conversion fees for profit tracking
$conversion_fees = [];
$daily_fees = [];
$stmt_fees = $conn->prepare("
    SELECT 
        cf.fee_id,
        cf.user_id,
        cf.amount_converted,
        cf.tax_amount,
        cf.tax_rate,
        cf.timestamp,
        u.username,
        c1.symbol AS from_symbol,
        c2.symbol AS to_symbol
    FROM conversion_fees cf
    JOIN users u ON cf.user_id = u.user_id
    JOIN currencies c1 ON cf.from_currency_id = c1.currency_id
    JOIN currencies c2 ON cf.to_currency_id = c2.currency_id
    ORDER BY cf.timestamp DESC
    LIMIT 100
");
$stmt_fees->execute();
$result_fees = $stmt_fees->get_result();
while ($row = $result_fees->fetch_assoc()) {
    $conversion_fees[] = $row;
}
$stmt_fees->close();

// Calculate daily fees grouped by currency
$stmt_daily = $conn->prepare("
    SELECT 
        c.symbol,
        DATE(cf.timestamp) as fee_date,
        SUM(cf.tax_amount) as total_fees,
        COUNT(*) as conversion_count
    FROM conversion_fees cf
    JOIN currencies c ON cf.from_currency_id = c.currency_id
    WHERE DATE(cf.timestamp) = CURDATE()
    GROUP BY c.symbol, DATE(cf.timestamp)
");
$stmt_daily->execute();
$result_daily = $stmt_daily->get_result();
while ($row = $result_daily->fetch_assoc()) {
    $daily_fees[] = $row;
}
$stmt_daily->close();

// Registration chart data and YEAR filter (dynamic options)
$registration_chart = ['labels' => [], 'data' => [], 'year' => (int)date('Y')];
$allowed_years = [];
// Determine registration timestamp column in users table
$possible_cols = ['created_at','registration_date','registered_at','created_on','signup_at','signup_date','created','timestamp','reg_time','reg_date'];
$placeholders = implode(',', array_fill(0, count($possible_cols), '?'));
$types = str_repeat('s', count($possible_cols) + 1);
$query = "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name IN ($placeholders) ORDER BY FIELD(column_name, '" . implode("','", $possible_cols) . "') LIMIT 1";
$stmt_col = $conn->prepare($query);
$params = array_merge([$dbname], $possible_cols);
$stmt_col->bind_param($types, ...$params);
$stmt_col->execute();
$res_col = $stmt_col->get_result();
$reg_col = null;
if ($row = $res_col->fetch_assoc()) { $reg_col = $row['column_name']; }
$stmt_col->close();

if ($reg_col) {
    // Determine available year range from user registrations
    $yearRes = $conn->query("SELECT MIN(YEAR($reg_col)) AS min_y, MAX(YEAR($reg_col)) AS max_y FROM users WHERE $reg_col IS NOT NULL");
    $yrRow = $yearRes ? $yearRes->fetch_assoc() : null;
    $minY = isset($yrRow['min_y']) && $yrRow['min_y'] ? (int)$yrRow['min_y'] : (int)date('Y');
    $maxY = isset($yrRow['max_y']) && $yrRow['max_y'] ? (int)$yrRow['max_y'] : (int)date('Y');
    // Always include the current year even if there are no registrations yet
    $maxY = max($maxY, (int)date('Y'));
    for ($y = $maxY; $y >= $minY; $y--) { $allowed_years[] = $y; }

    // Use selected year if provided; otherwise default to current year, clamped to range
    $selected_year = isset($_GET['reg_year']) ? (int)$_GET['reg_year'] : (int)date('Y');
    if (!in_array($selected_year, $allowed_years, true)) {
        // Clamp to nearest valid year within range
        $selected_year = min(max($selected_year, $minY), $maxY);
    }
    $registration_chart['year'] = $selected_year;

    // Build daily counts for selected YEAR
    $yr = (int)$selected_year;
    $sql = "SELECT DATE($reg_col) AS d, COUNT(*) AS c FROM users WHERE YEAR($reg_col) = $yr GROUP BY DATE($reg_col) ORDER BY d";
    $res = $conn->query($sql);
    $labels = []; $data = [];
    while ($r = $res->fetch_assoc()) {
        $labels[] = date('j-n-Y', strtotime($r['d']));
        $data[] = (int)$r['c'];
    }
    $registration_chart = ['labels' => $labels, 'data' => $data, 'year' => $selected_year];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>Admin Dashboard & Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #ef4444;
            color: white;
            font-size: 0.75rem;
            font-weight: bold;
        }

        /* Admin theme palette */
        .admin-theme {
            --admin-bg: #182233;         /* medium-dark background */
            --admin-surface: #202B3D;    /* elevated surface */
            --admin-primary: #14B8A6;    /* teal */
            --admin-primary-2: #2DD4BF;  /* teal accent */
            --admin-text: #E8EEF6;       /* light text */
            --admin-text-muted: #BAC6D8; /* muted text */
            --admin-border: rgba(255, 255, 255, 0.12);
            --admin-shadow: rgba(0, 0, 0, 0.5);
        }

        /* Dark theme overrides using admin palette */
        .dark-theme { background-color: var(--admin-bg); color: var(--admin-text); }
        .dark-theme .bg-white { background-color: var(--admin-surface) !important; }
        .dark-theme .bg-gray-50, .dark-theme .bg-indigo-50 { background-color: var(--admin-bg) !important; }
        .dark-theme .bg-gray-100 { background-color: var(--admin-bg) !important; }
        .dark-theme .text-gray-900, .dark-theme .text-gray-800 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-700 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-600, .dark-theme .text-gray-500 { color: var(--admin-text-muted) !important; }
        .dark-theme .border-gray-100, .dark-theme .border-gray-200 { border-color: var(--admin-border) !important; }
        .dark-theme .shadow-inner { box-shadow: inset 0 2px 4px 0 var(--admin-shadow) !important; }
        .dark-theme .shadow-lg, .dark-theme .shadow-md, .dark-theme .shadow-xl { box-shadow: 0 10px 15px -3px var(--admin-shadow), 0 4px 6px -4px var(--admin-shadow) !important; }
        .dark-theme nav a { color: var(--admin-text-muted) !important; }
        .dark-theme nav a:hover { color: var(--admin-primary) !important; }
        .dark-theme nav:not(.glass-nav), .dark-theme .bg-white.p-6.rounded-xl.shadow-lg, .dark-theme .bg-white.p-6.shadow-lg.rounded-xl { background-color: var(--admin-surface) !important; }
        .dark-theme .rounded-xl, .dark-theme .rounded-lg { border: 1px solid var(--admin-border); }
        .dark-theme .no-border { border: 0 !important; }
        .dark-theme .text-indigo-800 { color: var(--admin-text) !important; }
        .dark-theme .border-indigo-200 { border-color: var(--admin-border) !important; }

        /* Map Tailwind utility colors used in markup to the admin palette within the admin scope */
        .dark-theme .text-blue-600, .dark-theme .text-indigo-600, .dark-theme .text-indigo-700 { color: var(--admin-primary) !important; }
        .dark-theme .hover\:text-blue-600:hover { color: var(--admin-primary) !important; }
        .dark-theme .bg-blue-600, .dark-theme .bg-indigo-600 { background-color: var(--admin-primary) !important; }
        .dark-theme .hover\:bg-indigo-700:hover, .dark-theme .hover\:bg-blue-700:hover { background-color: var(--admin-primary-2) !important; }
        .dark-theme .bg-indigo-100 { background-color: rgba(20, 184, 166, 0.12) !important; }
        .dark-theme .text-green-700 { color: var(--admin-primary) !important; }
        .dark-theme .text-indigo-700 { color: var(--admin-primary) !important; }
        .dark-theme .border-gray-100 { border-color: var(--admin-border) !important; }
        /* Hover lift utility */
        .hover-lift { transition: transform 150ms ease, box-shadow 150ms ease; }
        .hover-lift:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 20px 25px -5px var(--admin-shadow), 0 10px 10px -5px var(--admin-shadow); }
        /* Dark inputs/selects */
        .dark-theme select, .dark-theme input, .dark-theme textarea { background-color: var(--admin-surface); border-color: var(--admin-border); color: var(--admin-text); }
        .glass-nav { background: rgba(24, 34, 51, 0.7) !important; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid var(--admin-border); }
    </style>
</head>
<body class="font-sans leading-normal tracking-normal admin-theme dark-theme">
    <?php if (!empty($message) && (preg_match('/^(Error|Insufficient)/', $message))): ?>
    <div id="popup-overlay" class="fixed inset-0 z-50 flex items-center justify-center">
        <div id="popup-backdrop" class="absolute inset-0 bg-black bg-opacity-50"></div>
        <div class="relative bg-white text-red-700 border border-red-400 rounded-lg shadow-2xl max-w-lg w-[90%] p-6 z-10">
            <div class="flex items-start gap-3">
                <i class="fas fa-exclamation-triangle text-red-600 mt-1.5"></i>
                <div class="flex-1"><?php echo htmlspecialchars($message); ?></div>
                <button id="popup-close" class="text-red-700 hover:text-red-900 text-2xl leading-none" aria-label="Close">&times;</button>
            </div>
        </div>
    </div>
    <script>
      (function(){
        const ov = document.getElementById('popup-overlay');
        const bd = document.getElementById('popup-backdrop');
        const cl = document.getElementById('popup-close');
        const close = ()=> { if (ov) ov.remove(); };
        if (bd) bd.addEventListener('click', close);
        if (cl) cl.addEventListener('click', close);
        document.addEventListener('keydown', (e)=> { if (e.key === 'Escape') close(); });
      })();
    </script>
    <?php endif; ?>
    <nav class="glass-nav w-full p-3 md:p-4 shadow-md">
        <div class="w-full max-w-7xl mx-auto flex items-center justify-between">
            <div class="text-lg md:text-xl font-bold text-gray-800">Admin Panel</div>
            <div class="flex items-center space-x-6">
                <a href="admin.php" class="<?php echo (!$show_requests && !$show_history && !$show_deposit && !$show_exchange_rate && !$show_fees) ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Dashboard">
                    <i class="fas fa-home text-2xl"></i>
                    <span class="sr-only">Dashboard</span>
                </a>
                <a href="admin_user_management.php" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="User Management">
                    <i class="fas fa-users-cog text-2xl"></i>
                    <span class="sr-only">User Management</span>
                </a>
                <a href="admin.php?view=requests" class="relative <?php echo $show_requests ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Notifications">
                    <i class="fas fa-bell text-2xl"></i>
                    <?php if (isset($badge_count) && $badge_count > 0): ?>
                        <span class="notification-badge"><?php echo $badge_count; ?></span>
                    <?php endif; ?>
                    <span class="sr-only">Notifications</span>
                </a>
                <a href="admin.php?view=history" class="<?php echo $show_history ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Transaction History">
                    <i class="fas fa-history text-2xl"></i>
                    <span class="sr-only">Transaction History</span>
                </a>
                <a href="admin.php?view=fees" class="<?php echo $show_fees ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Conversion Fees">
                    <i class="fas fa-coins text-2xl"></i>
                    <span class="sr-only">Conversion Fees</span>
                </a>
                <a href="admin.php?view=exchange_rate" class="<?php echo $show_exchange_rate ? 'text-blue-600 font-bold' : 'text-gray-700 hover:text-blue-600 font-medium'; ?> text-xl" title="Exchange Rate">
                    <i class="fas fa-exchange-alt text-2xl"></i>
                    <span class="sr-only">Exchange Rate</span>
                </a>
                <a href="admin_logout.php" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Logout">
                    <i class="fas fa-sign-out-alt text-2xl"></i>
                    <span class="sr-only">Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg no-border">
      
            <?php if (!$show_requests && !$show_all_requests && !$show_history && !$show_deposit && !$show_exchange_rate && !$show_fees): ?>
            <?php
                // Initialize balances for all wallet types
                $mmk_balance = 0; 
                $usd_balance = 0; 
                $thb_balance = 0;
                $sgd_balance = 0;
                $mmk_currency_id = null;
                
                // Set wallet balances
                foreach ($admin_wallets as $w) {
                    if ($w['symbol'] === 'MMK') { $mmk_balance = $w['balance']; }
                    if ($w['symbol'] === 'USD') { $usd_balance = $w['balance']; }
                    if ($w['symbol'] === 'THB') { $thb_balance = $w['balance']; }
                    if ($w['symbol'] === 'SGD') { $sgd_balance = $w['balance']; }
                    if ($w['symbol'] === 'MMK') { $mmk_balance = $w['balance']; }
                    if ($w['symbol'] === 'USD') { $usd_balance = $w['balance']; }
                }
                foreach ($currencies as $cur) { if ($cur['symbol'] === 'MMK') { $mmk_currency_id = $cur['currency_id']; break; } }
            ?>

            <div class="max-w-7xl mx-auto">
                <h1 class="text-3xl font-extrabold text-gray-800 mb-6">System Dashboard Overview</h1>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-white p-6 shadow-xl rounded-xl border border-gray-100 h-full flex flex-col items-center">
                            <div class="flex-shrink-0 mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-28 w-28 text-indigo-600 bg-indigo-100 rounded-full p-5 border-4 border-white shadow-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-800">Admin</h2>
                            <p class="text-sm text-gray-500 mb-8">System Administrator</p>

                            <div class="w-full grid grid-cols-2 gap-4 mb-8 p-4 bg-gray-50 rounded-lg shadow-inner border border-gray-200">
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">MMK Wallet</p>
                                    <p class="text-xl font-extrabold text-green-600 mt-1"><?php echo number_format($mmk_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">USD Wallet</p>
                                    <p class="text-xl font-extrabold text-blue-600 mt-1"><?php echo number_format($usd_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">THB Wallet</p>
                                    <p class="text-xl font-extrabold text-purple-600 mt-1"><?php echo number_format($thb_balance, 2); ?></p>
                                </div>
                                <div class="text-center p-3 bg-white rounded-lg border border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">SGD Wallet</p>
                                    <p class="text-xl font-extrabold text-amber-600 mt-1"><?php echo number_format($sgd_balance, 2); ?></p>
                                </div>
                            </div>

                            <div class="w-full mt-auto p-4 bg-white rounded-xl border border-gray-100">
                                <h3 class="text-lg font-semibold mb-3">Deposit from Bank</h3>
                                <button type="button" onclick="openDepositModal()" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg transition duration-150 shadow-md">
                                    Deposit
                                </button>
                            </div>
                        </div>

                    </div>

                    <div class="lg:col-span-2 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 hover:shadow-xl transition duration-300">
                                <h3 class="text-xl font-bold text-gray-800 mb-4">Active Users</h3>
                                <div class="flex items-center space-x-3">
                                    <span class="text-5xl font-extrabold text-green-700"><?php echo $active_users; ?></span>
                                </div>
                            </div>
                            <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 hover:shadow-xl transition duration-300">
                                <h3 class="text-xl font-bold text-gray-800 mb-4">Total Users</h3>
                                <div class="flex items-center">
                                    <span class="text-5xl font-extrabold text-indigo-700"><?php echo $total_users; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white p-6 shadow-lg rounded-xl border border-gray-100 min-h-80">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-xl font-bold text-gray-800">User Registration Chart</h3>
                                <div>
                                    <?php if (empty($allowed_years)) { $allowed_years = [(int)date('Y')]; } ?>
                                    <select id="regYear" class="p-2 border border-gray-300 rounded-lg text-sm">
                                        <?php foreach ($allowed_years as $y): ?>
                                            <option value="<?php echo (int)$y; ?>" <?php echo ((int)$registration_chart['year']===(int)$y)?'selected':''; ?>><?php echo (int)$y; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="w-full">
                                <canvas id="regChart" height="224"></canvas>
                            </div>
                            <script>
                                (function(){
                                    const labels = <?php echo json_encode($registration_chart['labels']); ?> || [];
                                    const data = <?php echo json_encode($registration_chart['data']); ?> || [];
                                    const canvas = document.getElementById('regChart');
                                    const urlParams = new URLSearchParams(window.location.search);
                                    const currentYear = urlParams.get('reg_year') || String(<?php echo json_encode((string)$registration_chart['year']); ?>);
                                    if (!canvas) return;
                                    const container = canvas.parentElement;
                                    let empty = document.getElementById('regChartEmpty');
                                    if (!labels.length) {
                                        canvas.style.display = 'none';
                                        if (!empty) {
                                            empty = document.createElement('div');
                                            empty.id = 'regChartEmpty';
                                            empty.className = 'text-center text-gray-500 py-8';
                                            empty.textContent = 'No registrations found for ' + currentYear;
                                            container.appendChild(empty);
                                        } else {
                                            empty.textContent = 'No registrations found for ' + currentYear;
                                            empty.style.display = '';
                                        }
                                        return;
                                    } else {
                                        if (empty) empty.style.display = 'none';
                                        canvas.style.display = '';
                                    }
                                    const ctx = canvas.getContext('2d');
                                    const chart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: labels,
                                            datasets: [{
                                                label: 'Registrations',
                                                data: data,
                                                borderColor: '#6366f1',
                                                backgroundColor: 'rgba(99,102,241,0.2)',
                                                tension: 0.35,
                                                fill: true,
                                                pointRadius: 3,
                                                pointHoverRadius: 5,
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            scales: {
                                                x: { title: { display: true, text: 'Date' } },
                                                y: { beginAtZero: true, title: { display: true, text: 'New Users' }, ticks: { stepSize: 1 } }
                                            },
                                            plugins: {
                                                legend: { display: false },
                                                tooltip: { callbacks: { label: (ctx) => ` ${ctx.parsed.y} registrations` } }
                                            }
                                        }
                                    });

                                    // Year switch
                                    const yearDD = document.getElementById('regYear');
                                    if (yearDD) {
                                        yearDD.addEventListener('change', function(){
                                            const url = new URL(window.location.href);
                                            url.searchParams.set('reg_year', this.value);
                                            url.searchParams.set('_', Date.now().toString()); // cache-buster
                                            window.location.assign(url.toString());
                                        });
                                    }
                                })();
                            </script>
                        </div>
                    </div>
                </div>

                <!-- Bank Deposit History (positioned under the overview grid) -->
                <div class="bg-white p-6 shadow-lg rounded-xl no-border mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800">Bank Deposit History</h3>
                        <div class="flex items-center space-x-2">
                            <label class="text-sm text-gray-600" for="bank_date">Date</label>
                            <style>
                                /* Make calendar icon white for the date input */
                                #bank_date::-webkit-calendar-picker-indicator { filter: invert(1) brightness(1.6); opacity: 1; }
                                /* Optional: ensure consistent look in dark schemes */
                                #bank_date { color-scheme: light; }
                            </style>
                            <input id="bank_date" name="bank_date" type="date"
                                   min="<?php echo htmlspecialchars($admin_history_min_date ?? ''); ?>"
                                   max="<?php echo htmlspecialchars(date('Y-m-d')); ?>"
                                   value="<?php echo htmlspecialchars($_GET['bank_date'] ?? ''); ?>"
                                   class="p-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <script>
                            (function(){
                                const input = document.getElementById('bank_date');
                                if (!input) return;
                                const minDate = input.getAttribute('min');
                                const maxDate = input.getAttribute('max');
                                function isInvalid(val){
                                    if (!val) return false;
                                    if (maxDate && val > maxDate) return true;
                                    if (minDate && val < minDate) return true;
                                    return false;
                                }
                                input.addEventListener('change', function(){
                                    if (isInvalid(this.value)){
                                        alert("You can't choose this date.");
                                        this.value = '';
                                        return;
                                    }
                                    const url = new URL(window.location.href);
                                    if (this.value) { url.searchParams.set('bank_date', this.value); }
                                    else { url.searchParams.delete('bank_date'); }
                                    url.searchParams.set('_', Date.now().toString());
                                    window.location.assign(url.toString());
                                });
                            })();
                        </script>
                    </div>
                    <div class="overflow-x-auto">
                        <style>
                            .no-border { border: 0 !important; }
                            .no-border .bank-history-scroll,
                            .no-border .table-bank-history,
                            .no-border .table-bank-history thead tr,
                            .no-border .table-bank-history tbody tr { border-bottom: 0 !important; }
                            .no-border .table-bank-history tbody tr:last-child { border-bottom: 0 !important; }
                            .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
                            .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px rgba(0,0,0,0.5); background-color: #0b1220; }
                            /* Subtle faded dividers like user management */
                            .table-bank-history { border-collapse: separate; border-spacing: 0; }
                            .table-bank-history thead tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                            .table-bank-history tbody tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                            .table-bank-history tbody tr:last-child { border-bottom-color: transparent !important; }
                            /* Sticky header */
                            .table-bank-history thead th { position: sticky; top: 0; z-index: 10; background-color: #0b1220; }
                            /* Scrollbar theming for the bank history scroll area */
                            .bank-history-scroll { scrollbar-color: #374151 #0b1220; scrollbar-width: thin; }
                            .bank-history-scroll::-webkit-scrollbar { width: 10px; }
                            .bank-history-scroll::-webkit-scrollbar-track { background: #0b1220; border-radius: 8px; }
                            .bank-history-scroll::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 8px; border: 2px solid #0b1220; }
                            .bank-history-scroll::-webkit-scrollbar-thumb:hover { background-color: #4b5563; }
                        </style>
                        <div class="overflow-y-auto bank-history-scroll" style="max-height: 480px;">
                        <style>
                            /* Enhanced danger icon button for delete */
                            .icon-btn-danger {
                                display: inline-flex; align-items: center; justify-content: center;
                                width: 34px; height: 34px;
                                border-radius: 0.5rem;
                                background: #ef4444; /* red-500 */
                                color: #fff; border: 1px solid rgba(239,68,68,0.35);
                                box-shadow: 0 2px 8px rgba(239,68,68,0.25);
                                transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease, border-color 120ms ease;
                            }
                            .icon-btn-danger:hover { background:#dc2626; border-color: rgba(220,38,38,0.45); box-shadow: 0 4px 14px rgba(220,38,38,0.35); transform: translateY(-1px); }
                            .icon-btn-danger:active { transform: translateY(0); box-shadow: 0 2px 8px rgba(220,38,38,0.25); }
                            .icon-btn-danger:focus { outline: none; box-shadow: 0 0 0 3px rgba(239,68,68,0.25), 0 4px 14px rgba(220,38,38,0.35); }
                        </style>
                        <table class="min-w-full table-bank-history">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Previous Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Current Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($bank_deposit_history)): ?>
                                    <?php foreach ($bank_deposit_history as $h): ?>
                                        <tr class="hover-lift-row">
                                            <td class="px-6 py-3 text-sm text-gray-800 font-semibold">
                                                <?php echo number_format((float)$h['amount'], 2) . ' ' . htmlspecialchars($h['symbol']); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo htmlspecialchars(date('Y-m-d', strtotime($h['created_at']))); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-600">
                                                <?php echo htmlspecialchars(date('H:i:s', strtotime($h['created_at']))); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo number_format((float)$h['previous_balance'], 2); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-800">
                                                <?php echo number_format((float)$h['after_balance'], 2); ?>
                                            </td>
                                            <td class="px-6 py-3 text-sm">
                                                <button type="button" aria-label="Delete record" title="Delete"
                                                    data-hist-id="<?php echo (int)$h['id']; ?>"
                                                    class="open-del-modal icon-btn-danger">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                                        <path d="M9 3a1 1 0 0 0-1 1v1H5.5a.75.75 0 0 0 0 1.5h13a.75.75 0 0 0 0-1.5H16V4a1 1 0 0 0-1-1H9zm-3 5.5a.75.75 0 0 1 .75.75v9A2.75 2.75 0 0 0 9.5 21h5a2.75 2.75 0 0 0 2.75-2.75v-9a.75.75 0 0 1 1.5 0v9A4.25 4.25 0 0 1 14.5 22.5h-5A4.25 4.25 0 0 1 5.25 18.25v-9A.75.75 0 0 1 6 8.5zm4 .75a.75.75 0 0 0-1.5 0v8a.75.75 0 0 0 1.5 0v-8zm5 0a.75.75 0 0 0-1.5 0v8a.75.75 0 0 0 1.5 0v-8z"/>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-6 text-center text-gray-500">No bank deposit history<?php echo !empty($_GET['bank_date']) ? ' for '.htmlspecialchars($_GET['bank_date']) : ''; ?>.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <div id="delModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
                    <div class="absolute inset-0 bg-black bg-opacity-50" onclick="(function(){ const m = document.getElementById('delModal'); if(m) m.classList.add('hidden'); })()"></div>
                    <div class="relative bg-gray-900 text-gray-100 rounded-xl shadow-2xl w-11/12 max-w-sm overflow-hidden border border-gray-700">
                        <div class="px-5 py-4 border-b border-gray-700">
                            <h4 class="text-base font-semibold text-gray-100">Delete Record</h4>
                        </div>
                        <div class="px-5 py-4 text-sm text-gray-300">
                            Are you sure you want to delete this deposit history record?
                        </div>
                        <div class="px-5 py-4 flex justify-end space-x-2 border-t border-gray-700">
                            <button type="button" id="btnDelCancel" class="px-3 py-1.5 text-sm rounded-md border border-gray-600 bg-gray-800 text-gray-200 hover:bg-gray-700">Cancel</button>
                            <button type="button" id="btnDelConfirm" class="px-3 py-1.5 text-sm rounded-md border border-red-700 text-white bg-red-600 hover:bg-red-700">Delete</button>
                        </div>
                    </div>
                </div>

                <form id="delForm" method="POST" class="hidden">
                    <input type="hidden" name="action" value="delete_bank_history">
                    <input type="hidden" name="history_id" id="delHistId" value="">
                    <input type="hidden" name="bank_date" value="<?php echo htmlspecialchars($_GET['bank_date'] ?? ''); ?>">
                </form>

                <script>
                    (function(){
                        const modal = document.getElementById('delModal');
                        const btnCancel = document.getElementById('btnDelCancel');
                        const btnConfirm = document.getElementById('btnDelConfirm');
                        const delForm = document.getElementById('delForm');
                        const delIdField = document.getElementById('delHistId');
                        let currentId = null;
                        function openModal(id){ currentId = id; delIdField.value = id; modal.classList.remove('hidden'); }
                        function closeModal(){ modal.classList.add('hidden'); currentId = null; }
                        document.querySelectorAll('.open-del-modal').forEach(btn => {
                            btn.addEventListener('click', () => openModal(btn.getAttribute('data-hist-id')));
                        });
                        btnCancel.addEventListener('click', closeModal);
                        btnConfirm.addEventListener('click', () => { if (currentId){ delForm.submit(); } });
                        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
                    })();
                </script>

                <!-- User Management section removed from dashboard as requested -->

                <script>
                    function openDepositModal() {
                        const m = document.getElementById('depositModal');
                        if (m) m.classList.remove('hidden');
                    }
                    function closeDepositModal() {
                        const m = document.getElementById('depositModal');
                        if (m) m.classList.add('hidden');
                    }
                </script>
            </div>
            <?php endif; ?>

            <!-- Deposit Modal -->
            <div id="depositModal" class="hidden fixed inset-0 z-50">
                <div class="absolute inset-0 bg-black bg-opacity-50" onclick="closeDepositModal()"></div>
                <div class="relative mx-auto my-8 bg-white rounded-xl shadow-2xl w-11/12 max-w-2xl h-[70vh] overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 border-b">
                        <h3 class="text-lg font-semibold">Deposit</h3>
                        <button class="text-gray-500 hover:text-gray-800" onclick="closeDepositModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <iframe src="deposit.php" class="w-full h-[calc(70vh-52px)]"></iframe>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    const sel = document.getElementById('tx_type');
                    if (!sel) return;
                    sel.addEventListener('change', function(){
                        const url = new URL(window.location.href);
                        if (this.value) url.searchParams.set('tx_type', this.value); else url.searchParams.delete('tx_type');
                        url.searchParams.set('_', Date.now().toString());
                        window.location.assign(url.toString());
                    });
                });
            </script>

            <div class="flex items-center space-x-4 mb-6 <?php echo (!$show_wallet && !$show_all_requests && !$show_history && !$show_deposit && !$show_exchange_rate) ? 'hidden' : ''; ?>">
                <div class="bg-gray-700 rounded-full h-12 w-12 flex items-center justify-center text-white text-xl font-bold">
                    <?php if ($show_wallet): ?>
                        <i class="fas fa-wallet"></i>
                    <?php elseif ($show_all_requests): ?>
                        <i class="fas fa-list-alt"></i>
                    <?php elseif ($show_history): ?>
                        <i class="fas fa-history"></i>
                    <?php elseif ($show_deposit): ?>
                        <i class="fas fa-plus-circle"></i>
                    <?php elseif ($show_exchange_rate): ?>
                        <i class="fas fa-chart-line"></i>
                    <?php else: ?>
                        <i class="fas fa-user-shield"></i>
                    <?php endif; ?>
                </div>
                
                <div>
                    <?php if ($show_all_requests): ?>
                        <h2 class="text-2xl font-bold text-gray-800">Requests History</h2>
                        <p class="text-gray-500">A complete log of all user requests and their statuses.</p>
                    <?php elseif ($show_exchange_rate): ?>
                        <h2 class="text-2xl font-bold text-gray-800">Exchange Rate Management</h2>
                        <p class="text-gray-500">Sync live rates from API with custom markup for your country.</p>
                    <?php elseif ($show_history): ?>
                        <h2 class="text-2xl font-bold text-gray-800">User History</h2>
                        <p class="text-gray-500">Manage User's Transaction History.</p>
                    <?php else: ?>
                        <h2 class="text-2xl font-bold text-gray-800">Admin Dashboard</h2>
                        <p class="text-gray-500">Manage all users.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            
            <div id="user-list-section" class="hidden">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Registered Users</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($users) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full table-bank-history">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">User ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Email</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['user_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No registered users found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="requests-section" class="<?php echo $show_requests ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Pending User Requests (<?php echo $pending_count; ?>)</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($pending_requests) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($pending_requests as $request): ?>
                                <div class="bg-white p-4 rounded-lg shadow border border-gray-200">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-lg font-bold text-gray-800">
                                            <?php echo htmlspecialchars(ucfirst($request['transaction_type'])); ?> Request #<?php echo htmlspecialchars($request['request_id']); ?>
                                        </span>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm text-gray-500"><?php echo htmlspecialchars($request['request_timestamp']); ?></span>
                                            <?php 
                                                $reqStr = !empty($request['request_timestamp']) ? date('M j, Y H:i', strtotime($request['request_timestamp'])) : '—';
                                                $decStr = !empty($request['decision_timestamp']) ? date('M j, Y H:i', strtotime($request['decision_timestamp'])) : '—';
                                                $rawCh  = trim((string)($request['payment_channel'] ?? ''));
                                                $keyCh  = strtolower($rawCh);
                                                if ($rawCh !== '') {
                                                    if ($keyCh === 'kpay') { $rawCh = 'KPay'; }
                                                    elseif ($keyCh === 'ayapay') { $rawCh = 'AyaPay'; }
                                                    elseif ($keyCh === 'wavepay' || $keyCh === 'wave') { $rawCh = 'WavePay'; }
                                                } else { $rawCh = '—'; }
                                            ?>
                                            <button type="button" title="Details"
                                                class="open-details inline-flex items-center px-2 py-1 border border-gray-300 rounded-md shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                                data-requested="<?php echo htmlspecialchars($reqStr); ?>"
                                                data-decision="<?php echo htmlspecialchars($decStr); ?>"
                                                data-channel="<?php echo htmlspecialchars($rawCh); ?>"
                                                data-proof="<?php echo htmlspecialchars($request['proof_of_screenshot'] ?? ''); ?>">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <p class="text-gray-700"><span class="font-medium">User:</span> <?php echo htmlspecialchars($request['username']); ?></p>
                                    <p class="text-gray-700"><span class="font-medium">Amount:</span> <?php echo number_format($request['amount'], 2) . ' ' . htmlspecialchars($request['symbol']); ?></p>
                                    <?php if ($request['transaction_type'] == 'deposit' && !empty($request['user_payment_id'])): ?>
                                        <p class="text-gray-700"><span class="font-medium">Transaction ID:</span> <?php echo htmlspecialchars($request['user_payment_id']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($request['transaction_type'] == 'deposit' && $request['proof_of_screenshot']): ?>
                                        <p class="mt-2">
                                            <a href="<?php echo htmlspecialchars($request['proof_of_screenshot']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">
                                                View Proof of Payment
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($request['description']): ?>
                                        <p class="text-gray-700 mt-2"><span class="font-medium">Details:</span> <?php echo htmlspecialchars($request['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-4 flex space-x-2">
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="flex-1">
                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <button type="submit" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-opacity-50 transition duration-200">
                                                <i class="fas fa-check-circle mr-2"></i>Confirm
                                            </button>
                                        </form>
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="flex-1">
                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="w-full bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-500 focus:ring-opacity-50 transition duration-200">
                                                <i class="fas fa-times-circle mr-2"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-clipboard-check text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No pending requests at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Wallet section removed as requested -->

            <div id="transaction-history-section" class="<?php echo $show_history ? '' : 'hidden'; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Transaction History</h3>
                    <div class="flex items-center space-x-2">
                        <label for="tx_type" class="text-sm text-gray-600">Type</label>
                        <select id="tx_type" class="p-2 border border-gray-300 rounded-lg text-sm">
                            <option value="" <?php echo empty($_GET['tx_type']) ? 'selected' : ''; ?>>All</option>
                            <option value="deposit" <?php echo (isset($_GET['tx_type']) && $_GET['tx_type']==='deposit') ? 'selected' : ''; ?>>Deposit</option>
                            <option value="withdrawal" <?php echo (isset($_GET['tx_type']) && $_GET['tx_type']==='withdrawal') ? 'selected' : ''; ?>>Withdrawal</option>
                        </select>
                    </div>
                </div>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php 
                        $filtered_history = $transaction_history;
                        if (!empty($tx_type)) {
                            $filtered_history = array_values(array_filter($transaction_history, function($row) use ($tx_type){
                                return isset($row['type']) && strtolower($row['type']) === $tx_type;
                            }));
                        }
                    ?>
                    <?php if (count($filtered_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <style>
                                /* Match Bank Deposit History styles */
                                .table-bank-history { border-collapse: separate; border-spacing: 0; }
                                .table-bank-history thead tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                                .table-bank-history tbody tr { border-bottom: 1px solid rgba(255,255,255,0.07) !important; }
                                .table-bank-history tbody tr:last-child { border-bottom-color: rgba(255,255,255,0.04) !important; }
                                .table-bank-history thead th { position: sticky; top: 0; z-index: 10; background-color: #0b1220 !important; }
                                .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
                                .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px rgba(0,0,0,0.5); background-color: #0b1220; }
                                .bank-history-scroll { scrollbar-color: #374151 #0b1220; scrollbar-width: thin; }
                                .bank-history-scroll::-webkit-scrollbar { width: 10px; }
                                .bank-history-scroll::-webkit-scrollbar-track { background: #0b1220; border-radius: 8px; }
                                .bank-history-scroll::-webkit-scrollbar-thumb { background-color: #374151; border-radius: 8px; border: 2px solid #0b1220; }
                                .bank-history-scroll::-webkit-scrollbar-thumb:hover { background-color: #4b5563; }
                            </style>
                            <div class="overflow-y-auto bank-history-scroll" style="max-height: 480px;">
                            <table class="min-w-full table-bank-history">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">User</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Decision</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($filtered_history as $transaction): ?>
                                        <tr class="hover-lift-row">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($transaction['username'] ?? ''); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $type = $transaction['type'];
                                                    echo htmlspecialchars(ucfirst($type));
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo number_format($transaction['amount'], 2) . ' ' . htmlspecialchars($transaction['symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($transaction['timestamp']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $decision = strtolower($transaction['approval_status'] ?? 'approved');
                                                    if ($decision === 'rejected') {
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Rejected</span>';
                                                    } else {
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Approved</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php 
                                                    $reqStr = !empty($transaction['ucr_request_timestamp']) ? date('M j, Y H:i', strtotime($transaction['ucr_request_timestamp'])) : '—';
                                                    $decStr = !empty($transaction['ucr_decision_timestamp']) ? date('M j, Y H:i', strtotime($transaction['ucr_decision_timestamp'])) : '—';
                                                    $rawCh  = trim((string)($transaction['ucr_payment_channel'] ?? ''));
                                                    $keyCh  = strtolower($rawCh);
                                                    if ($rawCh !== '') {
                                                        if ($keyCh === 'kpay') { $rawCh = 'KPay'; }
                                                        elseif ($keyCh === 'ayapay') { $rawCh = 'AyaPay'; }
                                                        elseif ($keyCh === 'wavepay' || $keyCh === 'wave') { $rawCh = 'WavePay'; }
                                                    } else { $rawCh = '—'; }
                                                ?>
                                                <button type="button" title="Details"
                                                    class="open-details inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md shadow-sm text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                                    data-requested="<?php echo htmlspecialchars($reqStr); ?>"
                                                    data-decision="<?php echo htmlspecialchars($decStr); ?>"
                                                    data-channel="<?php echo htmlspecialchars($rawCh); ?>"
                                                    data-proof="<?php echo htmlspecialchars($transaction['proof_of_screenshot'] ?? ''); ?>">
                                                    <i class="fas fa-info-circle mr-1"></i> Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-history text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No <?php echo htmlspecialchars($tx_type ?: 'transactions'); ?> found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Standalone Deposit section removed as requested -->

            <!-- CONVERSION FEES SECTION -->
            <div id="fees-section" class="<?php echo $show_fees ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-coins mr-2 text-yellow-500"></i>Today's Conversion Fees (Profit)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <?php if (count($daily_fees) > 0): ?>
                        <?php foreach ($daily_fees as $daily): ?>
                            <div class="bg-gradient-to-r from-green-400 to-green-600 rounded-xl shadow-lg p-6 text-white">
                                <div class="text-sm font-semibold opacity-80">Today's Profit (<?php echo htmlspecialchars($daily['symbol']); ?>)</div>
                                <div class="text-4xl font-bold mt-2"><?php echo number_format($daily['total_fees'], 2); ?></div>
                                <div class="text-sm opacity-75 mt-1"><?php echo $daily['conversion_count']; ?> conversions</div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-3 bg-gray-100 rounded-xl p-6 text-center">
                            <i class="fas fa-chart-line text-4xl text-gray-400 mb-2"></i>
                            <p class="text-gray-600">No conversion fees collected today</p>
                        </div>
                    <?php endif; ?>
                </div>

                <h3 class="text-xl font-semibold text-gray-800 mb-4 mt-8">Conversion Fees History</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($conversion_fees) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conversion</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee (5%)</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date & Time</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($conversion_fees as $fee): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($fee['username']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($fee['from_symbol']); ?> → <?php echo htmlspecialchars($fee['to_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo number_format($fee['amount_converted'], 2); ?> <?php echo htmlspecialchars($fee['from_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600">
                                                +<?php echo number_format($fee['tax_amount'], 2); ?> <?php echo htmlspecialchars($fee['from_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y H:i', strtotime($fee['timestamp'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-receipt text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No conversion fees recorded yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- EXCHANGE RATE SECTION -->
            <div id="exchange-rate-section" class="<?php echo $show_exchange_rate ? '' : 'hidden'; ?> relative overflow-hidden">
                
                <!-- Live Rate Preview Card -->
                <?php
                // Fetch live preview rate for USD to MMK
                list($live_usd_mmk, $eff_usd_mmk) = er_get_effective_rate('USD', 'MMK');
                ?>
                <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-gray-700 text-white rounded-full p-3">
                                <i class="fas fa-chart-line text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-800">Live Exchange Rate</h3>
                                <p class="text-gray-500 text-sm">Updated in real-time from global markets</p>
                            </div>
                        </div>
                        <button onclick="location.reload()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i>Refresh
                        </button>
                    </div>
                    <?php if (!empty($live_preview)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mt-6">
                        <?php foreach ($live_preview as $card): ?>
                        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                            <div class="text-gray-500 text-sm mb-2">1 <?php echo htmlspecialchars($card['base']); ?> =</div>
                            <div class="text-3xl font-bold mb-1 text-gray-900">
                                <?php echo number_format($card['effective'], 4); ?>
                                <span class="text-xl ml-2"><?php echo htmlspecialchars($card['target']); ?></span>
                            </div>
                            <div class="text-gray-500 text-xs">Live: <?php echo number_format($card['live'], 4); ?> → With markup</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-500 text-sm mt-4">No preview available. Ensure USD, EUR, THB, JPY, and MMK exist in the currencies table.</div>
                    <?php endif; ?>
                </div>

                <!-- Markup Settings Card -->
                <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="bg-gray-700 text-white rounded-full p-3">
                            <i class="fas fa-sliders-h text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Markup Settings</h3>
                            <p class="text-gray-500 text-sm">Adjust to match local market rates</p>
                        </div>
                    </div>
                    
                    <form action="admin.php?view=exchange_rate" method="POST">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="global_value" value="0">
                        
                        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                            <label for="mmk_percent" class="block text-gray-700 font-bold mb-3 text-lg">
                                <i class="fas fa-flag text-red-500 mr-2"></i>Myanmar Kyat (MMK) Markup
                            </label>
                            <div class="flex items-center gap-4">
                                <input type="number" step="1" id="mmk_percent" name="mmk_percent" 
                                       value="<?php echo isset($rate_settings['targets']['MMK']) ? htmlspecialchars($rate_settings['targets']['MMK']['value']) : '70'; ?>" 
                                       placeholder="70" 
                                       class="w-40 p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-2xl font-bold text-center">
                                <span class="text-3xl font-bold text-gray-600">%</span>
                                <div class="flex-1 ml-4">
                                    <div class="text-sm text-gray-600">
                                        <strong>Current:</strong> 1 USD = <span class="text-green-600 font-bold"><?php echo $eff_usd_mmk ? number_format($eff_usd_mmk, 2) : '---'; ?> MMK</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="mt-6 w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-xl transition-colors shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                            <i class="fas fa-save mr-2"></i>Save Markup Setting
                        </button>
                    </form>
                </div>

                <!-- Sync Rate Card -->
                <div class="bg-gray-50 rounded-2xl shadow-lg p-8 mb-8 border border-gray-200">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="bg-gray-700 text-white rounded-full p-3">
                            <i class="fas fa-download text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Sync Exchange Rate</h3>
                            <p class="text-gray-500 text-sm">Fetch and save live rates to your database</p>
                        </div>
                    </div>
                    
                    <form action="admin.php?view=exchange_rate" method="POST">
                        <input type="hidden" name="action" value="sync_from_api">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
                                <label class="block text-gray-700 font-bold mb-3">
                                    <i class="fas fa-arrow-right text-blue-500 mr-2"></i>From Currency
                                </label>
                                <select name="base_currency" class="w-full p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold bg-white">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>" <?php echo $currency['symbol'] === 'USD' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currency['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
                                <label class="block text-gray-700 font-bold mb-3">
                                    <i class="fas fa-arrow-left text-purple-500 mr-2"></i>To Currency
                                </label>
                                <select name="target_currency" class="w-full p-4 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-lg font-semibold bg-white">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>" <?php echo $currency['symbol'] === 'MMK' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currency['symbol']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-5 px-6 rounded-xl transition-colors shadow-lg hover:shadow-2xl transform hover:-translate-y-0.5 text-lg">
                            <i class="fas fa-cloud-download-alt mr-2"></i>Sync & Save Rate to Database
                        </button>
                    </form>
                </div>

                <!-- Existing Rates -->
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Current Exchange Rates</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($exchange_rates) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">Pair</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($exchange_rates as $rate): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($rate['base_symbol'] . ' → ' . $rate['target_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                1 <?php echo htmlspecialchars($rate['base_symbol']); ?> = <?php echo number_format($rate['rate'], 4); ?> <?php echo htmlspecialchars($rate['target_symbol']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo !empty($rate['rate_ts']) ? htmlspecialchars($rate['rate_ts']) : '<span class="text-gray-400">N/A</span>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-chart-line text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No exchange rates synced yet. Use the form above to sync from API.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="all-requests-history" class="<?php echo $show_all_requests ? '' : 'hidden'; ?>">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">All User Requests History</h3>
                <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                    <?php if (count($all_requests_history) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($all_requests_history as $request): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['username']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(ucfirst($request['transaction_type'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo number_format($request['amount'], 2) . ' ' . htmlspecialchars($request['symbol']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php
                                                    $status = $request['status'];
                                                    $color_class = 'bg-gray-100 text-gray-800';
                                                    if ($status === 'pending') {
                                                        $color_class = 'bg-yellow-100 text-yellow-800';
                                                    } elseif ($status === 'completed') {
                                                        $color_class = 'bg-green-100 text-green-800';
                                                    } elseif ($status === 'rejected') {
                                                        $color_class = 'bg-red-100 text-red-800';
                                                    }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color_class; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['request_timestamp']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-list-alt text-5xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500 text-lg">No user requests found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading dialog removed for Exchange Rate view -->

    <!-- Details Modal -->
    <div id="detailsModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:1rem;">
        <div class="modal-content" style="background-color:#ffffff; color:#111827; margin:0; padding:0; border-radius:1rem; width:90%; max-width:460px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.6), 0 10px 10px -5px rgba(0,0,0,0.5); border:1px solid #e5e7eb;">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeDetailsModal()" style="cursor:pointer; float:right; font-size:28px; font-weight:bold;">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-info-circle mr-2"></i>Request Details</h3>
                <p class="text-blue-100 text-sm mt-1">Review request and approval timestamps</p>
            </div>
            <div class="p-6 bg-gray-50">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">Requested</span>
                        <span id="adm-req" class="text-gray-900">—</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">Approved</span>
                        <span id="adm-dec" class="text-gray-900">—</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">Payment Channel</span>
                        <span id="adm-ch" class="text-gray-900">—</span>
                    </div>
                    <div id="adm-proof-row" class="flex items-center justify-between hidden">
                        <span class="font-medium text-gray-700">Proof of Payment</span>
                        <a id="adm-proof" href="#" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">View</a>
                    </div>
                </div>
                <div class="mt-6 text-right">
                    <button type="button" onclick="closeDetailsModal()" class="inline-flex items-center px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white font-semibold rounded-lg border border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirm Modal -->
    <div id="logoutModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:1rem;">
        <div class="modal-content" style="background-color:#ffffff; color:#111827; margin:0; padding:0; border-radius:1rem; width:90%; max-width:480px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.6), 0 10px 10px -5px rgba(0,0,0,0.5); border:1px solid #e5e7eb;">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeLogoutModal()" style="cursor:pointer; float:right; font-size:28px; font-weight:bold;">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-sign-out-alt mr-2"></i>Confirm Logout</h3>
                <p class="text-gray-200 text-sm mt-1">End your admin session</p>
            </div>
            <div class="p-6 bg-gray-100">
                <p class="text-gray-700 mb-6">Are you sure you want to log out?</p>
                <div class="flex space-x-3">
                    <a id="confirmLogoutBtn" href="#" class="flex-1 text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">Logout</a>
                    <button type="button" onclick="closeLogoutModal()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg border border-gray-600 hover:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Animated gradient background */
        .loading-backdrop {
            background: linear-gradient(-45deg, #667eea, #764ba2, #f093fb, #4facfe);
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            backdrop-filter: blur(8px);
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Smooth slow spinner */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .loading-spinner {
            animation: spin 4s cubic-bezier(0.4, 0.0, 0.2, 1) infinite;
            border-color: #667eea;
            border-top-color: transparent;
            border-right-color: #764ba2;
            border-bottom-color: #f093fb;
        }

        /* Pulsing glow effect */
        @keyframes pulseGlow {
            0%, 100% { 
                opacity: 0.2; 
                transform: translate(-50%, -50%) scale(1);
            }
            50% { 
                opacity: 0.4; 
                transform: translate(-50%, -50%) scale(1.1);
            }
        }
        .pulse-glow {
            animation: pulseGlow 3s ease-in-out infinite;
        }

        /* Exchange rate section fade in */
        #exchange-rate-section {
            animation: fadeInSection 0.8s ease-out;
        }

        @keyframes fadeInSection {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Colorful background for exchange rate section */
        #exchange-rate-section::before {
            content: '';
            position: absolute;
            top: -100px;
            left: 0;
            right: 0;
            height: 300px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            opacity: 0.08;
            border-radius: 50%;
            filter: blur(60px);
            z-index: -1;
            animation: floatBackground 8s ease-in-out infinite;
        }

        @keyframes floatBackground {
            0%, 100% { transform: translateY(0px) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }

        /* Card entrance animation */
        .loading-card {
            animation: cardEntrance 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Success bounce */
        @keyframes successBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .success-bounce {
            animation: successBounce 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
    </style>

    <script>
        function showLogoutModal(targetUrl) {
            var btn = document.getElementById('confirmLogoutBtn');
            btn.setAttribute('href', targetUrl);
            document.getElementById('logoutModal').style.display = 'flex';
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var logoutLinks = document.querySelectorAll('a[href$="admin_logout.php"]');
            logoutLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    showLogoutModal(link.href);
                });
            });

            // Close when clicking the backdrop
            window.addEventListener('click', function(event) {
                var modal = document.getElementById('logoutModal');
                if (event.target === modal) { modal.style.display = 'none'; }
            });

            // Details modal wiring
            const detailsModal = document.getElementById('detailsModal');
            const reqEl = document.getElementById('adm-req');
            const decEl = document.getElementById('adm-dec');
            const chEl  = document.getElementById('adm-ch');
            const proofRowEl = document.getElementById('adm-proof-row');
            const proofEl = document.getElementById('adm-proof');
            function openDetailsModal(requested, decision, channel, proof){
                if (reqEl) reqEl.textContent = requested || '—';
                if (decEl) decEl.textContent = decision || '—';
                if (chEl)  chEl.textContent  = channel || '—';
                const hasProof = !!(proof && proof.trim() !== '');
                if (proofRowEl) proofRowEl.classList.toggle('hidden', !hasProof);
                if (proofEl) {
                    if (hasProof) { proofEl.setAttribute('href', proof); }
                    else { proofEl.setAttribute('href', '#'); }
                }
                if (detailsModal) detailsModal.style.display = 'flex';
            }
            window.closeDetailsModal = function(){ if (detailsModal) detailsModal.style.display = 'none'; };
            document.querySelectorAll('.open-details').forEach(function(btn){
                btn.addEventListener('click', function(){
                    openDetailsModal(
                        btn.getAttribute('data-requested') || '—',
                        btn.getAttribute('data-decision')  || '—',
                        btn.getAttribute('data-channel')   || '—',
                        btn.getAttribute('data-proof')     || ''
                    );
                });
            });
        });
    </script>
</body>
</html>
