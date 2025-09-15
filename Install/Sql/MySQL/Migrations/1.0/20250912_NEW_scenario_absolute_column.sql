-- UP
ALTER TABLE `bdt_run_scenario`
    ADD `absolute` bit NULL;

-- DOWN
ALTER TABLE `bdt_run_scenario`
    DROP `absolute`;