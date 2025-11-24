<?php
session_start();
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$userid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['userimage'])) {

    $file = $_FILES['userimage'];
    $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    // Check upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=>"Upload error code: {$file['error']}"]);
        exit();
    }

    // Size check
    if ($file['size'] > $maxSize) {
        echo json_encode(['success'=>false,'message'=>'File too big. Max 2MB']);
        exit();
    }

    // MIME check
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success'=>false,'message'=>'Only JPG, PNG, GIF, WEBP allowed']);
        exit();
    }

    // Upload directory
    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFileName = "user_{$userid}_" . time() . "." . $ext;
    $destination = $uploadDir . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['success'=>false,'message'=>'Failed to move uploaded file. Check folder permissions']);
        exit();
    }

    // Database connection
    $conn = new mysqli("localhost","root","","currency_platform");

    // Detect if users.userimage column exists
    $hasUserimage = false;
    try {
        $dbName = 'currency_platform';
        $stmtCol = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'userimage' LIMIT 1");
        if ($stmtCol) {
            $stmtCol->bind_param('s', $dbName);
            $stmtCol->execute();
            $resCol = $stmtCol->get_result();
            $hasUserimage = (bool)$resCol->fetch_row();
            $stmtCol->close();
        }
    } catch (Throwable $e) { $hasUserimage = false; }

    if ($hasUserimage) {
        // Delete old image if recorded in DB
        $stmt = $conn->prepare("SELECT userimage FROM users WHERE user_id=?");
        if ($stmt) {
            $stmt->bind_param("i",$userid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $oldImage = $row['userimage'] ?? '';
                if ($oldImage && file_exists($uploadDir.$oldImage)) @unlink($uploadDir.$oldImage);
            }
            $stmt->close();
        }

        // Update DB with new filename
        $stmt = $conn->prepare("UPDATE users SET userimage=? WHERE user_id=?");
        if ($stmt) {
            $stmt->bind_param("si",$newFileName,$userid);
            $ok = $stmt->execute();
            $stmt->close();
        } else { $ok = false; }

        if ($ok) {
            $_SESSION['userimage'] = $newFileName;
            echo json_encode([
                'success' => true,
                'message' => 'Profile image updated successfully ✅',
                'newImagePath' => 'uploads/'.$newFileName
            ]);
        } else {
            // Still set session so UI updates even if DB not updated
            $_SESSION['userimage'] = $newFileName;
            echo json_encode(['success'=>true,'message'=>'Profile image updated (session only)','newImagePath'=>'uploads/'.$newFileName]);
        }
        $conn->close();
    } else {
        // No userimage column: skip DB ops, just update session
        $_SESSION['userimage'] = $newFileName;
        echo json_encode([
            'success' => true,
            'message' => 'Profile image updated (session only)',
            'newImagePath' => 'uploads/'.$newFileName
        ]);
    }

} else {
    echo json_encode(['success'=>false,'message'=>'No file uploaded']);
}
