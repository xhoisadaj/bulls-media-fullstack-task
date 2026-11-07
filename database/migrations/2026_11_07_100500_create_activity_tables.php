<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->string('credential_used')->nullable();
            $table->string('status');
            $table->json('counts')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->json('changed_columns')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->string('identity_value')->nullable();
            $table->unsignedInteger('sheet_row')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['source_table_id', 'status']);
        });

        Schema::create('structure_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_table_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('message', 500);
            $table->json('details')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        Schema::create('schema_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_table_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('column_name')->nullable();
            $table->string('column_type')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_histories');
        Schema::dropIfExists('structure_alerts');
        Schema::dropIfExists('pending_changes');
        Schema::dropIfExists('sync_runs');
    }
};
