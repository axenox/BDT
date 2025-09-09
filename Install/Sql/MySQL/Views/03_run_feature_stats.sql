CREATE OR REPLACE VIEW bdt_run_feature_stats AS
SELECT
    f.oid AS run_feature_oid,

    -- step statistics
    COALESCE(COUNT(ss.run_step_oid), 0) AS steps_total,
    COALESCE(SUM(ss.status = 100), 0) AS steps_passed,
    COALESCE(SUM(ss.status IN (101, 102)), 0) AS steps_failed,
    COALESCE(SUM(ss.status = 99), 0) AS steps_skipped,

    -- scenario statistics
    COALESCE(scen.scenarios_total, 0) AS scenarios_total,
    COALESCE(scen.scenarios_passed, 0) AS scenarios_passed,
    COALESCE(scen.scenarios_failed, 0) AS scenarios_failed,
    COALESCE(scen.scenarios_skipped, 0) AS scenarios_skipped,

    (CASE
         WHEN MAX(f.finished_on) IS NULL AND TIMESTAMPDIFF(MINUTE, MAX(ss.started_on), NOW()) > 5 THEN 102
         WHEN MAX(f.finished_on) IS NULL THEN 10
         ELSE MAX(ss.`status`)
        END) AS status

FROM
    bdt_run_feature f
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            run_feature_oid,
            COUNT(*) AS scenarios_total,
            SUM(status = 100) AS scenarios_passed,
            SUM(status IN (101, 102)) AS scenarios_failed,
            SUM(status = 99) AS scenarios_skipped
        FROM bdt_run_scenario_stats
        GROUP BY run_feature_oid
    ) scen ON scen.run_feature_oid = f.oid

GROUP BY f.oid