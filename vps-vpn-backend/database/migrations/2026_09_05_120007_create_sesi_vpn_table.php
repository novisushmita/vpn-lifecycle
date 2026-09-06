<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesi_vpn', function (Blueprint $table) {
            $table->id();
            $table->foreignId('akun_vpn_id')->constrained('akun_vpn')->restrictOnDelete();

            // Salinan username saat sesi berlangsung. Tanpa ini, riwayat lama
            // ikut berubah begitu admin mengedit username akun.
            $table->string('username_snapshot', 64);

            $table->string('ip_vpn', 45);
            $table->string('ip_asal', 45)->nullable(); // caller-id
            $table->timestamp('mulai_pada');
            $table->timestamp('selesai_pada')->nullable();
            $table->unsignedInteger('durasi_detik')->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();

            $table->index(['akun_vpn_id', 'mulai_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_vpn');
    }
};
