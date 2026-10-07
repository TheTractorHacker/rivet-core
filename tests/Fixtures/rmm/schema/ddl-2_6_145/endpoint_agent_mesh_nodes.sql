CREATE TABLE `endpoint_agent_mesh_nodes` (
  `device_id` int(11) NOT NULL,
  `mesh_node_id` varchar(200) NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'manual',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`device_id`),
  KEY `idx_node` (`mesh_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
