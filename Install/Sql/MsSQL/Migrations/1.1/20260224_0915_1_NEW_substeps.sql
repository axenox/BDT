-- UP

ALTER TABLE dbo.bdt_run_step
    ADD parent_step_oid BINARY(16);


ALTER TABLE dbo.bdt_run_step
    ADD CONSTRAINT FK_run_step_parent FOREIGN KEY (parent_step_oid) REFERENCES dbo.bdt_run_step (oid);

-- DOWN
-- DO NOT remove columns!