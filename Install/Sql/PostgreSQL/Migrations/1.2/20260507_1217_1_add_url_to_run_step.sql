-- UP
ALTER TABLE bdt_run_step ADD COLUMN url varchar(500);

-- DOWN
ALTER TABLE bdt_run_step DROP COLUMN url;