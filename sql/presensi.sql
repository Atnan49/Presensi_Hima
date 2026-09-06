-- ============================================
-- DATABASE: Sistem Presensi Mahasiswa
-- Laragon (MySQL) - Dibuat untuk ESP8266 + PN532
-- ============================================

CREATE DATABASE IF NOT EXISTS `presensi` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `presensi`;

-- ----------------------------
-- Tabel: students
-- Menyimpan data mahasiswa + UID kartu
-- ----------------------------
CREATE TABLE IF NOT EXISTS `students` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `uid` VARCHAR(50) NOT NULL COMMENT 'UID kartu RFID/NFC',
  `name` VARCHAR(100) NOT NULL COMMENT 'Nama mahasiswa',
  `nim` VARCHAR(20) DEFAULT NULL COMMENT 'Nomor Induk Mahasiswa',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=aktif, 0=nonaktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Tabel: events
-- Program Kerja / Acara Organisasi HIMA
-- ----------------------------
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL COMMENT 'Nama acara/program kerja',
  `description` TEXT DEFAULT NULL COMMENT 'Keterangan/deskripsi acara',
  `event_date` DATE NOT NULL COMMENT 'Tanggal pelaksanaan acara',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=sedang berlangsung, 0=nonaktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Acara default awal
INSERT INTO `events` (`id`, `name`, `description`, `event_date`, `is_active`) VALUES
(1, 'Kegiatan HIMA Umum', 'Presensi kegiatan reguler / program kerja HIMA', CURDATE(), 1)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- ----------------------------
-- Tabel: attendance
-- Menyimpan rekap absensi harian & per-acara
-- ----------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL COMMENT 'FK ke students.id',
  `event_id` INT(11) DEFAULT NULL COMMENT 'FK ke events.id',
  `session_id` VARCHAR(30) NOT NULL DEFAULT 'sesi_1' COMMENT 'ID sesi (sesi_1, sesi_2, dst)',
  `session_name` VARCHAR(60) NOT NULL DEFAULT 'Sesi 1 (Datang)' COMMENT 'Label sesi presensi',
  `uid` VARCHAR(50) NOT NULL COMMENT 'UID kartu (redundan untuk kemudahan)',
  `tap_time` DATETIME NOT NULL COMMENT 'Waktu tap kartu',
  `tap_date` DATE NOT NULL COMMENT 'Tanggal tap (untuk query harian)',
  PRIMARY KEY (`id`),
  KEY `idx_student_date` (`student_id`, `tap_date`),
  KEY `idx_tap_date` (`tap_date`),
  KEY `idx_event_id` (`event_id`),
  KEY `idx_session_id` (`session_id`),
  UNIQUE KEY `uniq_student_date_session` (`student_id`, `tap_date`, `session_id`),
  CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Tabel: unknown_cards
-- Kartu yang belum terdaftar pernah di-tap
-- ----------------------------
CREATE TABLE IF NOT EXISTS `unknown_cards` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `uid` VARCHAR(50) NOT NULL COMMENT 'UID kartu belum terdaftar',
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Pertama kali tap',
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Terakhir tap',
  `tap_count` INT(11) NOT NULL DEFAULT 1 COMMENT 'Berapa kali di-tap',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Tabel: admins
-- Menyimpan akun admin / operator sistem
-- ----------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Username admin',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Password terenkripsi bcrypt',
  `name` VARCHAR(100) NOT NULL COMMENT 'Nama lengkap admin/panitia',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Akun default: username 'admin', password 'admin123'
INSERT INTO `admins` (`username`, `password_hash`, `name`) VALUES
('admin', '$2y$12$YEdqoOuXXO53jWnLFTyLoOsFPcFrrc3Imxs.f8i0P979dLPqF7lJa', 'Administrator Presensi')
ON DUPLICATE KEY UPDATE `username` = `username`;

-- ----------------------------
-- Data contoh (opsional, bisa dihapus)
-- ----------------------------
-- INSERT INTO `students` (`uid`, `name`, `nim`) VALUES
-- ('A1B2C3D4', 'Budi Santoso', '2023001'),
-- ('E5F6G7H8', 'Siti Rahayu', '2023002');
