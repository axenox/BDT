-- UP
ALTER TABLE [bdt_run_step]
ADD [screenshot_path] VARCHAR(200) NULL;
GO

-- DOWN
ALTER TABLE [bdt_run_step]
DROP COLUMN [screenshot_path];
GO