-- UP

ALTER TABLE bdt_run
    ADD COLUMN chrome_info LONGTEXT NULL;

-- DOWN

ALTER TABLE bdt_run
    DROP COLUMN chrome_info;