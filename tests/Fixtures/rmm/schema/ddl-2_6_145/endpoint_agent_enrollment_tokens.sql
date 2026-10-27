CREATE TABLE `endpoint_agent_enrollment_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `token_selector` char(12) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT '',
  `client_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL DEFAULT 0,
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `expires_at` datetime NOT NULL,
  `max_uses` int(11) NOT NULL DEFAULT 1,
  `use_count` int(11) NOT NULL DEFAULT 0,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uniq_selector` (`token_selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
