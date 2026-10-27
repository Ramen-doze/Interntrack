CREATE DATABASE IF NOT EXISTS interntrack
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE interntrack;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(30) UNIQUE NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student','coordinator','supervisor','admin') NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE companies (
    company_id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    address VARCHAR(255),
    contact_person VARCHAR(150),
    contact_email VARCHAR(150),
    contact_number VARCHAR(50),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE internships (
    internship_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    company_id INT NOT NULL,
    supervisor_id INT NULL,
    coordinator_id INT NULL,
    start_date DATE,
    end_date DATE,
    required_hours INT DEFAULT 0,
    status ENUM('pending','ongoing','completed','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE RESTRICT,
    FOREIGN KEY (supervisor_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (coordinator_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE requirements (
    requirement_id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    requirement_name VARCHAR(150) NOT NULL,
    file_path VARCHAR(255),
    status ENUM('pending','submitted','approved','rejected') DEFAULT 'pending',
    remarks TEXT,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (internship_id) REFERENCES internships(internship_id) ON DELETE CASCADE
);

CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME,
    time_out TIME,
    hours_rendered DECIMAL(5,2) DEFAULT 0,
    status ENUM('present','late','absent','pending') DEFAULT 'pending',
    verified_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_attendance (internship_id, attendance_date),
    FOREIGN KEY (internship_id) REFERENCES internships(internship_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE accomplishment_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    report_title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    file_path VARCHAR(255),
    status ENUM('submitted','reviewed','rejected') DEFAULT 'submitted',
    remarks TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (internship_id) REFERENCES internships(internship_id) ON DELETE CASCADE
);

CREATE TABLE evaluations (
    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    evaluation_type ENUM('midterm','final') NOT NULL,
    rating DECIMAL(4,2) DEFAULT 0,
    comments TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (internship_id) REFERENCES internships(internship_id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO users (student_number, full_name, email, password_hash, role)
VALUES
('2026-0001', 'Demo Student', 'student@interntrack.test', '$2y$12$a4TCzWJtMcCPYhV7OHikaOGEI/yLScd/o4s7OsQVzB.eiMXbIs92K', 'student'),
(NULL, 'Demo Coordinator', 'coordinator@interntrack.test', '$2y$12$a4TCzWJtMcCPYhV7OHikaOGEI/yLScd/o4s7OsQVzB.eiMXbIs92K', 'coordinator'),
(NULL, 'Demo Supervisor', 'supervisor@interntrack.test', '$2y$12$a4TCzWJtMcCPYhV7OHikaOGEI/yLScd/o4s7OsQVzB.eiMXbIs92K', 'supervisor'),
(NULL, 'Demo Admin', 'admin@interntrack.test', '$2y$12$a4TCzWJtMcCPYhV7OHikaOGEI/yLScd/o4s7OsQVzB.eiMXbIs92K', 'admin');

INSERT INTO companies (company_name, address, contact_person, contact_email, contact_number)
VALUES
('Sample Technology Solutions', 'Pateros, Metro Manila', 'Juan Dela Cruz', 'juan@example.com', '09123456789');

INSERT INTO internships (student_id, company_id, supervisor_id, coordinator_id, start_date, end_date, required_hours, status)
VALUES (1, 1, 3, 2, '2026-09-01', '2026-12-15', 486, 'ongoing');

INSERT INTO requirements (internship_id, requirement_name)
VALUES
(1, 'Endorsement Letter'),
(1, 'Resume'),
(1, 'Medical Certificate'),
(1, 'Memorandum of Agreement');
