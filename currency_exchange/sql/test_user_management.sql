-- Test script for User Management System
-- Run these queries to verify the setup

-- 1. Check if user_bans table exists
SHOW TABLES LIKE 'user_bans';

-- 2. Check user_bans table structure
DESCRIBE user_bans;

-- 3. Check if user_status column exists in users table
SHOW COLUMNS FROM users LIKE 'user_status';

-- 4. View all users with their status (user_status: 0=banned, 1=active)
SELECT user_id, username, email, user_status,
    CASE 
        WHEN user_status = 0 THEN 'Banned'
        WHEN user_status = 1 THEN 'Active'
        ELSE 'Unknown'
    END AS status_text
FROM users 
ORDER BY username;

-- 5. View all active bans
SELECT 
    ub.ban_id,
    u.username,
    ub.ban_reason,
    ub.ban_duration_days,
    ub.banned_at,
    ub.expires_at,
    ub.is_active,
    CASE 
        WHEN ub.expires_at > NOW() THEN 'Active'
        ELSE 'Expired'
    END AS ban_status
FROM user_bans ub
JOIN users u ON ub.user_id = u.user_id
WHERE ub.is_active = 1
ORDER BY ub.banned_at DESC;

-- 6. View ban history (all bans, active and inactive)
SELECT 
    ub.ban_id,
    u.username,
    ub.ban_reason,
    ub.ban_duration_days,
    ub.banned_at,
    ub.expires_at,
    ub.is_active,
    ub.unbanned_at
FROM user_bans ub
JOIN users u ON ub.user_id = u.user_id
ORDER BY ub.banned_at DESC;

-- 7. Count users by status (user_status: 0=banned, 1=active)
SELECT 
    CASE 
        WHEN user_status = 0 THEN 'Banned'
        WHEN user_status = 1 THEN 'Active'
        ELSE 'Unknown'
    END AS status_text,
    COUNT(*) AS count
FROM users
GROUP BY user_status;

-- 8. Find users with expired bans that need cleanup
SELECT 
    u.user_id,
    u.username,
    u.user_status,
    ub.expires_at
FROM users u
JOIN user_bans ub ON u.user_id = ub.user_id
WHERE u.user_status = 0 
  AND ub.is_active = 1 
  AND ub.expires_at < NOW();

-- 9. Manually unban a user (if needed) - Replace USER_ID with actual ID
-- UPDATE user_bans SET is_active = 0, unbanned_at = NOW() WHERE user_id = USER_ID AND is_active = 1;
-- UPDATE users SET user_status = 1 WHERE user_id = USER_ID;

-- 10. Test ban a user for 1 day (Replace USER_ID with actual ID)
-- INSERT INTO user_bans (user_id, banned_by_admin_id, ban_reason, ban_duration_days, expires_at)
-- VALUES (USER_ID, 1, 'Test ban', 1, DATE_ADD(NOW(), INTERVAL 1 DAY));
-- UPDATE users SET user_status = 0 WHERE user_id = USER_ID;
