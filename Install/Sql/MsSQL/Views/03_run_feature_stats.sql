CREATE OR ALTER VIEW bdt_run_feature_stats AS
SELECT
    f.oid AS run_feature_oid,

    -- step statistics
    COALESCE(COUNT(ss.run_step_oid), 0) AS steps_total,
    COALESCE(SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END), 0) AS steps_passed,
    COALESCE(SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END), 0) AS steps_failed,
    COALESCE(SUM(CASE WHEN ss.[status] = 98 THEN 1 ELSE 0 END), 0) AS steps_skipped,

    -- scenario statistics
    COALESCE(scen.scenarios_total, 0) AS scenarios_total,
    COALESCE(scen.scenarios_passed, 0) AS scenarios_passed,
    COALESCE(scen.scenarios_failed, 0) AS scenarios_failed,
    COALESCE(scen.scenarios_skipped, 0) AS scenarios_skipped,

    (CASE
         -- Still running but last activity exceeded timeout threshold
         WHEN MAX(f.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 10 THEN 102
         -- Still running within timeout window
         WHEN MAX(f.finished_on) IS NULL THEN 10
         -- Any failed or timed-out step means the feature failed
         WHEN SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) > 0 THEN 101
         -- No passed steps at all (only skipped) means the feature is skipped
         WHEN SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END) = 0 THEN 98
         -- At least one passed step present (skipped steps are acceptable)
         ELSE 100
        END) AS [status],
    f.started_on,
    (CASE
         -- Feature completed: use the real finish timestamp
         WHEN f.finished_on IS NOT NULL THEN f.finished_on
         -- A timed-out step exists: treat its start time as the effective end
         WHEN SUM(CASE WHEN ss.[status] = 102 THEN 1 ELSE 0 END) > 0
             THEN MAX(CASE WHEN ss.[status] = 102 THEN ss.started_on ELSE NULL END)
         -- Still running: use the most recently started step as a proxy
         ELSE MAX(ss.started_on)
        END) AS finished_on
FROM
    bdt_run_feature f
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            run_feature_oid,
            COUNT(*) AS scenarios_total,
            SUM(CASE WHEN [status] IN (90, 100) THEN 1 ELSE 0 END) AS scenarios_passed,
            SUM(CASE WHEN [status] IN (91, 101, 102) THEN 1 ELSE 0 END) AS scenarios_failed,
            SUM(CASE WHEN [status] = 98 THEN 1 ELSE 0 END) AS scenarios_skipped
        FROM bdt_run_scenario_stats
        GROUP BY run_feature_oid
    ) scen ON scen.run_feature_oid = f.oid

GROUP BY
    f.oid,
    f.started_on,
    f.finished_on,
    scen.scenarios_total,
    scen.scenarios_passed,
    scen.scenarios_failed,
    scen.scenarios_skipped
