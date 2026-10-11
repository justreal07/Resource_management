# Admin Account Setup Guide

## Overview
This document explains the new admin account system where:
- **Admin** has a single default account with NO sign-up page (must use initializer)
- **Admin** can change their username and password from their settings page
- **Staff** can register themselves (sign-up page available)
- **Separation of roles** - Data is contained and managed separately

---

## Step-by-Step Setup Instructions

### Step 1: Create the Default Admin Account

1. Navigate to `admin_init.php` in your browser:
   ```
   http://localhost/Resource_management/admin_init.php
   ```

2. Click the button **"Create Default Admin Account"**

3. You will see confirmation with default credentials:
   - **Username:** `admin`
   - **Password:** `admin123`

4. **⚠️ IMPORTANT:** Delete or disable the `admin_init.php` file after creation for security

### Step 2: Login as Admin

1. Go to the login page: `http://localhost/Resource_management/login/login.php`

2. Select role: **Admin**

3. Enter credentials:
   - Username: `admin`
   - Password: `admin123`

4. You will be directed to the **Admin Dashboard**

### Step 3: Change Admin Credentials

1. From the Admin Dashboard, click **"⚙️ Admin Settings"** in the sidebar

2. You can now:
   - **Update Full Name** - Change your displayed name
   - **Change Username** - Create a new unique username
   - **Change Password** - Create a strong password

3. All changes require your current password for verification

---

## System Features

### Admin Account
✅ **Single default account** (admin/admin123)  
✅ **No sign-up page** for admin role  
✅ **Can modify username and password** via Settings page  
✅ **Full access** to all admin features  
✅ **Can manage** all applicants and approvals  
✅ **Can view** all reports  

### Staff Accounts
✅ **Can register** via Registration page  
✅ **Self-service sign-up** with username and password  
✅ **Limited access** compared to admin  
✅ **Cannot see** admin settings or functions  
✅ **Cannot** manage other staff accounts  

### Security Features
- All passwords are **hashed** using PHP's `password_hash()`
- Admin settings **require password verification** for changes
- Registration form **validates** username uniqueness
- **Session-based** authentication for security

---

## Login Flow

### For Admin:
```
1. Go to login.php
2. Select "Admin" role
3. Enter admin credentials
4. Access admin dashboard
5. Can change credentials in Settings
```

### For Staff:
```
1. Go to login.php (or click Register link)
2. Click "Register here"
3. Fill registration form (automatically sets role as Staff)
4. Create account
5. Login with new credentials
6. Access staff dashboard
```

---

## File Changes Summary

### Modified Files:
1. **login/register.php**
   - Removed admin role option
   - Only staff can register
   - Simplified form

2. **Main_dashboard/dashboard.php**
   - Added "⚙️ Admin Settings" link to sidebar
   - Direct access to admin settings page

### New Files:
1. **Main_dashboard/admin_settings.php**
   - Admin profile page
   - Change full name
   - Change username
   - Change password
   - All changes require password verification

2. **admin_init.php**
   - One-time initializer for default admin account
   - Creates admin/admin123 account
   - Should be deleted after first use

---

## Default Admin Credentials

| Field | Value |
|-------|-------|
| Username | admin |
| Password | admin123 |
| Role | Admin |
| Full Name | System Administrator |

⚠️ **CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN!**

---

## Workflow Example

### First Time Setup:
```
1. Run admin_init.php to create default admin
2. Login with admin/admin123
3. Go to Admin Settings (⚙️ icon in sidebar)
4. Change password to something secure
5. Change username if desired
6. Delete admin_init.php file
7. Share registration link with staff
8. Staff can now register themselves
```

### Staff Registration:
```
1. Staff goes to login/register.php (or login.php → Register link)
2. Fills in: Full Name, Username, Password
3. Account automatically created as "Staff" role
4. Can login and access their dashboard
```

### Admin Day-to-Day:
```
1. Login with their credentials
2. Access admin dashboard
3. Manage applicants
4. Review reports
5. Can change password anytime via Settings
6. Logout when done
```

---

## Security Recommendations

1. ✅ Change the default admin password immediately
2. ✅ Delete the `admin_init.php` file after setup
3. ✅ Use strong passwords (8+ characters, mix of upper/lower/numbers)
4. ✅ Don't share admin credentials
5. ✅ Regularly update admin password
6. ✅ Audit staff access periodically
7. ✅ Keep the system updated

---

## Troubleshooting

### Q: I forgot the admin password
**A:** Unfortunately, you'll need to reset the database manually. Only the admin can change their password through the Settings page.

### Q: Can I create multiple admin accounts?
**A:** No. The system only allows one admin account. This is by design to maintain data integrity and control.

### Q: Can staff become admin?
**A:** Not through the UI. Staff accounts can only be created via registration as "Staff" role. Only one admin account exists.

### Q: Where is the Register page?
**A:** 
- From Login: Click "Register here" link at bottom
- Direct link: `login/register.php`
- Only allows staff registration

### Q: How do I access admin settings?
**A:** Login as admin → Click "⚙️ Admin Settings" in the sidebar → Make your changes

---

## Database Structure

The `users` table has the following structure:
```sql
- id: AUTO_INCREMENT primary key
- username: VARCHAR(50) - UNIQUE
- password: VARCHAR(255) - HASHED
- role: ENUM('admin','staff')
- full_name: VARCHAR(100)
- created_at: TIMESTAMP
```

---

## Summary

✅ Admin account is now protected with a single default account  
✅ Admin can manage their own credentials via Settings  
✅ Staff can self-register without admin involvement  
✅ Clear separation between admin and staff roles  
✅ Secure password hashing and verification  
✅ Easy-to-use interface for account management  

Questions? Check the relevant PHP files for detailed comments and implementation.
