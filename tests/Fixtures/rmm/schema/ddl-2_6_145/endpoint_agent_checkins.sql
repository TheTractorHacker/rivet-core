CREATE TABLE `endpoint_agent_checkins` (
  `device_id` int(11) NOT NULL,
  `seq` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `collected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`seq`),
  KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
