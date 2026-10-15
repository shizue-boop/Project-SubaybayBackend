<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create only absent application tables. Existing Subaybay tables are left intact.
     * This migration intentionally has a no-op rollback to protect existing records.
     */
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('full_name', 150);
                $table->string('email', 190)->unique();
                $table->string('employee_id', 80)->nullable()->unique();
                $table->string('password');
                $table->enum('role', ['principal', 'coordinator', 'teacher'])->default('teacher');
                $table->string('section', 100)->nullable();
                $table->string('grade_level', 50)->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->date('joined_date');
                $table->string('profile_image')->nullable();
                $table->string('contact_number', 40)->nullable();
            });
        } elseif (!Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('employee_id', 80)->nullable());
        }

        if (!Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('lrn', 30)->unique();
                $table->string('full_name', 150);
                $table->string('section', 100);
                $table->string('grade_level', 50);
                $table->integer('teacher_id')->nullable()->index();
                $table->date('date_enrolled')->nullable();
                $table->string('profile_image')->nullable();
                $table->string('parent_guardian', 150)->nullable();
                $table->string('contact_number', 40)->nullable();
                $table->string('reading_profile', 50)->nullable();
                $table->decimal('score', 8, 2)->default(0);
                $table->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('assessments')) {
            Schema::create('assessments', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('student_id');
                $table->string('tool', 80);
                $table->integer('task1_score')->default(0);
                $table->integer('words_score')->default(0);
                $table->integer('sentences_score')->default(0);
                $table->integer('total_score')->default(0);
                $table->string('profile', 50);
                $table->date('assessment_date')->index();
                $table->integer('recorded_by')->nullable();
                $table->index('student_id');
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('progress_updates')) {
            Schema::create('progress_updates', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('student_id');
                $table->string('previous_profile', 50);
                $table->string('current_profile', 50);
                $table->enum('trend', ['improved', 'no_change', 'declined'])->default('no_change');
                $table->date('update_date');
                $table->integer('updated_by')->nullable();
                $table->text('remarks')->nullable();
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('learning_materials')) {
            Schema::create('learning_materials', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('material_type', 80)->default('Other Materials');
                $table->string('grade_level', 50);
                $table->string('reading_level', 50)->nullable();
                $table->string('language', 80)->default('All Languages');
                $table->string('alignment_part', 80)->default('General Reading Practice');
                $table->string('skill_focus', 80)->default('General Reading Practice');
                $table->string('file_path')->nullable();
                $table->string('file_format', 30)->nullable();
                $table->string('file_size', 30)->nullable();
                $table->integer('uploaded_by')->nullable();
                $table->dateTime('upload_date')->useCurrent();
                $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('material_distribution')) {
            Schema::create('material_distribution', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('material_id');
                $table->string('given_to', 150);
                $table->string('grade_level', 50)->nullable();
                $table->date('date_given');
                $table->integer('given_by')->nullable();
                $table->foreign('material_id')->references('id')->on('learning_materials')->cascadeOnDelete();
                $table->foreign('given_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('remedial_sessions')) {
            Schema::create('remedial_sessions', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('student_id');
                $table->integer('teacher_id')->nullable();
                $table->date('session_date');
                $table->time('start_time');
                $table->time('end_time')->nullable();
                $table->string('focus_area', 120)->nullable();
                $table->string('session_type', 80)->nullable();
                $table->string('status', 30)->default('Scheduled');
                $table->text('notes')->nullable();
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('remedial_attendance')) {
            Schema::create('remedial_attendance', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('session_id');
                $table->integer('student_id');
                $table->date('attendance_date');
                $table->enum('status', ['Present', 'Absent']);
                $table->text('remarks')->nullable();
                $table->integer('recorded_by')->nullable();
                $table->unique(['session_id', 'attendance_date'], 'attendance_once');
                $table->foreign('session_id')->references('id')->on('remedial_sessions')->cascadeOnDelete();
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('endorsements')) {
            Schema::create('endorsements', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('student_id');
                $table->integer('teacher_id');
                $table->string('assessment_tool', 80)->nullable();
                $table->date('assessment_date')->nullable();
                $table->string('assessment_result')->nullable();
                $table->string('reading_level', 50)->nullable();
                $table->text('reading_difficulty')->nullable();
                $table->text('teacher_remarks')->nullable();
                $table->string('supporting_document')->nullable();
                $table->enum('status', ['Pending', 'Approved', 'Returned for Revision', 'Rejected'])->default('Pending');
                $table->integer('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
                $table->foreign('teacher_id')->references('id')->on('users');
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('endorsement_history')) {
            Schema::create('endorsement_history', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('endorsement_id');
                $table->string('status', 40);
                $table->integer('changed_by');
                $table->text('remarks')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('endorsement_id')->references('id')->on('endorsements')->cascadeOnDelete();
                $table->foreign('changed_by')->references('id')->on('users');
            });
        }

        if (!Schema::hasTable('reports')) {
            Schema::create('reports', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('report_title', 200);
                $table->string('report_type', 80);
                $table->integer('generated_by')->nullable();
                $table->dateTime('generated_date')->useCurrent();
                $table->string('file_format', 20)->default('HTML');
                $table->string('status', 30)->default('Ready');
                $table->string('file_path')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('schedule_events')) {
            Schema::create('schedule_events', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('title', 200);
                $table->string('event_type', 80)->nullable();
                $table->string('grade_level', 50)->nullable();
                $table->string('section', 100)->nullable();
                $table->date('event_date');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->integer('teacher_id')->nullable();
                $table->text('description')->nullable();
                $table->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('grade_categories')) {
            Schema::create('grade_categories', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('grade_level', 50)->nullable();
                $table->string('assessment_type', 80)->nullable();
                $table->string('category_name', 100);
                $table->string('score_range', 100)->nullable();
                $table->text('recommended_action')->nullable();
                $table->integer('min_score')->default(0);
                $table->integer('max_score')->default(100);
                $table->text('description')->nullable();
            });
        }

        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('setting_key', 80)->primary();
                $table->text('setting_value')->nullable();
                $table->integer('updated_by')->nullable();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: Subaybay data is retained on rollback.
    }
};
