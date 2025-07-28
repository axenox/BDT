-- UP
ALTER TABLE [bdt_run_feature]
    ADD [alias] varchar(200) COLLATE utf8mb4_general_ci NULL;

UPDATE [bdt_run_feature] SET [bdt_run_feature.alias] =
    LEFT(
    RIGHT([bdt_run_feature.filename], CHARINDEX('/', REVERSE([bdt_run_feature.filename])) - 1),
    LEN(RIGHT([bdt_run_feature.filename], CHARINDEX('/', REVERSE([bdt_run_feature.filename])) - 1)) - 8
    );

CREATE TABLE bdt_run_suite (
   oid UNIQUEIDENTIFIER NOT NULL PRIMARY KEY,
   created_on DATETIME NOT NULL,
   modified_on DATETIME NOT NULL,
   created_by_user_oid UNIQUEIDENTIFIER NOT NULL,
   modified_by_user_oid UNIQUEIDENTIFIER NOT NULL,
   app_alias VARCHAR(100) NOT NULL,
   run_oid UNIQUEIDENTIFIER NOT NULL,
   total_page_count INT NOT NULL,
   effected_page_count INT NOT NULL,
   coverage DECIMAL(19,2) NOT NULL
);

-- DOWN
ALTER TABLE [bdt_run_feature]
DROP COLUMN [alias]
     
IF OBJECT_ID('bdt_run_suite', 'U') IS NOT NULL
DROP TABLE bdt_run_suite;