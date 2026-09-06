<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pengajuan extends Model
{
    use HasFactory;

    protected $table = 'pengajuan';

    /**
     * Dibatasi ketat: ini satu-satunya model yang menerima input dari
     * endpoint publik tanpa autentikasi. Kolom nomor, status, dan seluruh
     * kolom peninjauan hanya boleh diisi sistem.
     */
    protected $fillable = [
        'jenis', 'akun_vpn_id', 'nama', 'identitas', 'instansi', 'email',
        'vps_id', 'keperluan', 'keperluan_detail', 'durasi_mulai', 'durasi_selesai',
    ];

    protected function casts(): array
    {
        return [
            'durasi_mulai'   => 'date',
            'durasi_selesai' => 'date',
            'ditinjau_pada'  => 'datetime',
        ];
    }

    public function vps()
    {
        return $this->belongsTo(Vps::class);
    }

    public function ditinjauOleh()
    {
        return $this->belongsTo(User::class, 'ditinjau_oleh');
    }

    /** Akun yang dibuat DARI pengajuan ini. */
    public function akun()
    {
        return $this->hasOne(AkunVpn::class);
    }

    /** Akun yang DIPERPANJANG oleh pengajuan ini (jenis = perpanjangan). */
    public function akunDiperpanjang()
    {
        return $this->belongsTo(AkunVpn::class, 'akun_vpn_id');
    }
}
