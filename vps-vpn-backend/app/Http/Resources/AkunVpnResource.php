<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Password TIDAK PERNAH disertakan di sini. Menampilkannya punya endpoint
 * terpisah yang mencatat audit 'lihat_kredensial'.
 */
class AkunVpnResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'username'        => $this->username,
            'vps_id'          => $this->vps_id,
            'paket_bandwidth_id' => $this->paket_bandwidth_id,
            'ip_vpn'          => $this->ip_vpn,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'mulai_pada'      => $this->mulai_pada?->toDateString(),
            'selesai_pada'    => $this->selesai_pada?->toDateString(),
            'sisa_hari'       => $this->selesai_pada ? (int) now()->startOfDay()->diffInDays($this->selesai_pada, false) : null,
            'pesan_error'     => $this->pesan_error,
            'disinkron_pada'  => $this->disinkron_pada?->toIso8601String(),
            'vps'             => new VpsAdminResource($this->whenLoaded('vps')),
            'paket'           => $this->whenLoaded('paketBandwidth', fn () => [
                'nama'       => $this->paketBandwidth->nama,
                'rate_limit' => $this->paketBandwidth->rateLimit(),
            ]),
            'pengajuan_nomor' => $this->whenLoaded('pengajuan', fn () => $this->pengajuan->nomor),
            'router'          => [
                'secret_id'       => $this->router_secret_id,
                'address_list_id' => $this->router_addresslist_id,
                'firewall_id'     => $this->router_firewall_id,
            ],
        ];
    }
}
