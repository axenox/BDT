-- UP
ALTER TABLE bdt_run_step
    ALTER COLUMN error_message TYPE varchar(4000);

-- DOWN
-- Deliberately empty: shrinking the column back to varchar(200) would
-- silently truncate already stored error messages.