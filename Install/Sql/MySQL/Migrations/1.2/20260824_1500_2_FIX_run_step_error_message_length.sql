-- UP
ALTER TABLE `bdt_run_step`
    MODIFY COLUMN `error_message` TEXT NULL;

-- DOWN
-- Deliberately empty: shrinking the column back to varchar(200) would
-- silently truncate already stored error messages.