<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan_key')->nullable()->after('role');      // e.g. researcher-upgrade
            $table->timestamp('plan_expires_at')->nullable()->after('plan_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan_key', 'plan_expires_at']);
        });
    }
};
