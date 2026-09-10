<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buku besar seluruh operasi ke router. Kolom durasi_ms adalah sumber
        // data pengukuran Bab 4 dan terisi otomatis sejak percobaan pertama.
        Schema::create('operasi_router', function (Blueprint $table) {
            $table->id();

            $table->enum('jenis', [
                'provision', 'edit', 'disable', 'enable', 'hapus', 'hapus_vps',
                'expire', 'extend', 'ping', 'sinkron',
            ])->index();

            $table->foreignId('akun_vpn_id')->nullable()->constrained('akun_vpn')->nullOnDelete();
            $table->foreignId('vps_id')->nullable()->constrained('vps')->nullOnDelete();

            $table->enum('status', ['antre', 'berjalan', 'sukses', 'gagal'])->default('antre')->index();
            $table->json('payload')->nullable();
            $table->json('hasil')->nullable();
            $table->text('pesan_error')->nullable();
            $table->unsignedTinyInteger('percobaan')->default(0);
            $table->timestamp('dimulai_pada')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->unsignedInteger('durasi_ms')->nullable();

            $table->foreignId('dipicu_oleh')->nullable()->constrained('users')->nullOnDelete(); // null = scheduler
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operasi_router');
    }
};
