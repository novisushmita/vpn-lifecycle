<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'aksi', 'objek_tipe', 'objek_id', 'deskripsi',
        'data_lama', 'data_baru', 'ip_admin', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data_lama' => 'array',
            'data_baru' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
