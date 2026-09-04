-- UP

CREATE TABLE IF NOT EXISTS bdt_run_coverage_registry (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid         NOT NULL,
    modified_by_user_oid uuid         NOT NULL,
    run_uid              varchar(32)  NOT NULL,
    screen_slug          varchar(160) NOT NULL,
    screen_kind          varchar(10)  NOT NULL,
    widget_id            varchar(160) NOT NULL,
    object_uid           varchar(34)  NOT NULL,
    role_key             varchar(100) NOT NULL,
    work_category        varchar(50)  NOT NULL,
    element              varchar(100) NOT NULL,
    action_fingerprint   char(64)     NOT NULL,
    status               integer      NOT NULL,
    started_on           timestamp    NOT NULL,
    finished_on          timestamp    NULL,

    CONSTRAINT pk_bdt_run_coverage_registry PRIMARY KEY (oid),
    CONSTRAINT ck_bdt_run_coverage_registry_screen_kind CHECK (screen_kind IN ('page', 'dialog', 'popup')),
    CONSTRAINT ck_bdt_run_coverage_registry_work_category CHECK (work_category IN ('Filtering', 'Buttons')),
    CONSTRAINT uq_bdt_run_coverage_registry_identity UNIQUE
        (run_uid, screen_slug, screen_kind, widget_id, object_uid, role_key, work_category, element, action_fingerprint)
);

-- DOWN

-- Do not delete tables!