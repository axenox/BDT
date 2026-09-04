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
        screen_kind             nvarchar(10)    NOT NULL,
        widget_id               nvarchar(160)   NOT NULL,
        object_uid              nvarchar(34)    NOT NULL,
        role_key                nvarchar(400)   NOT NULL,
        work_category           nvarchar(50)    NOT NULL,
        element                 nvarchar(160)   NOT NULL,
        action_fingerprint      char(64)        NOT NULL,
        identity_hash           char(64)        NOT NULL,
        status                  int             NOT NULL,
        started_on              datetime2(0)    NOT NULL,
        finished_on             datetime2(0)    NULL,
        CONSTRAINT PK_run_coverage_registry PRIMARY KEY CLUSTERED (oid),
        CONSTRAINT CK_run_coverage_registry_screen_kind
            CHECK (screen_kind IN (N'page', N'dialog', N'popup')),
        CONSTRAINT UQ_run_coverage_registry_identity UNIQUE NONCLUSTERED (identity_hash)
);

CREATE NONCLUSTERED INDEX IX_run_coverage_registry_run
        ON dbo.bdt_run_coverage_registry (run_uid);
       
-- DOWN

-- Do not delete tables!