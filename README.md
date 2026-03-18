# EmpAxis – Employee Management System

A production-ready HR platform built with PHP, MySQL, and Vanilla JS.

## 🚀 Quick Setup (WAMP)

### 1. Database Setup
1. Start WAMP Server (ensure MySQL is running)
2. Open phpMyAdmin → `http://localhost/phpmyadmin`
3. Click **New** → Create database: `employee_management`
4. Select the database → click **Import**
5. Choose **`install.sql`** (single file, recommended) and click Go

### 2. File Setup
1. Copy the entire `employee-management-system/` folder to `C:\wamp64\www\`
2. Verify: `C:\wamp64\www\employee-management-system\index.php`

### 3. Configuration
Edit `config/database.php` if needed:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');           // Your MySQL password
define('DB_NAME', 'employee_management');
define('APP_URL',  'http://localhost/employee-management-system');
```

### 4. Access the App
Open browser: `http://localhost/employee-management-system`

## 🔑 Demo Login Credentials

| Role     | Username    | Email                    | Password   |
|----------|-------------|--------------------------|------------|
| Admin    | admin       | admin@company.com        | `password` |
| HR       | hrmanager   | hr@company.com           | `password` |
| Employee | emp48372    | john.doe@company.com     | `password` |

## 📁 Project Structure
```
employee-management-system/
├── install.sql              ← Import this ONE file for setup
├── config/database.php      ← DB credentials + APP_URL
├── includes/                ← auth.php, header, sidebar, footer
├── auth/                    ← login, register, forgot/reset password
├── dashboard/               ← Role-based dashboard
├── modules/
│   ├── employees/           ← CRUD, profiles, bulk actions
│   ├── departments/         ← Department management
│   ├── attendance/          ← Check-in/out, export CSV
│   ├── leave/               ← Apply, approve/reject leaves
│   ├── payroll/             ← Generate payroll, payslips
│   ├── reports/             ← Attendance, payroll, leave reports
│   ├── notifications/       ← In-app notification center
│   ├── activity/            ← System activity log
│   └── settings/            ← SMTP, password, announcements
├── assets/css/style.css     ← Main stylesheet (dark/light mode)
├── assets/js/main.js        ← Frontend logic
└── uploads/employee_photos/ ← Profile photo uploads
```

## ✅ Features
- **3 Roles**: Admin, HR Manager, Employee with different access
- **Dark/Light mode** with smooth transitions
- **Dashboard** with charts (Chart.js) for each role
- **Employee CRUD** with photo upload, bulk actions
- **Attendance** check-in/out with live clock
- **Leave Management** with balance tracking
- **Payroll** generation with printable payslips
- **Reports** with CSV export (attendance, payroll, leaves)
- **Notifications** in-app notification system
- **Activity Log** full audit trail
- **Search** global search across employees, departments, leaves
- **SMTP** configurable email settings

## 🔒 Security
- bcrypt password hashing (cost 12)
- PDO prepared statements (SQL injection safe)
- Session-based auth with role checks
- CSRF token generation
- XSS prevention via htmlspecialchars throughout
