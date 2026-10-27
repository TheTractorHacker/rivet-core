CREATE TABLE `endpoint_agent_checks` (
  `device_id` int(11) NOT NULL,
  `check_key` varchar(100) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'unknown',
  `detail` varchar(500) NOT NULL DEFAULT '',
  `consecutive_failures` int(11) NOT NULL DEFAULT 0,
  `consecutive_ok` int(11) NOT NULL DEFAULT 0,
  `episode` int(11) NOT NULL DEFAULT 0,
  `alert_id` int(11) DEFAULT NULL,
  `last_reported_at` datetime DEFAULT NULL,
  `last_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`,`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
