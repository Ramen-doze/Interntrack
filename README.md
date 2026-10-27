# InternTrack
Web-Based Internship/OJT Monitoring and Management System

Stack:
- PHP (procedural)
- MySQL
- HTML/CSS
- JavaScript

Implemented:
- Role-based login and dashboard routing for students, coordinators, supervisors, and admins
- Admin user creation, account updates/deactivation, optional password reset, and own profile/password settings
- Coordinator student and company management, internship assignment create/update, requirement setup/review, and attendance create/update
- Student profile updates, secure requirement document submissions, daily attendance check-in/out, and weekly accomplishment report submission
- Supervisor attendance verification, accomplishment report review/revision requests, and evaluation create/update
- Role-scoped downloads and CSRF-protected state-changing forms
- No permanent delete actions; account/company/assignment records are deactivated, cancelled, or retained to preserve history

Known workflow gaps:
- Requirements accept PDF, JPG, PNG, and DOCX uploads up to 5 MB; ensure PHP `fileinfo` is enabled and upload limits are configured accordingly.
- Attendance uses the web server's current date/time and does not provide GPS, geofencing, or a payroll/time-clock integration.
- External API integrations and notifications are not implemented.
- Review the workflows and evaluation rating scale against school policy before deployment.

## Setup
1. Copy the `interntrack` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database/interntrack.sql`.
4. Visit `http://localhost/interntrack/`.
5. Demo accounts are included in the SQL file. Passwords are `password123`.

If you imported the database before the demo password hash was corrected, run
this in phpMyAdmin's SQL tab to reset the four demo accounts:

```sql
UPDATE users
SET password_hash = '$2y$12$a4TCzWJtMcCPYhV7OHikaOGEI/yLScd/o4s7OsQVzB.eiMXbIs92K'
WHERE email IN (
    'student@interntrack.test',
    'coordinator@interntrack.test',
    'supervisor@interntrack.test',
    'admin@interntrack.test'
);
```

This project remains a development baseline, not a production-ready student information system. Before deployment, configure database credentials, replace demo accounts, and complete the remaining school-specific workflows.
