-- Create user_bans table to track user suspensions
CREATE TABLE IF NOT EXISTS user_bans (
    ban_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    banned_by_admin_id INT NOT NULL,
    ban_reason VARCHAR(500),
    ban_duration_days INT NOT NULL COMMENT 'Duration in days (1, 3, 7, 30, or 9999 for permanent)',
    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    unbanned_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_expires (expires_at)
);

-- Note: Using existing 'user_status' column in users table
-- user_status: 0 = banned/inactive, 1 = active (default)
-- We'll use: user_status = 0 for banned, user_status = 1 for active
