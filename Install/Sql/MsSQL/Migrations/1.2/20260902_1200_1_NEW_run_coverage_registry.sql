-- UP

IF OBJECT_ID(N'dbo.bdt_run_coverage_registry', N'U') IS NULL
BEGIN
CREATE TABLE dbo.bdt_run_coverage_registry (
        oid                     binary(16)      NOT NULL,
        created_on              datetime2(0)    NOT NULL,
        modified_on             datetime2(0)    NOT NULL,
        created_by_user_oid     binary(16)      NOT NULL,
        modified_by_user_oid    binary(16)      NOT NULL,
        run_uid                 binary(16)      NOT NULL,
        screen_slug             nvarchar(160)   NOT NULL,
        screen_kind             varchar(10)     COLLATE Latin1_General_100_BIN2 NOT NULL,
        widget_id               nvarchar(160)   NOT NULL,
        object_uid              nvarchar(34)    NOT NULL,
        role_key                nvarchar(400)   NOT NULL,
        work_category           varchar(50)     COLLATE Latin1_General_100_BIN2 NOT NULL,
        element                 nvarchar(160)   NOT NULL,
        action_fingerprint      char(64)        COLLATE Latin1_General_100_BIN2 NOT NULL,
        identity_hash           char(64)        COLLATE Latin1_General_100_BIN2 NOT NULL,
        status                  int             NOT NULL
        CONSTRAINT DF_bdt_run_coverage_registry_status DEFAULT (0),
        started_on              datetime2(0)    NOT NULL,
        finished_on             datetime2(0)    NULL,
        CONSTRAINT PK_bdt_run_coverage_registry PRIMARY KEY CLUSTERED (oid),
        CONSTRAINT CK_bdt_run_coverage_registry_screen_kind
        CHECK (screen_kind IN ('page', 'dialog', 'popup')),
        CONSTRAINT CK_bdt_run_coverage_registry_finished
        CHECK (finished_on IS NULL OR finished_on >= started_on),
    -- The claim is scoped to one run. Making run_uid part of the key means the uniqueness
    -- guarantee no longer depends on run_uid being folded into identity_hash by the caller.
               CONSTRAINT UQ_bdt_run_coverage_registry_identity
                   UNIQUE NONCLUSTERED (run_uid, identity_hash)
);
END

-- DOWN

-- Do not delete tables!