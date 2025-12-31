<?php
// bank.php
// Connect to the database
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "currency_platform";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Safely updates the bank's balance for a specific currency.
 *
 * @param mysqli $conn The database connection.
 * @param int $currency_id The currency ID.
 * @param float $amount_change The amount to add (positive) or subtract (negative).
 * @return bool True on success, false on failure (e.g., insufficient funds).
 */
function updateBankBalance($conn, $currency_id, $amount_change) {
    $conn->begin_transaction();
    try {
        // Ensure the record exists for the currency
        $stmt_insert = $conn->prepare("INSERT IGNORE INTO bank_accounts (currency_id, balance) VALUES (?, 0)");
        $stmt_insert->bind_param("i", $currency_id);
        $stmt_insert->execute();
        
        if ($amount_change >= 0) {
            // Deposit to the bank - add
            $stmt = $conn->prepare("
                UPDATE bank_accounts SET balance = balance + ?
                WHERE currency_id = ?
            ");
            $stmt->bind_param("di", $amount_change, $currency_id);
        } else {
            // Withdrawal from the bank - subtract with check
            $amount_to_subtract = abs($amount_change);
            $stmt = $conn->prepare("
                UPDATE bank_accounts SET balance = balance - ?
                WHERE currency_id = ? AND balance >= ?
            ");
            $stmt->bind_param("did", $amount_to_subtract, $currency_id, $amount_to_subtract);
        }
        
        $stmt->execute();
        
        if ($amount_change < 0 && $stmt->affected_rows === 0) {
            throw new Exception("Insufficient bank funds for this transaction");
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}
?>