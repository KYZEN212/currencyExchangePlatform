<?php
// Ban check middleware - Include this at the top of user pages after session_start()
// Usage: require_once 'check_user_ban.php';

if (!isset($_SESSION['username'])) {
    // User not logged in, skip ban check
    return;
}

// Get database connection (reuse existing connection if available)
if (!isset($conn) || !$conn || $conn->connect_error) {
    $servername = "localhost";
    $db_username = "root";
    $db_password = "";
    $dbname = "currency_platform";
    $conn = new mysqli($servername, $db_username, $db_password, $dbname);
    
    if ($conn->connect_error) {
        // Database connection failed, skip ban check
        return;
    }
}

// Get user ID from session username
$session_username = $_SESSION['username'];
$stmt_user = $conn->prepare("SELECT user_id, user_status FROM users WHERE username = ?");
$stmt_user->bind_param("s", $session_username);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if ($result_user->num_rows > 0) {
    $user_data = $result_user->fetch_assoc();
    $user_id = $user_data['user_id'];
    $user_status = $user_data['user_status'];
    
    // Check if user is banned (user_status: 0 = banned, 1 = active)
    if ($user_status == 0) {
        // Get active ban details
        $stmt_ban = $conn->prepare("
            SELECT ban_reason, expires_at, ban_duration_days 
            FROM user_bans 
            WHERE user_id = ? AND is_active = 1 
            ORDER BY banned_at DESC 
            LIMIT 1
        ");
        $stmt_ban->bind_param("i", $user_id);
        $stmt_ban->execute();
        $result_ban = $stmt_ban->get_result();
        
        if ($result_ban->num_rows > 0) {
            $ban_data = $result_ban->fetch_assoc();
            $expires_at = strtotime($ban_data['expires_at']);
            $now = time();
            
            // Check if ban has expired
            if ($now >= $expires_at) {
                // Ban expired, automatically unban
                $stmt_unban = $conn->prepare("UPDATE user_bans SET is_active = 0, unbanned_at = NOW() WHERE user_id = ? AND is_active = 1");
                $stmt_unban->bind_param("i", $user_id);
                $stmt_unban->execute();
                
                $stmt_status = $conn->prepare("UPDATE users SET user_status = 1 WHERE user_id = ?");
                $stmt_status->bind_param("i", $user_id);
                $stmt_status->execute();
                
                // Ban expired, allow access
                $stmt_unban->close();
                $stmt_status->close();
            } else {
                // Ban is still active - logout user and show ban message
                $ban_reason = htmlspecialchars($ban_data['ban_reason'] ?: 'Violation of terms');
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
                
                $expires_date = date('F j, Y \a\t g:i A', $expires_at);
                
                // Destroy session to logout user
                session_destroy();
                
                // Display ban page with auto-logout
                echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap");
        body { font-family: "Inter", sans-serif; }
        .countdown {
            font-size: 2rem;
            font-weight: bold;
            color: #ef4444;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4">
        <div class="text-center">
            <div class="bg-red-100 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-6 animate-pulse">
                <i class="fas fa-ban text-red-600 text-5xl"></i>
            </div>
            
            <h1 class="text-3xl font-bold text-gray-900 mb-3">Account Suspended</h1>
            <p class="text-gray-600 mb-6">You have been logged out automatically</p>
            
            <div class="bg-red-50 border-2 border-red-200 rounded-xl p-6 mb-6">
                <div class="text-sm text-gray-600 mb-2">Reason:</div>
                <div class="text-lg font-semibold text-red-700 mb-4">' . $ban_reason . '</div>
                
                ' . ($ban_data['ban_duration_days'] < 9999 ? '
                <div class="text-sm text-gray-600 mb-2">Time Remaining:</div>
                <div class="countdown" id="countdown">' . $duration_text . '</div>
                
                <div class="text-sm text-gray-500 mt-4">
                    <i class="far fa-clock mr-1"></i>
                    Expires: ' . $expires_date . '
                </div>
                ' : '
                <div class="text-lg font-bold text-red-700">
                    This is a permanent suspension
                </div>
                ') . '
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <p class="text-sm text-gray-700">
                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                    Your session has been terminated. You cannot access your account during this suspension.
                </p>
            </div>
            
            <a href="home.php" class="block w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold py-3 px-6 rounded-lg transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                <i class="fas fa-home mr-2"></i>Go to Home Page
            </a>
        </div>
    </div>
    
    <script>
        // Show alert on page load
        window.addEventListener("load", function() {
            Swal.fire({
                icon: "error",
                title: "Account Suspended!",
                html: "<strong>Reason:</strong> ' . $ban_reason . '<br><br>' . 
                      ($ban_data['ban_duration_days'] < 9999 ? 
                      '<strong>Duration:</strong> ' . $duration_text . '<br><strong>Expires:</strong> ' . $expires_date : 
                      '<strong>This is a permanent suspension</strong>') . '",
                confirmButtonText: "OK",
                confirmButtonColor: "#dc2626",
                allowOutsideClick: false,
                allowEscapeKey: false,
                customClass: {
                    popup: "animate__animated animate__shakeX"
                }
            });
        });
    </script>
    
    ' . ($ban_data['ban_duration_days'] < 9999 ? '
    <script>
        // Countdown timer
        const expiresAt = ' . $expires_at . ' * 1000;
        
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = expiresAt - now;
            
            if (distance < 0) {
                document.getElementById("countdown").innerHTML = "Ban expired";
                Swal.fire({
                    icon: "success",
                    title: "Ban Expired!",
                    text: "Your account suspension has ended. You can now login again.",
                    confirmButtonText: "Go to Home",
                    confirmButtonColor: "#10b981"
                }).then(() => {
                    window.location.href = "home.php";
                });
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            let countdownText = "";
            if (days > 0) countdownText += days + "d ";
            if (hours > 0 || days > 0) countdownText += hours + "h ";
            if (minutes > 0 || hours > 0 || days > 0) countdownText += minutes + "m ";
            countdownText += seconds + "s";
            
            document.getElementById("countdown").innerHTML = countdownText;
        }
        
        updateCountdown();
        setInterval(updateCountdown, 1000);
    </script>
    ' : '') . '
</body>
</html>';
                
                $stmt_ban->close();
                exit();
            }
        }
        $stmt_ban->close();
    }
}
$stmt_user->close();
?>
