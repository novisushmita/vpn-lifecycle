<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PencatatAudit;
use App\Services\Pengaturan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PengaturanController extends Controller
{
    public function index(): JsonResponse
    {
        $nilai = Pengaturan::semua();

        return response()->json([
            'daftar' => collect(config('pengaturan.bawaan'))->map(fn ($meta, $kunci) => [
                'kunci'   => $kunci,
                'label'   => $meta['label'],
                'bantuan' => $meta['bantuan'],
                'tipe'    => $meta['tipe'],
                'min'     => $meta['min'] ?? null,
                'max'     => $meta['max'] ?? null,
                'nilai'   => $nilai[$kunci] ?? $meta['nilai'],
                'bawaan'  => $meta['nilai'],
            ])->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $bawaan = config('pengaturan.bawaan');

        $data = $request->validate([
            'pengaturan'         => ['required', 'array'],
            'pengaturan.*.kunci' => ['required', Rule::in(array_keys($bawaan))],
            'pengaturan.*.nilai' => ['present'],
        ]);

        $diubah = [];

        foreach ($data['pengaturan'] as $baris) {
            $kunci = $baris['kunci'];
            $meta  = $bawaan[$kunci];
            $nilai = $baris['nilai'];

            if ($meta['tipe'] === 'angka') {
                $n = (int) $nilai;
                $min = $meta['min'] ?? 1;
                $max = $meta['max'] ?? PHP_INT_MAX;

                if ($n < $min || $n > $max) {
                    return response()->json([
                        'message' => "{$meta['label']} harus antara {$min} dan {$max}.",
                    ], 422);
                }
                $nilai = $n;
            }

            if ((string) $nilai === (string) Pengaturan::ambil($kunci)) {
                continue; // tidak berubah, tidak perlu dicatat
            }

            Pengaturan::simpan($kunci, $nilai, $request->user()->id);
            $diubah[$meta['label']] = $nilai;
        }

        if ($diubah === []) {
            return response()->json(['message' => 'Tidak ada perubahan.']);
        }

        PencatatAudit::catat(
            'ubah_pengaturan',
            'Mengubah pengaturan: ' . implode(', ', array_keys($diubah)) . '.',
            null,
            null,
            $diubah,
        );

        return response()->json([
            'message'       => 'Pengaturan disimpan.',
            'jumlah_diubah' => count($diubah),
        ]);
    }
}
