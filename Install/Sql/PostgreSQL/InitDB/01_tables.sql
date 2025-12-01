CREATE TABLE bdt_run_suite (
                               oid uuid PRIMARY KEY,
                               created_on timestamp NOT NULL,
                               modified_on timestamp NOT NULL,
                               created_by_user_oid uuid NOT NULL,
                               modified_by_user_oid uuid NOT NULL,
                               app_alias varchar(100) NOT NULL,
                               run_oid uuid NOT NULL,
                               total_page_count integer NOT NULL,
                               effected_page_count integer NOT NULL,
                               coverage numeric(19,2) NOT NULL
);

CREATE TABLE bdt_run (
                         oid uuid PRIMARY KEY,
                         created_on timestamp NOT NULL,
                         modified_on timestamp NOT NULL,
                         created_by_user_oid uuid NOT NULL,
                         modified_by_user_oid uuid NOT NULL,
                         started_on timestamp NOT NULL,
                         finished_on timestamp,
                         duration_ms double precision,
                         behat_command varchar(400)
);

CREATE TABLE bdt_run_feature (
                                 oid uuid PRIMARY KEY,
                                 created_on timestamp NOT NULL,
                                 modified_on timestamp NOT NULL,
                                 created_by_user_oid uuid NOT NULL,
                                 modified_by_user_oid uuid NOT NULL,
                                 run_oid uuid NOT NULL,
                                 run_sequence_idx integer NOT NULL,
                                 app_alias varchar(100),
                                 name varchar(500) NOT NULL,
                                 description text,
                                 filename varchar(200),
                                 started_on timestamp NOT NULL,
                                 finished_on timestamp,
                                 duration_ms double precision,
                                 content text
);

CREATE TABLE bdt_run_scenario (
                                  oid uuid PRIMARY KEY,
                                  created_on timestamp NOT NULL,
                                  modified_on timestamp NOT NULL,
                                  created_by_user_oid uuid,
                                  modified_by_user_oid uuid,
                                  run_feature_oid uuid NOT NULL,
                                  name varchar(1000) NOT NULL,
                                  line integer NOT NULL DEFAULT 0,
                                  started_on timestamp NOT NULL,
                                  finished_on timestamp,
                                  duration_ms double precision,
                                  tags varchar(200),
                                  absolute boolean,
                                  paused boolean,
                                  comment varchar(300),
                                  commented_by_user_oid uuid
);

CREATE TABLE bdt_run_scenario_action (
                                         oid uuid PRIMARY KEY,
                                         created_on timestamp NOT NULL,
                                         modified_on timestamp NOT NULL,
                                         created_by_user_oid uuid NOT NULL,
                                         modified_by_user_oid uuid NOT NULL,
                                         run_scenario_oid uuid NOT NULL,
                                         page_oid uuid,
                                         page_alias varchar(200) NOT NULL,
                                         widget_id varchar(2000),
                                         action_alias varchar(200),
                                         action_caption varchar(100),
                                         action_path varchar(400)
);

CREATE TABLE bdt_run_step (
                              oid uuid PRIMARY KEY,
                              created_on timestamp NOT NULL,
                              modified_on timestamp NOT NULL,
                              created_by_user_oid uuid,
                              modified_by_user_oid uuid,
                              run_scenario_oid uuid NOT NULL,
                              run_sequence_idx integer NOT NULL,
                              name varchar(1000) NOT NULL,
                              line integer NOT NULL DEFAULT 0,
                              started_on timestamp NOT NULL,
                              finished_on timestamp,
                              duration_ms double precision,
                              status integer NOT NULL,
                              error_message varchar(200),
                              error_log_id varchar(10),
                              screenshot_path varchar(200)
);

-- ======================
-- Foreign key definitions
-- ======================

ALTER TABLE bdt_run_feature
    ADD CONSTRAINT fk_bdt_run_feature_run
        FOREIGN KEY (run_oid)
            REFERENCES bdt_run (oid);

ALTER TABLE bdt_run_scenario
    ADD CONSTRAINT fk_bdt_run_scenario_feature
        FOREIGN KEY (run_feature_oid)
            REFERENCES bdt_run_feature (oid);

ALTER TABLE bdt_run_scenario_action
    ADD CONSTRAINT fk_bdt_run_scenario_action_scenario
        FOREIGN KEY (run_scenario_oid)
            REFERENCES bdt_run_scenario (oid);

ALTER TABLE bdt_run_step
    ADD CONSTRAINT fk_bdt_run_step_scenario
        FOREIGN KEY (run_scenario_oid)
            REFERENCES bdt_run_scenario (oid);