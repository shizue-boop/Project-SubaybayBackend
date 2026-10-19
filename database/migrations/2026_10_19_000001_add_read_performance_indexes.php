<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'students' => [
            'students_listing_order' => ['grade_level', 'section', 'full_name'],
            'students_teacher_listing' => ['teacher_id', 'grade_level', 'section', 'full_name'],
            'students_full_name_lookup' => ['full_name'],
        ],
        'assessments' => [
            'assessments_student_latest' => ['student_id', 'assessment_date', 'id'],
            'assessments_date_listing' => ['assessment_date', 'id'],
        ],
        'progress_updates' => [
            'progress_student_latest' => ['student_id', 'update_date', 'id'],
            'progress_date_listing' => ['update_date', 'id'],
        ],
        'remedial_sessions' => [
            'sessions_student_date' => ['student_id', 'session_date', 'start_time', 'id'],
            'sessions_teacher_date' => ['teacher_id', 'session_date', 'start_time'],
            'sessions_date_listing' => ['session_date', 'start_time'],
        ],
        'remedial_attendance' => [
            'attendance_date_listing' => ['attendance_date', 'id'],
            'attendance_student_date' => ['student_id', 'attendance_date', 'id'],
        ],
        'endorsements' => [
            'endorsements_status_date' => ['status', 'created_at', 'id'],
            'endorsements_teacher_status_date' => ['teacher_id', 'status', 'created_at', 'id'],
        ],
        'endorsement_history' => [
            'endorsement_history_date' => ['endorsement_id', 'created_at', 'id'],
        ],
        'material_distribution' => [
            'distribution_date_listing' => ['date_given', 'id'],
            'distribution_teacher_date' => ['given_by', 'date_given'],
            'distribution_student_lookup' => ['given_to'],
        ],
        'schedule_events' => [
            'schedule_date_listing' => ['event_date', 'start_time', 'id'],
            'schedule_teacher_date' => ['teacher_id', 'event_date', 'start_time'],
        ],
        'reports' => [
            'reports_date_listing' => ['created_at', 'id'],
            'reports_teacher_date' => ['generated_by', 'created_at', 'id'],
        ],
        'users' => [
            'users_role_status' => ['role', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) continue;
            $existing = Schema::getIndexes($tableName);

            foreach ($indexes as $name => $columns) {
                $alreadyCovered = collect($existing)->contains(fn (array $index) => $index['columns'] === $columns);
                if ($alreadyCovered) continue;

                Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
                $existing[] = ['columns' => $columns, 'name' => $name];
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) continue;
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($tableName, $name)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
        }
    }
};
