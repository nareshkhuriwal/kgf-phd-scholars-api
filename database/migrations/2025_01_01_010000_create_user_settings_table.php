<?php
// database/migrations/2025_01_01_020000_create_user_settings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();

            $table->string('citation_style', 120)->default('chicago-note-bibliography-short');
            $table->string('note_format', 40)->default('markdown+richtext');
            $table->string('language', 10)->default('en-US');

            $table->boolean('quick_copy_as_html')->default(false);
            $table->boolean('include_urls')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
