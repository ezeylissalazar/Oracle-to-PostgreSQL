<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('history_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migrated_table');
            $table->date('fecha_migration');
            $table->integer('cantidad_migracion')->default(1);
            $table->string('tipo_migracion');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_migrations');
    }
};
