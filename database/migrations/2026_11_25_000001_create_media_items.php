<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** 容量競合を防ぐロック行と、原本の表示情報を作る。 */
    public function up(): void
    {
        Schema::create('media_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('media_locks')->insert(['id' => 1]);

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('media_items');
            $table->enum('kind', ['folder', 'file']);
            $table->string('name');
            $table->string('storage_key', 48)->nullable()->unique();
            $table->string('mime', 48)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['parent_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('media_locks');
    }
};
