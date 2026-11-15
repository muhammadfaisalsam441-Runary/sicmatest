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
        Schema::table('session_reschedules', function (Blueprint $table) {
            $table->string('mode', 20)->default('room')->after('original_room_id');
            $table->foreignId('reservation_id')->nullable()->change();
            $table->dateTime('target_start_at')->nullable()->after('reservation_id');
            $table->dateTime('target_end_at')->nullable()->after('target_start_at');
            $table->string('note', 255)->nullable()->after('status');
            $table->uuid('idempotency_key')->nullable()->after('created_by');

            $table->index(['mode', 'status', 'target_start_at']);
            $table->unique(['created_by', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_reschedules', function (Blueprint $table) {
            $table->dropUnique(['created_by', 'idempotency_key']);
            $table->dropIndex(['mode', 'status', 'target_start_at']);
            $table->dropColumn(['mode', 'target_start_at', 'target_end_at', 'note', 'idempotency_key']);
            $table->foreignId('reservation_id')->nullable(false)->change();
        });
    }
};
