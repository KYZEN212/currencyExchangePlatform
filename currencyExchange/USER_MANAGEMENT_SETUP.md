# User Management System - Setup Guide

## Overview
This system adds comprehensive user management to the admin dashboard with the following features:
- View user wallet balances
- View user P2P transaction history
- Ban/suspend users with flexible duration (1 day, 3 days, 7 days, 30 days, or permanent)
- Automatic ban expiration
- Real-time countdown timer for banned users
- Ban status tracking

## Installation Steps

### 1. Create Database Tables
Run the SQL file to create the necessary tables:

```bash
# Navigate to phpMyAdmin or MySQL command line
# Execute the following file:
sql/create_user_bans_table.sql
```

Or run this SQL directly:

```sql
-- Create user_bans table
CREATE TABLE IF NOT EXISTS user_bans (
    ban_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    banned_by_admin_id INT NOT NULL,
    ban_reason VARCHAR(500),
    ban_duration_days INT NOT NULL COMMENT 'Duration in days (1, 3, 7, 30, or 9999 for permanent)',
    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    unbanned_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_expires (expires_at)
);

-- Add status column to users table
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS status TINYINT(1) DEFAULT 0 COMMENT '0=active, 1=banned';
```

### 2. Files Created

The following files have been created:

**SQL:**
- `sql/create_user_bans_table.sql` - Database schema for user bans

**PHP Pages:**
- `php/admin_user_management.php` - Main user management interface
- `php/admin_get_user_wallet.php` - API endpoint to fetch user wallet data
- `php/admin_get_user_p2p.php` - API endpoint to fetch user P2P history
- `php/check_user_ban.php` - Middleware to check if user is banned

**Modified Files:**
- `php/admin.php` - Added "User Management" link to navigation
- `php/dashboard.php` - Added ban check middleware
- `php/p2pTradeList.php` - Added ban check middleware
- `php/p2pTransactionHistory.php` - Added ban check middleware

### 3. Access the System

1. **Login as Admin:**
   - Go to: `http://localhost/New Testing/php/admin.php`
   - Use your admin credentials

2. **Navigate to User Management:**
   - Click "User Management" in the top navigation
   - Or go directly to: `http://localhost/New Testing/php/admin_user_management.php`

## Features

### 1. View User List
- See all registered users with their status (Active/Banned)
- View ban expiration dates for banned users

### 2. View User Wallet
- Click the "Wallet" button next to any user
- See all their currency balances in a modal popup
- Real-time data fetched via AJAX

### 3. View P2P Transaction History
- Click the "P2P" button next to any user
- See their complete peer-to-peer trading history
- Includes transaction type, amounts, exchange rates, and dates

### 4. Ban User
- Click the "Ban" button next to any active user
- Select ban duration:
  - 1 Day
  - 3 Days
  - 7 Days (1 Week)
  - 30 Days (1 Month)
  - Permanent
- Optionally add a reason for the ban
- User status immediately changes to "Banned"

### 5. Unban User
- Click the "Unban" button next to any banned user
- User is immediately unbanned and can access their account

### 6. Banned User Experience
When a banned user tries to access the platform:
- They see a full-screen ban notice
- Cannot access any features
- See the ban reason
- See a real-time countdown timer (for temporary bans)
- See the exact expiration date and time
- Only option is to logout

### 7. Automatic Ban Expiration
- Bans automatically expire when the time is up
- User can immediately access their account after expiration
- System checks ban status on every page load

## User Status Values

In the `users` table:
- `status = 0` → Active user (can access platform)
- `status = 1` → Banned user (cannot access platform)

## Ban Duration Values

In the `user_bans` table:
- `ban_duration_days = 1` → 1 day ban
- `ban_duration_days = 3` → 3 day ban
- `ban_duration_days = 7` → 7 day ban (1 week)
- `ban_duration_days = 30` → 30 day ban (1 month)
- `ban_duration_days = 9999` → Permanent ban

## Security Features

1. **Session Validation:** All admin pages check for valid admin session
2. **SQL Injection Protection:** All queries use prepared statements
3. **XSS Protection:** All user input is sanitized with htmlspecialchars()
4. **CSRF Protection:** Forms use POST method with server-side validation
5. **Automatic Ban Expiration:** Prevents indefinite bans from system errors

## Testing Checklist

- [ ] Create the database table successfully
- [ ] Login to admin panel
- [ ] Access User Management page
- [ ] View a user's wallet balances
- [ ] View a user's P2P transaction history
- [ ] Ban a user with 1 day duration
- [ ] Login as the banned user and verify ban screen appears
- [ ] Verify countdown timer works
- [ ] Unban the user from admin panel
- [ ] Verify user can access platform again
- [ ] Test permanent ban
- [ ] Verify ban auto-expires after duration

## Troubleshooting

### Issue: "Table 'user_bans' doesn't exist"
**Solution:** Run the SQL file `create_user_bans_table.sql` in phpMyAdmin

### Issue: "Column 'status' doesn't exist in users table"
**Solution:** Run the ALTER TABLE command from the SQL file

### Issue: Wallet/P2P data not loading
**Solution:** 
- Check browser console for JavaScript errors
- Verify `admin_get_user_wallet.php` and `admin_get_user_p2p.php` are accessible
- Check database connection in these files

### Issue: Ban check not working
**Solution:**
- Verify `check_user_ban.php` is in the php folder
- Check that it's included in dashboard.php, p2pTradeList.php, and p2pTransactionHistory.php
- Verify database connection is established before including the file

### Issue: User still sees ban screen after unban
**Solution:** User needs to refresh the page or logout and login again

## Database Schema

### user_bans Table
```
ban_id (INT, PRIMARY KEY, AUTO_INCREMENT)
user_id (INT, FOREIGN KEY → users.user_id)
banned_by_admin_id (INT)
ban_reason (VARCHAR 500)
ban_duration_days (INT)
banned_at (TIMESTAMP)
expires_at (TIMESTAMP)
is_active (TINYINT 1)
unbanned_at (TIMESTAMP NULL)
```

### users Table (Modified)
```
... existing columns ...
status (TINYINT 1) - 0=active, 1=banned
```

## API Endpoints

### GET admin_get_user_wallet.php?user_id={id}
Returns JSON with user's wallet balances:
```json
{
  "success": true,
  "wallets": [
    {
      "balance": "1000.00",
      "symbol": "USD",
      "name": "US Dollar",
      "currency_id": 1
    }
  ]
}
```

### GET admin_get_user_p2p.php?user_id={id}
Returns JSON with user's P2P transaction history:
```json
{
  "success": true,
  "transactions": [
    {
      "type": "Buy MMK for USD",
      "amount_sold": "100.00",
      "amount_bought": "170000.00",
      "sell_currency": "USD",
      "buy_currency": "MMK",
      "rate_display": "1 USD = 1700 MMK",
      "timestamp": "2025-10-09 13:20:00"
    }
  ]
}
```

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Verify all files are in the correct locations
3. Check PHP error logs in XAMPP
4. Verify database connection settings

---

**Version:** 1.0  
**Last Updated:** 2025-10-09  
**Compatible with:** PHP 7.4+, MySQL 5.7+
