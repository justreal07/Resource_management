# Project Organization Guide
**Purpose**: Organize root-level files into appropriate folders to prevent confusion and maintain clean structure

---

## 📁 FILES TO MOVE TO EXISTING FOLDERS

### **1. Move to `user/` folder**
```
applicant_logout.php  → user/applicant_logout.php
view_document.php     → user/view_document.php
```
**Reason**: These are applicant-related functions used by the user/applicant section

---

### **2. Move to `Main_dashboard/` folder**
```
delete_applicant.php  → Main_dashboard/delete_applicant.php
edit_applicant.php    → Main_dashboard/edit_applicant.php
reject_applicant.php  → Main_dashboard/reject_applicant.php
```
**Reason**: These are admin functions for managing applicants in the dashboard

---

### **3. Keep in ROOT folder (already well-placed)**
```
config.php                    ✅ Database configuration (KEEP HERE)
composer.json                 ✅ Package manager (KEEP HERE)
composer.lock                 ✅ Package lock file (KEEP HERE)
ADMIN_SETUP_GUIDE.md         ✅ Documentation (KEEP HERE)
GOOGLE_OAUTH_SETUP.md        ✅ Documentation (KEEP HERE)
LOCALHOST_SETUP.md           ✅ Documentation (KEEP HERE)
QUICK_START.md               ✅ Documentation (KEEP HERE)
WEEKLY_REPORT_GUIDE.md       ✅ Documentation (KEEP HERE)
```

---

### **4. Remove or Archive**
```
test_composer.php  ❌ TESTING FILE - Delete if no longer needed
```
**Reason**: Testing files should not be in production; delete or move to a `/tests/` folder if keeping

---

## 📋 BEFORE & AFTER STRUCTURE

### **BEFORE** (Current - Messy Root)
```
Resource_management/
├── applicant_logout.php         ← MOVE
├── config.php
├── delete_applicant.php         ← MOVE
├── edit_applicant.php           ← MOVE
├── reject_applicant.php         ← MOVE
├── test_composer.php            ← DELETE
├── view_document.php            ← MOVE
├── composer.json
├── composer.lock
├── ADMIN_SETUP_GUIDE.md
├── *.md files
├── user/
├── Main_dashboard/
└── [other folders]
```

### **AFTER** (Organized - Clean Root)
```
Resource_management/
├── config.php                   ✅ CORE CONFIG
├── composer.json                ✅ DEPENDENCIES
├── composer.lock
├── ADMIN_SETUP_GUIDE.md         ✅ DOCUMENTATION
├── GOOGLE_OAUTH_SETUP.md
├── LOCALHOST_SETUP.md
├── QUICK_START.md
├── WEEKLY_REPORT_GUIDE.md
├── PROJECT_ORGANIZATION_GUIDE.md
├── user/
│   ├── applicant_logout.php     ← MOVED HERE
│   ├── view_document.php        ← MOVED HERE
│   ├── applicant_login.php
│   ├── applicant_register.php
│   └── [other user files]
├── Main_dashboard/
│   ├── delete_applicant.php     ← MOVED HERE
│   ├── edit_applicant.php       ← MOVED HERE
│   ├── reject_applicant.php     ← MOVED HERE
│   ├── applicants.php
│   └── [other admin files]
└── [other folders: burial, medical, opd, etc.]
```

---

## ✅ HOW TO APPLY

1. **Move these files**:
   ```bash
   # Move to user/
   mv applicant_logout.php user/
   mv view_document.php user/

   # Move to Main_dashboard/
   mv delete_applicant.php Main_dashboard/
   mv edit_applicant.php Main_dashboard/
   mv reject_applicant.php Main_dashboard/
   ```

2. **Delete test file** (if not needed):
   ```bash
   del test_composer.php
   ```

3. **Update PHP file references** if any files link to these:
   - Check for `include/require` statements pointing to old paths
   - Update to new paths: `require '../user/applicant_logout.php'`

---

## 🔍 BENEFITS
✅ Root folder stays clean (only config, composer, docs)  
✅ Easy to find files (organized by feature/section)  
✅ Prevents calculation/reference mistakes  
✅ Professional project structure  
✅ Easier to maintain and scale

---

**Status**: Ready to organize! 🎯
