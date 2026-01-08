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
            // Add only if they don't already exist (safe re-run)
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'organization')) {
                $table->string('organization', 150)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 30)->nullable()->default('user')->after('organization');
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->nullable()->default('active')->after('role');
            }
            if (! Schema::hasColumn('users', 'terms_agreed_at')) {
                $table->timestamp('terms_agreed_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop only if present
            if (Schema::hasColumn('users', 'terms_agreed_at')) $table->dropColumn('terms_agreed_at');
            if (Schema::hasColumn('users', 'status'))         $table->dropColumn('status');
            if (Schema::hasColumn('users', 'role'))           $table->dropColumn('role');
            if (Schema::hasColumn('users', 'organization'))   $table->dropColumn('organization');
            if (Schema::hasColumn('users', 'phone'))          $table->dropColumn('phone');
        });
    }
};
