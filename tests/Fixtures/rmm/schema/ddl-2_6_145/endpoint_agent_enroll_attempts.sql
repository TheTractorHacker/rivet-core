CREATE TABLE `endpoint_agent_enroll_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) NOT NULL,
  `ip_text` varchar(64) NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(40) NOT NULL DEFAULT '',
  `token_selector` varchar(12) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_ip_time` (`ip_hash`,`attempted_at`),
  KEY `idx_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
