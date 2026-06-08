-- UP

ALTER TABLE bdt_run_feature
    ADD chrome_info NVARCHAR(MAX) NULL;
-- DOWN

ALTER TABLE bdt_run_feature
DROP COLUMN chrome_info;