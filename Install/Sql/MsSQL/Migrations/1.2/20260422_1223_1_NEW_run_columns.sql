-- UP

ALTER TABLE bdt_run
    ADD chrome_info NVARCHAR(MAX) NULL;
-- DOWN

ALTER TABLE bdt_run
DROP COLUMN chrome_info;