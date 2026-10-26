-- Run the statements in order. TiDB validates each ALTER TABLE against the
-- table schema as it exists before that statement, so keep new columns and
-- indexes in separate ALTER TABLE statements.
ALTER TABLE remedial_sessions
    ADD COLUMN IF NOT EXISTS related_endorsement_id INT NULL AFTER student_id;

ALTER TABLE remedial_sessions
    ADD COLUMN IF NOT EXISTS recorded_by INT NULL AFTER teacher_id;

ALTER TABLE remedial_sessions
    ADD INDEX IF NOT EXISTS idx_remedial_sessions_related_endorsement (related_endorsement_id);

ALTER TABLE remedial_sessions
    ADD INDEX IF NOT EXISTS idx_remedial_sessions_recorded_by (recorded_by);
