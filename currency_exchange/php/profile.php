<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    die("User not logged in.");
}

$userid = $_SESSION['user_id'];
$session_userimage = $_SESSION['userimage'] ?? '';

// Database connection
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SQL query (only username; avatar comes from session to avoid schema mismatch)
$sql = "SELECT username, user_walletAddress FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Profile</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary-color: #434190;
        --secondary-color: #48BB78;
        --text-color: #2D3748;
        --bg-color: #EDF2F7;
        --card-bg-color: #FFFFFF;
        --shadow-color: rgba(0, 0, 0, 0.05);
    }
    body {
        font-family: 'Poppins', sans-serif;
        background: var(--bg-color);
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        margin: 0;
    }
    .profile-card {
        background: var(--card-bg-color);
        border-radius: 1.5rem;
        box-shadow: 0 10px 15px -3px var(--shadow-color), 0 4px 6px -2px var(--shadow-color);
        padding: 2rem;
        width: 100%;
        max-width: 400px;
        text-align: center;
        animation: fadeIn 0.8s ease-out;
        position: relative;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .profile-image-container {
        position: relative;
        display: inline-block;
        margin-bottom: 1.5rem;
    }
    .profile-image {
        border-radius: 50%;
        width: 150px;
        height: 150px;
        object-fit: cover;
        border: 4px solid white;
        transition: transform 0.3s ease-in-out;
    }
    .icon-fallback {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        background: #e5e7eb;
        border: 4px solid white;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #374151;
        font-size: 2rem;
        user-select: none;
    }
    .edit-icon {
        position: absolute;
        bottom: 0;
        right: 0;
        background-color: white;
        color: white;
        border-radius: 50%;
        padding: 0.5rem;
        cursor: pointer;
        transition: background-color 0.3s ease-in-out;
        font-size: 1.2rem;
    }
    .edit-icon:hover {
        background-color: #e7e7f3ff;
    }
    .profile-card h2 {
        margin: 0 0 0.5rem 0;
        color: var(--text-color);
        font-size: 1.8rem;
        font-weight: 600;
    }
    .profile-card p {
        color: #718096;
        margin-top: 0;
        margin-bottom: 2rem;
    }
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 0.75rem;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 600;
        color: white;
        transition: all 0.3s ease-in-out;
    }
    .btn.primary {
        background: var(--primary-color);
        box-shadow: 0 4px 6px -1px rgba(90, 103, 216, 0.1), 0 2px 4px -1px rgba(90, 103, 216, 0.06);
    }
    .btn.primary:hover { background: #434190; transform: translateY(-2px); }
    .btn.secondary {
        background: var(--secondary-color);
        box-shadow: 0 4px 6px -1px rgba(72, 187, 120, 0.1), 0 2px 4px -1px rgba(72, 187, 120, 0.06);
    }
    .btn.secondary:hover { background: #38a169; transform: translateY(-2px); }
    .btn:active {
        transform: translateY(1px);
    }

    /* Back button style */
    .btn.back-btn {
        background: none;
        color: var(--text-color);
        border: 1px solid #CBD5E0;
        font-size: 0.9rem;
        transition: all 0.3s ease-in-out;
        box-shadow: none;
    }
    .btn.back-btn:hover {
        background: #E2E8F0;
        transform: translateY(-2px);
    }
    .btn.back-btn i {
        margin-right: 8px; /* Adds space between icon and text */
    }

    /* Modal styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        animation: modalFadeIn 0.3s ease-in;
    }
    @keyframes modalFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .modal-content {
        background-color: var(--card-bg-color);
        margin: 8% auto;
        padding: 2rem;
        border-radius: 1.5rem;
        width: 90%;
        max-width: 450px;
        position: relative;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        animation: modalSlideDown 0.4s ease-out;
    }
    @keyframes modalSlideDown {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .close-btn {
        color: #0c0c0cff;
        position: absolute;
        top: 1rem;
        right: 1.5rem;
        font-size: 2rem;
        font-weight: bold;
        cursor: pointer;
        transition: color 0.3s;
    }
    .close-btn:hover {
        color: #718096;
    }
    .modal-content h3 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 1.5rem;
        color: var(--text-color);
        font-size: 1.5rem;
    }
    .form-group {
        margin-bottom: 1.25rem;
        text-align: left;
    }
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-color);
        font-weight: 600;
    }
    .form-group input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #E2E8F0;
        border-radius: 0.5rem;
        box-sizing: border-box;
        font-size: 1rem;
        transition: border-color 0.3s;
    }
    .form-group input:focus {
        outline: none;
        border-color: var(--primary-color);
    }
    #passwordMessage {
        margin-top: 1rem;
        text-align: center;
        font-weight: 600;
    }
    .edit-icon svg {
        width: 14px;
        height: auto;
    }
    #resetPinBtn{
        text-decoration : none;
    }
    /* Username inline edit styles */
    .username-text {
        text-decoration: underline;
    }
    .username-edit {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-color);
        padding: 0;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .username-edit:hover { opacity: 0.8; }
</style>

</head>
<body>
<?php if ($result->num_rows > 0):
    $row = $result->fetch_assoc();
    // Derive image path from session
    $image_path = '';
    if (!empty($session_userimage)) {
        $upload_dir = __DIR__ . '/uploads/';
        if (file_exists($upload_dir . $session_userimage)) {
            $image_path = 'uploads/' . $session_userimage;
        } else {
            $base = pathinfo($session_userimage, PATHINFO_FILENAME);
            foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
                if (file_exists($upload_dir . $base . '.' . $ext)) {
                    $image_path = 'uploads/' . $base . '.' . $ext;
                    break;
                }
            }
        }
    }
?>
<div class='profile-card'>
    <a href="dashboard.php" class="btn back-btn" id="backBtn" style="position: absolute; top: 1rem; left: 1rem;">
        <i class="fas fa-arrow-left"></i>
    </a>

    <div class="profile-image-container">
        <?php if (!empty($image_path)) : ?>
            <img id="profileImage" class="profile-image" src='<?php echo htmlspecialchars($image_path); ?>' alt='User Image'>
        <?php else: ?>
            <img id="profileImage" class="profile-image" src='' alt='User Image' style="display:none;">
            <div id="profileFallback" class="icon-fallback"><i class="fa fa-user"></i></div>
        <?php endif; ?>
        <div class="edit-icon" id="editImageBtn">
           <svg width="800px" height="800px" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
             <path
                 fill-rule="evenodd"
                 clip-rule="evenodd"
                 d="M2 3C1.44772 3 1 3.44772 1 4V11C1 11.5523 1.44772 12 2 12H13C13.5523 12 14 11.5523 14 11V4C14 3.44772 13.5523 3 13 3H2ZM0 4C0 2.89543 0.895431 2 2 2H13C14.1046 2 15 2.89543 15 4V11C15 12.1046 14.1046 13 13 13H2C0.895431 13 0 12.1046 0 11V4ZM2 4.25C2 4.11193 2.11193 4 2.25 4H4.75C4.88807 4 5 4.11193 5 4.25V5.75454C5 5.89261 4.88807 6.00454 4.75 6.00454H2.25C2.11193 6.00454 2 5.89261 2 5.75454V4.25ZM12.101 7.58421C12.101 9.02073 10.9365 10.1853 9.49998 10.1853C8.06346 10.1853 6.89893 9.02073 6.89893 7.58421C6.89893 6.14769 8.06346 4.98315 9.49998 4.98315C10.9365 4.98315 12.101 6.14769 12.101 7.58421ZM13.101 7.58421C13.101 9.57302 11.4888 11.1853 9.49998 11.1853C7.51117 11.1853 5.89893 9.57302 5.89893 7.58421C5.89893 5.5954 7.51117 3.98315 9.49998 3.98315C11.4888 3.98315 13.101 5.5954 13.101 7.58421Z"
                 fill="#000000"
             />
           </svg>
        </div>
    </div>
    <h2 style="display:flex; align-items:center; justify-content:center; gap:8px;">
        <button class="username-edit" id="editUsernameBtn" title="Edit username" aria-label="Edit username">
            <i class="fas fa-pen"></i>
        </button>
        <span class="username-text" id="usernameText"><?php echo htmlspecialchars($row['username']); ?></span>
    </h2>
    <p>Welcome to your profile!</p>

    <div style="margin: 1rem 0; text-align:left;">
        <div style="color:#718096; font-size:.9rem; margin-bottom:.35rem;">Wallet Address</div>
        <div style="display:flex; gap:.5rem; align-items:stretch;">
            <input type="text" id="walletAddressInput" readonly value="<?php echo htmlspecialchars($row['user_walletAddress'] ?? ''); ?>" style="flex:1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color:#2D3748; background:#F8FAFC; border:1px solid #E2E8F0; padding:.6rem .75rem; border-radius:.5rem;" />
            <button class='btn secondary' id="copyWalletBtn" style="white-space:nowrap;">Copy</button>
        </div>
    </div>

    <input type="file" id="imageInput" name="userimage" style="display:none" accept="image/*">

    <div class="btn-group">
        <button class='btn primary' id="changePasswordBtn">Change Password</button>
        <a href="pin_reset_request.php" class='btn secondary' id="resetPinBtn"><i class="fas fa-key" style="margin-right:8px;"></i>Reset PIN</a>
        <button class='btn primary' id="loginHistoryBtn"><i class="fas fa-clock" style="margin-right:8px;"></i>Login History</button>
    </div>
</div>

<!-- Login History Modal -->
<div id="loginHistoryModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" id="closeLoginHistory">&times;</span>
        <h3>Login History</h3>
        <div id="loginHistoryBody" style="max-height: 360px; overflow:auto; text-align:left;">
            <p style="color:#718096;">Loading...</p>
        </div>
    </div>
</div>

<div id="changePasswordModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" id="closePassword">&times;</span>
        <h3>Change Password</h3>
        <form id="changePasswordForm" method="POST">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" name="current_password" id="current_password" required>
            </div>
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" name="new_password" id="new_password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" name="confirm_password" id="confirm_password" required>
            </div>
            <button type="submit" class="btn secondary">Change Password</button>
            <p id="passwordMessage"></p>
        </form>
    </div>
</div>

<!-- Change Username Modal -->
<div id="changeUsernameModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" id="closeUsername">&times;</span>
        <h3>Change Username</h3>
        <form id="changeUsernameForm" method="POST">
            <div class="form-group">
                <label for="new_username">New Username</label>
                <input type="text" name="new_username" id="new_username" required minlength="3" maxlength="20" placeholder="Enter new username">
                <small style="color:#718096;">3-20 characters.</small>
            </div>
            <button type="submit" class="btn secondary">Update Username</button>
            <p id="usernameMessage"></p>
        </form>
    </div>
    </div>

<script>
// open file explorer when clicking edit button
document.getElementById("editImageBtn").onclick = () => {
    document.getElementById("imageInput").click();
};

// when a file is selected, auto-upload
document.getElementById("imageInput").onchange = function() {
    const file = this.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append("userimage", file);

    fetch("edit_image.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // update image without reload and swap fallback -> image
            const imgEl = document.getElementById("profileImage");
            const fallbackEl = document.getElementById("profileFallback");
            imgEl.src = data.newImagePath + "?t=" + new Date().getTime();
            imgEl.style.display = "block";
            if (fallbackEl) fallbackEl.style.display = "none";
        } else {
            alert(data.message || "Upload failed.");
        }
    })
    .catch(() => alert("An error occurred."));
};

// Password modal
const passwordModal = document.getElementById("changePasswordModal");
document.getElementById("changePasswordBtn").onclick = () => passwordModal.style.display = "block";
document.getElementById("closePassword").onclick = () => passwordModal.style.display = "none";
window.onclick = (event) => {
    if(event.target == passwordModal) passwordModal.style.display = "none";
};

// AJAX password change
const changePasswordForm = document.getElementById('changePasswordForm');
const passwordMessage = document.getElementById('passwordMessage');

changePasswordForm.addEventListener('submit', function(e){
    e.preventDefault();
    const formData = new FormData(this);
    fetch('change_password.php', { method: 'POST', body: formData })
    .then(res => res.text())
    .then(data => {
        const isSuccess = data.toLowerCase().includes("success");
        passwordMessage.textContent = data;
        passwordMessage.style.color = isSuccess ? "green" : "red";
        if (isSuccess) {
            this.reset();
            passwordModal.style.display = "none";
            passwordMessage.textContent = "";
            alert('Password changed successfully');
        }
    })
    .catch(() => {
        passwordMessage.textContent = "An error occurred.";
        passwordMessage.style.color = "red";
    });
});

// Login History modal
const loginHistoryModal = document.getElementById('loginHistoryModal');
const closeLoginHistory = document.getElementById('closeLoginHistory');
const loginHistoryBtn = document.getElementById('loginHistoryBtn');
const loginHistoryBody = document.getElementById('loginHistoryBody');

// Helper to format date like: 11 Nov 2025, 13:20
function formatLoginAt(ts) {
    if (!ts) return '';
    try {
        const d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d.getTime())) return ts;
        const dd = String(d.getDate()).padStart(2, '0');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const mon = months[d.getMonth()];
        const yyyy = d.getFullYear();
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return `${dd} ${mon} ${yyyy}, ${hh}:${mm}`;
    } catch (_) { return ts; }
}

// Close on "X" click
closeLoginHistory.onclick = () => { loginHistoryModal.style.display = 'none'; };

loginHistoryBtn.onclick = () => {
    loginHistoryModal.style.display = 'block';
    loginHistoryBody.innerHTML = '<p style="color:#718096;">Loading...</p>';
    fetch('get_login_history.php')
      .then(r => r.json())
      .then(data => {
        if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
            loginHistoryBody.innerHTML = '<p style="color:#718096;">No login history found.</p>';
            return;
        }
        const rows = data.items.map(ev => `
          <tr style="border-bottom:1px solid #F1F5F9;">
            <td style="padding:8px; color:#2D3748;">${formatLoginAt(ev.login_at)}</td>
            <td title="${ev.ip_address || ''}" style="padding:8px; color:#2D3748; word-break: break-all; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">${ev.ip_address || ''}</td>
            <td style="padding:8px; color:#2D3748;">${ev.browser_name || ''}</td>
            <td title="${ev.device || ''}" style="padding:8px; color:#2D3748;">${ev.device || '-'}</td>
          </tr>
        `).join('');
        loginHistoryBody.innerHTML = `
          <div style="text-align:left;">
            <table style="width:100%; border-collapse: collapse; font-size: 0.95rem;">
              <thead>
                <tr style="border-bottom:1px solid #E2E8F0; color:#718096;">
                  <th style="text-align:left; padding:8px;">Login At</th>
                  <th style="text-align:left; padding:8px;">IP Address</th>
                  <th style="text-align:left; padding:8px;">Browser</th>
                  <th style="text-align:left; padding:8px;">Device</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>`;
      })
      .catch(() => {
        loginHistoryBody.innerHTML = '<p style="color:red;">Failed to load login history.</p>';
      });
};

window.addEventListener('click', function(e){ if (e.target === loginHistoryModal) loginHistoryModal.style.display = 'none'; });
    
const editUsernameBtn = document.getElementById("editUsernameBtn");
const closeUsername = document.getElementById("closeUsername");
const changeUsernameForm = document.getElementById('changeUsernameForm');
const usernameMessage = document.getElementById('usernameMessage');
const usernameText = document.getElementById('usernameText');
const usernameModal = document.getElementById('changeUsernameModal');

editUsernameBtn.onclick = () => usernameModal.style.display = "block";
closeUsername.onclick = () => usernameModal.style.display = "none";

// Close modal when clicking outside
window.addEventListener('click', function(e){
    if (e.target === usernameModal) usernameModal.style.display = 'none';
});

// AJAX username change
changeUsernameForm.addEventListener('submit', function(e){
    e.preventDefault();
    const formData = new FormData(this);
    fetch('change_username.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        const isSuccess = !!data.success;
        usernameMessage.textContent = data.message || (isSuccess ? 'Username updated.' : 'Update failed.');
        usernameMessage.style.color = isSuccess ? 'green' : 'red';
        if (isSuccess && data.new_username) {
            // Update displayed username text
            if (usernameText) usernameText.textContent = data.new_username;
            // Close modal and reset
            this.reset();
            setTimeout(() => { usernameModal.style.display = 'none'; usernameMessage.textContent=''; }, 600);
        }
    })
    .catch(() => {
        usernameMessage.textContent = 'An error occurred.';
        usernameMessage.style.color = 'red';
    });
});

const copyWalletBtn = document.getElementById('copyWalletBtn');
if (copyWalletBtn) {
    copyWalletBtn.onclick = () => {
        const inp = document.getElementById('walletAddressInput');
        const val = (inp && inp.value) ? inp.value.trim() : '';
        if (!val) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val)
                .then(() => alert('Copied'))
                .catch(() => alert('Copy failed'));
        } else {
            inp.focus();
            inp.select();
            try { document.execCommand('copy'); alert('Copied'); } catch (_) { alert('Copy failed'); }
            inp.setSelectionRange(inp.value.length, inp.value.length);
        }
    };
}

// Back button logic to force reload
document.getElementById('backBtn').addEventListener('click', function(e) {
    e.preventDefault();
    window.location.href = this.href + '?reload=' + new Date().getTime();
});

</script>

<?php else: echo "<p>No user found.</p>"; endif;
$stmt->close();
$conn->close();
?>
</body>
</html>