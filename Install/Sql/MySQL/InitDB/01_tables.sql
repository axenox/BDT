-- A test run represents launching behat once from CLI
CREATE TABLE IF NOT EXISTS `bdt_run` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `started_on` datetime NOT NULL,
    `finished_on` datetime DEFAULT NULL,
    `duration_ms` float DEFAULT NULL,
    `behat_command` varchar(400),
    PRIMARY KEY (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- Every feature involved in a test run will produce one row in this table
CREATE TABLE IF NOT EXISTS `bdt_run_feature` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `run_oid` binary(16) NOT NULL,
    `run_sequence_idx` int NOT NULL,
    `app_alias` varchar(100) DEFAULT NULL,
    `name` varchar(500) NOT NULL,
    `description` text COLLATE utf8mb4_general_ci,
    `filename` varchar(200) DEFAULT NULL,
    `started_on` datetime NOT NULL,
    `finished_on` datetime DEFAULT NULL,
    `duration_ms` float DEFAULT NULL,
    `content` longtext COLLATE utf8mb4_general_ci,
    PRIMARY KEY (`oid`),
    KEY `IX_run_feature_run` (`run_oid`) USING BTREE,
    CONSTRAINT `FK_run_feature_run` FOREIGN KEY (`run_oid`) REFERENCES `bdt_run` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- Every feature involved in a test run will produce one row in this table
CREATE TABLE IF NOT EXISTS `bdt_run_scenario` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `run_feature_oid` binary(16) NOT NULL,
    `name` varchar(1000) NOT NULL,
    `line` int NOT NULL DEFAULT '0',
    `started_on` datetime NOT NULL,
    `finished_on` datetime DEFAULT NULL,
    `duration_ms` float DEFAULT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `IX_run_scenario_feature` (`run_feature_oid`) USING BTREE,
    CONSTRAINT `FK_run_scenario_feature` FOREIGN KEY (`run_feature_oid`) REFERENCES `bdt_run_feature` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- Every step actually performed in a test run will produce one row in this table
CREATE TABLE IF NOT EXISTS `bdt_run_step` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) DEFAULT NULL,
    `modified_by_user_oid` binary(16) DEFAULT NULL,
    `run_scenario_oid` binary(16) NOT NULL,
    `run_sequence_idx` int NOT NULL,
    `name` varchar(1000) NOT NULL,
    `line` int NOT NULL DEFAULT '0',
    `started_on` datetime NOT NULL,
    `finished_on` datetime DEFAULT NULL,
    `duration_ms` float DEFAULT NULL,
    `status` int NOT NULL,
    `error_message` varchar(200) DEFAULT NULL,
    `error_log_id` varchar(10) DEFAULT NULL,
    PRIMARY KEY (`oid`),
    KEY `IX_run_step_scenario` (`run_scenario_oid`) USING BTREE,
    CONSTRAINT `FK_run_step_scenario` FOREIGN KEY (`run_scenario_oid`) REFERENCES `bdt_run_scenario` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `bdt_run_scenario_action` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `run_scenario_oid` binary(16) NOT NULL,
    `page_oid` binary(16) DEFAULT NULL,
    `page_alias` varchar(200) NOT NULL,
    `widget_id` varchar(2000) DEFAULT NULL,
    `action_alias` varchar(200) DEFAULT NULL,
    `action_path` varchar(400) DEFAULT NULL,
    `action_caption` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`oid`),
    KEY `IX_run_step_action_scenario` (`run_scenario_oid`) USING BTREE,
    CONSTRAINT `FK_run_step_action_scenario` FOREIGN KEY (`run_scenario_oid`) REFERENCES `bdt_run_scenario` (`oid`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;