<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Buku besar seluruh operasi ke router.
 * Kolom durasi_ms adalah sumber data pengukuran Bab 4.
 */
class OperasiRouter extends Model
{
    protected $table = 'operasi_router';

    protected $fillable = [
        'jenis', 'akun_vpn_id', 'vps_id', 'status', 'payload', 'hasil',
        'pesan_error', 'percobaan', 'dimulai_pada', 'selesai_pada', 'durasi_ms', 'dipicu_oleh',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'hasil'        => 'array',
            'dimulai_pada' => 'datetime',
            'selesai_pada' => 'datetime',
        ];
    }

    public function akunVpn()
    {
        return $this->belongsTo(AkunVpn::class);
    }

    public function vps()
    {
        return $this->belongsTo(Vps::class);
    }

    public function dipicuOleh()
    {
        return $this->belongsTo(User::class, 'dipicu_oleh');
    }
}
