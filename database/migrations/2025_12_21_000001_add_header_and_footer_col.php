<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('saved_reports')) {
            return;
        }
        Schema::table('saved_reports', function (Blueprint $table) {
            $table->json('headerFooter')->nullable()->after('selections');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saved_reports', function (Blueprint $table) {
            $table->dropColumn('headerFooter');
        });
    }
};