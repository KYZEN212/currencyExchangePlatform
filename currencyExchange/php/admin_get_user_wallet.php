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

// Fetch user's wallet balances
$wallets = [];
$stmt = $conn->prepare("
    SELECT w.balance, c.symbol, c.name, c.currency_id
    FROM wallets w
    JOIN currencies c ON w.currency_id = c.currency_id
    WHERE w.user_id = ? AND w.balance > 0
    ORDER BY w.balance DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $wallets[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'wallets' => $wallets
]);
?>
