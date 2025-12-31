-- Create exchange rate history table to track all rate changes
CREATE TABLE IF NOT EXISTS exchange_rate_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    base_currency_id INT NOT NULL,
    target_currency_id INT NOT NULL,
    rate DECIMAL(20, 8) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) DEFAULT 'admin',
    FOREIGN KEY (base_currency_id) REFERENCES currencies(currency_id) ON DELETE CASCADE,
    FOREIGN KEY (target_currency_id) REFERENCES currencies(currency_id) ON DELETE CASCADE,
    INDEX idx_currency_pair (base_currency_id, target_currency_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment to table
ALTER TABLE exchange_rate_history COMMENT = 'Stores historical exchange rates for tracking rate changes over time';
