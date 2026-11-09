<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When to sync: one or more times a day, on chosen weekdays, in a timezone; for one file or all files.
     * A default "every day at 02:00, all files" schedule keeps the daily sync the task asks for.
     */
    public function up(): void
    {
        Schema::create('sync_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('times');
            $table->json('days');
            $table->string('timezone')->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });

        DB::table('sync_schedules')->insert([
            'spreadsheet_id' => null,
            'times' => json_encode([(string) config('sync.daily_at', '02:00')]),
            'days' => json_encode([1, 2, 3, 4, 5, 6, 7]),
            'timezone' => 'UTC',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_schedules');
    }
};
