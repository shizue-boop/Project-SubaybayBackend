-- Normalize pupil sections to Alvarez, Aguinaldo, and Calderon.
-- Run the statements in order in the Subaybay TiDB schema.
-- First confirm the preflight query returns exactly one Ms. Cruz account.
-- Pupils already in one of the three target sections are preserved.
-- Pupils assigned to Ms. Cruz are moved to Alvarez. All other pupils with
-- missing/outdated sections are split by pupil ID order between Aguinaldo
-- and Calderon; the resulting groups differ by at most one pupil.

SELECT COUNT(*) AS ms_cruz_matches
FROM users
WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz';

SELECT section, COUNT(*) AS pupil_count
FROM students
GROUP BY section
ORDER BY section;

-- Update the uniquely matching Ms. Cruz teacher account, if exactly one exists.
UPDATE users AS target_user
JOIN (
    SELECT MIN(id) AS id, COUNT(*) AS match_count
    FROM users
    WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz'
) AS ms_cruz ON ms_cruz.id = target_user.id AND ms_cruz.match_count = 1
SET target_user.section = 'Alvarez';

-- Move pupils assigned to Ms. Cruz to Alvarez.
UPDATE students AS target_pupil
JOIN (
    SELECT MIN(id) AS id, COUNT(*) AS match_count
    FROM users
    WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz'
) AS ms_cruz ON ms_cruz.match_count = 1 AND target_pupil.teacher_id = ms_cruz.id
SET target_pupil.section = 'Alvarez';

-- Split every other pupil whose section is not one of the three approved names.
UPDATE students AS target_pupil
JOIN (
    SELECT ranked_pupils.id,
           CASE
               WHEN MOD(ranked_pupils.row_num, 2) = 1 THEN 'Aguinaldo'
               ELSE 'Calderon'
           END AS section_name
    FROM (
        SELECT eligible_pupils.id,
               ROW_NUMBER() OVER (ORDER BY eligible_pupils.id) AS row_num
        FROM (
            SELECT pupil.id
            FROM students AS pupil
            CROSS JOIN (
                SELECT MIN(id) AS id, COUNT(*) AS match_count
                FROM users
                WHERE role = 'teacher' AND TRIM(full_name) = 'Ms. Cruz'
            ) AS ms_cruz
            WHERE ms_cruz.match_count = 1
              AND (pupil.teacher_id IS NULL OR pupil.teacher_id <> ms_cruz.id)
              AND (
                  pupil.section IS NULL
                  OR UPPER(TRIM(pupil.section)) NOT IN ('ALVAREZ', 'AGUINALDO', 'CALDERON')
              )
        ) AS eligible_pupils
    ) AS ranked_pupils
) AS assignment ON assignment.id = target_pupil.id
SET target_pupil.section = assignment.section_name;

-- Confirm the resulting counts.
SELECT section, COUNT(*) AS pupil_count
FROM students
GROUP BY section
ORDER BY section;
