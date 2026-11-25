<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** 既存データとアカウント削除後の原本に、所有者・権限を要求しない。 */
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
