CREATE OR ALTER VIEW bdt_run_scenario_stats AS
SELECT
    ss.run_scenario_oid,
    ss.run_feature_oid,
    COUNT(ss.run_step_oid) AS steps_total,
    SUM(CASE WHEN ss.status = 100 THEN 1 ELSE 0 END) AS steps_passed,
    SUM(CASE WHEN ss.status IN (101, 102) THEN 1 ELSE 0 END) AS steps_failed,
    SUM(CASE WHEN ss.status = 99 THEN 1 ELSE 0 END) AS steps_skipped,
    CASE
        WHEN MAX(s.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 5 THEN 102
        WHEN MAX(s.finished_on) IS NULL THEN 10
        ELSE MAX(ss.status)
        END AS status
FROM
    bdt_run_step_stats ss
        INNER JOIN bdt_run_scenario s ON s.oid = ss.run_scenario_oid
GROUP BY
    ss.run_scenario_oid,
    ss.run_feature_oid
;