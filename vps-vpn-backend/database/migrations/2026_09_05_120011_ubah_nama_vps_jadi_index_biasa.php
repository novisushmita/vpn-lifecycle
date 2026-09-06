<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `nama` disamakan perlakuannya dengan `alamat_ip`: index biasa, keunikan
     * divalidasi di aplikasi hanya terhadap baris yang belum dihapus.
     *
     * Tanpa ini, nama VPS yang sudah di-soft-delete tetap "memesan" namanya
     * selamanya sehingga VPS baru tidak bisa memakai nama yang sama.
     */
    public function up(): void
    {
        Schema::table('vps', function (Blueprint $table) {
            $table->dropUnique('vps_nama_unique');
            $table->index('nama');
        });
    }

    public function down(): void
    {
        Schema::table('vps', function (Blueprint $table) {
            $table->dropIndex(['nama']);
            $table->unique('nama');
        });
    }
};
