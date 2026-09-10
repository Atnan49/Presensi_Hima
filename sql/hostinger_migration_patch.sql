-- ================================================================
-- PATCH MIGRASI DATABASE HOSTINGER (Sistem Presensi RFID HIMA)
-- Jalankan query ini di menu SQL phpMyAdmin jika menggunakan DB lama
-- ================================================================

-- 1. Tambah kolom struktur organisasi ke tabel students (abaikan jika sudah ada)
ALTER TABLE `students` ADD COLUMN `category` ENUM('BPI', 'BPH', 'Anggota') NOT NULL DEFAULT 'Anggota' AFTER `nim`;
ALTER TABLE `students` ADD COLUMN `division` VARCHAR(100) DEFAULT NULL AFTER `category`;
ALTER TABLE `students` ADD COLUMN `position` VARCHAR(100) DEFAULT NULL AFTER `division`;

-- 2. Tambah kolom jam & target audience ke tabel events (abaikan jika sudah ada)
ALTER TABLE `events` ADD COLUMN `start_time` TIME NULL DEFAULT '08:00:00' AFTER `event_date`;
ALTER TABLE `events` ADD COLUMN `end_time` TIME NULL DEFAULT NULL AFTER `start_time`;
ALTER TABLE `events` ADD COLUMN `target_audience` ENUM('all', 'committee_only', 'bpi_bph') NOT NULL DEFAULT 'all' AFTER `end_time`;

-- 3. Tambah kolom event, sesi, dan status telat ke tabel attendance (abaikan jika sudah ada)
ALTER TABLE `attendance` ADD COLUMN `event_id` INT(11) NULL DEFAULT NULL AFTER `student_id`;
ALTER TABLE `attendance` ADD COLUMN `session_id` VARCHAR(30) NOT NULL DEFAULT 'sesi_1' AFTER `event_id`;
ALTER TABLE `attendance` ADD COLUMN `session_name` VARCHAR(60) NOT NULL DEFAULT 'Sesi 1 (Datang)' AFTER `session_id`;
ALTER TABLE `attendance` ADD COLUMN `telat` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_name`;

-- 4. Buat tabel susunan kepanitiaan program kerja (event_committees)
CREATE TABLE IF NOT EXISTS `event_committees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `event_id` INT(11) NOT NULL COMMENT 'Relasi ke events.id',
  `student_id` INT(11) NOT NULL COMMENT 'Relasi ke students.id',
  `role` VARCHAR(100) NOT NULL COMMENT 'Jabatan panitia',
  `division` VARCHAR(100) DEFAULT NULL COMMENT 'Sie panitia',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_student` (`event_id`, `student_id`),
  KEY `idx_event_comm` (`event_id`),
  KEY `idx_student_comm` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
