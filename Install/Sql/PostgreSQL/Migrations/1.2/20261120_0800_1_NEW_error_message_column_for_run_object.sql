-- Description: Adds bdt_run.error_message (run-level failure written by RunTest/RunParallel; read by bdt_run_stats).
-- Author:      Gizem Bicer
-- Note:        PostgreSQL has no bdt_run_stats view in this app, so the column is stored but the "failed if error_message" status rule does not apply there.

-- UP
ALTER TABLE bdt_run ADD COLUMN IF NOT EXISTS "error_message" text NULL;

-- DOWN
ALTER TABLE bdt_run DROP COLUMN IF EXISTS "error_message";
