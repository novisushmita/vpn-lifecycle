<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akun_vpn', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengajuan_id')->unique()->constrained('pengajuan')->restrictOnDelete();
            $table->foreignId('vps_id')->constrained('vps')->restrictOnDelete();
            $table->foreignId('paket_bandwidth_id')->constrained('paket_bandwidth')->restrictOnDelete();

            $table->string('username', 64)->unique();
            $table->text('password'); // cast 'encrypted' di model

            // Unique lintas SELURUH baris termasuk yang sudah dihapus: IP tidak
            // pernah didaur ulang, supaya aturan firewall lama yang mungkin
            // tertinggal tidak pernah cocok dengan akun baru.
            $table->string('ip_vpn', 45)->nullable()->unique();

            $table->enum('status', [
                'menunggu_provision', 'provisioning', 'aktif', 'dinonaktifkan',
                'gagal_provision', 'akan_kedaluwarsa', 'kedaluwarsa', 'dihapus',
            ])->default('menunggu_provision')->index();

            $table->date('mulai_pada');
            $table->date('selesai_pada')->index(); // dipindai scheduler harian

            // .id objek di RouterOS (mis. *1A). Tanpa ini, edit/hapus harus
            // mencari by-name dan langsung rusak begitu username diubah.
            $table->string('router_secret_id', 24)->nullable();
            $table->string('router_addresslist_id', 24)->nullable();
            $table->string('router_firewall_id', 24)->nullable();

            $table->timestamp('disinkron_pada')->nullable();
            $table->text('pesan_error')->nullable();
            $table->timestamp('peringatan_h3_dikirim_pada')->nullable();
            $table->enum('alasan_penghapusan', ['admin', 'kedaluwarsa', 'vps_dihapus'])->nullable();

            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akun_vpn');
    }
};
