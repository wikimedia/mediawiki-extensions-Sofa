CREATE TABLE /*_*/sofa_map (
	sm_id int UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	sm_page int UNSIGNED NOT NULL,
	sm_schema int UNSIGNED NOT NULL, -- foreign key to sofa_schema.sms_id
	sm_key varbinary(767) NOT NULL,
	sm_value blob default NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/sm_key ON sofa_map (sm_schema, sm_key);
CREATE INDEX /*i*/sm_page ON sofa_map (sm_page);

CREATE TABLE /*_*/sofa_schema (
	sms_id int UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	sms_name varbinary(767) NOT NULL
);

CREATE UNIQUE INDEX /*i*/sms_name ON sofa_schema (sms_name);


CREATE TABLE /*_*/sofa_cache (
	sc_id int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	sc_from int UNSIGNED NOT NULL,
	sc_schema int UNSIGNED NOT NULL,
	sc_start varbinary(767) NULL,
	sc_stop varbinary(767) NULL
);

-- FIXME, also an index on just sc_from?
CREATE INDEX /*i*/sc_schema_from ON sofa_cache (sc_schema, sc_from);
-- FIXME, right now the query is not using these.
CREATE INDEX /*i*/sc_schema_start ON sofa_cache (sc_schema, sc_start);
-- FIXME Do we need an index on the stop field too? This is kind
-- of 2D as we want to find if our intreval overlaps some interval in the database
CREATE INDEX /*i*/sc_schema_stop ON sofa_cache (sc_schema, sc_stop);
