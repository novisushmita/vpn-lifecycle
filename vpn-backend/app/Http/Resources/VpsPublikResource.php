<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk data VPS untuk konsumen PUBLIK (form pengajuan, tanpa autentikasi).
 *
 * TIDAK BOLEH memuat alamat_ip. Menyaring di frontend tidak cukup — data
 * tetap terkirim di response JSON dan terlihat di DevTools.
 */
class VpsPublikResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'nama'       => $this->nama,
            'keterangan' => $this->keterangan,
        ];
    }
}
