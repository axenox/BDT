-- UP

CREATE TABLE IF NOT EXISTS bdt_run_coverage_registry (
    oid                     uuid           NOT NULL,
    created_on              timestamp(0)    NOT NULL,
    modified_on             timestamp(0)    NOT NULL,
    created_by_user_oid     uuid           NOT NULL,
    modified_by_user_oid    uuid           NOT NULL,
    run_uid                 uuid           NOT NULL,
    screen_slug             varchar(160)    NOT NULL,
    screen_kind             varchar(10)     NOT NULL,
    widget_id               varchar(160)    NOT NULL,
    object_uid              varchar(34)     NOT NULL,
    role_key                varchar(400)    NOT NULL,
    work_category           varchar(50)     NOT NULL,
    element                 varchar(160)    NOT NULL,
    action_fingerprint      char(64)        NOT NULL,
    identity_hash           char(64)        NOT NULL,
    status                  integer         NOT NULL,
    started_on              timestamp(0)    NOT NULL,
    finished_on             timestamp(0)    NULL,
    CONSTRAINT pk_run_coverage_registry PRIMARY KEY (oid),
    CONSTRAINT ck_run_coverage_registry_screen_kind
    CHECK (screen_kind IN ('page', 'dialog', 'popup')),
    CONSTRAINT uq_run_coverage_registry_identity UNIQUE (identity_hash)
    );

CREATE INDEX IF NOT EXISTS ix_run_coverage_registry_run
    ON bdt_run_coverage_registry (run_uid);

-- DOWN

-- Do not delete tables!