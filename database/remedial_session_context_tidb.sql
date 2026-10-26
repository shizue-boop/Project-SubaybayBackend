-- Apply once to add explicit endorsement and recorder links to remedial sessions.
ALTER TABLE remedial_sessions
    ADD COLUMN related_endorsement_id INT(11) NULL AFTER student_id,
    ADD COLUMN recorded_by INT(11) NULL AFTER teacher_id,
    ADD INDEX idx_remedial_sessions_related_endorsement (related_endorsement_id),
    ADD INDEX idx_remedial_sessions_recorded_by (recorded_by),
    ADD CONSTRAINT fk_remedial_sessions_related_endorsement
        FOREIGN KEY (related_endorsement_id) REFERENCES endorsements (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_remedial_sessions_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL;
