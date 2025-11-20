<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paper_files', function (Blueprint $t) {
            // Helpful composite index for common queries (paper detail page, ordering)
            $t->index(['paper_id', 'created_at'], 'paper_files_paper_created_idx');

            // Optional: prevent duplicates per paper when checksum is present.
            // MySQL allows multiple NULLs in a unique index (so missing checksum won’t block).
            $t->unique(['paper_id', 'checksum'], 'paper_files_paper_checksum_uniq');

            // Optional: if you want soft deletes for audit/restore
            if (!Schema::hasColumn('paper_files', 'deleted_at')) {
                $t->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('paper_files', function (Blueprint $t) {
            $t->dropIndex('paper_files_paper_created_idx');
            $t->dropUnique('paper_files_paper_checksum_uniq');
            if (Schema::hasColumn('paper_files', 'deleted_at')) {
                $t->dropSoftDeletes();
            }
        });
    }
};
