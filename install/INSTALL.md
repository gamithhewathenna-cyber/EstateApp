# TeaEstate Pro — Installation Guide (cPanel)

## Step 1: Create MySQL Database
1. Login to cPanel → MySQL Databases
2. Create database: e.g. `cpanelusername_teaestate`
3. Create database user with a strong password
4. Add user to database — grant ALL PRIVILEGES
5. Note your: database name, username, password

## Step 2: Import Database Schema
1. cPanel → phpMyAdmin
2. Select your new database (left panel)
3. Click "Import" tab
4. Upload: `install/database.sql`
5. Click "Go"

## Step 3: Upload Files
1. cPanel → File Manager → public_html (or a subfolder)
2. Upload all files EXCEPT the `install/` folder
   (or delete install/ after setup for security)
3. Keep folder structure intact

## Step 4: Edit Config
1. Open `includes/config.php`
2. Set your values:
   - DB_HOST: usually `localhost`
   - DB_NAME: `cpanelusername_teaestate`
   - DB_USER: your db username
   - DB_PASS: your db password
3. Save the file

## Step 5: First Login
- URL: https://yourdomain.com/ (or /subfolder/)
- Admin username: `admin`
- Admin password: `password`
- Supervisor username: `supervisor`
- Supervisor password: `password`

## Step 6: After Login (Important!)
1. Go to Users → Edit admin → Change password
2. Go to Settings → Add your real plantation names
3. Go to Workers → Add your workers

## Folder Structure
```
/
├── index.php          Dashboard
├── login.php          Login page
├── logout.php
├── workers.php
├── assignments.php    Daily work assignments
├── payroll.php
├── expenses.php
├── production.php
├── fertilizer.php
├── reports.php
├── users.php          Admin only
├── settings.php       Admin only
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── includes/
│   ├── config.php     ← EDIT THIS
│   ├── header.php
│   └── footer.php
├── api/
│   └── report.php
└── install/
    ├── database.sql   ← Import to phpMyAdmin
    └── INSTALL.md
```

## PHP Requirements
- PHP 8.0 or higher
- PDO + PDO_MySQL extension
- Session support
- Most cPanel shared hosts support all of these.

## Default Work Rates (editable via work_types table)
- Tea Plucking: Rs. 50 / kg
- Clearing Work: Rs. 2,000 / unit
- Tank Spraying: Rs. 200 / tank
- Helper: Rs. 1,000 / day
- Basic Work: Rs. 2,000 / unit

## Default Credentials
| Username   | Password | Role       |
|------------|----------|------------|
| admin      | password | Admin      |
| supervisor | password | Supervisor |

CHANGE THESE AFTER FIRST LOGIN!
