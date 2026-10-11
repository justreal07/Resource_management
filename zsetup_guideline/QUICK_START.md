# ⚡ Quick Reference - Gmail Authentication Setup

## Your Credentials (Already Configured ✅)

```
Client ID: 349310716434-pprha5iiov8v3io7s0muj6kbckkgunis.apps.googleusercontent.com
Client Secret: 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
Redirect URI: http://localhost/Resource_management/user/google_callback.php
```

---

## 🚀 Quick Start (3 Steps)

### Step 1: Install Dependencies
```bash
cd C:\xampp\htdocs\Resource_management
composer install
```

### Step 2: Add Database Column
```sql
ALTER TABLE `applicant_accounts` ADD COLUMN `google_token` LONGTEXT NULL AFTER `avatar`;
```
*(Run in phpMyAdmin or MySQL)*

### Step 3: Test Login
```
http://localhost/Resource_management/user/gmail_login.php
```

---

## 📁 Important Files Created

| File | What It Does |
|------|--------------|
| `gmail_login.php` | Beautiful Gmail sign-in page |
| `google_login_init.php` | Starts OAuth process |
| `google_callback.php` | Handles Google response |
| `LOCALHOST_SETUP.md` | Full setup instructions |
| `GOOGLE_OAUTH_SETUP.md` | OAuth technical details |

---

## 🔗 URLs to Access

**Gmail Sign-In Page:**
```
http://localhost/Resource_management/user/gmail_login.php
```

**Traditional Login:**
```
http://localhost/Resource_management/user/applicant_login.php
```

**Database Manager:**
```
http://localhost/phpmyadmin
```

**Main Dashboard (after login):**
```
http://localhost/Resource_management/Main_dashboard/dashboard.php
```

---

## ✅ Verification Commands

```bash
# Check if Composer is installed
composer --version

# Check if PHP is installed
php --version

# Check Apache status (XAMPP)
# (Check XAMPP Control Panel - should be green)

# Verify folder permissions (if issues)
icacls "C:\xampp\htdocs\Resource_management" /grant Users:F /T
```

---

## 🎯 Testing Gmail Sign-In Flow

1. **Start XAMPP** → Apache & MySQL green ✅
2. **Open** → `http://localhost/Resource_management/user/gmail_login.php`
3. **Click** → "Sign in with Gmail" button
4. **Sign in** → With your Gmail account
5. **Check** → phpMyAdmin > applicant_accounts table
6. **Verify** → Your email should appear in database

---

## 🐛 If Something Doesn't Work

### No autoload.php error
```bash
composer install
```

### Can't connect to database
- Check MySQL is running (XAMPP Control Panel)
- Check credentials in `config.php`
- Default: user=`root`, password=`` (empty)

### Port 80 already in use
```bash
# Find what's using port 80
netstat -ano | findstr :80

# Change Apache port in XAMPP settings
# Or stop conflicting application
```

### Google redirect error
- Verify localhost URL in browser
- Check `config.php` database settings
- Make sure `google_token` column exists in database

---

## 📋 Checklist Before Going Live

- [ ] XAMPP installed (Apache & MySQL working)
- [ ] Composer installed and dependencies loaded
- [ ] `google_token` column added to database
- [ ] Can access gmail_login.php without errors
- [ ] Can click Gmail sign-in button
- [ ] Google login redirects properly
- [ ] User data saved to database
- [ ] Session variables set correctly
- [ ] Redirects to dashboard after login

---

## 🔐 Security Reminders

✅ These credentials are safe for **localhost testing only**

When you deploy to production:
1. Create NEW credentials in Google Cloud Console
2. Update redirect URI to your domain name
3. Use HTTPS (https://)
4. Never hardcode secrets - use environment variables
5. Keep credentials out of version control

---

## 📞 Need Help?

### Read These Files:
1. **LOCALHOST_SETUP.md** - Complete setup guide
2. **GOOGLE_OAUTH_SETUP.md** - OAuth technical details
3. **ADMIN_SETUP_GUIDE.md** - Admin features

### Key Files to Check:
- `config.php` - Database connection
- `google_callback.php` - OAuth configuration
- `google_login_init.php` - OAuth configuration
- `gmail_login.php` - Login page

### Verify Folder Structure:
```
C:\xampp\htdocs\Resource_management\
├── user\
│   ├── gmail_login.php ✅ NEW
│   ├── google_login_init.php ✅
│   ├── google_callback.php ✅
│   └── vendor\ ← Created by composer
├── Main_dashboard\
├── config.php
├── database\
├── css\
└── ...
```

---

**Status:** ✅ Ready for Localhost Testing  
**Configuration:** ✅ Complete  
**Credentials:** ✅ Installed  
**Next Step:** Run `composer install` then access `gmail_login.php`
