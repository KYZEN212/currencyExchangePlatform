<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

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
$transactions = [];
// Embedding flag for dashboard include
$EMBED_IN_DASHBOARD = isset($EMBED_IN_DASHBOARD) && $EMBED_IN_DASHBOARD;

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check for database connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is banned
require_once 'check_user_ban.php';

// Function to safely get user ID (guarded to avoid redeclare when embedded)
if (!function_exists('getUserId')) {
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
}

$user_id = getUserId($conn, $session_username);
 
// Detect a timestamp/datetime column on p2p_trade_history to use as fill time
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
} catch (Exception $e) {
    // ignore and fall back to trade timestamp
}

// Build SELECT alias and ordering
if ($history_time_col) {
    $timeSelect = "h.`$history_time_col` AS fill_timestamp";
    $orderBy    = "ORDER BY h.`$history_time_col` DESC";
} else {
    $timeSelect = "t.timestamp AS fill_timestamp";
    $orderBy    = "ORDER BY t.timestamp DESC";
}

try {
    // Fetch the user's P2P transaction history from p2p_trade_history (includes partial fills)
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
<?php if (!$EMBED_IN_DASHBOARD): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>P2P Transaction History</title>
    <!-- Bootstrap CSS for navbar to match dashboard -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        /* Navbar look & feel to match dashboard.php */
        .navbar {
            padding: 1rem 0;
            background: #ffffff !important;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }
        .navbar-brand img { height: 30px; }
        .navbar-brand span {
            font-size: 1.25rem; font-weight: 700;
            background: linear-gradient(135deg, #38bdf8, #a78bfa);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .navbar-collapse { display: flex !important; }
        .navbar-nav { align-items: center; }
        .nav-link { font-weight: 500; color: #1e293b !important; padding: 0.5rem 1rem !important; border-radius: 0.5rem; }
        .nav-link.active { color: #2563eb !important; font-weight: 600; }
        .nav-link:hover { background: rgba(37, 99, 235, 0.10); color: #2563eb !important; transform: translateY(-2px); }
        a.nav-link:hover .userprofile { background-color: initial; border: initial; }
        .userprofile{ margin-left: 20px; background-color: none; border: none; }
        #show-notifications-btn { display: flex; align-items: center; border-radius: 0.5rem; padding: 6px 12px; }
        #show-notifications-btn i { vertical-align: middle; margin-right: 4px; }
        .navbar .ms-3 { margin-left: 1rem !important; }
        .navbar-nav .nav-item { margin-left: 1.5rem; }
    </style>
     </head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal" style="padding-top:56px;">

<nav class="p-4 flex justify-between items-center shadow-md fixed top-0 left-0 w-full z-10" style="background: linear-gradient(105deg, rgba(37, 99, 235, 0.9), rgba(37, 99, 235, 0.9));">
        <div class="flex items-center space-x-4">
            <span class="text-3xl font-bold text-white">Online Trading</span>
        </div>
        <div class="hidden md:flex items-center space-x-4">
            <a href="dashboard.php" class="text-white hover:text-white/90 font-medium">Dashboard</a>
            <a href="p2pTradeList.php" class="text-white hover:text-white/90 font-medium">P2P Trade</a>
            <a href="p2pTransactionHistory.php" class="text-white font-semibold" style="background: rgba(255,255,255,0.2); border-radius: 0.5rem; padding: 0.25rem 0.75rem;"><i class="fas fa-history"></i> P2P History</a>
            <a href="dashboard.php?action=notifications" class="text-white hover:text-white/90 font-medium relative focus:outline-none text-left">
            <i class="fas fa-bell"></i> Notifications
            </a>
            <a href="logout.php" class="text-white hover:text-white/90 font-medium">Logout</a>
            <a href="profile.php" class="d-flex align-items-center justify-content-center rounded-circle text-decoration-none" style="width:38px; height:38px; overflow:hidden; background:#e5e7eb; color:#111827;">
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
                    <?php $initials_local = strtoupper(substr($session_username ?? 'U', 0, 1)); ?>
                    <span style="font-weight:700; font-size:0.9rem; line-height:38px; width:100%; text-align:center; color:#111827;">
                        <?php echo htmlspecialchars($initials_local); ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        <div class="md:hidden">
            <button id="mobile-menu-button" class="text-white focus:outline-none">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>
    </nav>

<?php endif; ?>

    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <div class="flex items-center space-x-4">
                <div class="h-12 w-12 flex items-center justify-center overflow-hidden" style="border-radius:50%; background:#e5e7eb;">
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
                        <span class="text-white text-xl font-bold" style="display:block; width:100%; height:100%; line-height:48px; text-align:center; background:#2563eb; border-radius:50%;">
                            <?php echo htmlspecialchars($initials); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Your P2P Transaction History</h2>
                    <p class="text-gray-500">All your peer-to-peer trade fills (full and partial)</p>
                </div>
            </div>

            <hr class="my-6">

            <div class="overflow-x-auto">
                <?php if (empty($transactions)): ?>
                    <p class="text-center text-gray-500 py-8">You have no P2P transactions yet.</p>
                <?php else: ?>
                    <table class="min-w-full bg-white border border-gray-200 rounded-lg shadow-md">
                        <thead>
                            <tr class="w-full bg-gray-50 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                <th class="py-3 px-4 border-b">Transaction</th>
                                <th class="py-3 px-4 border-b">Type</th>
                                <th class="py-3 px-4 border-b">Amount</th>
                                <th class="py-3 px-4 border-b">Exchange Rate</th>
                                <th class="py-3 px-4 border-b">For</th>
                                <th class="py-3 px-4 border-b">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-700">
                                        <?php 
                                            $buyerId    = (int)$transaction['buyer_user_id'];       // acceptor id in our system
                                            $sellerId   = (int)$transaction['seller_user_id'];      // poster id in our system
                                            $buyerName  = htmlspecialchars($transaction['buyer_username']);
                                            $sellerName = htmlspecialchars($transaction['seller_username']);
                                            $tradeType  = strtolower((string)$transaction['trade_type']); // 'buy' or 'sell' indicates poster's intent

                                            $youAreAcceptor = ($buyerId === (int)$user_id);
                                            $youArePoster   = ($sellerId === (int)$user_id);

                                            // Self-trade or unexpected
                                            if ($buyerId === $sellerId || (!$youAreAcceptor && !$youArePoster)) {
                                                echo "Self-trade between your own accounts";
                                            } else if ($youArePoster) {
                                                // You posted the trade
                                                if ($tradeType === 'sell') {
                                                    echo "You sold to {$buyerName}";
                                                } else { // poster was buying
                                                    echo "You bought from {$buyerName}";
                                                }
                                            } else { // you are acceptor
                                                if ($tradeType === 'sell') {
                                                    echo "You bought from {$sellerName}";
                                                } else { // poster was buying
                                                    echo "You sold to {$sellerName}";
                                                }
                                            }
                                            // Optional debug info: append when URL has ?debug=1
                                            if (isset($_GET['debug'])) {
                                                $dbgYouArePoster = ($sellerId === (int)$user_id);
                                                $dbgCounterparty = $dbgYouArePoster ? $buyerName : $sellerName;
                                                echo " <span class='ml-2 text-xs text-gray-400'>(debug: type=" . htmlspecialchars($transaction['trade_type']) .
                                                     ", buyer_id=" . $buyerId .
                                                     ", buyer='" . $buyerName . "'" .
                                                     ", seller_id=" . $sellerId .
                                                     ", seller='" . $sellerName . "'" .
                                                     ", youArePoster=" . ($dbgYouArePoster ? '1' : '0') .
                                                     ", counterparty='" . $dbgCounterparty . "')</span>";
                                            }
                                        ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-700">
                                        <?php
                                            $buy_sym  = htmlspecialchars($transaction['buy_currency_symbol']);
                                            $sell_sym = htmlspecialchars($transaction['sell_currency_symbol']);
                                            if (strtolower($transaction['trade_type']) === 'buy') {
                                                // trade_type buy means post text: Sell BUY for SELL
                                                echo "Sell {$buy_sym} for {$sell_sym}";
                                            } else {
                                                // trade_type sell means post text: Buy SELL for BUY
                                                echo "Buy {$sell_sym} for {$buy_sym}";
                                            }
                                        ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm font-medium text-gray-900">
                                        <?php 
                                            echo number_format($transaction['amount_sold'], 2) . " " . htmlspecialchars($transaction['sell_currency_symbol']);
                                        ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-700">
    <?php 
        $rate_text = 'N/A';
        $buy_symbol = htmlspecialchars($transaction['buy_currency_symbol']);
        $sell_symbol = htmlspecialchars($transaction['sell_currency_symbol']);
        $is_usd_mmk = (($buy_symbol === 'USD' && $sell_symbol === 'MMK') || ($buy_symbol === 'MMK' && $sell_symbol === 'USD'));

        if ($is_usd_mmk) {
            // For USD/MMK, show the exact saved rate: 1 USD = X MMK
            $exactRate = (float)$transaction['exchange_rate'];
            $rate_text = "1 USD = " . number_format($exactRate, 0) . " MMK";
        } else if ($transaction['amount_sold'] > 0 && $transaction['amount_bought'] > 0) {
            // Fallback to computed display for other pairs
            $buy_amount = $transaction['amount_bought'];
            $sell_amount = $transaction['amount_sold'];

            if ($buy_amount < $sell_amount) {
                $exchangeRate = $sell_amount / $buy_amount;
                $rate_text = "1 {$buy_symbol} = " . number_format($exchangeRate, 2) . " {$sell_symbol}";
            } else {
                $exchangeRate = $buy_amount / $sell_amount;
                $rate_text = "1 {$sell_symbol} = " . number_format($exchangeRate, 2) . " {$buy_symbol}";
            }
        }
        echo $rate_text;
    ?>
</td>
                                    <td class="py-3 px-4 text-sm text-gray-700">
                                        <?php 
                                            echo number_format($transaction['amount_bought'], 2) . " " . htmlspecialchars($transaction['buy_currency_symbol']);
                                        ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-500"><?php echo htmlspecialchars($transaction['fill_timestamp']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Mobile menu toggle functionality
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');

            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', () => {
                    mobileMenu.classList.toggle('hidden');
                });
            }
        });
    </script>

    <!-- Real-time Ban Check -->
    <?php if (!$EMBED_IN_DASHBOARD) { include 'ban_check_script.php'; } ?>
<?php if (!$EMBED_IN_DASHBOARD): ?>
</body>
</html>
<?php endif; ?>