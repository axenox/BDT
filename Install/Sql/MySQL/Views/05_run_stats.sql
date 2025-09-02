CREATE OR REPLACE VIEW bdt_run_stats AS
SELECT
    r.oid AS run_oid,

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

    -- feature statistics
    COALESCE(feat.features_total, 0) AS features_total,
    COALESCE(feat.features_passed, 0) AS features_passed,
    COALESCE(feat.features_failed, 0) AS features_failed,
    COALESCE(feat.features_skipped, 0) AS features_skipped,
    (CASE
         WHEN MAX(r.finished_on) IS NULL AND TIMESTAMPDIFF(MINUTE, MAX(ss.started_on), NOW()) > 5 THEN 102
         WHEN MAX(r.finished_on) IS NULL THEN 10
         ELSE MAX(ss.`status`)
        END) AS status

FROM
    bdt_run r
        LEFT JOIN bdt_run_feature f ON f.run_oid = r.oid
        LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
        LEFT JOIN (
        SELECT
            f.run_oid,
            COUNT(*) AS scenarios_total,
            SUM(status = 100) AS scenarios_passed,
            SUM(status IN (101, 102)) AS scenarios_failed,
            SUM(status = 99) AS scenarios_skipped
        FROM bdt_run_feature f
                 JOIN bdt_run_scenario_stats scen ON scen.run_feature_oid = f.oid
        GROUP BY f.run_oid
    ) scen ON scen.run_oid = r.oid
        LEFT JOIN (
        SELECT
            run_oid,
            COUNT(*) AS features_total,
            SUM(status = 100) AS features_passed,
            SUM(status IN (101, 102)) AS features_failed,
            SUM(status = 99) AS features_skipped
        FROM (
                 SELECT
                     f.run_oid,
                     f.oid AS run_feature_oid,
                     CASE
                         WHEN SUM(ss.status IN (101, 102)) > 0 THEN 101
                         WHEN SUM(ss.status = 99) > 0 THEN 99
                         WHEN SUM(ss.status = 100) = COUNT(ss.run_step_oid) THEN 100
                         ELSE 0
                         END AS status
                 FROM bdt_run_feature f
                          LEFT JOIN bdt_run_step_stats ss ON ss.run_feature_oid = f.oid
                 GROUP BY f.run_oid, f.oid
             ) feat_stats
        GROUP BY run_oid
    ) feat ON feat.run_oid = r.oid

GROUP BY r.oid
;