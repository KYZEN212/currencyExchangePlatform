<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['banned' => false, 'logged_in' => false]);
    exit();
}

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['banned' => false, 'error' => 'Database connection failed']);
    exit();
}

$session_username = $_SESSION['username'];

// Get user status
$stmt = $conn->prepare("SELECT user_id, user_status FROM users WHERE username = ?");
$stmt->bind_param("s", $session_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $user_status = $user['user_status'];
    
    // Check if banned (user_status: 0 = banned, 1 = active)
    if ($user_status == 0) {
        // Get ban details
        $stmt_ban = $conn->prepare("
            SELECT ban_reason, expires_at, ban_duration_days 
            FROM user_bans 
            WHERE user_id = ? AND is_active = 1 
            ORDER BY banned_at DESC 
            LIMIT 1
        ");
        $stmt_ban->bind_param("i", $user['user_id']);
        $stmt_ban->execute();
        $ban_result = $stmt_ban->get_result();
        
        if ($ban_result->num_rows > 0) {
            $ban_data = $ban_result->fetch_assoc();
            
            // Check if ban expired
            $expires_at = strtotime($ban_data['expires_at']);
            $now = time();
            
            if ($now >= $expires_at) {
                // Ban expired, auto-unban
                $stmt_unban = $conn->prepare("UPDATE user_bans SET is_active = 0, unbanned_at = NOW() WHERE user_id = ? AND is_active = 1");
                $stmt_unban->bind_param("i", $user['user_id']);
                $stmt_unban->execute();
                
                $stmt_status = $conn->prepare("UPDATE users SET user_status = 1 WHERE user_id = ?");
                $stmt_status->bind_param("i", $user['user_id']);
                $stmt_status->execute();
                
                echo json_encode(['banned' => false, 'expired' => true]);
            } else {
                // Still banned
                $time_remaining = $expires_at - $now;
                $days = floor($time_remaining / 86400);
                $hours = floor(($time_remaining % 86400) / 3600);
                $minutes = floor(($time_remaining % 3600) / 60);
                
                if ($ban_data['ban_duration_days'] >= 9999) {
                    $duration_text = "permanently";
                } else {
                    $duration_text = "";
                    if ($days > 0) $duration_text .= "$days day" . ($days > 1 ? "s" : "") . " ";
                    if ($hours > 0) $duration_text .= "$hours hour" . ($hours > 1 ? "s" : "") . " ";
                    if ($minutes > 0 && $days == 0) $duration_text .= "$minutes minute" . ($minutes > 1 ? "s" : "");
                    $duration_text = trim($duration_text);
                }
                
                echo json_encode([
                    'banned' => true,
                    'reason' => $ban_data['ban_reason'] ?: 'Violation of terms',
                    'duration' => $duration_text,
                    'expires_at' => date('F j, Y \a\t g:i A', $expires_at),
                    'is_permanent' => $ban_data['ban_duration_days'] >= 9999
                ]);
            }
        } else {
            echo json_encode(['banned' => false]);
        }
        $stmt_ban->close();
    } else {
        echo json_encode(['banned' => false]);
    }
} else {
    echo json_encode(['banned' => false, 'user_not_found' => true]);
}

$stmt->close();
$conn->close();
?>
