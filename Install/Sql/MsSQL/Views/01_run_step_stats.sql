CREATE OR ALTER VIEW dbo.bdt_run_step_stats ASSELECT
    s.oid AS run_step_oid,
    sc.oid AS run_scenario_oid,
    f.oid AS run_feature_oid,
    r.oid AS run_oid,
    (CASE 
        WHEN s.finished_on IS NULL AND DATEDIFF(MINUTE, s.started_on, GETDATE()) > 5 THEN 102
        ELSE s.`status`
    END) AS `status`,
    s.started_on,
    s.finished_on
FROM
    bdt_run_step s
        INNER JOIN bdt_run_scenario sc ON sc.oid = s.run_scenario_oid
        INNER JOIN bdt_run_feature f ON f.oid = sc.run_feature_oid
        INNER JOIN bdt_run r ON r.oid = f.run_oid