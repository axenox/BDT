-- UP
ALTER TABLE `bdt_run_step`
    ADD `details` text COLLATE 'utf8mb4_general_ci' NULL;


-- DOWN
ALTER TABLE `bdt_run_step`
DROP `details`;