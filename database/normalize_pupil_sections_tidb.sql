-- Normalize pupil sections to Alvarez, Aguinaldo, and Calderon.
-- Run the entire script in one TiDB SQL Editor session.
-- Existing pupils already in one of the three sections are preserved.
-- Pupils assigned to the uniquely named Ms. Cruz account are moved to Alvarez.
-- Other pupils with missing/outdated sections are split by pupil ID order;
-- the two resulting groups differ by at most one pupil.
-- If there is not exactly one teacher named "Ms. Cruz", the update is a no-op.

SELECT COUNT(*) AS ms_cruz_matches
FROM users
WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz';

SELECT section, COUNT(*) AS pupil_count
FROM students
GROUP BY section
ORDER BY section;

SET @ms_cruz_match_count := (
    SELECT COUNT(*)
    FROM users
    WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz'
);
SET @ms_cruz_id := (
    SELECT MIN(id)
    FROM users
    WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz'
    HAVING COUNT(*) = 1
);

DROP TEMPORARY TABLE IF EXISTS tmp_pupil_section_assignment;
CREATE TEMPORARY TABLE tmp_pupil_section_assignment (
    student_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    section_name VARCHAR(32) NOT NULL
);

START TRANSACTION;

UPDATE users
SET section = 'Alvarez'
WHERE id = @ms_cruz_id AND @ms_cruz_match_count = 1;

UPDATE students
SET section = 'Alvarez'
WHERE teacher_id = @ms_cruz_id AND @ms_cruz_match_count = 1;

INSERT INTO tmp_pupil_section_assignment (student_id, section_name)
SELECT ordered_pupils.id,
       CASE WHEN MOD(ordered_pupils.row_num, 2) = 1 THEN 'Aguinaldo' ELSE 'Calderon' END
FROM (
    SELECT s.id, ROW_NUMBER() OVER (ORDER BY s.id) AS row_num
    FROM students s
    WHERE @ms_cruz_match_count = 1
      AND (s.teacher_id IS NULL OR s.teacher_id <> @ms_cruz_id)
      AND (s.section IS NULL OR UPPER(TRIM(s.section)) NOT IN ('ALVAREZ', 'AGUINALDO', 'CALDERON'))
) AS ordered_pupils;

UPDATE students s
JOIN tmp_pupil_section_assignment assignment ON assignment.student_id = s.id
SET s.section = assignment.section_name;

COMMIT;

SELECT section, COUNT(*) AS pupil_count
FROM students
GROUP BY section
ORDER BY section;

DROP TEMPORARY TABLE tmp_pupil_section_assignment;
