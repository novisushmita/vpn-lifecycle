<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VpsAdminResource extends JsonResource
{
    public function toArray($request): array
    {
        $ping = $this->resource->pingTerakhir;

        return [
            'id'              => $this->id,
            'nama'            => $this->nama,
            'alamat_ip'       => $this->alamat_ip,
            'keterangan'      => $this->keterangan,
            'aktif'           => $this->aktif,
            'sedang_dihapus'  => $this->sedang_dihapus,
            // DUA HAL BERBEDA, jangan tertukar:
            //   status_terakhir   = status resmi VPS, sudah melewati flap
            //                       protection (baru DOWN setelah 3 kegagalan
            //                       berturut-turut). Ini yang dipakai badge,
            //                       ringkasan dashboard, dan pelaporan.
            //   ping_terakhir.status = hasil MENTAH satu pemeriksaan terakhir,
            //                       tanpa flap protection. Hanya untuk teks
            //                       detail per baris.
            // Memakai ping_terakhir.status untuk menentukan status VPS akan
            // memunculkan alarm palsu dari satu paket yang hilang.
            'status_terakhir' => $this->status_terakhir,
            'rtt_terakhir_ms' => $this->rtt_terakhir_ms,
            'gagal_berturut'  => $this->gagal_berturut,
            'dicek_pada'      => $this->dicek_pada?->toIso8601String(),
            'ping_terakhir'   => $ping ? [
                'status' => $ping->status,
                'packet_loss' => $ping->packet_loss,
                'rtt_avg_ms' => $ping->rtt_avg_ms,
                'checked_at' => $ping->checked_at?->toIso8601String(),
            ] : null,
            'jumlah_akun'     => $this->whenCounted('akunVpn'),
        ];
    }
}
