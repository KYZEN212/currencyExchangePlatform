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
$currencies = [];
$message = '';

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

// Get all currencies for the dropdown
$stmt_currencies = $conn->prepare("SELECT currency_id, symbol, name FROM currencies");
$stmt_currencies->execute();
$result_currencies = $stmt_currencies->get_result();
while ($row = $result_currencies->fetch_assoc()) {
    $currencies[] = $row;
}
$stmt_currencies->close();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deposit_amount'])) {
    if (!$user_id) {
        $message = "User not found.";
    } else {
        $currency_id = $_POST['deposit_currency_id'];
        $amount = $_POST['deposit_amount'];
        $user_payment_id = $_POST['user_payment_id'];
        $payment_channel = $_POST['payment_channel'] ?? null; // kpay | wavepay | ayapay
        $proof_screenshot_url = null;
        
        // Handle file upload
        if (isset($_FILES['proof_screenshot']) && $_FILES['proof_screenshot']['error'] == 0) {
            $target_dir = "uploads/";
            $file_name = uniqid() . '-' . basename($_FILES["proof_screenshot"]["name"]);
            $target_file = $target_dir . $file_name;
            
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            if (move_uploaded_file($_FILES["proof_screenshot"]["tmp_name"], $target_file)) {
                $proof_screenshot_url = $target_file;
            } else {
                $message = "Sorry, there was an error uploading your file.";
            }
        } else {
            $message = "Please upload a proof of payment screenshot.";
        }

        if ($message == '') {
           $stmt = $conn->prepare("INSERT INTO user_currency_requests (user_id, currency_id, amount, transaction_type, user_payment_id, proof_of_screenshot, payment_channel) VALUES (?, ?, ?, 'deposit', ?, ?, ?)");
$stmt->bind_param('iidsss', $user_id, $currency_id, $amount, $user_payment_id, $proof_screenshot_url, $payment_channel);
if ($stmt->execute()) {
    $message = "Your request has been submitted successfully.";
    // Add a redirect after a successful submission
    header("Location: dashboard.php?message=Deposit%20request%20submitted%20successfully.");
    exit();
} else {
    $message = "Error submitting request: " . $stmt->error;
}
            $stmt->close();
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
    <title>Deposit Funds</title>
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
            <a href="p2pTradeList.php" class="text-gray-700 hover:text-blue-600 font-medium">P2P Trade</a>
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
            <a href="p2p_trade.php" class="text-gray-700 hover:text-blue-600 font-medium">P2P Trade</a>
            <a href="logout.php" class="text-gray-700 hover:text-blue-600 font-medium">Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto mt-8 p-4 md:p-8">
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <div class="flex items-center space-x-4 mb-6">
                <div class="bg-green-500 rounded-full h-12 w-12 flex items-center justify-center text-white text-xl font-bold">
                    <i class="fas fa-plus"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Deposit Funds</h2>
                    <p class="text-gray-500">Submit a request to add funds to your wallet.</p>
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
                <h4 class="text-xl font-semibold text-gray-800 mb-4">Deposit Request Form</h4>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="deposit_currency_id" class="block text-gray-700 font-medium mb-2">Currency to Deposit</label>
                        <select id="deposit_currency_id" name="deposit_currency_id" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition duration-200">
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?php echo htmlspecialchars($currency['currency_id']); ?>">
                                    <?php echo htmlspecialchars($currency['name']); ?> (<?php echo htmlspecialchars($currency['symbol']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="deposit_amount" class="block text-gray-700 font-medium mb-2">Amount</label>
                        <input type="number" id="deposit_amount" name="deposit_amount" step="0.01" min="0.01" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition duration-200">
                    </div>
                    <!-- Banking Channel Buttons -->
                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2">Select Banking Channel</label>
                        <input type="hidden" id="payment_channel" name="payment_channel" value="">
                        <div class="flex flex-wrap gap-3">
                            <button type="button" data-channel="kpay" data-qr="../QR/kpay-qr.jpg" class="channel-btn px-4 py-2 rounded-lg text-white font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 bg-blue-600 hover:bg-blue-700 ring-blue-500 transform">KPay</button>
                            <button type="button" data-channel="wavepay" data-qr="../QR/wavepay-qr.jpg" class="channel-btn px-4 py-2 rounded-lg text-white font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 bg-yellow-500 hover:bg-yellow-600 ring-yellow-400 transform">WavePay</button>
                            <button type="button" data-channel="ayapay" data-qr="../QR/ayapay-qr.jpg" class="channel-btn px-4 py-2 rounded-lg text-white font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 bg-red-500 hover:bg-red-600 ring-red-400 transform">AYA Pay</button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Choose the channel used for your transfer.</p>
                    </div>
                    <div class="mb-4">
                        <label for="user_payment_id" class="block text-gray-700 font-medium mb-2">Your Payment ID / Transaction ID</label>
                        <input type="text" id="user_payment_id" name="user_payment_id" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition duration-200">
                    </div>
                    <div class="mb-6">
                        <label for="proof_screenshot" class="block text-gray-700 font-medium mb-2">Proof of Payment Screenshot</label>
                        <input type="file" id="proof_screenshot" name="proof_screenshot" required class="w-full text-gray-700">
                        <p class="text-xs text-gray-500 mt-1">Please upload a screenshot of your payment transfer.</p>
                    </div>
                    <button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-4 focus:ring-green-500 focus:ring-opacity-50 transition duration-200">
                        Submit Deposit Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- QR Modal -->
    <div id="qr_modal" class="fixed inset-0 z-50 hidden">
        <div id="qr_backdrop" class="absolute inset-0 bg-black bg-opacity-50"></div>
        <div class="relative z-10 flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-lg font-semibold">Scan QR - <span id="modal_qr_label"></span></h3>
                    <button id="qr_close" class="text-gray-500 hover:text-gray-700" aria-label="Close QR">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <img id="modal_qr_image" src="" alt="Payment QR" class="w-full h-auto object-contain rounded border" />
                <p id="modal_qr_error" class="mt-2 text-sm text-red-600 hidden"></p>
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

        // Banking channel selection
        const channelButtons = document.querySelectorAll('.channel-btn');
        const channelInput = document.getElementById('payment_channel');
        // Modal elements
        const qrModal = document.getElementById('qr_modal');
        const qrBackdrop = document.getElementById('qr_backdrop');
        const modalQrImage = document.getElementById('modal_qr_image');
        const modalQrLabel = document.getElementById('modal_qr_label');
        const qrClose = document.getElementById('qr_close');

        function setActiveChannel(selected) {
            channelButtons.forEach(btn => {
                if (btn.dataset.channel === selected) {
                    btn.classList.add('ring-4', 'scale-95');
                } else {
                    btn.classList.remove('ring-4', 'scale-95');
                }
            });
        }

        function openQrModal(src, label) {
            if (modalQrLabel) modalQrLabel.textContent = label || '';
            if (qrModal) qrModal.classList.remove('hidden');
            if (modalQrImage) {
                // Reset error
                const err = document.getElementById('modal_qr_error');
                if (err) { err.textContent = ''; err.classList.add('hidden'); }
                modalQrImage.onload = () => { if (err) err.classList.add('hidden'); };
                modalQrImage.onerror = () => {
                    if (err) {
                        err.textContent = `Unable to load QR image at ${src}. Check file path and name.`;
                        err.classList.remove('hidden');
                    }
                };
                modalQrImage.src = src || '';
            }
        }

        function closeQrModal() {
            if (qrModal) qrModal.classList.add('hidden');
        }

        channelButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const selected = btn.dataset.channel; // keep for styling state
                const src = btn.getAttribute('data-qr');
                const label = btn.textContent.trim(); // visible text e.g., 'KPay'
                if (channelInput) channelInput.value = label; // store label instead of slug
                setActiveChannel(selected);
                if (src) openQrModal(src, label);
            });
        });

        // Modal close handlers
        if (qrBackdrop) qrBackdrop.addEventListener('click', closeQrModal);
        if (qrClose) qrClose.addEventListener('click', closeQrModal);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeQrModal();
        });

        // Do not auto-select any channel on load; wait for user click
        // If a value is pre-filled (e.g., from server), initialize accordingly
        if (channelInput && channelInput.value) {
            setActiveChannel(channelInput.value);
        }
    </script>
</body>
</html>