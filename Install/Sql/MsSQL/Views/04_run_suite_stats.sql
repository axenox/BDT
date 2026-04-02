CREATE OR ALTER VIEW bdt_run_suite_stats AS
SELECT
    f.run_oid,
    a.page_oid,
    a.page_alias,
    COUNT(DISTINCT a.oid) AS action_count,
    COUNT(DISTINCT sc.oid) AS scenario_count,
    (CASE
         -- Still running but last activity exceeded timeout threshold
         WHEN MAX(f.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 10 THEN 102
         -- Still running within timeout window
         WHEN MAX(f.finished_on) IS NULL THEN 10
         -- Any failed or timed-out step means the suite failed
         WHEN SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) > 0 THEN 101
         -- No passed steps at all (only skipped) means the suite is skipped
         WHEN SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END) = 0 THEN 98
         -- At least one passed step present (skipped steps are acceptable)
         ELSE 100
        END) AS [status]
FROM bdt_run_scenario_action AS a
         JOIN bdt_run_scenario      AS sc ON a.run_scenario_oid  = sc.oid
         JOIN bdt_run_step_stats    AS ss ON ss.run_scenario_oid = sc.oid
         JOIN bdt_run_feature       AS f  ON sc.run_feature_oid  = f.oid
GROUP BY
    f.run_oid,
    a.page_oid,
    a.page_alias
