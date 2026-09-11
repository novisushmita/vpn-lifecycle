<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Drift extends Model
{
    protected $table = 'drift';

    protected $fillable = [
        'akun_vpn_id', 'vps_id', 'jenis_objek', 'atribut', 'jenis_drift',
        'nilai_db', 'nilai_router', 'status', 'resolusi',
        'terdeteksi_pada', 'diselesaikan_pada', 'diselesaikan_oleh',
    ];

    protected function casts(): array
    {
        return [
            'terdeteksi_pada'   => 'datetime',
            'diselesaikan_pada' => 'datetime',
        ];
    }

    public function scopeTerbuka(Builder $q): Builder
    {
        return $q->where('status', 'terbuka');
    }

    public function akunVpn()
    {
        return $this->belongsTo(AkunVpn::class);
    }

    public function vps()
    {
        return $this->belongsTo(Vps::class);
    }

    public function diselesaikanOleh()
    {
        return $this->belongsTo(User::class, 'diselesaikan_oleh');
    }
}
