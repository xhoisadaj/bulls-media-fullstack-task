<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tab_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('header_row');
            $table->unsignedInteger('start_column');
            $table->unsignedInteger('end_column')->nullable();
            $table->json('header_names');
            $table->string('end_rule')->default('first_empty_row');
            $table->unsignedInteger('end_row')->nullable();
            $table->string('identity_mode')->default('content');
            $table->json('key_column_ids')->nullable();
            $table->string('apply_mode')->default('review');
            $table->string('hash_version')->nullable();
            $table->string('status')->default('ok');
            $table->text('status_reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('source_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_table_id')->constrained()->cascadeOnDelete();
            $table->string('header');
            $table->unsignedInteger('position')->nullable();
            $table->string('type')->default('string');
            $table->boolean('compare')->default(true);
            $table->boolean('is_present')->default(true);
            $table->timestamps();
            $table->unique(['source_table_id', 'header']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_columns');
        Schema::dropIfExists('source_tables');
    }
};
