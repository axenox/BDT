-- UP
DECLARE @ConstraintName NVARCHAR(200);

SELECT @ConstraintName = name
FROM sys.key_constraints
WHERE type = 'PK' AND parent_object_id = OBJECT_ID('bdt_run_suite');

IF @ConstraintName IS NOT NULL
BEGIN
EXEC('ALTER TABLE bdt_run_suite DROP CONSTRAINT ' + @ConstraintName);
END

ALTER TABLE bdt_run_suite ALTER COLUMN oid BINARY(16) NOT NULL;
ALTER TABLE bdt_run_suite ALTER COLUMN created_by_user_oid BINARY(16) NOT NULL;
ALTER TABLE bdt_run_suite ALTER COLUMN modified_by_user_oid BINARY(16) NOT NULL;
ALTER TABLE bdt_run_suite ALTER COLUMN run_oid BINARY(16) NOT NULL;

ALTER TABLE bdt_run_suite ADD CONSTRAINT PK_bdt_run_suite_oid PRIMARY KEY (oid);

-- DOWN