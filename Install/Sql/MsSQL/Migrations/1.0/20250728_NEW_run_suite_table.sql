-- UP
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
IF OBJECT_ID('bdt_run_suite', 'U') IS NOT NULL
DROP TABLE bdt_run_suite;