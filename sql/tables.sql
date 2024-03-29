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
	sms_name varbinary(255) NOT NULL
);

CREATE UNIQUE INDEX /*i*/sms_name ON sofa_schema (sms_name);


CREATE TABLE /*_*/sofa_cache (
	sc_from INT UNSIGNED NOT NULL,
	sc_map_id INT UNSIGNED NOT NULL,
	PRIMARY KEY (sc_map_id, sc_from)
);

CREATE INDEX /*i*/sc_from ON sofa_cache (sc_from);
