-- Optional read-performance indexes for the TiDB Cloud database.
-- Safe to run repeatedly: TiDB skips indexes that already exist by name.
-- Performance indexes used by the Subaybay API.

CREATE INDEX IF NOT EXISTS students_listing_order
    ON students (grade_level, section, full_name);
CREATE INDEX IF NOT EXISTS students_teacher_listing
    ON students (teacher_id, grade_level, section, full_name);
CREATE INDEX IF NOT EXISTS students_full_name_lookup
    ON students (full_name);

CREATE INDEX IF NOT EXISTS grade_categories_grade_category
    ON grade_categories (grade_level, category_name);

CREATE INDEX IF NOT EXISTS materials_upload_date_listing
    ON learning_materials (upload_date, id);
CREATE INDEX IF NOT EXISTS materials_grade_date_listing
    ON learning_materials (grade_level, upload_date, id);

CREATE INDEX IF NOT EXISTS assessments_student_latest
    ON assessments (student_id, assessment_date, id);
CREATE INDEX IF NOT EXISTS assessments_date_listing
    ON assessments (assessment_date, id);

CREATE INDEX IF NOT EXISTS progress_student_latest
    ON progress_updates (student_id, update_date, id);
CREATE INDEX IF NOT EXISTS progress_date_listing
    ON progress_updates (update_date, id);

CREATE INDEX IF NOT EXISTS sessions_student_date
    ON remedial_sessions (student_id, session_date, start_time, id);
CREATE INDEX IF NOT EXISTS sessions_teacher_date
    ON remedial_sessions (teacher_id, session_date, start_time);
CREATE INDEX IF NOT EXISTS sessions_date_listing
    ON remedial_sessions (session_date, start_time);

CREATE INDEX IF NOT EXISTS attendance_date_listing
    ON remedial_attendance (attendance_date, id);
CREATE INDEX IF NOT EXISTS attendance_student_date
    ON remedial_attendance (student_id, attendance_date, id);

CREATE INDEX IF NOT EXISTS endorsements_status_date
    ON endorsements (status, created_at, id);
CREATE INDEX IF NOT EXISTS endorsements_teacher_status_date
    ON endorsements (teacher_id, status, created_at, id);
CREATE INDEX IF NOT EXISTS endorsement_history_date
    ON endorsement_history (endorsement_id, created_at, id);

CREATE INDEX IF NOT EXISTS distribution_date_listing
    ON material_distribution (date_given, id);
CREATE INDEX IF NOT EXISTS distribution_teacher_date
    ON material_distribution (given_by, date_given);
CREATE INDEX IF NOT EXISTS distribution_student_lookup
    ON material_distribution (given_to);

CREATE INDEX IF NOT EXISTS schedule_date_listing
    ON schedule_events (event_date, start_time, id);
CREATE INDEX IF NOT EXISTS schedule_teacher_date
    ON schedule_events (teacher_id, event_date, start_time);

CREATE INDEX IF NOT EXISTS reports_date_listing
    ON reports (created_at, id);
CREATE INDEX IF NOT EXISTS reports_teacher_date
    ON reports (generated_by, created_at, id);

CREATE INDEX IF NOT EXISTS users_role_status
    ON users (role, status);
