-- UP
ALTER TABLE [bdt_run_scenario]
    ADD [comment] NVARCHAR(300) COLLATE Latin1_General_CI_AS NULL,
    ADD [commented_by_user_oid] UNIQUEIDENTIFIER NULL;

-- DOWN
ALTER TABLE [bdt_run_scenario]
    DROP COLUMN [comment],
    DROP COLUMN [commented_by_user_oid];