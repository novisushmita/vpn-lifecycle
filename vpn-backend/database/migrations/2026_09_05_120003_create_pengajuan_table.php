<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan', function (Blueprint $table) {
            $table->id();

            // Dibuat di dalam transaksi dengan penguncian baris; unique di sini
            // adalah jaring pengaman terakhir terhadap nomor kembar.
            $table->string('nomor', 24)->unique();

            $table->enum('jenis', ['baru', 'perpanjangan'])->default('baru');

            // FK ditambahkan di migration terpisah karena tabel akun_vpn
            // baru dibuat setelah tabel ini.
            $table->foreignId('akun_vpn_id')->nullable();

            $table->string('nama', 120);
            $table->string('identitas', 64);
            $table->string('instansi', 160);
            $table->string('email', 160);

            // restrictOnDelete: database MENOLAK penghapusan VPS yang masih
            // punya pengajuan. Penghapusan wajib lewat job berantai.
            $table->foreignId('vps_id')->constrained('vps')->restrictOnDelete();

            $table->string('keperluan', 120);
            $table->text('keperluan_detail')->nullable();
            $table->date('durasi_mulai');
            $table->date('durasi_selesai');

            $table->enum('status', ['diajukan', 'ditinjau', 'disetujui', 'ditolak'])
                ->default('diajukan')->index();
            $table->text('alasan_penolakan')->nullable();
            $table->foreignId('ditinjau_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditinjau_pada')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan');
    }
};
