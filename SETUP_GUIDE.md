# TeaEstate Pro – cPanel Setup Guide

## Default Login Credentials
- **Admin:** username `admin` / password `admin123`
- **Supervisor:** username `supervisor` / password `admin123`
⚠️ Change these immediately after first login via User Management.

---

## Step 1: Create the Database
1. Log into cPanel
2. Go to **MySQL Databases**
3. Create a new database (e.g. `yourusername_teaestate`)
4. Create a MySQL user and set a strong password
5. Add the user to the database with **ALL PRIVILEGES**

## Step 2: Import the Database Schema
1. Go to **phpMyAdmin** in cPanel
2. Select your new database on the left
3. Click the **Import** tab
4. Click **Choose File** and select `database.sql`
5. Click **Go** — all tables and sample data will be created

## Step 3: Configure the App
Open `includes/config.php` and update:
```php
define('DB_HOST', 'localhost');          // Usually localhost
define('DB_NAME', 'yourusername_teaestate');  // Your DB name
define('DB_USER', 'yourusername_dbuser');      // Your DB user
define('DB_PASS', 'your_strong_password');     // Your DB password
```

Set BASE_URL if app is in a subfolder:
```php
define('BASE_URL', '/teaestate');  // If uploaded to /public_html/teaestate/
define('BASE_URL', '');            // If uploaded to /public_html/ root
```

## Step 4: Upload Files
Using cPanel File Manager or FTP:
- Upload ALL files to your web directory
- Recommended: `/public_html/teaestate/` or `/public_html/`
- Make sure `assets/` folder and all PHP files are uploaded

## Step 5: Set Permissions
In File Manager, set permissions:
- `includes/` folder → 755
- All `.php` files → 644
- `assets/` folder → 755

## Step 6: Access the App
Navigate to: `https://yourdomain.com/teaestate/`
Or: `https://yourdomain.com/` if uploaded to root

---

## File Structure
```
teaestate/
├── index.php           ← Dashboard
├── login.php           ← Login page
├── logout.php
├── workers.php         ← Worker management
├── assignments.php     ← Daily work assignments
├── payroll.php         ← Payroll reports
├── expenses.php        ← Expense tracking
├── production.php      ← Tea production tracking
├── fertilizer.php      ← Fertilizer cycle tracking
├── reports.php         ← Reports & CSV exports
├── users.php           ← User management (admin only)
├── database.sql        ← Import this to phpMyAdmin
├── .htaccess           ← Security & URL rules
├── includes/
│   ├── config.php      ← ⚠️ Edit DB credentials here
│   ├── db.php          ← Database class
│   ├── auth.php        ← Authentication
│   ├── functions.php   ← Helper functions
│   ├── header.php      ← Page header/nav
│   └── footer.php      ← Page footer
└── assets/
    ├── css/app.css     ← All styles
    └── js/app.js       ← All JavaScript
```

## Troubleshooting
- **Blank page:** Check DB credentials in config.php
- **Can't login:** Make sure database.sql was imported
- **Styles missing:** Check BASE_URL setting in config.php
- **Permission denied:** Set folder permissions to 755, files to 644

## Security Notes
- Change default passwords immediately
- Set error_reporting to 0 in config.php on production
- Keep the `includes/` folder protected (handled by .htaccess)
- Use HTTPS on your domain
