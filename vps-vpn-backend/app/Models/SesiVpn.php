<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesiVpn extends Model
{
    protected $table = 'sesi_vpn';

    protected $fillable = [
        'akun_vpn_id', 'username_snapshot', 'ip_vpn', 'ip_asal',
        'mulai_pada', 'selesai_pada', 'durasi_detik', 'bytes_in', 'bytes_out', 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'mulai_pada'   => 'datetime',
            'selesai_pada' => 'datetime',
            'aktif'        => 'boolean',
        ];
    }

    public function akunVpn()
    {
        return $this->belongsTo(AkunVpn::class);
    }
}
