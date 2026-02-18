-- UP
ALTER TABLE [bdt_run_step]
    ADD [details] NVARCHAR(MAX) NULL;


-- DOWN
ALTER TABLE [bdt_run_step]
DROP COLUMN [details];