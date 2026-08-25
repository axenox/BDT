-- UP
ALTER TABLE dbo.bdt_run_step
    ALTER COLUMN error_message nvarchar(4000) NULL;

-- DOWN
-- Deliberately empty: shrinking the column back to nvarchar(200) would
-- silently truncate already stored error messages.