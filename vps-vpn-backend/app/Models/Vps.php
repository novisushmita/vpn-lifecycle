<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vps extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vps';

    protected $fillable = ['nama', 'alamat_ip', 'keterangan', 'aktif'];

    /**
     * Nilai default disetel di model, bukan hanya di kolom basis data.
     * Objek hasil create() tidak memuat default dari basis data, sehingga
     * response API akan berisi null bila tidak dinyatakan di sini.
     */
    protected $attributes = [
        'aktif'           => true,
        'sedang_dihapus'  => false,
        'status_terakhir' => 'unknown',
        'gagal_berturut'  => 0,
    ];

    protected function casts(): array
    {
        return [
            'aktif'           => 'boolean',
            'sedang_dihapus'  => 'boolean',
            'rtt_terakhir_ms' => 'float',
            'dicek_pada'      => 'datetime',
        ];
    }

    /** VPS yang boleh tampil di form pengajuan publik (CLAUDE.md 4.4). */
    public function scopePublik(Builder $q): Builder
    {
        return $q->where('aktif', true)->where('sedang_dihapus', false);
    }

    public function akunVpn()
    {
        return $this->hasMany(AkunVpn::class);
    }

    public function pengajuan()
    {
        return $this->hasMany(Pengajuan::class);
    }

    public function healthChecks()
    {
        return $this->hasMany(VpsHealthCheck::class);
    }

    public function pingTerakhir()
    {
        return $this->hasOne(VpsHealthCheck::class)->ofMany([
            'checked_at' => 'max',
            'id' => 'max',
        ], fn ($query) => $query->where('sumber', 'router'));
    }
}
