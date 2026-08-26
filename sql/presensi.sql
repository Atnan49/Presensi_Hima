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
-- Tabel: attendance
-- Menyimpan rekap absensi harian
-- ----------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL COMMENT 'FK ke students.id',
  `uid` VARCHAR(50) NOT NULL COMMENT 'UID kartu (redundan untuk kemudahan)',
  `tap_time` DATETIME NOT NULL COMMENT 'Waktu tap kartu',
  `tap_date` DATE NOT NULL COMMENT 'Tanggal tap (untuk query harian)',
  PRIMARY KEY (`id`),
  KEY `idx_student_date` (`student_id`, `tap_date`),
  KEY `idx_tap_date` (`tap_date`),
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
-- Data contoh (opsional, bisa dihapus)
-- ----------------------------
-- INSERT INTO `students` (`uid`, `name`, `nim`) VALUES
-- ('A1B2C3D4', 'Budi Santoso', '2023001'),
-- ('E5F6G7H8', 'Siti Rahayu', '2023002');
