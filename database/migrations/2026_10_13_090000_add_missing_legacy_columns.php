<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'contact_number')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('contact_number', 40)->nullable());
        }
        if (Schema::hasTable('progress_updates') && !Schema::hasColumn('progress_updates', 'remarks')) {
            Schema::table('progress_updates', fn (Blueprint $table) => $table->text('remarks')->nullable());
        }
        if (!Schema::hasTable('grade_categories')) return;

        if (!Schema::hasColumn('grade_categories', 'min_score')) {
            Schema::table('grade_categories', fn (Blueprint $table) => $table->integer('min_score')->default(0));
        }
        if (!Schema::hasColumn('grade_categories', 'max_score')) {
            Schema::table('grade_categories', fn (Blueprint $table) => $table->integer('max_score')->default(100));
        }
        if (!Schema::hasColumn('grade_categories', 'description')) {
            Schema::table('grade_categories', fn (Blueprint $table) => $table->text('description')->nullable());
        }
    }

    public function down(): void
    {
        // Keep the added columns and values on rollback to avoid data loss.
    }
};
