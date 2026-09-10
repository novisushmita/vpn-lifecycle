<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PengajuanResource;
use App\Jobs\OperasiAkunJob;
use App\Jobs\ProvisionAkunJob;
use App\Models\OperasiRouter;
use App\Mail\PengajuanDitolak;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Services\PencatatAudit;
use App\Services\Vpn\PenerbitAkun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PengajuanController extends Controller
{
    public function index(Request $request)
    {
        $q = Pengajuan::with('vps')->latest('id');

        if ($status = $request->query('status')) {
            // 'menunggu' bukan nilai enum, melainkan gabungan dua status yang
            // dipakai angka ringkasan di dashboard. Tanpa ini, mengeklik angka
            // itu akan menampilkan daftar yang jumlahnya tidak cocok.
            $status === 'menunggu'
                ? $q->whereIn('status', ['diajukan', 'ditinjau'])
                : $q->where('status', $status);
        }

        if ($cari = $request->query('cari')) {
            $q->where(fn ($w) => $w->where('nomor', 'like', "%{$cari}%")
                ->orWhere('nama', 'like', "%{$cari}%")
                ->orWhere('instansi', 'like', "%{$cari}%")
                ->orWhereHas('vps', fn ($v) => $v->where('nama', 'like', "%{$cari}%")));
        }

        return PengajuanResource::collection($q->paginate(15));
    }

    public function show(Pengajuan $pengajuan)
    {
        return new PengajuanResource(
            $pengajuan->load('vps', 'ditinjauOleh', 'akun.paketBandwidth', 'akun.vps')
        );
    }

    /** Menyetujui pengajuan: terbitkan akun lalu antrikan provisioning. */
    public function setujui(Request $request, Pengajuan $pengajuan, PenerbitAkun $penerbit): JsonResponse
    {
        $data = $request->validate([
            'paket_bandwidth_id' => ['required', 'exists:paket_bandwidth,id'],
        ]);

        if (! in_array($pengajuan->status, ['diajukan', 'ditinjau'], true)) {
            return response()->json([
                'message' => "Pengajuan berstatus {$pengajuan->status} tidak dapat disetujui lagi.",
            ], 422);
        }

        // Perpanjangan tidak menerbitkan akun baru; ia memperpanjang akun
        // yang sudah ada dan menghidupkannya kembali bila sudah kedaluwarsa.
        if ($pengajuan->jenis === 'perpanjangan') {
            return $this->setujuiPerpanjangan($request, $pengajuan);
        }

        $paket = PaketBandwidth::findOrFail($data['paket_bandwidth_id']);
        $akun  = $penerbit->dariPengajuan($pengajuan, $paket, $request->user()->id);

        $pengajuan->forceFill([
            'status'        => 'disetujui',
            'ditinjau_oleh' => $request->user()->id,
            'ditinjau_pada' => now(),
        ])->save();

        ProvisionAkunJob::dispatch($akun->id, $request->user()->id);

        PencatatAudit::catat(
            'acc_pengajuan',
            "Menyetujui pengajuan {$pengajuan->nomor} untuk {$pengajuan->nama}, paket {$paket->nama}.",
            $pengajuan,
        );

        return response()->json([
            'message' => 'Pengajuan disetujui. Akun sedang diprovision ke router.',
            'akun_id' => $akun->id,
        ]);
    }

    private function setujuiPerpanjangan(Request $request, Pengajuan $pengajuan): JsonResponse
    {
        $akun = $pengajuan->akunDiperpanjang;

        if (! $akun) {
            return response()->json(['message' => 'Akun yang diperpanjang tidak ditemukan.'], 422);
        }

        abort_if($akun->operasi()->where('jenis', 'edit')->whereIn('status', ['antre', 'berjalan'])->exists(), 409, 'Edit akun masih berjalan. Tunggu hingga selesai.');

        if ($akun->operasi()->whereIn('status', ['antre', 'berjalan'])->exists()) {
            return response()->json([
                'message' => 'Masih ada operasi akun yang berjalan. Tunggu sampai selesai.',
            ], 409);
        }

        // Perpanjangan menghidupkan kembali akun di router bila sudah
        // kedaluwarsa, sehingga dijalankan lewat antrean seperti operasi
        // siklus hidup lainnya.
        $operasi = OperasiRouter::create([
            'jenis'       => 'extend',
            'akun_vpn_id' => $akun->id,
            'vps_id'      => $akun->vps_id,
            'status'      => 'antre',
            'payload'     => ['nomor' => $pengajuan->nomor],
            'dipicu_oleh' => $request->user()->id,
        ]);

        OperasiAkunJob::dispatch($operasi->id, $akun->id, 'extend', [
            'selesai_pada' => $pengajuan->durasi_selesai->toDateString(),
        ]);

        $pengajuan->forceFill([
            'status'        => 'disetujui',
            'ditinjau_oleh' => $request->user()->id,
            'ditinjau_pada' => now(),
        ])->save();

        PencatatAudit::catat(
            'acc_perpanjangan',
            "Menyetujui perpanjangan {$pengajuan->nomor}: akun {$akun->username} berlaku sampai {$pengajuan->durasi_selesai->toDateString()}.",
            $pengajuan,
        );

        return response()->json([
            'message'    => 'Perpanjangan disetujui. Akun sedang diperbarui di router.',
            'akun_id'    => $akun->id,
            'operasi_id' => $operasi->id,
        ], 202);
    }

    public function tolak(Request $request, Pengajuan $pengajuan): JsonResponse
    {
        $data = $request->validate([
            'alasan_penolakan' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        if (! in_array($pengajuan->status, ['diajukan', 'ditinjau'], true)) {
            return response()->json([
                'message' => "Pengajuan berstatus {$pengajuan->status} tidak dapat ditolak lagi.",
            ], 422);
        }

        $pengajuan->forceFill([
            'status'           => 'ditolak',
            'alasan_penolakan' => $data['alasan_penolakan'],
            'ditinjau_oleh'    => $request->user()->id,
            'ditinjau_pada'    => now(),
        ])->save();

        Mail::to($pengajuan->email)->queue(new PengajuanDitolak($pengajuan));

        PencatatAudit::catat('tolak_pengajuan', "Menolak pengajuan {$pengajuan->nomor}.", $pengajuan);

        return response()->json(['message' => 'Pengajuan ditolak.']);
    }
}
