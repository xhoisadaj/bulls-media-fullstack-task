<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('managed_table_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['source_table_id', 'managed_table_id']);
        });

        Schema::create('mapping_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapping_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_column_id')->constrained()->cascadeOnDelete();
            $table->string('target_column');
            $table->timestamps();
            $table->unique(['mapping_id', 'target_column']);
            $table->unique(['mapping_id', 'source_column_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_columns');
        Schema::dropIfExists('mappings');
        Schema::dropIfExists('managed_tables');
    }
};
