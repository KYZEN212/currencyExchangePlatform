<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/config.php';

$user_id = (int)$_SESSION['user_id'];
$limit = 20; // return up to 20 recent events

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}

$items = [];
try {
    $sql = "SELECT login_at, ip_address, user_agent, browser_name, device, browser_version, os_name, os_version FROM user_login_events WHERE user_id = ? ORDER BY login_at DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $user_id, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ip = $row['ip_address'] ?? '';
            if ($ip === '::1') {
                $ip = '127.0.0.1';
            } elseif (stripos((string)$ip, '::ffff:') === 0) {
                $ip = substr($ip, strrpos($ip, ':') + 1);
            }
            $items[] = [
                'login_at' => $row['login_at'],
                'ip_address' => $ip,
                'user_agent' => $row['user_agent'] ?? '',
                'browser_name' => $row['browser_name'] ?? '',
                'browser_version' => $row['browser_version'] ?? '',
                'os_name' => $row['os_name'] ?? '',
                'os_version' => $row['os_version'] ?? '',
                'device' => $row['device'] ?? ''
            ];
        }
        $stmt->close();
    }
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve history.']);
} finally {
    $conn->close();
}
