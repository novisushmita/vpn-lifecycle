<?php

namespace App\Http\Controllers\Publik;

use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPengajuanRequest;
use App\Mail\PengajuanDiterima;
use App\Models\Pengajuan;
use App\Services\Vpn\NomorPengajuan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class PengajuanController extends Controller
{
    public function store(SimpanPengajuanRequest $request, NomorPengajuan $nomor): JsonResponse
    {
        $pengajuan = new Pengajuan($request->validated());
        $pengajuan->nomor  = $nomor->berikutnya();
        $pengajuan->status = 'diajukan';
        $pengajuan->save();

        // Antrean, bukan blocking: kegagalan SMTP tidak boleh membuat
        // pengajuan yang sudah tersimpan tampak gagal bagi pemohon.
        Mail::to($pengajuan->email)->queue(new PengajuanDiterima($pengajuan));

        return response()->json(['nomor' => $pengajuan->nomor], 201);
    }

    /**
     * Cek status berdasarkan nomor pengajuan.
     *
     * Sengaja TIDAK menampilkan kredensial VPN: endpoint ini tanpa autentikasi,
     * sehingga siapa pun yang tahu nomor pengajuan bisa membacanya.
     * Kredensial dikirim lewat email ke alamat pemohon.
     */
    public function show(string $nomor): JsonResponse
    {
        $pengajuan = Pengajuan::with('vps', 'akun')->where('nomor', $nomor)->first();

        if (! $pengajuan) {
            return response()->json(['message' => 'Nomor pengajuan tidak ditemukan.'], 404);
        }

        $akun = $pengajuan->akun;

        // Baris akun sudah ada sejak pengajuan disetujui, jauh sebelum objeknya
        // terpasang di router. Yang menentukan kredensial sudah terkirim adalah
        // status akun, bukan keberadaan barisnya.
        $siap = $akun && in_array($akun->status, [
            StatusAkun::Aktif, StatusAkun::AkanKedaluwarsa,
            StatusAkun::Dinonaktifkan, StatusAkun::Kedaluwarsa,
        ], true);

        return response()->json([
            'nomor'            => $pengajuan->nomor,
            'nama'             => $pengajuan->nama,
            'instansi'         => $pengajuan->instansi,
            'vps'              => $pengajuan->vps?->nama,
            'status'           => $pengajuan->status,
            'catatan'          => $this->catatan($pengajuan, $siap),
            'diajukan_pada'    => $pengajuan->created_at?->toDateString(),
            'berlaku_sampai'   => $akun?->selesai_pada?->toDateString(),
        ]);
    }

    private function catatan(Pengajuan $p, bool $kredensialTerkirim): string
    {
        return match ($p->status) {
            'diajukan'  => 'Pengajuan diterima dan menunggu ditinjau administrator.',
            'ditinjau'  => 'Pengajuan sedang ditinjau administrator.',
            'ditolak'   => $p->alasan_penolakan ?: 'Pengajuan ditolak.',
            'disetujui' => $kredensialTerkirim
                ? 'Disetujui. Kredensial VPN telah dikirim ke email Anda.'
                : 'Disetujui. Akun VPN sedang disiapkan, mohon tunggu. Kredensial dikirim setelah akun siap dipakai.',
            default     => '-',
        };
    }
}
