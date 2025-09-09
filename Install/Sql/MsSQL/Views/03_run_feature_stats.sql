CREATE OR ALTER VIEW bdt_run_feature_stats AS
SELECT
    f.oid AS run_feature_oid,

    -- step statistics
    COUNT(ss.run_step_oid) AS steps_total,
    SUM(CASE WHEN ss.status = 100 THEN 1 ELSE 0 END) AS steps_passed,
    SUM(CASE WHEN ss.status IN (101, 102) THEN 1 ELSE 0 END) AS steps_failed,
    SUM(CASE WHEN ss.status = 99 THEN 1 ELSE 0 END) AS steps_skipped,

    -- scenario statistics
    COALESCE(scen.scenarios_total, 0) AS scenarios_total,
    COALESCE(scen.scenarios_passed, 0) AS scenarios_passed,
    COALESCE(scen.scenarios_failed, 0) AS scenarios_failed,
    COALESCE(scen.scenarios_skipped, 0) AS scenarios_skipped,

    CASE
        WHEN MAX(f.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 5 THEN 102
        WHEN MAX(f.finished_on) IS NULL THEN 10
        ELSE MAX(ss.status)
        END AS status

FROM
    bdt_run_feature f
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            run_feature_oid,
            COUNT(*) AS scenarios_total,
            SUM(CASE WHEN status = 100 THEN 1 ELSE 0 END) AS scenarios_passed,
            SUM(CASE WHEN status IN (101, 102) THEN 1 ELSE 0 END) AS scenarios_failed,
            SUM(CASE WHEN status = 99 THEN 1 ELSE 0 END) AS scenarios_skipped
        FROM bdt_run_scenario_stats
        GROUP BY run_feature_oid
    ) scen ON scen.run_feature_oid = f.oid

GROUP BY
    f.oid,
    scen.scenarios_total,
    scen.scenarios_passed,
    scen.scenarios_failed,
    scen.scenarios_skipped
;