<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_table_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->char('fingerprint', 64);
            $table->string('hash_version')->nullable();
            $table->string('identity_value')->nullable();
            $table->unsignedInteger('sheet_row')->nullable();
            $table->boolean('keep_on_removal')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['source_table_id', 'fingerprint']);
            $table->index(['source_table_id', 'identity_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_records');
    }
};
