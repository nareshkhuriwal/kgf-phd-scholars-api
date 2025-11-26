<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This adds subscription and trial fields for admin accounts
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Subscription status: 'active', 'trial', 'expired', 'cancelled'
            if (!Schema::hasColumn('users', 'subscription_status')) {
                $table->string('subscription_status', 20)
                    ->default('active')
                    ->after('status');
            }

            // Plan key for tracking which plan the user is on
            if (!Schema::hasColumn('users', 'plan_key')) {
                $table->string('plan_key', 50)
                    ->nullable()
                    ->after('subscription_status');
            }

            // Trial flag (1 = on trial, 0 = not on trial)
            if (!Schema::hasColumn('users', 'trial')) {
                $table->boolean('trial')
                    ->default(0)
                    ->after('plan_key');
            }

            // Trial start date
            if (!Schema::hasColumn('users', 'trial_start_date')) {
                $table->timestamp('trial_start_date')
                    ->nullable()
                    ->after('trial');
            }

            // Trial end date
            if (!Schema::hasColumn('users', 'trial_end_date')) {
                $table->timestamp('trial_end_date')
                    ->nullable()
                    ->after('trial_start_date');
            }

            // Role-specific fields
            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 50)
                    ->nullable()
                    ->unique()
                    ->after('organization');
            }

            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department', 150)
                    ->nullable()
                    ->after('employee_id');
            }

            if (!Schema::hasColumn('users', 'specialization')) {
                $table->string('specialization', 255)
                    ->nullable()
                    ->after('department');
            }

            if (!Schema::hasColumn('users', 'research_area')) {
                $table->string('research_area', 255)
                    ->nullable()
                    ->after('specialization');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'research_area'))         $table->dropColumn('research_area');
            if (Schema::hasColumn('users', 'specialization'))        $table->dropColumn('specialization');
            if (Schema::hasColumn('users', 'department'))            $table->dropColumn('department');
            if (Schema::hasColumn('users', 'employee_id'))           $table->dropColumn('employee_id');
            if (Schema::hasColumn('users', 'trial_end_date'))        $table->dropColumn('trial_end_date');
            if (Schema::hasColumn('users', 'trial_start_date'))      $table->dropColumn('trial_start_date');
            if (Schema::hasColumn('users', 'trial'))                 $table->dropColumn('trial');
            if (Schema::hasColumn('users', 'plan_key'))              $table->dropColumn('plan_key');
            if (Schema::hasColumn('users', 'subscription_status'))   $table->dropColumn('subscription_status');
        });
    }
};