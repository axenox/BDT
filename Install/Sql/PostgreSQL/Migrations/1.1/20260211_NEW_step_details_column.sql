-- UP
ALTER TABLE bdt_run_step
    ADD COLUMN details TEXT;

-- DOWN
ALTER TABLE bdt_run_step
DROP COLUMN details;