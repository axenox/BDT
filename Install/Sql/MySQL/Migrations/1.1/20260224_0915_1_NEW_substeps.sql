-- UP

ALTER TABLE `bdt_run_step`
    ADD COLUMN `parent_step_oid` BINARY(16) NULL DEFAULT NULL AFTER `screenshot_path`;

ALTER TABLE `bdt_run_step`
    ADD CONSTRAINT `FK_run_step_parent` FOREIGN KEY (`parent_step_oid`) REFERENCES `bdt_run_step` (`oid`) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- DOWN
-- DO NOT remove columns!