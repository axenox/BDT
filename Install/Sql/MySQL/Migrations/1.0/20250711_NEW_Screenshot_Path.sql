-- UP
ALTER TABLE `bdt_run_step`
ADD `screenshot_path` varchar(200) COLLATE 'utf8mb4_general_ci' NULL;

-- DOWN
ALTER TABLE `bdt_run_step`
DROP COLUMN `screenshot_path`