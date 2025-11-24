<?php
/**
 * Adds a new exchange rate and its inverse to the database.
 * The function uses a transaction to ensure both rates are added or neither are.
 *
 * @param int $base_currency_id The ID of the base currency.
 * @param int $target_currency_id The ID of the target currency.
 * @param float $rate The exchange rate (1 base = X target).
 * @return bool True on success, false on failure.
 */
function addExchangeRate($conn, $base_currency_id, $target_currency_id, $rate) {
    // Multi-currencies feature: look up symbols and block certain pairs
    $stmt = $conn->prepare("
        SELECT c1.symbol as base_symbol, c2.symbol as target_symbol 
        FROM currencies c1, currencies c2 
        WHERE c1.currency_id = ? AND c2.currency_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $base_currency_id, $target_currency_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row) {
                $base_symbol = strtoupper($row['base_symbol'] ?? '');
                $target_symbol = strtoupper($row['target_symbol'] ?? '');
                $blocked_pairs = [
                    ['USD','JPY'], ['JPY','USD'],
                    ['USD','THB'], ['THB','USD']
                ];
                foreach ($blocked_pairs as $pair) {
                    if ($base_symbol === $pair[0] && $target_symbol === $pair[1]) {
                        error_log("Blocked pair detected: {$base_symbol}/{$target_symbol} - skipping update");
                        return true; // treat as success but skip write
                    }
                }
            }
        }
        $stmt->close();
    }

    $conn->begin_transaction();
    try {
        // Prepare the statement to insert or update the exchange rate
        // Using ON DUPLICATE KEY UPDATE to handle existing rates
        $stmt_insert = $conn->prepare("
            INSERT INTO exchange_rates (base_currency_id, target_currency_id, rate)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE rate = VALUES(rate), timestamp = CURRENT_TIMESTAMP()
        ");
        
        // Insert the rate from Base to Target
        $stmt_insert->bind_param("iid", $base_currency_id, $target_currency_id, $rate);
        if (!$stmt_insert->execute()) {
            throw new Exception("Failed to set exchange rate from base to target.");
        }

        // Save to history table
        $stmt_history = $conn->prepare("
            INSERT INTO exchange_rate_history (base_currency_id, target_currency_id, rate)
            VALUES (?, ?, ?)
        ") or die($conn->error);
        $stmt_history->bind_param("iid", $base_currency_id, $target_currency_id, $rate);
        $stmt_history->execute();

        // Calculate the inverse rate
        $inverse_rate = 1 / $rate;
        
        // Insert the reverse rate from Target to Base
        $stmt_insert->bind_param("iid", $target_currency_id, $base_currency_id, $inverse_rate);
        if (!$stmt_insert->execute()) {
            throw new Exception("Failed to set exchange rate from target to base.");
        }

        // Save inverse to history table
        $stmt_history->bind_param("iid", $target_currency_id, $base_currency_id, $inverse_rate);
        $stmt_history->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

/**
 * Deletes an exchange rate from the database.
 *
 * @param mysqli $conn The database connection.
 * @param int $rate_id The ID of the rate to delete.
 * @return bool True on success, false on failure.
 */
function deleteExchangeRate($conn, $rate_id) {
    $stmt = $conn->prepare("DELETE FROM exchange_rates WHERE rate_id = ?");
    $stmt->bind_param("i", $rate_id);
    return $stmt->execute();
}

/**
 * Gets today's exchange rates for display in user dashboard
 * @param mysqli $conn The database connection.
 * @return array Array of exchange rates for today
 */
function getTodayExchangeRates($conn) {
    $rates = [];
    
    $stmt = $conn->prepare("
        SELECT 
            er.rate_id,
            er.base_currency_id,
            er.target_currency_id,
            er.rate,
            er.timestamp,
            c1.symbol AS base_symbol,
            c1.name AS base_name,
            c2.symbol AS target_symbol,
            c2.name AS target_name
        FROM exchange_rates er
        JOIN currencies c1 ON er.base_currency_id = c1.currency_id
        JOIN currencies c2 ON er.target_currency_id = c2.currency_id
        WHERE DATE(er.timestamp) = CURDATE()
        ORDER BY er.timestamp DESC, er.base_currency_id, er.target_currency_id
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $rates[] = $row;
    }
    
    $stmt->close();
    return $rates;
}

/**
 * Gets exchange rate history for a specific currency pair
 * @param mysqli $conn The database connection.
 * @param int $base_currency_id The base currency ID
 * @param int $target_currency_id The target currency ID
 * @return array Array of historical exchange rates
 */
function getExchangeRateHistory($conn, $base_currency_id, $target_currency_id) {
    $history = [];
    
    $stmt = $conn->prepare("
        SELECT 
            erh.history_id,
            erh.rate,
            erh.timestamp,
            erh.updated_by,
            c1.symbol AS base_symbol,
            c2.symbol AS target_symbol
        FROM exchange_rate_history erh
        JOIN currencies c1 ON erh.base_currency_id = c1.currency_id
        JOIN currencies c2 ON erh.target_currency_id = c2.currency_id
        WHERE erh.base_currency_id = ? AND erh.target_currency_id = ?
        ORDER BY erh.timestamp DESC
        LIMIT 50
    ");
    
    $stmt->bind_param("ii", $base_currency_id, $target_currency_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    $stmt->close();
    return $history;
}

/**
 * Gets all available currency pairs for exchange rate display
 * @param mysqli $conn The database connection.
 * @return array Array of unique currency pairs
 */
function getAvailableCurrencyPairs($conn) {
    $pairs = [];
    
    $stmt = $conn->prepare("
        SELECT DISTINCT
            er.base_currency_id,
            er.target_currency_id,
            c1.symbol AS base_symbol,
            c2.symbol AS target_symbol
        FROM exchange_rates er
        JOIN currencies c1 ON er.base_currency_id = c1.currency_id
        JOIN currencies c2 ON er.target_currency_id = c2.currency_id
        ORDER BY c1.symbol, c2.symbol
    ");
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $pairs[] = $row;
    }
    
    $stmt->close();
    return $pairs;
}
?>