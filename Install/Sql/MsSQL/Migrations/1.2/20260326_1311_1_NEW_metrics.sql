-- UP

IF OBJECT_ID(N'dbo.bdt_metric', N'U') IS NULL
BEGIN
CREATE TABLE dbo.bdt_metric
(
    oid                 binary(16)       NOT NULL,
    created_on          datetime         NOT NULL,
    modified_on         datetime         NOT NULL,
    created_by_user_oid binary(16)       NOT NULL,
    modified_by_user_oid binary(16)      NOT NULL,
    app_oid             binary(16)       NOT NULL,
    subject_object_oid  binary(16)       NULL,
    name                varchar(100)     NOT NULL,
    description         varchar(400)     NULL,
    prototype_path      varchar(400)     NOT NULL,
    config_uxon         nvarchar(max)    NULL,
    enabled_flag        tinyint          NOT NULL CONSTRAINT DF_bdt_metric_enabled_flag DEFAULT (1),

    CONSTRAINT PK_bdt_metric PRIMARY KEY CLUSTERED (oid)
);

-- MySQL KEY `FK_metric_app` (`app_oid`)
CREATE NONCLUSTERED INDEX IX_bdt_metric_app_oid
    ON dbo.bdt_metric(app_oid);

-- FK to exf_app(oid)
ALTER TABLE dbo.bdt_metric
    ADD CONSTRAINT FK_metric_app
        FOREIGN KEY (app_oid) REFERENCES dbo.exf_app(oid);
END
GO


IF OBJECT_ID(N'dbo.bdt_run_metric_score', N'U') IS NULL
BEGIN
CREATE TABLE dbo.bdt_run_metric_score
(
    oid                 binary(16)       NOT NULL,
    created_on          datetime         NOT NULL,
    modified_on         datetime         NOT NULL,
    created_by_user_oid binary(16)       NOT NULL,
    modified_by_user_oid binary(16)      NOT NULL,
    metric_oid          binary(16)       NOT NULL,
    run_oid             binary(16)       NOT NULL,
    app_oid             binary(16)       NULL,

    score_expected      float            NOT NULL,
    score_absolute      float            NOT NULL,
    score_percentual    float            NOT NULL,

    subject_name        varchar(200)     NULL,
    subject_object_oid  binary(16)       NULL,
    subject_uid         varchar(200)     NULL,

    steps_count         int              NOT NULL,
    steps_oids          nvarchar(max)    NULL,

    CONSTRAINT PK_bdt_run_metric_score PRIMARY KEY CLUSTERED (oid)
);

-- MySQL KEY `FK_metric_score_app` (`app_oid`)
CREATE NONCLUSTERED INDEX IX_bdt_run_metric_score_app_oid
    ON dbo.bdt_run_metric_score(app_oid);

-- MySQL KEY `FK_metric_score_run` (`run_oid`)
CREATE NONCLUSTERED INDEX IX_bdt_run_metric_score_run_oid
    ON dbo.bdt_run_metric_score(run_oid);

-- MySQL KEY `IDX_metric_run_app_name_percent`
CREATE NONCLUSTERED INDEX IX_bdt_run_metric_score_metric_run_app_name_percent
    ON dbo.bdt_run_metric_score(metric_oid, run_oid, app_oid, subject_name, score_percentual);

    -- FKs
ALTER TABLE dbo.bdt_run_metric_score
    ADD CONSTRAINT FK_metric_score_app
        FOREIGN KEY (app_oid) REFERENCES dbo.exf_app(oid);

ALTER TABLE dbo.bdt_run_metric_score
    ADD CONSTRAINT FK_metric_score_metric
        FOREIGN KEY (metric_oid) REFERENCES dbo.bdt_metric(oid);

ALTER TABLE dbo.bdt_run_metric_score
    ADD CONSTRAINT FK_metric_score_run
        FOREIGN KEY (run_oid) REFERENCES dbo.bdt_run(oid);
END
GO

-- DOWN

-- Do not delete tables!