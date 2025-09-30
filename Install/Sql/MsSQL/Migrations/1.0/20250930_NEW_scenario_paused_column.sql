-- UP
ALTER TABLE [bdt_run_scenario]
    ADD [paused] bit NULL;

-- DOWN
ALTER TABLE [bdt_run_scenario]
    DROP COLUMN [paused];