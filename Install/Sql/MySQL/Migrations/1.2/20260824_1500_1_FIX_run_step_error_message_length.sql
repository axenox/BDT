-- UP
ALTER TABLE `bdt_run_step`
    MODIFY COLUMN `error_message` varchar(4000) DEFAULT NULL;

-- DOWN
-- Deliberately empty: shrinking the column back to varchar(200) would
-- silently truncate already stored error messages.