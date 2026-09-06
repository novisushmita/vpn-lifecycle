<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penyimpanan kunci-nilai untuk pengaturan yang boleh diubah admin lewat
     * web. Nilai bawaannya tetap di config/pengaturan.php; tabel ini hanya
     * menyimpan yang benar-benar diubah, sehingga menghapus satu baris berarti
     * kembali ke bawaan.
     */
    public function up(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->string('kunci', 64)->primary();
            $table->text('nilai')->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
