-- Additive migration for the PHP admin panel.
-- This does not rename or alter any existing frontend tables.
CREATE TABLE IF NOT EXISTS `AdminSetting` (
  `key` varchar(100) NOT NULL,
  `typedValue` text NOT NULL,
  `updatedBy` varchar(191) DEFAULT NULL,
  `updatedAt` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`key`),
  KEY `AdminSetting_updatedBy_idx` (`updatedBy`),
  CONSTRAINT `AdminSetting_updatedBy_fkey` FOREIGN KEY (`updatedBy`) REFERENCES `User` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AdminAuditLog` (
  `id` varchar(191) NOT NULL,
  `actorId` varchar(191) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity` varchar(80) NOT NULL,
  `entityId` varchar(191) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ipAddress` varchar(45) DEFAULT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `AdminAuditLog_actorId_idx` (`actorId`),
  KEY `AdminAuditLog_createdAt_idx` (`createdAt`),
  CONSTRAINT `AdminAuditLog_actorId_fkey` FOREIGN KEY (`actorId`) REFERENCES `User` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `AdminSetting` (`key`, `typedValue`) VALUES
('business_name', 'Pahadi Stay'),
('commission_percent', '12'),
('timezone', 'Asia/Kolkata'),
('currency', 'INR')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);
