-- UP
ALTER TABLE dbo.bdt_run_step
    ALTER COLUMN url nvarchar(4000) NULL;

-- DOWN
-- Deliberately empty: shrinking the column back to nvarchar(500) would
-- silently truncate already stored URLs.