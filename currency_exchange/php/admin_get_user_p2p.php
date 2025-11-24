<?php
session_start();
header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit();
}

// Detect timestamp column
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
    // Fallback to trade timestamp
}

if ($history_time_col) {
    $timeSelect = "h.`$history_time_col` AS fill_timestamp";
    $orderBy = "ORDER BY h.`$history_time_col` DESC";
} else {
    $timeSelect = "t.timestamp AS fill_timestamp";
    $orderBy = "ORDER BY t.timestamp DESC";
}

// Fetch user's P2P transaction history
$transactions = [];
$sql = "
    SELECT 
        h.amount_bought,
        h.amount_sold,
        h.exchange_rate,
        $timeSelect,
        t.trade_type,
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
    LIMIT 50
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $buy_sym = $row['buy_currency_symbol'];
    $sell_sym = $row['sell_currency_symbol'];
    $trade_type = strtolower($row['trade_type']);
    
    // Determine transaction type display
    if ($trade_type === 'buy') {
        $type = "Sell {$buy_sym} for {$sell_sym}";
    } else {
        $type = "Buy {$sell_sym} for {$buy_sym}";
    }
    
    // Format rate display
    $is_usd_mmk = (($buy_sym === 'USD' && $sell_sym === 'MMK') || ($buy_sym === 'MMK' && $sell_sym === 'USD'));
    if ($is_usd_mmk) {
        $rate_display = "1 USD = " . number_format($row['exchange_rate'], 0) . " MMK";
    } else {
        $rate_display = number_format($row['exchange_rate'], 4);
    }
    
    $transactions[] = [
        'type' => $type,
        'amount_sold' => $row['amount_sold'],
        'amount_bought' => $row['amount_bought'],
        'sell_currency' => $sell_sym,
        'buy_currency' => $buy_sym,
        'rate_display' => $rate_display,
        'timestamp' => $row['fill_timestamp']
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'transactions' => $transactions
]);
?>
