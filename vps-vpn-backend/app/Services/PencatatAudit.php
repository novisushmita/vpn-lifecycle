<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/** Jejak tindakan admin. Wajib dipanggil untuk setiap aksi yang mengubah state. */
class PencatatAudit
{
    public static function catat(
        string $aksi,
        string $deskripsi,
        ?Model $objek = null,
        ?array $dataLama = null,
        ?array $dataBaru = null,
    ): void {
        AuditLog::create([
            'user_id'    => auth()->id(),
            'aksi'       => $aksi,
            'objek_tipe' => $objek ? class_basename($objek) : null,
            'objek_id'   => $objek?->getKey(),
            'deskripsi'  => $deskripsi,
            'data_lama'  => $dataLama,
            'data_baru'  => $dataBaru,
            'ip_admin'   => Request::ip() ?? '0.0.0.0',
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
