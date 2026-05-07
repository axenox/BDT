-- UP
ALTER TABLE dbo.bdt_run_step ADD url nvarchar(500) NULL;

-- DOWN
ALTER TABLE dbo.bdt_run_step DROP COLUMN url;