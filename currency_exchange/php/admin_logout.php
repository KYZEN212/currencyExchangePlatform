<?php
session_start();

// Unset all of the session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to the admin login page (adminTransaction.php)
header("Location: adminTransaction.php");
exit();
?>