CREATE OR ALTER VIEW bdt_run_stats AS
SELECT
    r.oid AS run_oid,

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

    -- feature statistics
    COALESCE(feat.features_total, 0) AS features_total,
    COALESCE(feat.features_passed, 0) AS features_passed,
    COALESCE(feat.features_failed, 0) AS features_failed,
    COALESCE(feat.features_skipped, 0) AS features_skipped,

    (CASE
         -- Still running but last activity exceeded timeout threshold
         WHEN MAX(r.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 10 THEN 102
         -- Still running within timeout window
         WHEN MAX(r.finished_on) IS NULL THEN 10
        -- Any failed or timed-out step means the run failed. Undefined steps (30) are intentionally
        -- absent from every branch here: an undefined step is reported at scenario level only and
        -- must not turn a whole run red - it only shows up in the scenario totals.
         WHEN SUM(CASE WHEN ss.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) > 0 THEN 101
         -- No passed steps at all (only skipped) means the run is skipped
         WHEN SUM(CASE WHEN ss.[status] IN (90, 100) THEN 1 ELSE 0 END) = 0 THEN 98
         -- At least one passed step present (skipped steps are acceptable)
         ELSE 100
        END) AS [status],
    r.started_on,
    (CASE
         -- Run completed: use the real finish timestamp
         WHEN r.finished_on IS NOT NULL THEN r.finished_on
         -- A timed-out step exists: treat its start time as the effective end
         WHEN SUM(CASE WHEN ss.[status] = 102 THEN 1 ELSE 0 END) > 0
             THEN MAX(CASE WHEN ss.[status] = 102 THEN ss.started_on ELSE NULL END)
         -- Still running: use the most recently started step as a proxy
         ELSE MAX(ss.started_on)
        END) AS finished_on
FROM
    bdt_run r
        LEFT JOIN bdt_run_feature f ON f.run_oid = r.oid
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            f.run_oid,
            COUNT(*) AS scenarios_total,
            SUM(CASE WHEN scen.[status] IN (90, 100) THEN 1 ELSE 0 END) AS scenarios_passed,
            SUM(CASE WHEN scen.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) AS scenarios_failed,
            SUM(CASE WHEN scen.[status] = 98 THEN 1 ELSE 0 END) AS scenarios_skipped
        FROM bdt_run_feature f
                 JOIN bdt_run_scenario_stats scen ON scen.run_feature_oid = f.oid
        GROUP BY f.run_oid
    ) scen ON scen.run_oid = r.oid
    LEFT JOIN (
    -- The feature status used to be re-derived inline here from the step statuses, which
    -- was a second copy of the CASE that bdt_run_feature_stats already owns - and a copy with
    -- different rules, since it knew nothing about running or timed-out features and returned 0 for
    -- a feature without steps. Two copies of the same verdict logic drift apart on the first change.
    -- Reading the existing view keeps one definition of "what a feature status is" and mirrors how
    -- the scenario counts above are already assembled.
    SELECT
    f.run_oid,
    COUNT(*) AS features_total,
    SUM(CASE WHEN fs.[status] IN (90, 100) THEN 1 ELSE 0 END) AS features_passed,
    SUM(CASE WHEN fs.[status] IN (91, 101, 102) THEN 1 ELSE 0 END) AS features_failed,
    SUM(CASE WHEN fs.[status] = 98 THEN 1 ELSE 0 END) AS features_skipped
    FROM bdt_run_feature f
    JOIN bdt_run_feature_stats fs ON fs.run_feature_oid = f.oid
    GROUP BY f.run_oid
    ) feat ON feat.run_oid = r.oid

GROUP BY
    r.oid,
    r.started_on,
    r.finished_on,
    scen.scenarios_total,
    scen.scenarios_passed,
    scen.scenarios_failed,
    scen.scenarios_skipped,
    feat.features_total,
    feat.features_passed,
    feat.features_failed,
    feat.features_skipped