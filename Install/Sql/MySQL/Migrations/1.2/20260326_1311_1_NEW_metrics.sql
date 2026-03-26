-- UP

CREATE TABLE IF NOT EXISTS `bdt_metric` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `app_oid` binary(16) NOT NULL,
    `subject_object_oid` binary(16) DEFAULT NULL,
    `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
    `description` varchar(400) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `prototype_path` varchar(400) COLLATE utf8mb4_general_ci NOT NULL,
    `config_uxon` longtext COLLATE utf8mb4_general_ci,
    `enabled_flag` tinyint NOT NULL DEFAULT (1),
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `FK_metric_app` (`app_oid`),
    CONSTRAINT `FK_metric_app` FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;


CREATE TABLE IF NOT EXISTS `bdt_run_metric_score` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `metric_oid` binary(16) NOT NULL,
    `run_oid` binary(16) NOT NULL,
    `app_oid` binary(16) DEFAULT NULL,
    `score_expected` float NOT NULL,
    `score_absolute` float NOT NULL,
    `score_percentual` float NOT NULL,
    `subject_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
    `subject_object_oid` binary(16) DEFAULT NULL,
    `subject_uid` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `steps_count` int NOT NULL,
    `steps_oids` text COLLATE utf8mb4_general_ci,
    PRIMARY KEY (`oid`) USING BTREE,
    KEY `FK_metric_score_app` (`app_oid`),
    KEY `FK_metric_score_run` (`run_oid`),
    KEY `IDX_metric_run_app_name_percent` (`metric_oid`,`run_oid`,`app_oid`,`subject_name`,`score_percentual`),
    CONSTRAINT `FK_metric_score_app` FOREIGN KEY (`app_oid`) REFERENCES `exf_app` (`oid`),
    CONSTRAINT `FK_metric_score_metric` FOREIGN KEY (`metric_oid`) REFERENCES `bdt_metric` (`oid`),
    CONSTRAINT `FK_metric_score_run` FOREIGN KEY (`run_oid`) REFERENCES `bdt_run` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables!