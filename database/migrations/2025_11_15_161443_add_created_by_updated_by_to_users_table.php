<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('remember_token');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->index('created_by', 'idx_users_created_by');
            $table->index('updated_by', 'idx_users_updated_by');

            $table->foreign('created_by', 'fk_users_created_by')
                ->references('id')->on('users')
                ->onDelete('set null')
                ->onUpdate('cascade');

            $table->foreign('updated_by', 'fk_users_updated_by')
                ->references('id')->on('users')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_created_by');
            $table->dropForeign('fk_users_updated_by');

            $table->dropIndex('idx_users_created_by');
            $table->dropIndex('idx_users_updated_by');

            $table->dropColumn(['created_by', 'updated_by']);
        });
    }

};
