<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vps_health_checks', function (Blueprint $table) {
            $table->id();

            // Telemetri murni, tidak punya objek pasangan di router,
            // jadi cascade di sini aman.
            $table->foreignId('vps_id')->constrained('vps')->cascadeOnDelete();

            $table->enum('sumber', ['router', 'laravel'])->default('router');
            $table->enum('status', ['up', 'down']);
            $table->decimal('rtt_avg_ms', 6, 2)->nullable();
            $table->unsignedTinyInteger('packet_loss')->default(0);
            $table->text('pesan_error')->nullable();
            $table->timestamp('checked_at');

            $table->index(['vps_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vps_health_checks');
    }
};
