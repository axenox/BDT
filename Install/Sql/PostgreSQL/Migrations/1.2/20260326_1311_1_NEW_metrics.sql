-- UP

-- =========================================================
-- bdt_metric
-- =========================================================
CREATE TABLE IF NOT EXISTS bdt_metric (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid         NOT NULL,
    modified_by_user_oid uuid         NOT NULL,
    app_oid              uuid         NOT NULL,
    subject_object_oid   uuid         NULL,
    name                 varchar(100) NOT NULL,
    description          varchar(400) NULL,
    prototype_path       varchar(400) NOT NULL,
    config_uxon          text         NULL,
    enabled_flag         smallint     NOT NULL DEFAULT 1,
    CONSTRAINT pk_bdt_metric PRIMARY KEY (oid),
    CONSTRAINT fk_metric_app FOREIGN KEY (app_oid) REFERENCES exf_app (oid)
    );

-- MySQL KEY `FK_metric_app` (`app_oid`)
CREATE INDEX IF NOT EXISTS ix_bdt_metric_app_oid
    ON bdt_metric (app_oid);


-- =========================================================
-- bdt_run_metric_score
-- =========================================================
CREATE TABLE IF NOT EXISTS bdt_run_metric_score (
    oid                  uuid          NOT NULL,
    created_on           timestamp     NOT NULL,
    modified_on          timestamp     NOT NULL,
    created_by_user_oid  uuid          NOT NULL,
    modified_by_user_oid uuid          NOT NULL,
    metric_oid           uuid          NOT NULL,
    run_oid              uuid          NOT NULL,
    app_oid              uuid          NULL,
    
    score_expected       double precision NOT NULL,
    score_absolute       double precision NOT NULL,
    score_percentual     double precision NOT NULL,
    
    subject_name         varchar(200)  NULL,
    subject_object_oid   uuid          NULL,
    subject_uid          varchar(200)  NULL,
    
    steps_count          integer       NOT NULL,
    steps_oids           text          NULL,

    CONSTRAINT pk_bdt_run_metric_score PRIMARY KEY (oid),
    CONSTRAINT fk_metric_score_app    FOREIGN KEY (app_oid)    REFERENCES exf_app (oid),
    CONSTRAINT fk_metric_score_metric FOREIGN KEY (metric_oid) REFERENCES bdt_metric (oid),
    CONSTRAINT fk_metric_score_run    FOREIGN KEY (run_oid)    REFERENCES bdt_run (oid)
);

-- MySQL KEY `FK_metric_score_app` (`app_oid`)
CREATE INDEX IF NOT EXISTS ix_bdt_run_metric_score_app_oid
    ON bdt_run_metric_score (app_oid);

-- MySQL KEY `FK_metric_score_run` (`run_oid`)
CREATE INDEX IF NOT EXISTS ix_bdt_run_metric_score_run_oid
    ON bdt_run_metric_score (run_oid);

-- MySQL KEY `IDX_metric_run_app_name_percent` (`metric_oid`,`run_oid`,`app_oid`,`subject_name`,`score_percentual`)
CREATE INDEX IF NOT EXISTS ix_metric_run_app_name_percent
    ON bdt_run_metric_score (metric_oid, run_oid, app_oid, subject_name, score_percentual);

-- DOWN

-- Do not delete tables!