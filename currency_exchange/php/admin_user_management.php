<?php
session_start();

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$message = '';

// Check if the admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle ban/unban actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'ban_user' && isset($_POST['user_id']) && isset($_POST['ban_duration'])) {
        $user_id = intval($_POST['user_id']);
        $ban_duration = intval($_POST['ban_duration']);
        $ban_reason = isset($_POST['ban_reason']) ? trim($_POST['ban_reason']) : 'Violation of terms';
        
        $conn->begin_transaction();
        try {
            // Calculate expiration date
            if ($ban_duration == 9999) {
                // Permanent ban - set to 100 years from now
                $expires_at = date('Y-m-d H:i:s', strtotime('+100 years'));
            } else {
                $expires_at = date('Y-m-d H:i:s', strtotime("+{$ban_duration} days"));
            }
            
            // Insert ban record
            $stmt_ban = $conn->prepare("INSERT INTO user_bans (user_id, banned_by_admin_id, ban_reason, ban_duration_days, expires_at) VALUES (?, ?, ?, ?, ?)");
            $stmt_ban->bind_param("iisis", $user_id, $admin_id, $ban_reason, $ban_duration, $expires_at);
            $stmt_ban->execute();
            
            // Update user status (user_status: 0 = banned, 1 = active)
            $stmt_status = $conn->prepare("UPDATE users SET user_status = 0 WHERE user_id = ?");
            $stmt_status->bind_param("i", $user_id);
            $stmt_status->execute();
            
            $conn->commit();
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4'>User has been banned successfully.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>Error banning user: " . $e->getMessage() . "</div>";
        }
    } elseif ($_POST['action'] === 'unban_user' && isset($_POST['user_id'])) {
        $user_id = intval($_POST['user_id']);
        
        $conn->begin_transaction();
        try {
            // Deactivate all active bans
            $stmt_unban = $conn->prepare("UPDATE user_bans SET is_active = 0, unbanned_at = NOW() WHERE user_id = ? AND is_active = 1");
            $stmt_unban->bind_param("i", $user_id);
            $stmt_unban->execute();
            
            // Update user status (user_status: 0 = banned, 1 = active)
            $stmt_status = $conn->prepare("UPDATE users SET user_status = 1 WHERE user_id = ?");
            $stmt_status->bind_param("i", $user_id);
            $stmt_status->execute();
            
            $conn->commit();
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4'>User has been unbanned successfully.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>Error unbanning user: " . $e->getMessage() . "</div>";
        }
    }
}

// Fetch all users with their ban status
$users = [];
$stmt_users = $conn->prepare("
    SELECT 
        u.user_id, 
        u.username, 
        u.email, 
        u.user_status,
        ub.ban_id,
        ub.ban_reason,
        ub.ban_duration_days,
        ub.banned_at,
        ub.expires_at,
        ub.is_active
    FROM users u
    LEFT JOIN user_bans ub ON u.user_id = ub.user_id AND ub.is_active = 1
    ORDER BY u.username ASC
");
$stmt_users->execute();
$result_users = $stmt_users->get_result();
while ($row = $result_users->fetch_assoc()) {
    $users[] = $row;
}
$stmt_users->close();
// Pending requests count for notifications badge (to keep navbar consistent with dashboard)
$pending_count = 0;
$stmt_pending = $conn->prepare("SELECT COUNT(*) AS cnt FROM user_currency_requests WHERE status = 'pending'");
if ($stmt_pending) {
    $stmt_pending->execute();
    $res_p = $stmt_pending->get_result();
    if ($res_p) { $row_p = $res_p->fetch_assoc(); $pending_count = isset($row_p['cnt']) ? (int)$row_p['cnt'] : 0; }
    $stmt_pending->close();
}

// Compute unseen notifications count based on what was seen on Notifications view
$badge_count = $pending_count - (isset($_SESSION['seen_pending_count']) ? (int)$_SESSION['seen_pending_count'] : 0);
if ($badge_count < 0) { $badge_count = 0; }

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        /* Admin theme palette (medium-dark + teal) */
        .admin-theme {
            --admin-bg: #182233;         /* medium-dark background */
            --admin-surface: #202B3D;    /* elevated surface */
            --admin-primary: #14B8A6;    /* teal */
            --admin-primary-2: #2DD4BF;  /* teal accent */
            --admin-text: #E8EEF6;       /* light text */
            --admin-text-muted: #BAC6D8; /* muted text */
            --admin-border: rgba(255, 255, 255, 0.12);
            --admin-shadow: rgba(0, 0, 0, 0.5);
        }
        .glass-nav { background: rgba(24, 34, 51, 0.7); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-bottom: 1px solid var(--admin-border); }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            /* Center the modal content */
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-content {
            background-color: var(--admin-surface);
            color: var(--admin-text);
            margin: 0;
            padding: 0;
            border-radius: 1rem;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            border: 1px solid var(--admin-border);
            box-shadow: 0 20px 25px -5px var(--admin-shadow), 0 10px 10px -5px var(--admin-shadow);
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover,
        .close:focus {
            color: #000;
        }
        /* Dark theme overrides using admin palette */
        .dark-theme { background-color: var(--admin-bg); color: var(--admin-text); }
        .dark-theme .bg-white { background-color: var(--admin-surface) !important; }
        .dark-theme .bg-gray-50 { background-color: var(--admin-bg) !important; }
        .dark-theme .bg-gray-100 { background-color: var(--admin-bg) !important; }
        .dark-theme .text-gray-900, .dark-theme .text-gray-800 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-700 { color: var(--admin-text) !important; }
        .dark-theme .text-gray-600, .dark-theme .text-gray-500 { color: var(--admin-text-muted) !important; }
        .dark-theme .border-gray-100, .dark-theme .border-gray-200, .dark-theme .border-gray-300 { border-color: var(--admin-border) !important; }
        .dark-theme .rounded-xl, .dark-theme .rounded-lg { border: 1px solid var(--admin-border); }
        .dark-theme input, .dark-theme select, .dark-theme textarea { background-color: var(--admin-surface); border-color: var(--admin-border); color: var(--admin-text); }
        .dark-theme table { background-color: var(--admin-surface) !important; }
        /* Hover lift utility */
        .hover-lift { transition: transform 150ms ease, box-shadow 150ms ease; }
        .hover-lift:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 20px 25px -5px var(--admin-shadow), 0 10px 10px -5px var(--admin-shadow); }
        /* Subtle row lift for user entries */
        .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
        .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px var(--admin-shadow); background-color: var(--admin-bg); }
        .notification-badge { position: absolute; top: -8px; right: -8px; background-color: #ef4444; color: white; border-radius: 9999px; padding: 0 6px; font-size: 10px; line-height: 18px; min-width: 18px; text-align: center; }
        /* Wallet modal: ensure light cards use dark text for contrast */
        .wallet-scope .text-gray-900 { color: #0F172A !important; }
        .wallet-scope .text-gray-700 { color: #334155 !important; }
        .wallet-scope .text-gray-600 { color: #475569 !important; }
        .wallet-scope .text-gray-500 { color: #64748B !important; }
    </style>
    <?php $embed = isset($_GET['embed']); if ($embed): ?>
    <style>
        body { background: transparent !important; padding-top: 0 !important; }
        nav { display: none !important; }
        .container { margin: 0 !important; padding: 0 !important; max-width: none !important; width: 100% !important; }
        .bg-white.p-6.rounded-xl.shadow-lg { background: transparent !important; box-shadow: none !important; padding: 0 !important; border-radius: 0 !important; }
        .flex.items-center.space-x-4.mb-6, .container hr { display: none !important; }
    </style>
    <?php endif; ?>
 </head>
<body class="admin-theme dark-theme">

    <?php if (!$embed): ?>
    <nav class="glass-nav w-full p-3 md:p-4 shadow-xl">
        <div class="w-full max-w-7xl mx-auto flex items-center justify-between">
            <div class="text-lg md:text-xl font-bold text-gray-800">Admin Panel</div>
            <div class="flex items-center space-x-6">
                <a href="admin.php" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Dashboard">
                    <i class="fas fa-home text-2xl"></i>
                    <span class="sr-only">Dashboard</span>
                </a>
                <a href="admin_user_management.php" class="text-blue-600 font-bold text-xl" title="User Management">
                    <i class="fas fa-users-cog text-2xl"></i>
                    <span class="sr-only">User Management</span>
                </a>
                <a href="admin.php?view=requests" class="relative text-gray-700 hover:text-blue-600 font-medium text-xl" title="Notifications">
                    <i class="fas fa-bell text-2xl"></i>
                    <?php if (isset($badge_count) && $badge_count > 0): ?>
                        <span class="notification-badge"><?php echo $badge_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="admin.php?view=history" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Transaction History">
                    <i class="fas fa-history text-2xl"></i>
                    <span class="sr-only">Transaction History</span>
                </a>
                <a href="admin.php?view=fees" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Conversion Fees">
                    <i class="fas fa-coins text-2xl"></i>
                    <span class="sr-only">Conversion Fees</span>
                </a>
                <a href="admin.php?view=exchange_rate" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Exchange Rate">
                    <i class="fas fa-exchange-alt text-2xl"></i>
                    <span class="sr-only">Exchange Rate</span>
                </a>
                <a href="admin_logout.php" class="text-gray-700 hover:text-blue-600 font-medium text-xl" title="Logout">
                    <i class="fas fa-sign-out-alt text-2xl"></i>
                    <span class="sr-only">Logout</span>
                </a>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center space-x-4">
                    <div class="bg-blue-600 rounded-full h-12 w-12 flex items-center justify-center text-white text-xl font-bold">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">User Management</h2>
                        <p class="text-gray-500">View user details, wallets, P2P history, and manage bans</p>
                    </div>
                </div>
                <!-- Top-right search (25% width) -->
                <div class="hidden md:block w-1/4 relative">
                    <i class="fas fa-search absolute left-3 top-1/2 text-gray-400 pointer-events-none" aria-hidden="true" style="transform: translateY(-58%); margin-top: 1px;"></i>
                    <input id="adminUserSearch" type="text" placeholder="Search users..." class="w-full pl-10 p-3 rounded-lg border border-gray-600 bg-transparent focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
            </div>

    <!-- Unban Confirm Modal -->
    <div id="unbanModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeUnbanModal()">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-unlock mr-2"></i>Confirm Unban</h3>
                <p class="text-green-100 text-sm mt-1">Restore access for this user</p>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="unban_user">
                <input type="hidden" name="user_id" id="unban_user_id">
                <div class="mb-4">
                    <p class="text-gray-700">Are you sure you want to unban <span id="unban_username" class="font-semibold text-green-700"></span>?</p>
                    <p class="text-gray-500 text-sm mt-1">This will deactivate any active bans and set the account status to Active.</p>
                </div>
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">
                        Unban User
                    </button>
                    <button type="button" onclick="closeUnbanModal()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-3 px-4 rounded-lg transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>

            

            <?php if (!empty($message)): ?>
                <?php echo $message; ?>
            <?php endif; ?>

            <div class="overflow-x-auto">
                <style>
                    .hover-lift-row { transition: transform 120ms ease, box-shadow 120ms ease, background-color 120ms ease; }
                    .hover-lift-row:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px var(--admin-shadow); background-color: var(--admin-bg); }
                    .table-bank-history { border-collapse: separate; border-spacing: 0; }
                    .table-bank-history thead tr { border-bottom: 0 !important; }
                    .table-bank-history tbody tr { border-bottom: 1px solid var(--admin-border) !important; }
                    .table-bank-history tbody tr:last-child { border-bottom-color: rgba(255,255,255,0.08) !important; }
                    .table-bank-history thead th { position: sticky; top: 0; z-index: 10; background-color: var(--admin-bg); }
                    .bank-history-scroll { scrollbar-color: #3b4557 var(--admin-bg); scrollbar-width: thin; }
                    .bank-history-scroll::-webkit-scrollbar { width: 10px; }
                    .bank-history-scroll::-webkit-scrollbar-track { background: var(--admin-bg); border-radius: 8px; }
                    .bank-history-scroll::-webkit-scrollbar-thumb { background-color: #3b4557; border-radius: 8px; border: 2px solid var(--admin-bg); }
                    .bank-history-scroll::-webkit-scrollbar-thumb:hover { background-color: #4b5563; }
                </style>
                <?php if (count($users) > 0): ?>
                    <!-- Scroll container limits height to ~10 rows; adjust if needed -->
                    <div class="max-h-[560px] overflow-y-auto rounded-lg bank-history-scroll">
                    <table class="min-w-full table-bank-history">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="py-3 px-4">User ID</th>
                                <th class="py-3 px-4">Username</th>
                                <th class="py-3 px-4">Email</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTbody" class="bg-white">
                            <?php foreach ($users as $user): ?>
                                <tr class="border-b border-gray-200 hover:bg-gray-50 hover-lift-row">
                                    <td class="py-3 px-4 text-sm text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($user['user_id']); ?></td>
                                    <td class="py-3 px-4 text-sm font-medium text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td class="py-3 px-4 text-sm text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td class="py-3 px-4 text-sm">
                                        <?php if ($user['user_status'] == 0 && $user['is_active'] == 1): ?>
                                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                <i class="fas fa-ban mr-1"></i> Banned
                                            </span>
                                            <div class="text-xs text-gray-500 mt-1">
                                                Expires: <?php echo date('M j, Y H:i', strtotime($user['expires_at'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i> Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-right">
                                        <div class="flex justify-end gap-2 flex-wrap md:flex-nowrap">
                                            <button onclick="viewWallet(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                    class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift">
                                                <i class="fas fa-wallet mr-1"></i> Wallet
                                            </button>
                                            <button onclick="viewP2PHistory(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                    class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift">
                                                <i class="fas fa-exchange-alt mr-1"></i> P2P
                                            </button>
                                            <?php if ($user['user_status'] == 0 && $user['is_active'] == 1): ?>
                                                <button onclick="showUnbanModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')"
                                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift">
                                                    <i class="fas fa-unlock mr-1"></i> Unban
                                                </button>
                                            <?php else: ?>
                                                <button onclick="showBanModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                        class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift">
                                                    <i class="fas fa-ban mr-1"></i> Ban
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-users text-5xl text-gray-400 mb-4"></i>
                        <p class="text-gray-500 text-lg">No users found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ban Modal -->
    <div id="banModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-red-500 to-red-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeBanModal()">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-ban mr-2"></i>Ban User</h3>
                <p class="text-red-100 text-sm mt-1">Suspend user account for a specified duration</p>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="ban_user">
                <input type="hidden" name="user_id" id="ban_user_id">
                
                <div class="mb-4">
                    <label class="block text-gray-700 font-bold mb-2">User: <span id="ban_username" class="text-blue-600"></span></label>
                </div>
                
                <div class="mb-4">
                    <label for="ban_duration" class="block text-gray-700 font-bold mb-2">Ban Duration</label>
                    <select name="ban_duration" id="ban_duration" required class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="1">1 Day</option>
                        <option value="3">3 Days</option>
                        <option value="7">7 Days (1 Week)</option>
                        <option value="30">30 Days (1 Month)</option>
                        <option value="9999">Permanent</option>
                    </select>
                </div>
                
                <div class="mb-6">
                    <label for="ban_reason" class="block text-gray-700 font-bold mb-2">Reason (Optional)</label>
                    <textarea name="ban_reason" id="ban_reason" rows="3" 
                              class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"
                              placeholder="Enter reason for ban..."></textarea>
                </div>
                
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">
                        <i class="fas fa-ban mr-2"></i>Ban User
                    </button>
                    <button type="button" onclick="closeBanModal()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-3 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Wallet Modal -->
    <div id="walletModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeWalletModal()">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-wallet mr-2"></i>User Wallet</h3>
                <p class="text-blue-100 text-sm mt-1">View user's wallet balances</p>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <label class="block text-gray-700 font-bold mb-2">User: <span id="wallet_username" class="text-blue-600"></span></label>
                </div>
                <div id="walletContent" class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading wallet data...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- P2P History Modal -->
    <div id="p2pModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeP2PModal()">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-exchange-alt mr-2"></i>P2P Transaction History</h3>
                <p class="text-purple-100 text-sm mt-1">View user's peer-to-peer trades</p>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <label class="block text-gray-700 font-bold mb-2">User: <span id="p2p_username" class="text-purple-600"></span></label>
                </div>
                <div id="p2pContent" class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i>
                    <p class="text-gray-500 mt-2">Loading P2P history...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Confirm Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-gray-700 to-gray-800 text-white p-6 rounded-t-xl">
                <span class="close" onclick="closeLogoutModal()">&times;</span>
                <h3 class="text-2xl font-bold"><i class="fas fa-sign-out-alt mr-2"></i>Confirm Logout</h3>
                <p class="text-gray-200 text-sm mt-1">End your admin session</p>
            </div>
            <div class="p-6 bg-gray-100">
                <p class="text-gray-300 mb-6">Are you sure you want to log out?</p>
                <div class="flex space-x-3">
                    <a id="confirmLogoutBtn" href="#" class="flex-1 text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg transition-colors">Logout</a>
                    <button type="button" onclick="closeLogoutModal()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg border border-gray-600 hover:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-colors">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- Dynamic Search Logic ---
        (function(){
            const input = document.getElementById('adminUserSearch');
            const tbody = document.getElementById('usersTbody');
            if (!input || !tbody) return;

            let timer = null;
            const debounce = (fn, delay=300) => {
                return (...args) => {
                    clearTimeout(timer);
                    timer = setTimeout(() => fn.apply(null, args), delay);
                };
            };

            function esc(s){
                const div = document.createElement('div');
                div.textContent = s == null ? '' : String(s);
                return div.innerHTML;
            }

            function formatExpire(ts){
                if (!ts) return '';
                try { return new Date(ts.replace(' ', 'T')).toLocaleString(); } catch(e){ return esc(ts); }
            }

            function renderUsers(users){
                if (!Array.isArray(users)) users = [];
                if (users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No users found</td></tr>';
                    return;
                }
                const rows = users.map(u => {
                    const banned = String(u.user_status) === '0' && String(u.is_active) === '1';
                    const statusHtml = banned
                        ? '<span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-ban mr-1"></i> Banned</span>'
                          + (u.expires_at ? ('<div class="text-xs text-gray-500 mt-1">Expires: ' + esc(formatExpire(u.expires_at)) + '</div>') : '')
                        : '<span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i> Active</span>';

                    const walletBtn = `<button onclick="viewWallet(${Number(u.user_id)}, '${esc(u.username)}')" class=\"bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift\"><i class=\"fas fa-wallet mr-1\"></i> Wallet</button>`;
                    const p2pBtn = `<button onclick="viewP2PHistory(${Number(u.user_id)}, '${esc(u.username)}')" class=\"bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift\"><i class=\"fas fa-exchange-alt mr-1\"></i> P2P</button>`;
                    const banBtn = banned
                        ? `<button onclick=\"showUnbanModal(${Number(u.user_id)}, '${esc(u.username)}')\" class=\"bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift\"><i class=\"fas fa-unlock mr-1\"></i> Unban</button>`
                        : `<button onclick=\"showBanModal(${Number(u.user_id)}, '${esc(u.username)}')\" class=\"bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs font-medium hover-lift\"><i class=\"fas fa-ban mr-1\"></i> Ban</button>`;

                    return `
                        <tr class=\"border-b border-gray-200 hover:bg-gray-50 hover-lift-row\">
                            <td class=\"py-3 px-4 text-sm text-gray-700 whitespace-nowrap\">${esc(u.user_id)}</td>
                            <td class=\"py-3 px-4 text-sm font-medium text-gray-900 whitespace-nowrap\">${esc(u.username)}</td>
                            <td class=\"py-3 px-4 text-sm text-gray-700 whitespace-nowrap\">${esc(u.email)}</td>
                            <td class=\"py-3 px-4 text-sm\">${statusHtml}</td>
                            <td class=\"py-3 px-4 text-sm text-right\">
                                <div class=\"flex justify-end gap-2 flex-wrap md:flex-nowrap\">
                                    ${walletBtn}
                                    ${p2pBtn}
                                    ${banBtn}
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
                tbody.innerHTML = rows;
            }

            const performSearch = debounce(() => {
                const q = input.value.trim();
                fetch('admin_search_users.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => { if (data && data.success) { renderUsers(data.users); } else { renderUsers([]); } })
                    .catch(() => renderUsers([]));
            }, 250);

            input.addEventListener('input', performSearch);
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); performSearch(); } });
        })();
        function showBanModal(userId, username) {
            document.getElementById('ban_user_id').value = userId;
            document.getElementById('ban_username').textContent = username;
            document.getElementById('banModal').style.display = 'flex';
        }

        function closeBanModal() {
            document.getElementById('banModal').style.display = 'none';
        }

        function showUnbanModal(userId, username) {
            document.getElementById('unban_user_id').value = userId;
            document.getElementById('unban_username').textContent = username;
            document.getElementById('unbanModal').style.display = 'flex';
        }

        function closeUnbanModal() {
            document.getElementById('unbanModal').style.display = 'none';
        }

        function viewWallet(userId, username) {
            document.getElementById('wallet_username').textContent = username;
            document.getElementById('walletModal').style.display = 'flex';
            document.getElementById('walletContent').innerHTML = '<i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i><p class="text-gray-500 mt-2">Loading wallet data...</p>';
            
            // Fetch wallet data
            fetch('admin_get_user_wallet.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div class="wallet-scope grid grid-cols-1 md:grid-cols-2 gap-4">';
                        if (data.wallets.length > 0) {
                            data.wallets.forEach(wallet => {
                                html += `
                                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-sm text-gray-600">${wallet.name}</p>
                                                <p class="text-2xl font-bold text-gray-900">${parseFloat(wallet.balance).toFixed(2)}</p>
                                            </div>
                                            <div class="bg-blue-500 text-white rounded-full w-12 h-12 flex items-center justify-center font-bold">
                                                ${wallet.symbol}
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html += '<div class="col-span-2 text-center py-4 text-gray-500">No wallet balances found</div>';
                        }
                        html += '</div>';
                        document.getElementById('walletContent').innerHTML = html;
                    } else {
                        document.getElementById('walletContent').innerHTML = '<div class="text-center text-red-500">Error loading wallet data</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('walletContent').innerHTML = '<div class="text-center text-red-500">Error: ' + error.message + '</div>';
                });
        }

        function closeWalletModal() {
            document.getElementById('walletModal').style.display = 'none';
        }

        function viewP2PHistory(userId, username) {
            document.getElementById('p2p_username').textContent = username;
            document.getElementById('p2pModal').style.display = 'flex';
            document.getElementById('p2pContent').innerHTML = '<i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i><p class="text-gray-500 mt-2">Loading P2P history...</p>';
            
            // Fetch P2P history
            fetch('admin_get_user_p2p.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">';
                        html += '<thead class="bg-gray-50"><tr>';
                        html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>';
                        html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>';
                        html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>';
                        html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>';
                        html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';
                        
                        if (data.transactions.length > 0) {
                            data.transactions.forEach(tx => {
                                html += `
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-gray-700">${tx.type}</td>
                                        <td class="px-4 py-2 text-sm font-medium">${parseFloat(tx.amount_sold).toFixed(2)} ${tx.sell_currency}</td>
                                        <td class="px-4 py-2 text-sm text-gray-600">${tx.rate_display}</td>
                                        <td class="px-4 py-2 text-sm text-gray-500">${tx.timestamp}</td>
                                    </tr>
                                `;
                            });
                        } else {
                            html += '<tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No P2P transactions found</td></tr>';
                        }
                        
                        html += '</tbody></table></div>';
                        document.getElementById('p2pContent').innerHTML = html;
                    } else {
                        document.getElementById('p2pContent').innerHTML = '<div class="text-center text-red-500">Error loading P2P history</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('p2pContent').innerHTML = '<div class="text-center text-red-500">Error: ' + error.message + '</div>';
                });
        }

        function closeP2PModal() {
            document.getElementById('p2pModal').style.display = 'none';
        }

        function showLogoutModal(targetUrl) {
            var btn = document.getElementById('confirmLogoutBtn');
            btn.setAttribute('href', targetUrl);
            document.getElementById('logoutModal').style.display = 'flex';
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var logoutLinks = document.querySelectorAll('a[href$="admin_logout.php"]');
            logoutLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    showLogoutModal(link.href);
                });
            });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>

</body>
</html>
