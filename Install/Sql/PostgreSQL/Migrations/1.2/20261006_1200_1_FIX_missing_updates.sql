-- UP

-- Substeps (MsSQL 1.1/20260224_0915_1_NEW_substeps.sql)
ALTER TABLE bdt_run_step
    ADD COLUMN IF NOT EXISTS parent_step_oid uuid NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_run_step_parent'
    ) THEN
        ALTER TABLE bdt_run_step
            ADD CONSTRAINT fk_run_step_parent FOREIGN KEY (parent_step_oid) REFERENCES bdt_run_step (oid);
    END IF;
END
$$;

-- Feature info (MsSQL 1.2/20260608_1505_1_NEW_feature_info_column.sql)
ALTER TABLE bdt_run_feature
    ADD COLUMN IF NOT EXISTS chrome_info text NULL;

-- Expected numbers (MsSQL 1.2/20260611_1422_1_NEW_run_expected_numbers.sql)
ALTER TABLE bdt_run
    ADD COLUMN IF NOT EXISTS expected_feature_count integer NULL,
    ADD COLUMN IF NOT EXISTS expected_scenario_count integer NULL;

-- Rename absolute -> obsolete (MsSQL 1.0/20251204_FIX_scenario_absolute_to_obsolete.sql)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'bdt_run_scenario' AND column_name = 'absolute'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'bdt_run_scenario' AND column_name = 'obsolete'
    ) THEN
        ALTER TABLE bdt_run_scenario RENAME COLUMN absolute TO obsolete;
    END IF;
END
$$;

-- DOWN
-- Do not remove columns!
