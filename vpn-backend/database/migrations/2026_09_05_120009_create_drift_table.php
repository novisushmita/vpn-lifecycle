<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('akun_vpn_id')->nullable()->constrained('akun_vpn')->nullOnDelete();
            $table->foreignId('vps_id')->nullable()->constrained('vps')->nullOnDelete();

            $table->enum('jenis_objek', ['ppp_secret', 'address_list', 'firewall_rule']);
            $table->string('atribut', 64)->nullable();

            // yatim_di_router menangkap sisa penghapusan yang gagal separuh jalan.
            $table->enum('jenis_drift', ['nilai_beda', 'hilang_di_router', 'yatim_di_router']);

            $table->text('nilai_db')->nullable();
            $table->text('nilai_router')->nullable();

            $table->enum('status', ['terbuka', 'diselesaikan', 'diabaikan'])->default('terbuka')->index();
            $table->enum('resolusi', ['push', 'pull'])->nullable();

            $table->timestamp('terdeteksi_pada');
            $table->timestamp('diselesaikan_pada')->nullable();
            $table->foreignId('diselesaikan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drift');
    }
};
