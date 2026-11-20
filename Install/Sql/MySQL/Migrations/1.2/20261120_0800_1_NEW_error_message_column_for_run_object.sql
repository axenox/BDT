-- Description: Adds bdt_run.error_message (run-level failure written by RunTest/RunParallel; read by bdt_run_stats).
-- Author:      Gizem Bicer

-- UP
ALTER TABLE bdt_run ADD COLUMN error_message LONGTEXT NULL;

-- DOWN
ALTER TABLE bdt_run DROP COLUMN error_message;