-- UP
ALTER TABLE `bdt_run_step` ADD COLUMN `url` varchar(500) DEFAULT NULL AFTER `error_log_id`;

-- DOWN
ALTER TABLE `bdt_run_step` DROP COLUMN `url`;