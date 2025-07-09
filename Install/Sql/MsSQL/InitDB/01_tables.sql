-- A test run represents launching behat once from CLI
CREATE TABLE dbo.bdt_run (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16) NOT NULL,
    modified_by_user_oid binary(16) NOT NULL,
    started_on datetime NOT NULL,
    finished_on datetime,
    duration_ms float,
    PRIMARY KEY (oid)
);

-- Every feature involved in a test run will produce one row in this table
CREATE TABLE dbo.bdt_run_feature (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16) NOT NULL,
    modified_by_user_oid binary(16) NOT NULL,
    run_oid binary(16) NOT NULL,
    run_sequence_idx int NOT NULL,
    app_alias varchar(100),
    name varchar(500) NOT NULL,
    description nvarchar(max),
    filename varchar(200),
    started_on datetime NOT NULL,
    finished_on datetime,
    duration_ms float,
    content nvarchar(max),
    PRIMARY KEY (oid)
);
CREATE INDEX IX_run_feature_run ON dbo.bdt_run_feature (run_oid);

-- Every feature involved in a test run will produce one row in this table
CREATE TABLE dbo.bdt_run_scenario (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16),
    modified_by_user_oid binary(16),
    run_feature_oid binary(16) NOT NULL,
    name varchar(1000) NOT NULL,
    line int NOT NULL DEFAULT '0',
    started_on datetime NOT NULL,
    finished_on datetime,
    duration_ms float,
    PRIMARY KEY (oid)
);
CREATE INDEX IX_run_scenario_feature ON dbo.bdt_run_scenario (run_feature_oid);

-- Every step actually performed in a test run will produce one row in this table
CREATE TABLE dbo.bdt_run_step (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16),
    modified_by_user_oid binary(16),
    run_scenario_oid binary(16) NOT NULL,
    run_sequence_idx int NOT NULL,
    name varchar(1000) NOT NULL,
    line int NOT NULL DEFAULT '0',
    started_on datetime NOT NULL,
    finished_on datetime,
    duration_ms float,
    status int NOT NULL,
    error_message varchar(200),
    error_log_id varchar(10),
    PRIMARY KEY (oid)
);
CREATE INDEX IX_run_step_scenario ON dbo.bdt_run_step (run_scenario_oid);

CREATE TABLE dbo.bdt_run_scenario_action (
    oid binary(16) NOT NULL,
    created_on datetime NOT NULL,
    modified_on datetime NOT NULL,
    created_by_user_oid binary(16) NOT NULL,
    modified_by_user_oid binary(16) NOT NULL,
    run_scenario_oid binary(16) NOT NULL,
    page_oid binary(16) NOT NULL,
    page_alias varchar(200) NOT NULL,
    widget_id varchar(2000),
    action_alias varchar(200),
    PRIMARY KEY (oid)
);
CREATE INDEX IX_run_scenario_action_scenario ON dbo.bdt_run_scenario_action (run_scenario_oid);