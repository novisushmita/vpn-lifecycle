<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vps', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 64)->unique();

            // Sengaja index biasa, BUKAN unique: baris yang sudah di-soft-delete
            // harus melepaskan IP-nya supaya bisa dipakai VPS baru. MySQL/MariaDB
            // tidak punya partial unique index, jadi keunikan divalidasi di
            // FormRequest: Rule::unique('vps','alamat_ip')->whereNull('deleted_at')
            $table->string('alamat_ip', 45)->index();

            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);

            // Denormalisasi hasil ping terakhir supaya halaman daftar VPS
            // tidak perlu subquery ke vps_health_checks.
            $table->enum('status_terakhir', ['up', 'down', 'unknown'])->default('unknown');
            $table->decimal('rtt_terakhir_ms', 6, 2)->nullable();
            $table->unsignedTinyInteger('gagal_berturut')->default(0);
            $table->timestamp('dicek_pada')->nullable();

            // Penghapusan berantai sedang berjalan; VPS langsung disembunyikan
            // dari endpoint publik sebelum akun-akunnya selesai dibersihkan.
            $table->boolean('sedang_dihapus')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vps');
    }
};
