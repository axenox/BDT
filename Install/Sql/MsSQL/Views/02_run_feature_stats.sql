CREATE OR ALTER VIEW dbo.bdt_run_feature_stats AS
SELECT
    ss.run_feature_oid,
    COUNT(ss.run_step_oid) AS steps,
    COUNT(DISTINCT ss.run_scenario_oid) AS scenarios,
    (CASE
         WHEN MAX(f.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 5 THEN 102
         WHEN MAX(f.finished_on) IS NULL THEN 10
         ELSE MAX(ss.status)
    END) AS status
FROM
    bdt_run_step_stats ss
        INNER JOIN bdt_run_feature f ON f.oid = ss.run_feature_oid
GROUP BY ss.run_feature_oid, ss.run_oid
