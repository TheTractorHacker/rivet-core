CREATE TABLE `endpoint_agent_releases` (
  `release_id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(40) NOT NULL,
  `url` varchar(500) NOT NULL,
  `sha256` char(64) NOT NULL,
  `min_version` varchar(40) NOT NULL DEFAULT '0.0.0',
  `ring` varchar(20) NOT NULL DEFAULT 'stable',
  `rollout_pct` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`release_id`),
  UNIQUE KEY `uniq_version_ring` (`version`,`ring`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
