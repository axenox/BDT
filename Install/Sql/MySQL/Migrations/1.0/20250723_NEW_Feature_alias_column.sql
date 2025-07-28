-- UP
ALTER TABLE `bdt_run_feature`
    ADD `alias` varchar(200) COLLATE 'utf8mb4_general_ci' NULL;

UPDATE bdt_run_feature SET bdt_run_feature.alias =
    LEFT(
    SUBSTRING_INDEX(bdt_run_feature.filename, '/', -1),
    LENGTH(SUBSTRING_INDEX(bdt_run_feature.filename, '/', -1)) - 8
    );

CREATE TABLE `bdt_run_suite` (
     `oid` binary(16) NOT NULL,
     `created_on` datetime NOT NULL,
     `modified_on` datetime NOT NULL,
     `created_by_user_oid` binary(16) NOT NULL,
     `modified_by_user_oid` binary(16) NOT NULL,
     `app_alias` varchar(100) NOT NULL,
     `run_oid` binary(16) NOT NULL,
     `total_page_count` int NOT NULL,
     `effected_page_count` int NOT NULL,
     `coverage` decimal(19,2) NOT NULL,
     PRIMARY KEY (`oid`)
);
-- DOWN
ALTER TABLE `bdt_run_feature`
DROP COLUMN `alias`

DROP TABLE IF EXISTS `bdt_run_suite`;