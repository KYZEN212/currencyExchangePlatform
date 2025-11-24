<?php
session_start();

// Database credentials
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

// Check if the user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Get the logged-in user's username from the session
$session_username = $_SESSION['username'];
$initials = strtoupper(substr($session_username, 0, 1));
$wallets = [];
$message = '';
// PIN state for current user
$pin_hash = null;

// Connect to the database
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to safely get user ID
function getUserId($conn, $username) {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        return $user['user_id'];
    }
    return null;
}

$user_id = getUserId($conn, $session_username);

// Load user's PIN hash (created during registration)
if ($user_id) {
    $stmt_pin = $conn->prepare("SELECT pin_hash FROM user_pins WHERE user_id = ? LIMIT 1");
    if ($stmt_pin) {
        $stmt_pin->bind_param("i", $user_id);
        $stmt_pin->execute();
        $res = $stmt_pin->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && !empty($row['pin_hash'])) { $pin_hash = $row['pin_hash']; }
        $stmt_pin->close();
    }
}

// Get user's wallet balances for the dropdown and balance check
$stmt_wallets = $conn->prepare("
    SELECT w.currency_id, w.balance, c.symbol, c.name
    FROM wallets w
    JOIN currencies c ON w.currency_id = c.currency_id
    WHERE w.user_id = ?
");
$stmt_wallets->bind_param("i", $user_id);
$stmt_wallets->execute();
$result_wallets = $stmt_wallets->get_result();
while ($row = $result_wallets->fetch_assoc()) {
    $wallets[] = $row;
}
$stmt_wallets->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['withdraw_amount'])) {
    if (!$user_id) {
        $message = "User not found.";
    } else {
        $currency_id = $_POST['withdraw_currency_id'];
        $amount = $_POST['withdraw_amount'];
        $withdrawal_details = $_POST['withdrawal_details'];
        
        // Require and verify 4-digit PIN before proceeding
        $entered_pin = $_POST['withdraw_pin'] ?? '';
        if (empty($pin_hash)) {
            $message = "Security PIN not set. Please contact support.";
        } elseif (!preg_match('/^\d{4}$/', $entered_pin)) {
            $message = "Please enter your 4-digit PIN.";
        } elseif (!password_verify($entered_pin, $pin_hash)) {
            $message = "Incorrect PIN.";
        }
        
        if (empty($message)) {
        
        // Check if user has sufficient balance
        $current_balance = 0;
        foreach ($wallets as $wallet) {
            if ($wallet['currency_id'] == $currency_id) {
                $current_balance = $wallet['balance'];
                break;
            }
        }

        if ($amount > $current_balance) {
            $message = "Insufficient balance. You only have " . number_format($current_balance, 2) . " available.";
        } else {
            // Insert withdrawal request into the database
            $stmt = $conn->prepare("INSERT INTO user_currency_requests (user_id, currency_id, amount, transaction_type, description) VALUES (?, ?, ?, 'withdrawal', ?)");
            $stmt->bind_param("iids", $user_id, $currency_id, $amount, $withdrawal_details);

          if ($stmt->execute()) {
    $message = "Your withdrawal request has been submitted successfully.";
    // Add a redirect after a successful submission
    header("Location: dashboard.php?message=Withdrawal%20request%20submitted%20successfully.");
    exit();
} else {
    $message = "Error submitting request: " . $stmt->error;
}
            $stmt->close();
        }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw Funds</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
    </style>
 </head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal pt-16">

    <!-- Top Navigation Bar -->
    <nav class="fixed top-0 inset-x-0 z-50 bg-white bg-opacity-10 backdrop-filter backdrop-blur-lg border-b border-white border-opacity-20 p-4 flex justify-between items-center shadow-md">
        <div class="flex items-center space-x-4">
            <span class="text-3xl font-bold text-blue-600">Crypto-Platform</span>
        </div>
        <div class="hidden md:flex items-center space-x-4">
            <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
            <a href="p2p_trade.php" class="text-gray-700 hover:text-blue-600 font-medium">P2P Trade</a>
            <a href="logout.php" class="text-gray-700 hover:text-blue-600 font-medium">Logout</a>
        </div>
        <div class="md:hidden">
            <button id="mobile-menu-button" class="text-gray-700 focus:outline-none">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile Menu (Hidden by default) -->
    <div id="mobile-menu" class="hidden md:hidden bg-white shadow-md">
        <div class="flex flex-col p-4 space-y-2">
            <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
            <a href="p2pTradeList.php" class="text-gray-700 hover:text-blue-600 font-medium">P2P Trade</a>
            <a href="logout.php" class="text-gray-700 hover:text-blue-600 font-medium">Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <div class="flex items-center space-x-4 mb-6">
                <div class="bg-red-500 rounded-full h-12 w-12 flex items-center justify-center text-white text-xl font-bold">
                    <i class="fas fa-minus"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Withdraw Funds</h2>
                    <p class="text-gray-500">Submit a request to withdraw funds from your wallet.</p>
                </div>
            </div>

            <hr class="my-6">

            <!-- Display message -->
            <?php if (!empty($message)): ?>
                <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                <h4 class="text-xl font-semibold text-gray-800 mb-4">Withdrawal Request Form</h4>
                <form id="withdraw-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" autocomplete="off">
                    <div class="mb-4">
                        <label for="withdraw_currency_id" class="block text-gray-700 font-medium mb-2">Currency to Withdraw</label>
                        <select id="withdraw_currency_id" name="withdraw_currency_id" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 transition duration-200">
                            <?php foreach ($wallets as $wallet): ?>
                                <option value="<?php echo htmlspecialchars($wallet['currency_id']); ?>">
                                    <?php echo htmlspecialchars($wallet['name']); ?> (<?php echo htmlspecialchars($wallet['symbol']); ?>) - Balance: <?php echo number_format($wallet['balance'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="withdraw_amount" class="block text-gray-700 font-medium mb-2">Amount</label>
                        <input type="number" id="withdraw_amount" name="withdraw_amount" step="0.01" min="0.01" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 transition duration-200">
                    </div>
                    <div class="mb-6">
                        <label for="withdrawal_details" class="block text-gray-700 font-medium mb-2">Withdrawal Details (e.g., Bank Account, Crypto Address)</label>
                        <textarea id="withdrawal_details" name="withdrawal_details" rows="3" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 transition duration-200"></textarea>
                    </div>
                    <!-- Hidden field to hold PIN set via modal -->
                    <input type="hidden" name="withdraw_pin" id="withdraw_pin_hidden" />
                    <button id="submit-withdraw-btn" type="button" class="w-full bg-red-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-500 focus:ring-opacity-50 transition duration-200">
                        Submit Withdrawal Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- PIN Modal -->
    <div id="pinModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-2">Enter Withdrawal PIN</h3>
            <p class="text-sm text-gray-500 mb-4">Type the 4-digit PIN you created during registration.</p>
            <input type="password" id="pinInput" inputmode="numeric" pattern="\d{4}" maxlength="4" minlength="4" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 mb-3" placeholder="••••" />
            <div id="pinError" class="text-red-600 text-sm mb-3 hidden">Please enter a valid 4-digit PIN.</div>
            <div class="flex gap-2 justify-end">
                <button id="cancelPinBtn" class="px-4 py-2 rounded-lg border text-gray-700">Cancel</button>
                <button id="confirmPinBtn" class="px-4 py-2 rounded-lg bg-blue-600 text-white">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle functionality
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
            });
        }

        // PIN modal logic
        const submitBtn = document.getElementById('submit-withdraw-btn');
        const form = document.getElementById('withdraw-form');
        const modal = document.getElementById('pinModal');
        const pinInput = document.getElementById('pinInput');
        const pinError = document.getElementById('pinError');
        const cancelBtn = document.getElementById('cancelPinBtn');
        const confirmBtn = document.getElementById('confirmPinBtn');
        const hiddenPin = document.getElementById('withdraw_pin_hidden');

        function openModal() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            pinInput.value = '';
            pinError.classList.add('hidden');
            setTimeout(() => pinInput.focus(), 50);
        }
        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        function isValidPin(v) {
            return /^\d{4}$/.test(v);
        }

        if (submitBtn) {
            submitBtn.addEventListener('click', (e) => {
                e.preventDefault();
                openModal();
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                closeModal();
            });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const val = pinInput.value.trim();
                if (!isValidPin(val)) {
                    pinError.textContent = 'Please enter a valid 4-digit PIN.';
                    pinError.classList.remove('hidden');
                    pinInput.focus();
                    return;
                }
                hiddenPin.value = val;
                closeModal();
                form.submit();
            });
        }
        pinInput && pinInput.addEventListener('keyup', (e) => {
            if (e.key === 'Enter') {
                confirmBtn.click();
            }
        });
    </script>
</body>
</html>
