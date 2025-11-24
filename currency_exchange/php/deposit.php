<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo 'Unauthorized';
    exit();
}

$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$mmk_currency_id = null;
$usd_currency_id = null;

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$stmt_currencies = $conn->prepare("SELECT currency_id, symbol FROM currencies");
$stmt_currencies->execute();
$res = $stmt_currencies->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['symbol'] === 'MMK') { $mmk_currency_id = $row['currency_id']; }
    if ($row['symbol'] === 'USD') { $usd_currency_id = $row['currency_id']; }
}
$stmt_currencies->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Deposit Funds</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter','sans-serif'] } } } }
  </script>
  <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'); body{font-family: 'Inter', sans-serif;}
    .dark-theme { background-color: #0f172a; color: #e5e7eb; }
    .dark-theme .bg-white { background-color: #111827 !important; }
    .dark-theme .bg-gray-50 { background-color: #0b1220 !important; }
    .dark-theme .bg-gray-100 { background-color: #0f172a !important; }
    .dark-theme .text-gray-900, .dark-theme .text-gray-800 { color: #e5e7eb !important; }
    .dark-theme .text-gray-700 { color: #cbd5e1 !important; }
    .dark-theme .text-gray-600 { color: #94a3b8 !important; }
    .dark-theme .text-gray-500 { color: #9ca3af !important; }
    .dark-theme .border-gray-100, .dark-theme .border-gray-200, .dark-theme .border-gray-300 { border-color: #374151 !important; }
    .dark-theme .rounded-xl, .dark-theme .rounded-lg { border: 1px solid #374151; }
    .dark-theme input, .dark-theme select, .dark-theme textarea { background-color: #0b1220; border-color: #374151; color: #e5e7eb; }
    .hover-lift { transition: transform 150ms ease, box-shadow 150ms ease; }
    .hover-lift:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5), 0 10px 10px -5px rgba(0,0,0,0.4); }
  </style>
</head>
<body class="dark-theme">
  <div class="p-6">
    <h3 class="text-xl font-semibold text-gray-800 mb-4">Deposit Funds to Your Wallet</h3>
    <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
      <form action="admin.php" method="POST" target="_parent">
        <input type="hidden" name="action" value="admin_deposit">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label for="currency_id" class="block text-gray-700 font-medium mb-2">Currency</label>
            <select id="currency_id" name="currency_id" class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
              <?php if (!is_null($mmk_currency_id)): ?>
                <option value="<?php echo htmlspecialchars($mmk_currency_id); ?>" selected>MMK</option>
              <?php endif; ?>
              <?php if (!is_null($usd_currency_id)): ?>
                <option value="<?php echo htmlspecialchars($usd_currency_id); ?>" <?php echo is_null($mmk_currency_id) ? 'selected' : ''; ?>>USD</option>
              <?php endif; ?>
            </select>
          </div>
          <div>
            <label for="amount" id="amountLabel" class="block text-gray-700 font-medium mb-2">Amount (<?php echo !is_null($mmk_currency_id) ? 'MMK' : 'USD'; ?>)</label>
            <input type="number" step="0.01" id="amount" name="amount" placeholder="e.g., 100.00" required class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" <?php echo (is_null($mmk_currency_id) && is_null($usd_currency_id)) ? 'disabled' : ''; ?>>
          </div>
        </div>
        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-lg transition-colors" <?php echo (is_null($mmk_currency_id) && is_null($usd_currency_id)) ? 'disabled' : ''; ?>>
          Deposit
        </button>
        <?php if (is_null($mmk_currency_id) && is_null($usd_currency_id)): ?>
          <p class="text-xs text-red-600 mt-2">No supported currencies (MMK or USD) were found. Please add them to currencies.</p>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <script>
    const currencySelect = document.getElementById('currency_id');
    const amountLabel = document.getElementById('amountLabel');
    if (currencySelect) {
      const updateLabel = () => {
        const label = currencySelect.options[currencySelect.selectedIndex]?.text || '';
        amountLabel.textContent = `Amount (${label})`;
      };
      currencySelect.addEventListener('change', updateLabel);
      updateLabel();
    }
  </script>
</body>
</html>
