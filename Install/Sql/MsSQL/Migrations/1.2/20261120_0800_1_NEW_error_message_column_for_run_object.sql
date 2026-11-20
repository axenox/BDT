-- Description: Adds bdt_run.error_message (run-level failure written by RunTest/RunParallel; read by bdt_run_stats).
-- Author:      Gizem Bicer

-- UP
IF COL_LENGTH('dbo.bdt_run', 'error_message') IS NULL
ALTER TABLE dbo.bdt_run ADD error_message NVARCHAR(MAX) NULL;

-- DOWN
IF COL_LENGTH('dbo.bdt_run', 'error_message') IS NOT NULL
ALTER TABLE dbo.bdt_run DROP COLUMN error_message;