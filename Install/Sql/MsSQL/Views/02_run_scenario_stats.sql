CREATE OR ALTER VIEW bdt_run_scenario_stats AS
SELECT
    ss.run_scenario_oid,
    ss.run_feature_oid,
    COALESCE(COUNT(ss.run_step_oid), 0) AS steps_total,
    COALESCE(SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END), 0) AS steps_passed,
    COALESCE(SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END), 0) AS steps_failed,
    COALESCE(SUM(CASE WHEN ss.[status] = 98 THEN 1 ELSE 0 END), 0) AS steps_skipped,
    (CASE
         -- Still running but last activity exceeded timeout threshold
         WHEN MAX(s.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 10 THEN 102
         -- Still running within timeout window
         WHEN MAX(s.finished_on) IS NULL THEN 10
         -- Any failed or timed-out step means the scenario failed
         WHEN SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) > 0 THEN 101
         -- No passed steps at all (only skipped) means the scenario is skipped
         WHEN SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END) = 0 THEN 98
         -- At least one passed step present (skipped steps are acceptable)
         ELSE 100
        END) AS [status],
    s.started_on,
    (CASE
        -- Scenario completed: use the real finish timestamp
        WHEN s.finished_on IS NOT NULL THEN s.finished_on
        -- A timed-out step exists: treat its start time as the effective end
        WHEN SUM(CASE WHEN ss.[status] = 102 THEN 1 ELSE 0 END) > 0
            THEN MAX(CASE WHEN ss.[status] = 102 THEN ss.started_on ELSE NULL END)
        -- Still running: use the most recently started step as a proxy
        ELSE MAX(ss.started_on)
        END) AS finished_on
FROM
    bdt_run_step_stats ss
        JOIN bdt_run_scenario s ON s.oid = ss.run_scenario_oid
GROUP BY
    ss.run_scenario_oid,
    ss.run_feature_oid,
    s.started_on,
    s.finished_on
