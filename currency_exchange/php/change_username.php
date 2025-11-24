<?php
session_start();
header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$new_username = trim($_POST['new_username'] ?? '');

// Basic validation
if ($new_username === '') {
    echo json_encode(['success' => false, 'message' => 'Username is required.']);
    exit;
}

// Enforce length 3-20 characters (allow any characters; DB collation will handle)
$len = function_exists('mb_strlen') ? mb_strlen($new_username, 'UTF-8') : strlen($new_username);
if ($len < 3 || $len > 20) {
    echo json_encode(['success' => false, 'message' => 'Username must be 3-20 characters.']);
    exit;
}

require_once __DIR__ . '/config.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}

try {
    // Check uniqueness (case-insensitive depending on collation, but enforce explicitly)
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? AND user_id <> ?');
    $stmt->bind_param('si', $new_username, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already taken.']);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();

    // Update username
    $stmt_upd = $conn->prepare('UPDATE users SET username = ? WHERE user_id = ?');
    $stmt_upd->bind_param('si', $new_username, $user_id);
    if (!$stmt_upd->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to update username.']);
        $stmt_upd->close();
        $conn->close();
        exit;
    }
    $stmt_upd->close();

    // Update session for immediate UI consistency
    $_SESSION['username'] = $new_username;

    echo json_encode(['success' => true, 'message' => 'Username updated successfully.', 'new_username' => $new_username]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error.']);
} finally {
    $conn->close();
}
