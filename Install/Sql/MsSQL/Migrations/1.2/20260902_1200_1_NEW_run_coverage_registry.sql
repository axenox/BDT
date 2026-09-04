-- UP

IF OBJECT_ID(N'dbo.bdt_run_coverage_registry', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.bdt_run_coverage_registry
    (
        oid                  binary(16)    NOT NULL,
        created_on           datetime      NOT NULL,
        modified_on          datetime      NOT NULL,
        created_by_user_oid  binary(16)    NOT NULL,
        modified_by_user_oid binary(16)    NOT NULL,
        run_uid              varchar(32)   NOT NULL,
        screen_slug          nvarchar(160) NOT NULL,
        screen_kind          varchar(10)   NOT NULL,
        widget_id            nvarchar(160) NOT NULL,
        object_uid           varchar(34)   NOT NULL,
        role_key             nvarchar(100) NOT NULL,
        work_category        varchar(50)   NOT NULL,
        element              nvarchar(100) NOT NULL,
        action_fingerprint   char(64)      NOT NULL,
        status               int           NOT NULL,
        started_on           datetime      NOT NULL,
        finished_on          datetime      NULL,

        CONSTRAINT PK_bdt_run_coverage_registry PRIMARY KEY CLUSTERED (oid),
        CONSTRAINT CK_bdt_run_coverage_registry_screen_kind CHECK (screen_kind IN ('page', 'dialog', 'popup')),
        CONSTRAINT CK_bdt_run_coverage_registry_work_category CHECK (work_category IN ('Filtering', 'Buttons')),
        CONSTRAINT UQ_bdt_run_coverage_registry_identity UNIQUE NONCLUSTERED
            (run_uid, screen_slug, screen_kind, widget_id, object_uid, role_key, work_category, element, action_fingerprint)
    );
END
GO

-- DOWN

-- Do not delete tables!