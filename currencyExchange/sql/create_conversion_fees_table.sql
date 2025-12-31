-- Create conversion fees table to track all conversion taxes collected
CREATE TABLE IF NOT EXISTS conversion_fees (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_currency_id INT NOT NULL,
    to_currency_id INT NOT NULL,
    amount_converted DECIMAL(20, 8) NOT NULL,
    tax_amount DECIMAL(20, 8) NOT NULL,
    tax_rate DECIMAL(5, 4) DEFAULT 0.0500,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (from_currency_id) REFERENCES currencies(currency_id) ON DELETE CASCADE,
    FOREIGN KEY (to_currency_id) REFERENCES currencies(currency_id) ON DELETE CASCADE,
    INDEX idx_timestamp (timestamp),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment to table
ALTER TABLE conversion_fees COMMENT = 'Tracks all conversion fees/taxes collected from users for profit calculation';
