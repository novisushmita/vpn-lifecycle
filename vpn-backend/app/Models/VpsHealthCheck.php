<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpsHealthCheck extends Model
{
    protected $table = 'vps_health_checks';

    public $timestamps = false;

    protected $fillable = [
        'vps_id', 'sumber', 'status', 'rtt_avg_ms', 'packet_loss', 'pesan_error', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'rtt_avg_ms' => 'float',
            'checked_at' => 'datetime',
        ];
    }

    public function vps()
    {
        return $this->belongsTo(Vps::class);
    }
}
