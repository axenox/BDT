CREATE OR REPLACE VIEW bdt_run_step_stats AS
SELECT
    s.oid AS run_step_oid,
    sc.oid AS run_scenario_oid,
    f.oid AS run_feature_oid,
    r.oid AS run_oid
FROM
    bdt_run_step s
        INNER JOIN bdt_run_scenario sc ON sc.oid = s.run_scenario_oid
        INNER JOIN bdt_run_feature f ON f.oid = sc.run_feature_oid
        INNER JOIN bdt_run r ON r.oid = f.run_oid