CREATE OR REPLACE VIEW bdt_run_scenario_stats AS
SELECT
    ss.run_scenario_oid,
    ss.run_feature_oid,
    COALESCE(COUNT(ss.run_step_oid), 0) AS steps_total,
    COALESCE(SUM(ss.status = 100), 0) AS steps_passed,
    COALESCE(SUM(ss.status IN (101, 102)), 0) AS steps_failed,
    COALESCE(SUM(ss.status = 99), 0) AS steps_skipped,
    (CASE
         WHEN MAX(s.finished_on) IS NULL AND TIMESTAMPDIFF(MINUTE, MAX(ss.started_on), NOW()) > 5 THEN 102
         WHEN MAX(s.finished_on) IS NULL THEN 10
         ELSE MAX(ss.`status`)
        END) AS status
FROM
    bdt_run_step_stats ss
        JOIN bdt_run_scenario s on s.oid = ss.run_scenario_oid
GROUP BY
    ss.run_scenario_oid,
    ss.run_feature_oid
;