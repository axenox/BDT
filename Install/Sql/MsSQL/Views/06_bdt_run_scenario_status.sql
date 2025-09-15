CREATE OR ALTER VIEW bdt_run_scenario_status AS
WITH OrderedScenarios AS (
    SELECT
        s.oid AS run_scenario_oid,
        s.run_feature_oid,
        f.app_alias,
        f.filename,
        rs.steps_total,
        rs.steps_passed,
        rs.steps_failed,
        rs.steps_skipped,
        s.name,
        s.tags,
        rs.status,
        s.finished_on,
        s.absolute,
        ROW_NUMBER() OVER (
            PARTITION BY f.filename, s.name
            ORDER BY s.finished_on DESC
        ) AS rn
    FROM
        bdt_run_scenario s
        INNER JOIN bdt_run_feature f ON f.oid = s.run_feature_oid
        INNER JOIN bdt_run_scenario_stats rs ON rs.run_scenario_oid = s.oid
)
SELECT
    run_scenario_oid,
    run_feature_oid,
    app_alias,
    steps_total,
    steps_passed,
    steps_failed,
    steps_skipped,
    CASE
        WHEN tags IS NULL THEN 'paused'
        WHEN tags NOT LIKE '%Status::Ready%' THEN 'paused'
        WHEN status IN (101, 102) THEN 'failed'
        WHEN status = 99 THEN 'skipped'
        WHEN status = 100 THEN 'passed'
        WHEN status = 10 THEN 'started'
        WHEN status = 0 THEN 'pending'
        ELSE 'unknown'
        END AS scenario_status,
    status AS scenario_status_code,
    finished_on
FROM
    OrderedScenarios
WHERE
    ([absolute] IS NULL OR [absolute] != 1) AND
    rn = 1
;