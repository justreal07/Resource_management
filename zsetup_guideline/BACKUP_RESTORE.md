# Database and uploaded-file backups

The database holds document records, not the uploaded pictures themselves. A database dump or Git checkout alone cannot restore those pictures. Uploads are deliberately excluded from Git because they contain personal information.

## Make a backup

Pause new submissions, document changes and office updates for the backup window. Run from the project folder in PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\backup_data.ps1 -Destination 'D:\LGU-Backups'
```

This execution-policy setting applies only to that process; it does not change Windows settings. Choose a private destination outside the website folder. Keep an additional copy on a separate drive or protected backup storage.

The script finds `mysqldump.exe` in the XAMPP installation containing the project, then on PATH. If installed elsewhere, supply `-MySqlDump 'C:\path\to\mysqldump.exe'`. For a different database use `-Database name`. The default is `lgu_assistance_system`. For a password-protected database, use `-MySqlOptionsFile` pointing to a private MySQL client options file outside the website; do not put a password on the command line. `-MySqlUser` can override the username. Local XAMPP defaults to root only when no options file or username is supplied.

Each ZIP includes:

- `database.sql`, including database rows, triggers, routines and events.
- `documents/`, `avatars/`, `staff_avatars/`, and `announcement_images/`, including hidden protection files.
- `manifest.json`, listing the size and SHA-256 checksum of every data file.

Before publishing the ZIP, the script verifies every listed file inside it. It also rejects a backup if uploaded files change while being copied. This detects ordinary concurrent file changes; it does not replace a quiet backup window, because the database and filesystem are separate stores. Failed backups leave no published archive. A missing media directory is an error, while an empty directory is included.

The backup contains only files that still exist. It cannot reconstruct uploads that were already missing. It does not contain source code or private `*.local.php` configuration files. Keep those configurations separately in secure storage, and retain the source-code version used with the backup. The ZIP is not encrypted; limit access to its destination.

## Test restoration before replacing a live system

1. Extract the ZIP into a private temporary folder outside the website.
2. Check every `manifest.json` entry against its extracted file size and SHA-256 value using `Get-FileHash -Algorithm SHA256`. Confirm all four media directories are present.
3. Create a **new, empty test database**. Import `database.sql` into that database, not the live one. In phpMyAdmin, select the test database first, then Import. SQL import errors must be resolved before treating the backup as restorable.
4. Use a separate test copy of the matching application source. Point its private database configuration at the restored test database. Copy the four media directories from the archive into that test copy, preserving `.htaccess`. Keep email sending disabled in the test copy.
5. Check application counts, statuses and document records, then open sample documents and profile images through the authenticated application. A file missing before the backup will still be missing after restoration.
6. Only after verification, schedule any live restoration separately. Back up the current live state first and stop writes during the replacement. Keep the database and media folders from the same archive together.

## Regression checks

`tests/backup_data.ps1` uses synthetic files in a temporary directory and exports a disposable `lgu_logic_test_*` database. It checks all media directories, hidden files, destination restrictions and failed-export cleanup. It never restores over the live database.
