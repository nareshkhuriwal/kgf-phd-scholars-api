<?php
// database/migrations/2025_11_09_000001_add_review_sections_to_reviews_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('reviews')) {
            return;
        }   
        Schema::table('reviews', function (Blueprint $table) {
            // JSON works with MySQL 5.7+/MariaDB 10.2.7+/Postgres
            if (!Schema::hasColumn('reviews', 'review_sections')) {
                $table->json('review_sections')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('reviews', 'html')) {
                $table->longText('html')->nullable()->after('review_sections');
            }
        });
    }
    public function down(): void {
        Schema::table('reviews', function (Blueprint $table) {
            if (Schema::hasColumn('reviews', 'review_sections')) $table->dropColumn('review_sections');
            // don't drop html if you already had it; remove if you want
        });
    }
};
