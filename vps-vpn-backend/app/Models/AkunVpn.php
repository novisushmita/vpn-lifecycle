<?php

namespace App\Models;

use App\Enums\StatusAkun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AkunVpn extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'akun_vpn';

    protected $fillable = [
        'pengajuan_id', 'vps_id', 'paket_bandwidth_id', 'username', 'password',
        'ip_vpn', 'mulai_pada', 'selesai_pada',
    ];

    /** Password tidak pernah ikut serialisasi otomatis; harus diminta eksplisit + dicatat audit. */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'                   => 'encrypted',
            'status'                     => StatusAkun::class,
            'mulai_pada'                 => 'date',
            'selesai_pada'               => 'date',
            'disinkron_pada'             => 'datetime',
            'peringatan_h3_dikirim_pada' => 'datetime',
        ];
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('status', StatusAkun::Aktif);
    }

    /** Akun yang objeknya seharusnya masih ada di router — dipakai job sinkron drift. */
    public function scopeAdaDiRouter(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            StatusAkun::MenungguProvision->value,
            StatusAkun::Dihapus->value,
        ]);
    }

    public function pengajuan()
    {
        return $this->belongsTo(Pengajuan::class);
    }

    public function vps()
    {
        return $this->belongsTo(Vps::class);
    }

    public function paketBandwidth()
    {
        return $this->belongsTo(PaketBandwidth::class);
    }

    public function sesi()
    {
        return $this->hasMany(SesiVpn::class);
    }

    /** Sesi yang sedang berjalan, kalau ada. */
    public function sesiAktif()
    {
        return $this->hasOne(SesiVpn::class)->where('aktif', true);
    }

    public function operasi()
    {
        return $this->hasMany(OperasiRouter::class);
    }

    public function drift()
    {
        return $this->hasMany(Drift::class);
    }

    /** Total durasi pemakaian seluruh sesi, dalam detik. */
    public function totalDurasiPemakaian(): int
    {
        return (int) $this->sesi()->sum('durasi_detik');
    }
}
