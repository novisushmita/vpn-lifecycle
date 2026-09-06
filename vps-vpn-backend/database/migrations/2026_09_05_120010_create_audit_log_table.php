<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Aksi wajib termasuk 'lihat_kredensial' — mitigasi penyimpanan
            // password secara reversible.
            $table->string('aksi', 64)->index();

            $table->string('objek_tipe', 64)->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->string('deskripsi', 255);
            $table->json('data_lama')->nullable();
            $table->json('data_baru')->nullable();
            $table->string('ip_admin', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['objek_tipe', 'objek_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
