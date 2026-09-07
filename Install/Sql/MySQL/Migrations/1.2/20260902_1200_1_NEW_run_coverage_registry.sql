-- UP

CREATE TABLE IF NOT EXISTS bdt_run_coverage_registry (
    oid                     binary(16)      NOT NULL,
    created_on              datetime        NOT NULL,
    modified_on             datetime        NOT NULL,
    created_by_user_oid     binary(16)      NOT NULL,
    modified_by_user_oid    binary(16)      NOT NULL,
    run_uid                 binary(16)      NOT NULL,
    screen_slug             varchar(160)    NOT NULL,
    screen_kind             varchar(10)     SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NOT NULL,
    widget_id               varchar(160)    NOT NULL,
    object_uid              varchar(34)     NOT NULL,
    role_key                varchar(400)    NOT NULL,
    work_category           varchar(50)     SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NOT NULL,
    element                 varchar(160)    NOT NULL,
    action_fingerprint      char(64)        SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NOT NULL,
    identity_hash           char(64)        SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NOT NULL,
    status                  int             NOT NULL DEFAULT 0,
    started_on              datetime        NOT NULL,
    finished_on             datetime        NULL,
    PRIMARY KEY (oid),
    CONSTRAINT CK_bdt_run_coverage_registry_screen_kind
    CHECK (screen_kind IN ('page', 'dialog', 'popup')),
    CONSTRAINT CK_bdt_run_coverage_registry_finished
    CHECK (finished_on IS NULL OR finished_on >= started_on),
    UNIQUE KEY UQ_bdt_run_coverage_registry_identity (run_uid, identity_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DOWN

-- Do not delete tables!