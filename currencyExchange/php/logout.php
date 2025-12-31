<?php
session_start();
session_destroy();

// Check if user was banned
$banned = isset($_GET['banned']) && $_GET['banned'] == '1';

if ($banned) {
    // Redirect to ban page
    header("Location: banned_page.php");
    exit();
} else {
    // Normal logout - redirect to home
    header("Location: home.php");
    exit();
}
?>
