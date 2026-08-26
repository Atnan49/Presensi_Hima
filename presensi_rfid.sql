-- ============================================================
-- DATABASE: presensi_rfid
-- Sistem Presensi Mahasiswa berbasis RFID
-- Tap Kartu untuk Rapat, Seminar, Event Kampus
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `presensi_rfid`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `presensi_rfid`;

-- ============================================================
-- Tabel: mahasiswa
-- Data mahasiswa dan kartu RFID mereka
-- ============================================================
CREATE TABLE `mahasiswa` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `uid`       VARCHAR(20)  NOT NULL COMMENT 'UID Kartu RFID',
  `nim`       VARCHAR(20)  NOT NULL COMMENT 'Nomor Induk Mahasiswa',
  `nama`      VARCHAR(100) NOT NULL,
  `prodi`     VARCHAR(100) NOT NULL DEFAULT '',
  `angkatan`  YEAR         NOT NULL DEFAULT '2024',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `nim` (`nim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabel: acara
-- Data acara/kegiatan yang presensinya direkap
-- ============================================================
CREATE TABLE `acara` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `nama_acara`    VARCHAR(200) NOT NULL,
  `tanggal`       DATE         NOT NULL,
  `waktu_mulai`   TIME         NOT NULL DEFAULT '08:00:00',
  `waktu_selesai` TIME         NOT NULL DEFAULT '10:00:00',
  `lokasi`        VARCHAR(200) NOT NULL DEFAULT '',
  `deskripsi`     TEXT,
  `status`        ENUM('draft','aktif','selesai') NOT NULL DEFAULT 'draft',
  `dibuat_pada`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabel: presensi
-- Rekap kehadiran mahasiswa per acara
-- ============================================================
CREATE TABLE `presensi` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `acara_id`      INT NOT NULL,
  `mahasiswa_id`  INT NOT NULL,
  `uid`           VARCHAR(20)  NOT NULL COMMENT 'UID saat tap',
  `waktu_tap`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status_hadir`  ENUM('hadir','terlambat') NOT NULL DEFAULT 'hadir',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_presensi` (`acara_id`, `mahasiswa_id`),
  FOREIGN KEY (`acara_id`)     REFERENCES `acara`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`mahasiswa_id`) REFERENCES `mahasiswa`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabel: last_uid
-- UID terakhir yang dibaca Arduino (untuk monitoring)
-- ============================================================
CREATE TABLE `last_uid` (
  `id`  INT         NOT NULL,
  `uid` VARCHAR(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `last_uid` (`id`, `uid`) VALUES (1, '');

COMMIT;
