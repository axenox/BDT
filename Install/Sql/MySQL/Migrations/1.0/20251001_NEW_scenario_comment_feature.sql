-- UP
ALTER TABLE `bdt_run_scenario`
    ADD `comment` varchar(300) COLLATE 'utf8mb4_general_ci' NULL,
    ADD `commented_by_user_oid` binary(16) NULL;

-- DOWN
ALTER TABLE `bdt_run_scenario`
    DROP `comment`,
    DROP `commented_by_user_oid`;