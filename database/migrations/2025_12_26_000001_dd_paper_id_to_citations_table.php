<?php
// database/migrations/xxxx_xx_xx_add_paper_id_to_citations_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('citations', function (Blueprint $table) {
            $table->foreignId('paper_id')->nullable()->after('id');
            
            $table->foreign('paper_id')
                ->references('id')
                ->on('papers')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('citations', function (Blueprint $table) {
            $table->dropForeign(['paper_id']);
            $table->dropColumn('paper_id');
        });
    }
};