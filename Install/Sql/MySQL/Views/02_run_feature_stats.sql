CREATE OR REPLACE VIEW bdt_run_feature_stats AS
SELECT
    ss.run_feature_oid,
    COUNT(s.oid) AS steps,
    COUNT(DISTINCT ss.run_scenario_oid) AS scenarios,
    MAX(s.`status`) AS status
FROM
    bdt_run_step s
        INNER JOIN bdt_run_step_stats ss ON s.oid = ss.run_step_oid
GROUP BY ss.run_feature_oid, ss.run_oid