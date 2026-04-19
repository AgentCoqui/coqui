-- Threshold Question Set Tracker Schema
-- Version 1.2
-- Tracking issuance, utilization, and responses to threshold questions across Vector hush cache and Threshold archive nodes

CREATE TABLE threshold_question_set (
    question_id VARCHAR(32) PRIMARY KEY,
    question_text TEXT NOT NULL,
    category VARCHAR(32) NOT NULL,
    issued_at TIMESTAMP WITH TIME ZONE NOT NULL,
    issuer VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL, -- 'pending', 'received', 'logged', 'discarded'
    encrypted_response_hash VARCHAR(64) NOT NULL,
    response_hash VARCHAR(64), -- NULL until received
    received_at TIMESTAMP WITH TIME ZONE,
    observation_id INTEGER
);

CREATE TABLE threshold_issue_log (
    log_id SERIAL PRIMARY KEY,
    question_set_id VARCHAR(32) NOT NULL,
    issued_at TIMESTAMP NOT NULL,
    recipient VARCHAR(64) NOT NULL,
    delivery_method VARCHAR(64) NOT NULL,
    routing_node VARCHAR(64) NOT NULL,
    vector_hush_reference VARCHAR(64),
    surveillance_flag BOOLEAN NOT NULL,
    notes TEXT
);

CREATE TABLE observation_matrix (
    observation_id SERIAL PRIMARY KEY,
    observation_type VARCHAR(32) NOT NULL,
    observation_start TIMESTAMP NOT NULL,
    observation_end TIMESTAMP NOT NULL,
    detected_anomaly BOOLEAN DEFAULT FALSE,
    anomaly_category VARCHAR(64),
    related_question_id VARCHAR(32),
    operator_initials VARCHAR(8) NOT NULL
);

CREATE INDEX idx_threshold_questions_status ON threshold_question_set(status);
CREATE INDEX idx_threshold_log recipient ON threshold_issue_log(recipient);
CREATE INDEX idx_observations_questions ON observation_matrix(related_question_id);

CREATE TABLE surveillance_window_monitor (
    window_id SERIAL PRIMARY KEY,
    surveillance_window_start TIMESTAMP NOT NULL,
    surveillance_window_end TIMESTAMP NOT NULL,
    related_question_id VARCHAR(32),
    vector_hush_reference VARCHAR(64),
    detection_status VARCHAR(16) NOT NULL -- 'clear', 'warning', 'compromised'
);
