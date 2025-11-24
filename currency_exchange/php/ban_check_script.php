<!-- Real-time Ban Check Script - Include this in all user pages -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Check ban status every 3 seconds
let banCheckInterval;
let isCheckingBan = false;

function checkBanStatus() {
    if (isCheckingBan) return; // Prevent multiple simultaneous checks
    
    isCheckingBan = true;
    
    fetch('check_ban_status.php')
        .then(response => response.json())
        .then(data => {
            if (data.banned) {
                // User has been banned - show alert and logout
                clearInterval(banCheckInterval); // Stop checking
                
                Swal.fire({
                    icon: 'error',
                    title: 'Account Suspended!',
                    html: '<strong>Reason:</strong> ' + data.reason + '<br><br>' +
                          (data.is_permanent ? 
                          '<strong>This is a permanent suspension</strong>' :
                          '<strong>Duration:</strong> ' + data.duration + '<br><strong>Expires:</strong> ' + data.expires_at),
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#dc2626',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showClass: {
                        popup: 'animate__animated animate__shakeX'
                    }
                }).then(() => {
                    // Logout and redirect
                    window.location.href = 'logout.php?banned=1';
                });
            }
            isCheckingBan = false;
        })
        .catch(error => {
            console.error('Ban check error:', error);
            isCheckingBan = false;
        });
}

// Start checking when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Check immediately
    checkBanStatus();
    
    // Then check every 3 seconds
    banCheckInterval = setInterval(checkBanStatus, 3000);
});

// Stop checking when page is hidden (performance optimization)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        clearInterval(banCheckInterval);
    } else {
        checkBanStatus();
        banCheckInterval = setInterval(checkBanStatus, 3000);
    }
});
</script>
