<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('review_citations')) {
            return;
        }
        Schema::table('review_citations', function (Blueprint $table) {
            $table->unsignedInteger('first_used_order')
                  ->nullable()
                  ->after('citation_id');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('review_citations', function (Blueprint $table) {
            $table->dropColumn('first_used_order');
            $table->dropTimestamps();
        });
    }
};
