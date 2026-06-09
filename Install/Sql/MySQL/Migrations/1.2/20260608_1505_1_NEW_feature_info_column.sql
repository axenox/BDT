-- UP

ALTER TABLE bdt_run_feature
    ADD COLUMN chrome_info LONGTEXT NULL;

-- DOWN

ALTER TABLE bdt_run_feature
    DROP COLUMN chrome_info;