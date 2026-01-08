<?php
// database/migrations/XXXX_XX_XX_XXXXXX_add_accepted_at_to_researcher_invites_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('researcher_invites')) {
            return;
        }
        Schema::table('researcher_invites', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('researcher_invites', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
    }
};
