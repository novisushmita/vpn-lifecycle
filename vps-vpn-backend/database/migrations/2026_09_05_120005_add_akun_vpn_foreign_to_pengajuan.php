<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan', function (Blueprint $table) {
            // Pengajuan perpanjangan menunjuk akun yang diperpanjang.
            $table->foreign('akun_vpn_id')->references('id')->on('akun_vpn')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan', function (Blueprint $table) {
            $table->dropForeign(['akun_vpn_id']);
        });
    }
};
