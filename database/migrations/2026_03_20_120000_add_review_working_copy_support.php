<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_files', function (Blueprint $table) {
            if (!Schema::hasColumn('paper_files', 'is_review_copy')) {
                $table->boolean('is_review_copy')->default(false)->after('uploaded_by');
            }
        });

        Schema::table('reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('reviews', 'review_working_copy_file_id')) {
                $table->foreignId('review_working_copy_file_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('paper_files')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            if (Schema::hasColumn('reviews', 'review_working_copy_file_id')) {
                $table->dropConstrainedForeignId('review_working_copy_file_id');
            }
        });

        Schema::table('paper_files', function (Blueprint $table) {
            if (Schema::hasColumn('paper_files', 'is_review_copy')) {
                $table->dropColumn('is_review_copy');
            }
        });
    }
};
