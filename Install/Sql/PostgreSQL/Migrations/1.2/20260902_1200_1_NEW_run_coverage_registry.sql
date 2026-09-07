-- UP

CREATE TABLE IF NOT EXISTS bdt_run_coverage_registry (
    oid                     bytea           NOT NULL,
    created_on              timestamp(0)    NOT NULL,
    modified_on             timestamp(0)    NOT NULL,
    created_by_user_oid     bytea           NOT NULL,
    modified_by_user_oid    bytea           NOT NULL,
    run_uid                 bytea           NOT NULL,
    screen_slug             varchar(160)    NOT NULL,
    screen_kind             varchar(10)     NOT NULL,
    widget_id               varchar(160)    NOT NULL,
    object_uid              varchar(34)     NOT NULL,
    role_key                varchar(400)    NOT NULL,
    work_category           varchar(50)     NOT NULL,
    element                 varchar(160)    NOT NULL,
    -- varchar, not char: PostgreSQL's bpchar pads to length and ignores trailing spaces on
    -- comparison, which would make two differently-padded hashes compare equal.
    action_fingerprint      varchar(64)     NOT NULL,
    identity_hash           varchar(64)     NOT NULL,
    status                  integer         NOT NULL DEFAULT 0,
    started_on              timestamp(0)    NOT NULL,
    finished_on             timestamp(0)    NULL,
    CONSTRAINT pk_bdt_run_coverage_registry PRIMARY KEY (oid),
    CONSTRAINT ck_bdt_run_coverage_registry_screen_kind
    CHECK (screen_kind IN ('page', 'dialog', 'popup')),
    CONSTRAINT ck_bdt_run_coverage_registry_finished
    CHECK (finished_on IS NULL OR finished_on >= started_on),
    CONSTRAINT uq_bdt_run_coverage_registry_identity UNIQUE (run_uid, identity_hash)
    );

-- DOWN

-- Do not delete tables!