CREATE TABLE `endpoint_agent_binaries` (
  `binary_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `arch` varchar(10) NOT NULL,
  `sha256` char(64) NOT NULL,
  `size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `storage_name` varchar(64) NOT NULL,
  `uploaded_by` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`binary_id`),
  UNIQUE KEY `uniq_version_arch` (`version`,`arch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
