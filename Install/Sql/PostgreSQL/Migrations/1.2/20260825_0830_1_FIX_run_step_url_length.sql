-- UP
ALTER TABLE bdt_run_step
    ALTER COLUMN url TYPE varchar(4000);

-- DOWN
-- Deliberately empty: shrinking the column back to varchar(500) would
-- silently truncate already stored URLs.