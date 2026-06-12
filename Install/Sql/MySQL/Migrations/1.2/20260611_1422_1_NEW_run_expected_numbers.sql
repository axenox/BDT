-- UP

ALTER TABLE bdt_run
    ADD COLUMN expected_feature_count INT NULL,
    ADD COLUMN expected_scenario_count INT NULL;

-- DOWN

ALTER TABLE bdt_run
DROP COLUMN expected_feature_count,
    DROP COLUMN expected_scenario_count;