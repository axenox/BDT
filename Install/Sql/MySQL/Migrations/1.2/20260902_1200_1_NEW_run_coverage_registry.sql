-- UP

CREATE TABLE IF NOT EXISTS `bdt_run_coverage_registry` (
    `oid` binary(16) NOT NULL,
    `created_on` datetime NOT NULL,
    `modified_on` datetime NOT NULL,
    `created_by_user_oid` binary(16) NOT NULL,
    `modified_by_user_oid` binary(16) NOT NULL,
    `run_uid` varchar(32) NOT NULL,
    `screen_slug` varchar(160) NOT NULL,
    `screen_kind` varchar(10) NOT NULL,
    `widget_id` varchar(160) NOT NULL,
    `object_uid` varchar(34) NOT NULL,
    `role_key` varchar(100) NOT NULL,
    `work_category` varchar(50) NOT NULL,
    `element` varchar(100) NOT NULL,
    `action_fingerprint` char(64) NOT NULL,
    `status` int NOT NULL,
    `started_on` datetime NOT NULL,
    `finished_on` datetime DEFAULT NULL,
    PRIMARY KEY (`oid`) USING BTREE,
    CONSTRAINT `CK_run_coverage_registry_screen_kind` CHECK (`screen_kind` IN ('page', 'dialog', 'popup')),
    CONSTRAINT `CK_run_coverage_registry_work_category` CHECK (`work_category` IN ('Filtering', 'Buttons')),
    CONSTRAINT `UQ_run_coverage_registry_identity` UNIQUE
        (`run_uid`, `screen_slug`, `screen_kind`, `widget_id`, `object_uid`, `role_key`, `work_category`, `element`, `action_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not delete tables!