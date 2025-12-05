-- UP
ALTER TABLE [dbo].[bdt_run_suite] ALTER COLUMN [created_by_user_oid] binary(16) NOT NULL;
ALTER TABLE [dbo].[bdt_run_suite] ALTER COLUMN [modified_by_user_oid] binary(16) NOT NULL;

DECLARE @SchemaName sysname = 'dbo';
DECLARE @TableName  sysname = 'bdt_run_suite';
DECLARE @PKName     sysname;
DECLARE @sql        nvarchar(max);

SELECT
    @PKName = kc.name
FROM
    sys.key_constraints kc
WHERE
    kc.[type] = 'PK'
  AND kc.[parent_object_id] = OBJECT_ID(@SchemaName + '.' + @TableName);

IF @PKName IS NOT NULL
BEGIN
    SET @sql = N'ALTER TABLE ' 
             + QUOTENAME(@SchemaName) + N'.' + QUOTENAME(@TableName) 
             + N' DROP CONSTRAINT ' + QUOTENAME(@PKName) + N';';

    PRINT @sql;
EXEC sp_executesql @sql;
END

ALTER TABLE [dbo].[bdt_run_suite] ALTER COLUMN [oid] binary(16) NOT NULL;

ALTER TABLE dbo.bdt_run_suite
    ADD CONSTRAINT PK_bdt_run_suite_oid
        PRIMARY KEY CLUSTERED (oid);



-- DOWN