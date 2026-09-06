<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaketBandwidth extends Model
{
    use HasFactory;

    protected $table = 'paket_bandwidth';

    protected $fillable = ['nama', 'rx_rate', 'tx_rate', 'ppp_profile', 'keterangan', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /** Format rate-limit RouterOS: "rx/tx". */
    public function rateLimit(): string
    {
        return $this->rx_rate . '/' . $this->tx_rate;
    }

    public function akunVpn()
    {
        return $this->hasMany(AkunVpn::class);
    }
}
