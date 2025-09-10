CREATE OR ALTER VIEW dbo.bdt_run_suite_stats AS
SELECT
    f.run_oid,
    a.page_oid,
    a.page_alias,
    COUNT(DISTINCT a.oid) AS action_count,
    COUNT(DISTINCT sc.oid) AS scenario_count,
    (CASE
         WHEN MAX(f.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 5 THEN 102
         WHEN MAX(f.finished_on) IS NULL THEN 10
         ELSE MAX(ss.status)
        END) AS status
FROM bdt_run_scenario_action AS a
         JOIN bdt_run_scenario      AS sc ON a.run_scenario_oid   = sc.oid
         JOIN bdt_run_step_stats    AS ss ON ss.run_scenario_oid = sc.oid
         JOIN bdt_run_feature       AS f  ON sc.run_feature_oid    = f.oid
GROUP BY
    f.run_oid,
    a.page_oid,
    a.page_alias;