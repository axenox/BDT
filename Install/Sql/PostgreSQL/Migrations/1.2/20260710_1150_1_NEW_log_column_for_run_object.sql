-- UP
ALTER TABLE bdt_run ADD COLUMN "log" text NULL;
-- DOWN
ALTER TABLE bdt_run DROP COLUMN "log";