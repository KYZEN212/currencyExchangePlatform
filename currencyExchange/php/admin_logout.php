<?php
session_start();

// Unset all of the session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect back to the admin dashboard route which shows the admin login when not authenticated
header("Location: admin.php");
exit();
?>