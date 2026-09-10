<?php

namespace App\Http\Controllers\Publik;

use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Models\Pengajuan;
use App\Services\Vpn\NomorPengajuan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permintaan perpanjangan masa akses.
 *
 * Pemohon tidak punya login, jadi identifikasinya memakai nomor pengajuan
 * lama. Endpoint ini tidak pernah menampilkan kredensial apa pun.
 */
class PerpanjanganController extends Controller
{
    public function store(Request $request, NomorPengajuan $nomor): JsonResponse
    {
        $data = $request->validate([
            'nomor'          => ['required', 'string', 'max:24'],
            'durasi_selesai' => ['required', 'date', 'after:today'],
            'keperluan'      => ['required', 'string', 'max:120'],
        ]);

        $lama = Pengajuan::with('akun')->where('nomor', $data['nomor'])->first();

        if (! $lama || ! $lama->akun) {
            return response()->json([
                'message' => 'Nomor pengajuan tidak ditemukan atau belum memiliki akun VPN.',
            ], 404);
        }

        $akun = $lama->akun;

        if ($akun->status === StatusAkun::Dihapus) {
            return response()->json([
                'message' => 'Akun sudah dihapus. Silakan ajukan akses baru.',
            ], 422);
        }

        if ($data['durasi_selesai'] <= $akun->selesai_pada->toDateString()) {
            return response()->json([
                'message' => 'Tanggal perpanjangan harus setelah masa berlaku saat ini ('
                    . $akun->selesai_pada->toDateString() . ').',
            ], 422);
        }

        $adaMenunggu = Pengajuan::where('akun_vpn_id', $akun->id)
            ->where('jenis', 'perpanjangan')
            ->whereIn('status', ['diajukan', 'ditinjau'])
            ->exists();

        if ($adaMenunggu) {
            return response()->json([
                'message' => 'Masih ada permintaan perpanjangan yang menunggu ditinjau.',
            ], 422);
        }

        $baru = new Pengajuan([
            'jenis'            => 'perpanjangan',
            'akun_vpn_id'      => $akun->id,
            'nama'             => $lama->nama,
            'identitas'        => $lama->identitas,
            'instansi'         => $lama->instansi,
            'email'            => $lama->email,
            'vps_id'           => $lama->vps_id,
            'keperluan'        => $data['keperluan'],
            'durasi_mulai'     => $akun->selesai_pada->toDateString(),
            'durasi_selesai'   => $data['durasi_selesai'],
        ]);
        $baru->nomor  = $nomor->berikutnya();
        $baru->status = 'diajukan';
        $baru->save();

        return response()->json(['nomor' => $baru->nomor], 201);
    }
}
