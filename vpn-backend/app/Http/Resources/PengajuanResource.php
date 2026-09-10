<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PengajuanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'nomor'            => $this->nomor,
            'jenis'            => $this->jenis,
            'nama'             => $this->nama,
            'identitas'        => $this->identitas,
            'instansi'         => $this->instansi,
            'email'            => $this->email,
            'keperluan'        => $this->keperluan,
            'keperluan_detail' => $this->keperluan_detail,
            'durasi_mulai'     => $this->durasi_mulai?->toDateString(),
            'durasi_selesai'   => $this->durasi_selesai?->toDateString(),
            'status'           => $this->status,
            'alasan_penolakan' => $this->alasan_penolakan,
            'ditinjau_pada'    => $this->ditinjau_pada?->toIso8601String(),
            'dibuat_pada'      => $this->created_at?->toIso8601String(),
            'vps'              => new VpsAdminResource($this->whenLoaded('vps')),
            'ditinjau_oleh'    => $this->whenLoaded('ditinjauOleh', fn () => $this->ditinjauOleh?->name),
            'akun'             => new AkunVpnResource($this->whenLoaded('akun')),
        ];
    }
}
