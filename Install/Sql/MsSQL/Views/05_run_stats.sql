CREATE OR ALTER VIEW bdt_run_stats AS
SELECT
    r.oid AS run_oid,

    -- Step statistics
    COUNT(ss.run_step_oid) AS steps_total,
    SUM(CASE WHEN ss.status = 100 THEN 1 ELSE 0 END) AS steps_passed,
    SUM(CASE WHEN ss.status IN (101, 102) THEN 1 ELSE 0 END) AS steps_failed,
    SUM(CASE WHEN ss.status = 99 THEN 1 ELSE 0 END) AS steps_skipped,

    -- Scenario statistics
    COALESCE(scen.scenarios_total, 0) AS scenarios_total,
    COALESCE(scen.scenarios_passed, 0) AS scenarios_passed,
    COALESCE(scen.scenarios_failed, 0) AS scenarios_failed,
    COALESCE(scen.scenarios_skipped, 0) AS scenarios_skipped,

    -- Feature statistics
    COALESCE(feat.features_total, 0) AS features_total,
    COALESCE(feat.features_passed, 0) AS features_passed,
    COALESCE(feat.features_failed, 0) AS features_failed,
    COALESCE(feat.features_skipped, 0) AS features_skipped,

    CASE
        WHEN MAX(r.finished_on) IS NULL AND DATEDIFF(MINUTE, MAX(ss.started_on), GETDATE()) > 5 THEN 102
        WHEN MAX(r.finished_on) IS NULL THEN 10
        ELSE MAX(ss.status)
        END AS status

FROM
    bdt_run r
        LEFT JOIN bdt_run_feature f ON f.run_oid = r.oid
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            f.run_oid,
            COUNT(*) AS scenarios_total,
            SUM(CASE WHEN status = 100 THEN 1 ELSE 0 END) AS scenarios_passed,
            SUM(CASE WHEN status IN (101, 102) THEN 1 ELSE 0 END) AS scenarios_failed,
            SUM(CASE WHEN status = 99 THEN 1 ELSE 0 END) AS scenarios_skipped
        FROM bdt_run_feature f
                 JOIN bdt_run_scenario_stats scen ON scen.run_feature_oid = f.oid
        GROUP BY f.run_oid
    ) scen ON scen.run_oid = r.oid
        LEFT JOIN (
        SELECT
            run_oid,
            COUNT(*) AS features_total,
            SUM(CASE WHEN status = 100 THEN 1 ELSE 0 END) AS features_passed,
            SUM(CASE WHEN status IN (101, 102) THEN 1 ELSE 0 END) AS features_failed,
            SUM(CASE WHEN status = 99 THEN 1 ELSE 0 END) AS features_skipped
        FROM (
                 SELECT
                     f.run_oid,
                     f.oid AS run_feature_oid,
                     CASE
                         WHEN SUM(CASE WHEN ss.status IN (101, 102) THEN 1 ELSE 0 END) > 0 THEN 101
                         WHEN SUM(CASE WHEN ss.status = 99 THEN 1 ELSE 0 END) > 0 THEN 99
                         WHEN SUM(CASE WHEN ss.status = 100 THEN 1 ELSE 0 END) = COUNT(ss.run_step_oid) THEN 100
                         ELSE 0
                         END AS status
                 FROM bdt_run_feature f
                          LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
                 GROUP BY f.run_oid, f.oid
             ) feat_stats
        GROUP BY run_oid
    ) feat ON feat.run_oid = r.oid

GROUP BY r.oid,
         scen.scenarios_total, scen.scenarios_passed, scen.scenarios_failed, scen.scenarios_skipped,
         feat.features_total, feat.features_passed, feat.features_failed, feat.features_skipped
;