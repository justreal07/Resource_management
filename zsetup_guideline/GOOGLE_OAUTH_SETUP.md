# Google OAuth Gmail Authentication Setup Guide

## Overview
This guide explains the improved Google OAuth authentication system with proper error handling, token storage, and security features.

## Fixed Issues

### 1. **CSRF Protection** ✅
- Added state parameter validation to prevent CSRF attacks
- State is generated and stored in session, then verified on callback

### 2. **Error Handling** ✅
- Comprehensive try-catch blocks for all API calls
- OAuth error detection from Google response
- Proper error logging and user-friendly redirects
- Validation of access tokens before use

### 3. **Token Storage** ✅
- Access tokens stored in database as JSON
- Enables token refresh and re-authentication without user interaction
- `google_token` column added to `applicant_accounts` table

### 4. **User Management** ✅
- Checks if user exists in database
- Auto-creates new accounts with Google credentials
- Updates token on subsequent logins
- Generates random password for OAuth users

### 5. **Session Management** ✅
- Sets proper session variables (`user_id`, `user_email`, `user_name`, `user_picture`)
- Tracks authentication type (`auth_type = 'google'`)
- Cleans up OAuth state from session after use

### 6. **Database Integration** ✅
- Uses PDO prepared statements (SQL injection prevention)
- Works with existing `applicant_accounts` table
- Stores user info from Google (name, email, picture)

## Setup Instructions

### Step 1: Add Database Column
Run this SQL migration:
```sql
ALTER TABLE `applicant_accounts` ADD COLUMN `google_token` LONGTEXT NULL;
```

### Step 2: Get Google OAuth Credentials
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing one
3. Enable "Google+ API"
4. Go to Credentials → Create OAuth 2.0 Client ID
5. Choose "Web application"
6. Add Authorized redirect URIs (must match the folder the project is in under htdocs):
   - `http://localhost/Resource_management/Resource_management/user/google_callback.php`
7. Copy your Client ID and Client Secret

### Step 3: Configure OAuth Credentials
Paste them into `google_oauth.local.php` in the project root:

```php
return [
    'GOOGLE_CLIENT_ID'     => 'xxxx.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => 'GOCSPX-xxxx',
    'GOOGLE_REDIRECT_URI'  => '',   // empty = detected automatically
];
```

Environment variables with the same names override this file. Until both values are filled in,
the Google button shows "Google sign-in is not set up yet" instead of sending users to a broken Google page.

### Step 4: Update Login Page
In your `applicant_login.php`, add a Google login button:
```html
<a href="google_login_init.php" class="btn btn-danger">
    <i class="fab fa-google"></i> Login with Google
</a>
```

### Step 5: Verify Composer Installation
Ensure you have Google API client installed:
```bash
composer require google/apiclient:^2.0
```

## File Structure

```
user/
├── google_callback.php      ← Handles OAuth callback (fixed)
├── google_login_init.php    ← Initiates OAuth flow (new)
├── applicant_login.php      ← Your login page (update needed)
└── vendor/
    └── autoload.php
```

## How It Works

### Login Flow:
1. User clicks "Login with Google" button
2. Redirects to `google_login_init.php`
3. Generates CSRF state token and stores in session
4. Redirects user to Google login page
5. User grants permission
6. Google redirects back to `google_callback.php` with authorization code

### Callback Processing:
1. Validates state parameter (CSRF check)
2. Exchanges authorization code for access token
3. Retrieves user info from Google
4. Checks if user exists in database
5. Creates new account or updates existing one
6. Sets session variables
7. Redirects to dashboard

## Security Features

✅ **CSRF Protection**: State parameter validation
✅ **SQL Injection Prevention**: Prepared statements (PDO)
✅ **Token Storage**: Securely stored as JSON in database
✅ **Error Logging**: All errors logged for debugging
✅ **Exception Handling**: Try-catch blocks prevent crashes
✅ **Input Validation**: Email and token validation

## Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| "Invalid state parameter" | CSRF attack suspected | User should retry login |
| "No authorization code received" | Google auth failed | Check redirect URI in Console |
| "Failed to retrieve access token" | Invalid credentials | Update Client ID/Secret |
| "Email not provided by Google" | Google account issue | User should use different account |
| "No OAuth code received" | Old error message (replaced) | N/A - Code now has proper handling |

## Database Schema

```sql
ALTER TABLE `applicant_accounts` ADD COLUMN `google_token` LONGTEXT NULL AFTER `avatar`;
```

Session Variables Set:
- `$_SESSION['user_id']` - User ID in database
- `$_SESSION['user_email']` - Email from Google
- `$_SESSION['user_name']` - Full name from Google
- `$_SESSION['user_picture']` - Google profile picture URL
- `$_SESSION['auth_type']` - Set to 'google' for OAuth users

## Testing

1. **Local Testing**:
   ```bash
   php -S localhost:8000
   # Visit: http://localhost:8000/Resource_management/user/applicant_login.php
   ```

2. **Debug Mode**:
   - Check browser console for redirect errors
   - Check PHP error logs for exceptions
   - Verify database connection in `config.php`

3. **Verify Setup**:
   - Check Google Cloud Console credentials
   - Verify redirect URI matches exactly
   - Confirm Composer packages installed
   - Test database column was added

## Common Issues

### "composer require google/apiclient"
**Solution**: Install via terminal in project root:
```bash
composer require google/apiclient:^2.0
```

### "Redirect URI mismatch"
**Solution**: Ensure redirect URI in Google Cloud Console matches exactly:
- Must be: `http://localhost/Resource_management/Resource_management/user/google_callback.php`
- Open the site with the same host you registered (`localhost` and `127.0.0.1` count as different)

### "CSRF attack" on every login
**Solution**: Check if session is working properly
- Verify `session_start()` is called before redirects
- Check browser cookie settings
- Test with different browser

### Token not saving to database
**Solution**: Check migration was applied:
```sql
SHOW COLUMNS FROM applicant_accounts;
-- Should show google_token column
```

## Next Steps

1. ✅ Update credentials in both PHP files
2. ✅ Run SQL migration to add `google_token` column
3. ✅ Update login page with Google button
4. ✅ Test the OAuth flow end-to-end
5. ✅ Monitor error logs during testing
6. ✅ Deploy to production with HTTPS

## Additional Features (Optional)

### Refresh Expired Tokens:
```php
if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($refreshToken);
}
```

### Store Multiple OAuth Providers:
```sql
ALTER TABLE `applicant_accounts` 
ADD COLUMN `oauth_provider` VARCHAR(50) DEFAULT 'local',
ADD COLUMN `google_id` VARCHAR(255) UNIQUE NULL;
```

### Logout Revoke Token:
```php
$client->revokeToken($accessToken);
```
