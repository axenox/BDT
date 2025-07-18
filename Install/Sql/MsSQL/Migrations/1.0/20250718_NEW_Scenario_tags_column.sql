-- UP
ALTER TABLE [bdt_run_scenario]
    ADD [tags] NVARCHAR(200) COLLATE Latin1_General_CI_AS NULL;


-- DOWN
ALTER TABLE [bdt_run_scenario]
DROP COLUMN [tags];