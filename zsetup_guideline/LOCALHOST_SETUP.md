# 🚀 Gmail Authentication - Localhost Setup Guide

## 📋 Prerequisites Checklist

Before you start, make sure you have these installed on your computer:

- [ ] **XAMPP** (PHP 8.0+) - [Download here](https://www.apachefriends.org/)
- [ ] **Composer** (PHP Package Manager) - [Download here](https://getcomposer.org/)
- [ ] **Git** (Optional, for version control) - [Download here](https://git-scm.com/)
- [ ] **Web Browser** (Chrome, Firefox, Edge, Safari)

---

## 🔧 Installation Steps

### Step 1: Start XAMPP Server

**Windows:**
1. Open XAMPP Control Panel
2. Click **Start** next to "Apache"
3. Click **Start** next to "MySQL"
4. Wait for both to turn green ✅

**Mac/Linux:**
```bash
sudo /Applications/XAMPP/xamppfiles/bin/xampp start
# or
sudo /opt/lampp/bin/xampp start
```

### Step 2: Install Google API Client Library

Navigate to your project folder and install the required library:

```bash
# Open Command Prompt or Terminal
cd C:\xampp\htdocs\Resource_management

# Install Google API Client
composer require google/apiclient:^2.0
```

**Expected output:**
```
Using version ^2.0 for google/apiclient
./composer.json has been updated
Loading composer repositories...
Installing dependencies...
...
Generating autoload files
```

If you get an error about `composer` command not found:
- Install Composer from https://getcomposer.org/
- Or use the full path: `C:\path\to\composer.phar require google/apiclient:^2.0`

### Step 3: Create Database Column

1. Open **phpMyAdmin** in your browser:
   - Go to: `http://localhost/phpmyadmin`
   
2. Select your database: `lgu_assistance_system`

3. Click **SQL** tab and run this query:
   ```sql
   ALTER TABLE `applicant_accounts` ADD COLUMN `google_token` LONGTEXT NULL AFTER `avatar`;
   ```

4. Click **Go** ✅

---

## 🔑 Your Google OAuth Credentials (Already Configured)

**Client ID:**
```
349310716434-pprha5iiov8v3io7s0muj6kbckkgunis.apps.googleusercontent.com
```

**Client Secret:**
```
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
```

These are already configured in your files:
- ✅ `user/google_callback.php`
- ✅ `user/google_login_init.php`

---

## 🌐 Access Your Application on Localhost

### 1. **Gmail Sign-In Page** (NEW)
Open in your browser:
```
http://localhost/Resource_management/user/gmail_login.php
```

### 2. **Traditional Login** (Existing)
```
http://localhost/Resource_management/user/applicant_login.php
```

### 3. **Main Dashboard** (After Login)
```
http://localhost/Resource_management/Main_dashboard/dashboard.php
```

---

## 🧪 Testing Gmail Sign-In Locally

### Test Flow:

1. **Start Apache & MySQL** (XAMPP)
2. **Install Dependencies**:
   ```bash
   cd C:\xampp\htdocs\Resource_management
   composer install
   ```

3. **Access Gmail Login Page**:
   ```
   http://localhost/Resource_management/user/gmail_login.php
   ```

4. **Click "Sign in with Gmail"**
   - Browser will redirect to Google login
   - Sign in with your Gmail account
   - Google will ask for permissions
   - Accept and you'll be redirected back

5. **Check if User Created**:
   - Go to phpMyAdmin
   - Check `applicant_accounts` table
   - New user should be listed with your Gmail email

### Troubleshooting:

| Problem | Solution |
|---------|----------|
| "Redirect URI mismatch" | Make sure localhost URL matches Google Cloud settings |
| "No autoload.php found" | Run `composer install` in the project root |
| XAMPP won't start | Check if ports 80 (Apache) or 3306 (MySQL) are in use |
| Can't connect to database | Check MySQL is running and credentials in `config.php` |
| Session not working | Check PHP session folder has write permissions |

---

## 📁 File Structure (After Setup)

```
Resource_management/
├── user/
│   ├── gmail_login.php                 ← Use this login page
│   ├── google_login_init.php           ← Initiates OAuth
│   ├── google_callback.php             ← Handles callback (Fixed)
│   ├── applicant_login.php             ← Traditional login
│   ├── applicant_register.php          ← Registration
│   └── vendor/                         ← Created by composer
│       ├── google/
│       ├── autoload.php
│       └── ...
├── Main_dashboard/
│   └── dashboard.php                   ← After login redirect
├── config.php                          ← Database config
├── database/
│   ├── lgu_assistance_system.sql       ← Database schema
│   └── migration_add_google_token.sql  ← Add this column
└── GOOGLE_OAUTH_SETUP.md               ← Setup guide

```

---

## 🚀 Quick Start Commands

### For Windows (Command Prompt):
```bash
# 1. Navigate to project
cd C:\xampp\htdocs\Resource_management

# 2. Install dependencies
composer install

# 3. Start XAMPP (do this from XAMPP Control Panel)

# 4. Open browser
start http://localhost/Resource_management/user/gmail_login.php
```

### For Mac (Terminal):
```bash
# 1. Navigate to project
cd /Applications/XAMPP/xamppfiles/htdocs/Resource_management

# 2. Install dependencies
composer install

# 3. Start XAMPP
sudo /Applications/XAMPP/xamppfiles/bin/xampp start

# 4. Open browser
open http://localhost/Resource_management/user/gmail_login.php
```

### For Linux (Terminal):
```bash
# 1. Navigate to project
cd /opt/lampp/htdocs/Resource_management

# 2. Install dependencies
composer install

# 3. Start XAMPP
sudo /opt/lampp/bin/xampp start

# 4. Open browser
firefox http://localhost/Resource_management/user/gmail_login.php
```

---

## ✅ Verification Checklist

Before considering setup complete:

- [ ] XAMPP Apache is running (green)
- [ ] XAMPP MySQL is running (green)
- [ ] `composer install` completed successfully
- [ ] `google_token` column added to database
- [ ] Can access `http://localhost/Resource_management/user/gmail_login.php`
- [ ] Can see "Sign in with Gmail" button
- [ ] Can click button without errors
- [ ] Redirected to Google login page
- [ ] After signing in, redirected back to app
- [ ] User appears in database `applicant_accounts` table

---

## 🔐 Security Notes for Localhost Testing

✅ **Safe to use credentials for localhost development**

When you go to **production** (real server), you should:
1. Create NEW credentials in Google Cloud Console
2. Update redirect URI to your production domain
3. Never commit credentials to version control
4. Use environment variables for sensitive data

Example `.env` file (do NOT commit to git):
```
GOOGLE_CLIENT_ID=YOUR_PRODUCTION_CLIENT_ID
GOOGLE_CLIENT_SECRET=YOUR_PRODUCTION_CLIENT_SECRET
GOOGLE_REDIRECT_URI=https://yourdomain.com/user/google_callback.php
```

---

## 📊 How the Gmail Login Works

```
1. User clicks "Sign in with Gmail"
                    ↓
2. Redirects to google_login_init.php
                    ↓
3. Generates CSRF token (security)
                    ↓
4. Redirects to Google Login Page
                    ↓
5. User signs in with Gmail account
                    ↓
6. Google redirects back with auth code
                    ↓
7. google_callback.php processes auth code
                    ↓
8. Exchanges code for access token
                    ↓
9. Gets user info (email, name, picture)
                    ↓
10. Checks/creates user in database
                    ↓
11. Sets session variables
                    ↓
12. Redirects to dashboard.php
```

---

## 🆘 Common Issues & Solutions

### Issue 1: "composer: command not found"
**Solution:**
- Install Composer from https://getcomposer.org/download/
- Or use the installer .exe file for Windows
- Restart Command Prompt after installation

### Issue 2: "The autoloader could not find the requested class"
**Solution:**
```bash
# In your project root directory:
composer install
composer dump-autoload
```

### Issue 3: "Google said this browser or app may not be secure"
**Solution:**
- This is normal for localhost development
- Click "Yes, continue" on Google's prompt
- Use HTTPS for production

### Issue 4: "XAMPP Apache not starting"
**Solution:**
- Check if port 80 is in use: 
  - Windows: `netstat -ano | findstr :80`
  - Mac/Linux: `sudo lsof -i :80`
- Stop conflicting application or change Apache port in XAMPP config

### Issue 5: "Can't connect to MySQL database"
**Solution:**
- Make sure MySQL is running in XAMPP (should be green)
- Check credentials in `config.php`
- Default: user=`root`, password=`` (empty)
- Port should be `3306`

---

## 📱 Testing on Different Devices

### Local Network Access (From Other Devices):
If you want to test on your phone or another computer:

```
http://YOUR_COMPUTER_IP/Resource_management/user/gmail_login.php
```

**Find your computer IP:**

Windows:
```bash
ipconfig
# Look for IPv4 Address (e.g., 192.168.1.100)
```

Mac/Linux:
```bash
ifconfig
# Look for inet address
```

**Update Google Cloud settings:**
- Add your computer IP to Authorized redirect URIs
- Example: `http://192.168.1.100/Resource_management/user/google_callback.php`

---

## 🎯 Next Steps After Testing

1. ✅ Test localhost thoroughly
2. ✅ Get a domain name (e.g., myassistance.com)
3. ✅ Get SSL certificate (HTTPS)
4. ✅ Update Google OAuth redirect URI
5. ✅ Create new credentials for production
6. ✅ Update credentials in production files
7. ✅ Deploy to production server

---

## 📞 Support Files Created

You now have:

| File | Purpose |
|------|---------|
| `gmail_login.php` | Modern Gmail sign-in interface |
| `google_login_init.php` | OAuth flow initiator |
| `google_callback.php` | OAuth callback handler |
| `GOOGLE_OAUTH_SETUP.md` | Detailed OAuth setup guide |
| `LOCALHOST_SETUP.md` | This file - Localhost setup |

---

## ⚡ Quick Verification Script

Paste this in your browser console to verify setup:

```javascript
// Check if you're on localhost
if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    console.log('✅ Running on localhost');
} else {
    console.log('⚠️  Not running on localhost');
}

// Check HTTPS (needed for production)
if (window.location.protocol === 'https:') {
    console.log('✅ Using HTTPS (Production ready)');
} else {
    console.log('⚠️  Using HTTP (Localhost only)');
}
```

---

**Setup Date:** June 2026  
**Credentials Configured:** YES ✅  
**Ready for Testing:** YES ✅  
**Ready for Production:** NO - Needs domain & HTTPS
