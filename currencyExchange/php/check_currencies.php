<?php
require_once 'config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if currencies table exists
$result = $conn->query("SHOW TABLES LIKE 'currencies'");
if ($result->num_rows === 0) {
    die("Error: The 'currencies' table does not exist in the database.\n");
}

// Get all currencies
$sql = "SELECT * FROM currencies ORDER BY currency_id";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<h3>Available Currencies:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>Symbol</th><th>Code</th><th>Is Active</th></tr>";
    
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row["currency_id"] . "</td>";
        echo "<td>" . htmlspecialchars($row["name"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["symbol"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["code"]) . "</td>";
        echo "<td>" . ($row["is_active"] ? "Yes" : "No") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No currencies found in the database.\n";
}

// Check exchange_rates table
$result = $conn->query("SHOW TABLES LIKE 'exchange_rates'");
if ($result->num_rows === 0) {
    die("<br>Error: The 'exchange_rates' table does not exist in the database.\n");
}

// Get exchange rates
$sql = "SELECT er.*, 
        c1.symbol as base_symbol, 
        c2.symbol as target_symbol 
        FROM exchange_rates er
        JOIN currencies c1 ON er.base_currency_id = c1.currency_id
        JOIN currencies c2 ON er.target_currency_id = c2.currency_id
        ORDER BY er.timestamp DESC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<h3>Exchange Rates:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Base</th><th>Target</th><th>Rate</th><th>Last Updated</th></tr>";
    
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row["rate_id"] . "</td>";
        echo "<td>" . $row["base_symbol"] . " (ID: " . $row["base_currency_id"] . ")</td>";
        echo "<td>" . $row["target_symbol"] . " (ID: " . $row["target_currency_id"] . ")</td>";
        echo "<td>1 " . $row["base_symbol"] . " = " . $row["rate"] . " " . $row["target_symbol"] . "</td>";
        echo "<td>" . $row["timestamp"] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<br>No exchange rates found in the database.\n";
}

$conn->close();
?>
