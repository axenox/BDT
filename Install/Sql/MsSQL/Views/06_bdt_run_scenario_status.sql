CREATE OR ALTER VIEW bdt_run_scenario_status AS
WITH LatestFinishedOn AS (
    SELECT
        rsn.oid AS run_scenario_oid,
        MAX(rsn.finished_on) AS max_finished_on
    FROM
        bdt_run_scenario rsn
    GROUP BY
        rsn.oid
)
SELECT
    rsn.oid AS run_scenario_oid,
    rsn.run_feature_oid AS run_feature_oid,
    f.app_alias,
    rs.steps_total,
    rs.steps_passed,
    rs.steps_failed,
    rs.steps_skipped,
    rsn.tags,
    CASE
        WHEN rsn.tags NOT LIKE '%Status::Ready%' THEN 'paused'
        WHEN rs.status IN (101, 102) THEN 'failed'
        WHEN rs.status = 99 THEN 'skipped'
        WHEN rs.status = 100 THEN 'passed'
        WHEN rs.status = 10 THEN 'started'
        WHEN rs.status = 0 THEN 'pending'
        ELSE 'unknown'
        END AS scenario_status,
    rs.status AS scenario_status_code,
    rsn.finished_on AS finished_on
FROM
    bdt_run_scenario_stats rs
        INNER JOIN bdt_run_scenario rsn ON rsn.oid = rs.run_scenario_oid
        INNER JOIN LatestFinishedOn lfo ON lfo.run_scenario_oid = rsn.oid AND lfo.max_finished_on = rsn.finished_on
        INNER JOIN bdt_run_feature f ON f.oid = rsn.run_feature_oid
;