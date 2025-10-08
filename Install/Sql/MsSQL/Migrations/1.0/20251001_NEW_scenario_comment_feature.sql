-- UP
ALTER TABLE [dbo].[bdt_run_scenario] ADD
    [comment] nvarchar(300) COLLATE SQL_Latin1_General_CP1_CI_AS NULL,
    [commented_by_user_oid] binary(16) NULL;

-- DOWN
ALTER TABLE [bdt_run_scenario]
    DROP COLUMN [comment],
    DROP COLUMN [commented_by_user_oid];
