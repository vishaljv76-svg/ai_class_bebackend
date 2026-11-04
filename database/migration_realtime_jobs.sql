-- Migration: Real-Time Lecture Processing Jobs and Educational Resources
-- Database: ai_class

CREATE TABLE IF NOT EXISTS `lecture_processing_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `audio_id` int(11) NOT NULL,
  `transcription_id` int(11) DEFAULT NULL,
  `note_id` int(11) DEFAULT NULL,
  `quiz_id` int(11) DEFAULT NULL,
  `status` ENUM('PENDING', 'UPLOADING', 'TRANSCRIBING', 'GENERATING_NOTES', 'GENERATING_QUIZ', 'SEARCHING_RESOURCES', 'SAVING_RESULTS', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'PENDING',
  `progress_percent` int(11) NOT NULL DEFAULT 0,
  `current_step` varchar(100) NOT NULL DEFAULT 'Queued',
  `error_message` text DEFAULT NULL,
  `resources_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_jobs_user_status` (`user_id`, `status`),
  KEY `idx_jobs_audio` (`audio_id`),
  KEY `fk_jobs_transcription` (`transcription_id`),
  KEY `fk_jobs_note` (`note_id`),
  KEY `fk_jobs_quiz` (`quiz_id`),
  CONSTRAINT `fk_jobs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_audio` FOREIGN KEY (`audio_id`) REFERENCES `audio_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_transcription` FOREIGN KEY (`transcription_id`) REFERENCES `transcriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_note` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lecture_resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `note_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `url` varchar(500) NOT NULL,
  `source` varchar(100) NOT NULL DEFAULT 'Web',
  `topic` varchar(255) DEFAULT NULL,
  `snippet` text DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_resources_note` (`note_id`),
  KEY `idx_resources_user` (`user_id`),
  KEY `fk_resources_job` (`job_id`),
  CONSTRAINT `fk_resources_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resources_note` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resources_job` FOREIGN KEY (`job_id`) REFERENCES `lecture_processing_jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
