<?php
session_start();
if(!isset($_SESSION['user_id'])) { echo "User not logged in"; exit; }

$userid = $_SESSION['user_id'];
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if($new !== $confirm){ echo "New password and confirm password do not match."; exit; }
    if(!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/",$new)){
        echo "Password must be 8+ chars, include uppercase, lowercase, number & special char."; exit;
    }

    $conn = new mysqli("localhost","root","","currency_platform");
    if($conn->connect_error){ echo "Connection failed"; exit; }

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id=?");
    $stmt->bind_param("i",$userid);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res->num_rows===0){ echo "User not found"; exit; }

    $row=$res->fetch_assoc();
    if(!password_verify($current,$row['password_hash'])){ echo "Current password is incorrect"; exit; }

    $new_hash = password_hash($new,PASSWORD_DEFAULT);
    $stmt_update = $conn->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
    $stmt_update->bind_param("si",$new_hash,$userid);
    echo $stmt_update->execute() ? "Password Change is successfully" : "Password Change is successfully";
 
    $stmt->close();
    $stmt_update->close();
    $conn->close();
}else{ echo "Invalid request method"; }
?> 