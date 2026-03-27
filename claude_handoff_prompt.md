Project handoff for Claude
Generated on: 2026-03-24
Environment verified: WAMP on Windows, app URL `http://localhost/ems2`, PHP 8.3.28, MySQL database `employee_management`

Use this as the full prompt for Claude:

```text
You are reviewing and improving a PHP/MySQL Employee Management System called EmpAxis located at `C:\wamp64\www\ems2`.

Your goal:
1. Understand the current architecture and features.
2. Use the verified notes below as ground truth unless the code clearly proves otherwise.
3. Find root causes for the broken/inconsistent parts.
4. Propose or implement fixes carefully without breaking working flows.
5. Prioritize bugs, permission issues, data correctness, and export/payroll logic.

Project summary:
- Stack: PHP, MySQL, Vanilla JS, WAMP/Apache.
- Main URL: `http://localhost/ems2`
- Database name: `employee_management`
- Main roles: `admin`, `hr`, `employee`
- Main areas: auth, dashboard, employees, departments, attendance, leave, payroll, reports, notifications, announcements, settings, global search.

Verified environment facts:
- The app is reachable at `http://localhost/ems2/`.
- DB connection works.
- PHP syntax check passed for all PHP files in the project.
- Seed/demo data exists.
- Current DB counts when tested:
  - users: 14
  - employees: 6
  - departments: 6
  - attendance: 4
  - attendance_sessions: 32
  - leave_requests: 0
  - payroll: 0
  - notifications: 2
  - activity_log: 139
  - announcements: 2
  - smtp_config: 1

Verified demo accounts:
- Admin: username `admin`, password `password`
- HR: username `hrmanager`, password `password`
- Employee in DB/README: username `emp48372`, email `john.doe@company.com`, password `password`

Verified working behavior:
- Login page loads.
- Forgot password page loads.
- Reset password page loads.
- Register page loads.
- Admin login works.
- HR login works.
- Real employee login works with `emp48372` / `password`.
- Admin pages that loaded successfully:
  - dashboard
  - employees
  - departments
  - attendance
  - leave
  - payroll
  - reports
  - activity
  - notifications
  - announcements
  - settings
- HR pages that loaded successfully:
  - dashboard
  - employees
  - departments
  - attendance
  - leave
  - payroll
  - reports
  - activity
  - notifications
  - announcements
  - settings
- Employee pages that loaded successfully:
  - dashboard
  - own profile
  - attendance
  - leave
  - notifications
  - announcements
  - global search
- Employee attempts to access admin/hr-only pages redirected back to dashboard instead of showing those pages:
  - employees index
  - payroll
  - activity
  - settings
  - reports
- Search page loads for authenticated users.
- Attendance CSV export works and returns CSV.
- Reports attendance CSV export works and returns CSV.
- Payroll `calc_hours` AJAX endpoint returns JSON successfully.
- Leave invalid submission validation works for half-day across multiple dates.
- Attendance invalid action returns a clean JSON error.

Verified broken or inconsistent behavior:
- The login page’s “Quick demo login” employee button is wrong.
  - UI uses username `john.doe`
  - actual seeded employee account is `emp48372`
  - result: the employee quick-login chip fails
- There is a permission inconsistency around Settings:
  - sidebar shows Settings only for admin
  - backend route allows both admin and hr
  - need to decide intended behavior and make UI + backend consistent
- Search/demo credential messaging is inconsistent:
  - placeholder/demo UI suggests `john.doe`
  - README and DB seed use `emp48372`
- Exported attendance times look suspicious and likely incorrect for timezone handling.
  - Export code formats stored UTC timestamps with `date(strtotime(...))`
  - UI elsewhere uses `formatStoredUtcToApp(...)`
  - example export output included odd values like `02:48 AM` and `10:49 AM`
  - likely root issue: stored UTC is being interpreted as local time rather than converted from UTC to Asia/Kolkata
- Payroll hour calculation likely uses the wrong source table / logic.
  - code comment says it sums completed sessions
  - actual query in `modules/payroll/index.php` uses `attendance`, not `attendance_sessions`
  - that risks incorrect pay when multiple sessions or breaks exist
  - it should probably use `worked_minutes` or actual `attendance_sessions`

Important code-level observations:
- Attendance/session logic is centralized heavily in `includes/auth.php`.
- The app stores UTC-ish timestamps and later formats for app timezone in many UI locations.
- Attendance has both `attendance` summary rows and `attendance_sessions` detail rows.
- Payroll appears to depend on attendance data but may not be aligned with session-based tracking.
- SMTP is configurable and settings page includes a direct SMTP test feature.
- PHPMailer is optional; raw SMTP sending exists.

High-priority investigation areas:
1. Fix employee quick demo login credential mismatch.
2. Decide whether HR should access Settings. Then align:
   - route guard
   - sidebar visibility
   - any related docs
3. Audit all CSV/report exports for timezone correctness.
4. Audit payroll hourly calculation to ensure it matches the session-based attendance design.
5. Review auth/recovery/account creation flows for consistency with seeded/demo credentials.
6. Check whether any dashboard/report values are computed from summary rows in ways that disagree with session rows.

Files most likely relevant:
- `auth/login.php`
- `README.md`
- `includes/auth.php`
- `includes/sidebar.php`
- `modules/attendance/index.php`
- `modules/attendance/export.php`
- `modules/payroll/index.php`
- `modules/reports/index.php`
- `modules/settings/index.php`
- `search.php`

Please produce:
1. A concise architecture summary.
2. A list of confirmed bugs and inconsistencies.
3. Root-cause analysis for each major issue.
4. Recommended fixes in priority order.
5. Any risky or missing tests you think should be added.

Be careful to distinguish:
- verified behavior from runtime testing
- inferred issues from source review

Also assume the current tested date was 2026-03-24 and the app was running locally on WAMP when these notes were collected.
```
