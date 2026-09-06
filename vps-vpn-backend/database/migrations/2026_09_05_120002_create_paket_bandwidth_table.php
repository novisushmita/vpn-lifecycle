<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paket_bandwidth', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 32)->unique();
            $table->string('rx_rate', 16);
            $table->string('tx_rate', 16);
            $table->string('ppp_profile', 64);
            $table->string('keterangan', 160)->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_bandwidth');
    }
};
