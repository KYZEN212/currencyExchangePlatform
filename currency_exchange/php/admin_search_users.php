<?php
// AJAX endpoint: search users by query (username/email/id). Returns JSON rows compatible with admin_user_management.php table.
session_start();

header('Content-Type: application/json');

// Only admins
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// DB creds (match admin_user_management.php)
$servername = 'localhost';
$db_username = 'root';
$db_password = '';
$dbname = 'currency_platform';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit();
}

// Base SQL
$sql = "
    SELECT 
        u.user_id, 
        u.username, 
        u.email, 
        u.user_status,
        ub.ban_id,
        ub.ban_reason,
        ub.ban_duration_days,
        ub.banned_at,
        ub.expires_at,
        ub.is_active
    FROM users u
    LEFT JOIN user_bans ub ON u.user_id = ub.user_id AND ub.is_active = 1
";
$params = [];
$types = '';

if ($q !== '') {
    // Support searching by id, username, or email
    $sql .= " WHERE (u.username LIKE ? OR u.email LIKE ? OR u.user_id = ?)";
    $like = '%' . $q . '%';
    $id = ctype_digit($q) ? (int)$q : -1;
    $params = [$like, $like, $id];
    $types = 'ssi';
}

$sql .= " ORDER BY u.username ASC LIMIT ?";
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed']);
    $conn->close();
    exit();
}

// Bind dynamically
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'users' => $rows]);
