-- UP
ALTER TABLE `bdt_run_scenario`
    ADD `tags` varchar(200) COLLATE 'utf8mb4_general_ci' NULL;


-- DOWN
ALTER TABLE `bdt_run_scenario`
DROP COLUMN `tags`