-- UP

ALTER TABLE bdt_run
    ADD expected_feature_count INT NULL,
        expected_scenario_count INT NULL;

-- DOWN

ALTER TABLE bdt_run
DROP COLUMN expected_feature_count, expected_scenario_count;