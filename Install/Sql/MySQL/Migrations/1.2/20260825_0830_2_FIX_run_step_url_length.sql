-- UP
ALTER TABLE `bdt_run_step`
    MODIFY COLUMN `url` TEXT NULL;

-- DOWN
-- Deliberately empty: shrinking the column back to varchar(500) would
-- silently truncate already stored URLs.