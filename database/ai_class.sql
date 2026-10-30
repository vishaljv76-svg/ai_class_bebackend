-- ========================================================
-- SmartNotes AI Database Schema
-- Database: `ai_class` (or `smartnotes_ai`)
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci
-- ========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- 1. Table structure for table `users`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `reset_token` VARCHAR(255) DEFAULT NULL,
  `reset_expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Table structure for table `user_tokens` (Secure Session Tokens)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token` (`token`),
  KEY `idx_user_tokens_user` (`user_id`),
  CONSTRAINT `fk_user_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Table structure for table `student_profiles`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_profiles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `course` VARCHAR(150) DEFAULT NULL,
  `department` VARCHAR(150) DEFAULT NULL,
  `semester` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_profile_user_id` (`user_id`),
  CONSTRAINT `fk_student_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Table structure for table `notes`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(150) NOT NULL DEFAULT 'Computer Science',
  `course_code` VARCHAR(50) DEFAULT 'CS301',
  `tag` VARCHAR(50) DEFAULT 'AI notes',
  `professor` VARCHAR(150) DEFAULT 'Prof. Sarah Chen',
  `duration` VARCHAR(50) DEFAULT '42 min',
  `read_time` VARCHAR(50) DEFAULT '6 min read',
  `icon_type` VARCHAR(50) DEFAULT 'Database',
  `summary` TEXT DEFAULT NULL,
  `full_content` LONGTEXT DEFAULT NULL,
  `key_takeaways` TEXT DEFAULT NULL,
  `important_terms` TEXT DEFAULT NULL,
  `section1_title` VARCHAR(255) DEFAULT NULL,
  `section1_content` TEXT DEFAULT NULL,
  `section2_title` VARCHAR(255) DEFAULT NULL,
  `section2_bullets` TEXT DEFAULT NULL,
  `callout_title` VARCHAR(255) DEFAULT NULL,
  `callout_content` TEXT DEFAULT NULL,
  `section3_title` VARCHAR(255) DEFAULT NULL,
  `section3_content1` TEXT DEFAULT NULL,
  `section3_content2` TEXT DEFAULT NULL,
  `footer_text` VARCHAR(255) DEFAULT NULL,
  `is_bookmarked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notes_user_created` (`user_id`, `created_at`),
  KEY `idx_notes_subject` (`subject`),
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. Table structure for table `subjects`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `icon_type` VARCHAR(50) DEFAULT 'ComputerScience',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_subject_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Table structure for table `user_subjects`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_subjects` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `subject_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_subject_unique` (`user_id`, `subject_id`),
  CONSTRAINT `fk_user_subjects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. Table structure for table `quizzes`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `note_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(100) DEFAULT 'Computer Science',
  `score_percentage` INT(11) NOT NULL DEFAULT 0,
  `correct_count` INT(11) NOT NULL DEFAULT 0,
  `total_questions` INT(11) NOT NULL DEFAULT 5,
  `difficulty` VARCHAR(50) DEFAULT 'Moderate',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quizzes_user` (`user_id`),
  CONSTRAINT `fk_quizzes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Default Seed Data for Subjects
-- --------------------------------------------------------
INSERT IGNORE INTO `subjects` (`id`, `name`, `code`, `description`, `icon_type`) VALUES
(1, 'Computer Science', 'CS301', 'Code, systems & databases', 'ComputerScience'),
(2, 'Mathematics', 'MATH201', 'Equations, logic & problem solving', 'Mathematics'),
(3, 'Physics', 'PHYS101', 'Matter, motion & energy', 'Physics'),
(4, 'Chemistry', 'CHEM101', 'Elements, reactions & discovery', 'Chemistry'),
(5, 'English', 'ENG101', 'Language, literature & expression', 'English');

-- --------------------------------------------------------
-- Seed Sample Notes (For User ID 1 if exists, or available for onboarding)
-- --------------------------------------------------------
INSERT IGNORE INTO `notes` (
  `id`, `user_id`, `title`, `subject`, `course_code`, `tag`, `professor`, 
  `duration`, `read_time`, `icon_type`, `summary`, `full_content`,
  `key_takeaways`, `important_terms`, `section1_title`, `section1_content`, 
  `section2_title`, `section2_bullets`, `callout_title`, `callout_content`, 
  `section3_title`, `section3_content1`, `section3_content2`, `footer_text`, `is_bookmarked`
) VALUES
(
  1, 1, 'Database Normalization', 'Computer Science', 'CS301', 'AI notes', 'Prof. Sarah Chen',
  '42 min', '6 min read', 'Database',
  'Normalization reduces redundancy and prevents data anomalies. Move from atomic values (1NF) to full-key dependency (2NF), then remove transitive dependencies (3NF).',
  'Normalization organizes relations to minimize repeated data. It protects consistency when records are inserted, updated or deleted.',
  '["Repeated data can cause update, insertion and deletion anomalies.", "2NF removes partial dependencies on a composite key.", "3NF keeps non-key attributes dependent only on the key."]',
  '["Functional dependency", "Composite key", "Normal forms"]',
  '01 Why normalize?',
  'Normalization organizes relations to minimize repeated data. It protects consistency when records are inserted, updated or deleted.',
  '02 The first three normal forms',
  '["1NF — Atomic values in each cell; no repeating groups.", "2NF — In 1NF, and every non-key attribute depends on the whole candidate key.", "3NF — In 2NF, with no transitive dependency between non-key attributes."]',
  'Functional dependency: X → Y',
  'Each value of X determines exactly one value of Y.\nExample: StudentID → StudentName.',
  '03 Worked example',
  'Enrollment(StudentID, CourseID, StudentName, Grade) has key (StudentID, CourseID). StudentName depends only on StudentID: a partial dependency.',
  'Split into Student(StudentID, StudentName) and Enrollment(StudentID, CourseID, Grade) to reach 2NF.',
  'Excerpt · Next: 3NF decomposition & practice',
  0
),
(
  2, 1, 'Eigenvalues & Eigenvectors', 'Mathematics', 'MATH201', 'AI notes', 'Dr. Robert Vance',
  '36 min', '5 min read', 'Math',
  'Eigenvalues represent scalar factors by which eigenvectors are stretched or shrunk during a linear transformation: Av = λv.',
  'A linear transformation maps vectors to vectors. Eigenvectors retain their directional axis, changing only in magnitude by the eigenvalue λ.',
  '["Av = λv defines the characteristic equation det(A - λI) = 0.", "Used in principal component analysis (PCA), stability analysis, and quantum mechanics."]',
  '["Characteristic equation", "Determinant", "Linear transformation"]',
  '01 Geometric Interpretation',
  'Most vectors change direction under matrix multiplication. Eigenvectors do not rotate; they are merely scaled.',
  '02 Finding Eigenvalues',
  '["Solve det(A - λI) = 0 for the eigenvalues.", "Substitute each λ back into (A - λI)v = 0 to solve for the eigenspace basis."]',
  'Characteristic Equation',
  'det(A - λI) = 0 is a polynomial equation whose roots are eigenvalues.',
  '03 Applications',
  'Eigenvalues govern vibration modes in mechanical systems and page rank algorithms on the web.',
  'Diagonalizing symmetric matrices simplifies computing large matrix powers.',
  'Excerpt · Next: SVD and Spectral Theorem',
  0
),
(
  3, 1, 'SQL Joins & Subqueries', 'Computer Science', 'CS301', 'AI notes', 'Prof. Sarah Chen',
  '48 min', '7 min read', 'Database',
  'Understanding INNER, LEFT, RIGHT, and FULL OUTER joins alongside correlated and non-correlated subqueries for efficient relational data retrieval.',
  'Relational databases store related records across normalized tables. Joins connect these tables on common foreign-to-primary key relationships.',
  '["INNER JOIN returns rows with matches in both tables.", "LEFT JOIN preserves all rows from the left table.", "Subqueries can appear in SELECT, FROM, or WHERE clauses."]',
  '["Cartesian product", "Outer join", "Correlated subquery"]',
  '01 Join Types Overview',
  'Joins combine columns from one or more tables based on a related column between them.',
  '02 Subquery Best Practices',
  '["Use EXISTS instead of IN for large correlated subquery performance.", "Prefer JOINs over deep subquery nests when the query optimizer permits."]',
  'Performance Note',
  'Missing indexes on foreign key join columns cause full table scans.',
  '03 Practical Example',
  'SELECT s.name, c.title FROM students s INNER JOIN enrollments e ON s.id = e.student_id INNER JOIN courses c ON e.course_id = c.id;',
  'This retrieves all students and their enrolled course titles seamlessly.',
  'Excerpt · Next: Window functions and CTEs',
  0
);

SET FOREIGN_KEY_CHECKS = 1;

