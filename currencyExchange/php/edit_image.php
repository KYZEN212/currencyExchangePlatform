<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
$conn = new mysqli("localhost", "root", "", "currency_platform");

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}

if (isset($_FILES["userimage"]) && $_FILES["userimage"]["error"] == 0) {
    $file = $_FILES["userimage"];
    
    // Validate file type
    $allowed_types = ["image/jpeg", "image/jpg", "image/png", "image/gif", "image/webp"];
    if (!in_array($file["type"], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
        exit();
    }
    
    // Validate file size (max 5MB)
    if ($file["size"] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 5MB)']);
        exit();
    }
    
    // Get file extension
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    
    // Generate unique filename: userid_timestamp_random.extension
    $new_filename = "user_" . $user_id . "_" . time() . "_" . rand(1000, 9999) . "." . $file_extension;
    $upload_dir = "uploads/";
    
    // Create uploads directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Delete old profile image if exists
    $stmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($old_image);
    $stmt->fetch();
    $stmt->close();
    
    if ($old_image && file_exists($upload_dir . $old_image)) {
        unlink($upload_dir . $old_image);
    }
    
    // Upload new image
    if (move_uploaded_file($file["tmp_name"], $upload_dir . $new_filename)) {
        // Update database with new image name
        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_filename, $user_id);
        
        if ($stmt->execute()) {
            // Update session
            $_SESSION['userimage'] = $new_filename;
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile picture updated successfully',
                'newImagePath' => $upload_dir . $new_filename
            ]);
        } else {
            // Remove uploaded file if database update fails
            unlink($upload_dir . $new_filename);
            echo json_encode(['success' => false, 'message' => 'Database update failed']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
}

$conn->close();
?>