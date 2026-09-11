<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusAkun;
use App\Http\Controllers\Controller;
use App\Http\Resources\AkunVpnResource;
use App\Jobs\OperasiAkunJob;
use App\Jobs\ProvisionAkunJob;
use App\Mail\KredensialVpn;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Services\PencatatAudit;
use App\Services\Pengaturan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\EditAkunRequest;
use App\Jobs\EditAkunJob;
use App\Services\Vpn\EditAkunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AkunVpnController extends Controller
{
    public function update(EditAkunRequest $request, AkunVpn $akun): JsonResponse
    {
        $data = $request->validated();
        $operasi = DB::transaction(function () use ($request, $akun, $data) {
            $akun = AkunVpn::whereKey($akun->id)->lockForUpdate()->firstOrFail();
            abort_unless(EditAkunService::boleh($akun), 422, 'Akun dengan status ini belum dapat diedit.');
            abort_if($akun->operasi()->whereIn('status', ['antre', 'berjalan'])->exists(), 409, 'Masih ada operasi akun yang berjalan. Tunggu hingga selesai.');
            $op = OperasiRouter::create([
                'jenis' => 'edit', 'akun_vpn_id' => $akun->id, 'vps_id' => $data['vps_id'], 'status' => 'antre',
                'dipicu_oleh' => $request->user()->id,
                'payload' => ['versi' => $akun->updated_at->toISOString(), 'ip_admin' => $request->ip(), 'password_diubah' => ! empty($data['password'])],
            ]);
            EditAkunJob::dispatch($op->id, $data);
            return $op;
        });

        return response()->json(['message' => 'Perubahan akun sedang diantrekan.', 'operasi_id' => $operasi->id], 202);
    }

    public function index(Request $request)
    {
        $q = AkunVpn::with(['vps', 'paketBandwidth', 'pengajuan'])->latest('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($cari = $request->query('cari')) {
            $q->where(fn ($w) => $w->where('username', 'like', "%{$cari}%")
                ->orWhere('ip_vpn', 'like', "%{$cari}%"));
        }

        return AkunVpnResource::collection($q->paginate(15));
    }

    public function show(AkunVpn $akun)
    {
        return new AkunVpnResource(
            $akun->load(['vps', 'paketBandwidth', 'pengajuan'])
        );
    }

    /**
     * Menampilkan kredensial. Endpoint terpisah dan SELALU dicatat audit —
     * ini mitigasi atas penyimpanan password secara reversible (batasan #13).
     */
    public function kredensial(AkunVpn $akun): JsonResponse
    {
        PencatatAudit::catat(
            'lihat_kredensial',
            "Melihat kredensial akun {$akun->username}.",
            $akun,
        );

        return response()->json([
            'username' => $akun->username,
            'password' => $akun->password,
            'ip_vpn'   => $akun->ip_vpn,
            'server'   => Pengaturan::alamatServerVpn(),
        ]);
    }

    /**
     * Kirim ulang email kredensial (mis. pemohon bilang emailnya hilang/tidak
     * masuk). Pakai mailable & audit yang sama dengan pengiriman pertama di
     * ProvisionAkunJob — cuma dipicu manual oleh admin.
     */
    public function kirimUlangKredensial(AkunVpn $akun): JsonResponse
    {
        abort_unless(
            in_array($akun->status, [StatusAkun::Aktif, StatusAkun::AkanKedaluwarsa, StatusAkun::Dinonaktifkan], true),
            422,
            'Akun ini belum pernah terpasang di router, belum ada kredensial untuk dikirim.',
        );

        $akun->loadMissing('pengajuan');

        Mail::to($akun->pengajuan->email)->queue(new KredensialVpn(
            $akun,
            $akun->password,
            Pengaturan::alamatServerVpn(),
            (string) config('routeros.ipsec_psk'),
        ));

        PencatatAudit::catat(
            'kirim_ulang_kredensial',
            "Mengirim ulang email kredensial akun {$akun->username} ke {$akun->pengajuan->email}.",
            $akun,
        );

        return response()->json(['message' => "Email kredensial dikirim ulang ke {$akun->pengajuan->email}."]);
    }

    public function nonaktifkan(Request $request, AkunVpn $akun): JsonResponse
    {
        return $this->antrekan($request, $akun, 'disable', 'disable_akun',
            "Menonaktifkan akun {$akun->username}.");
    }

    public function aktifkan(Request $request, AkunVpn $akun): JsonResponse
    {
        return $this->antrekan($request, $akun, 'enable', 'enable_akun',
            "Mengaktifkan kembali akun {$akun->username}.");
    }

    public function destroy(Request $request, AkunVpn $akun): JsonResponse
    {
        return $this->antrekan($request, $akun, 'hapus', 'hapus_akun',
            "Menghapus akun {$akun->username}.", ['alasan' => 'admin']);
    }

    /** Mengulang provisioning yang gagal. */
    public function ulangiProvision(Request $request, AkunVpn $akun): JsonResponse
    {
        if ($akun->status !== StatusAkun::GagalProvision) {
            return response()->json([
                'message' => 'Hanya akun berstatus gagal_provision yang dapat diulang.',
            ], 422);
        }

        ProvisionAkunJob::dispatch($akun->id, $request->user()->id);

        PencatatAudit::catat('ulangi_provision', "Mengulang provisioning akun {$akun->username}.", $akun);

        return response()->json(['message' => 'Provisioning diantrikan ulang.']);
    }

    /** Riwayat sesi koneksi — metadata saja, tanpa isi komunikasi. */
    public function sesi(AkunVpn $akun)
    {
        return response()->json(
            $akun->sesi()->latest('mulai_pada')->limit(50)->get()->map(fn ($s) => [
                'id'           => $s->id,
                'username'     => $s->username_snapshot,
                'ip_vpn'       => $s->ip_vpn,
                'ip_asal'      => $s->ip_asal,
                'mulai_pada'   => $s->mulai_pada?->toIso8601String(),
                'selesai_pada' => $s->selesai_pada?->toIso8601String(),
                'durasi_detik' => $s->durasi_detik,
                'bytes_in'     => $s->bytes_in,
                'bytes_out'    => $s->bytes_out,
                'aktif'        => $s->aktif,
            ])
        );
    }

    /** Riwayat operasi router untuk satu akun — jejak siklus hidupnya. */
    public function operasi(AkunVpn $akun)
    {
        return response()->json(
            OperasiRouter::where('akun_vpn_id', $akun->id)
                ->latest('id')->limit(50)
                ->get(['id', 'jenis', 'status', 'pesan_error', 'durasi_ms', 'created_at'])
        );
    }

    /**
     * Mengantrekan satu operasi siklus hidup.
     *
     * Baris operasi_router dibuat lebih dulu sebagai penanda yang dapat
     * dipantau antarmuka, lalu job memperbarui baris yang sama. Operasi ke
     * router tidak pernah dijalankan di dalam request HTTP (CLAUDE.md 6.2).
     */
    private function antrekan(
        Request $request,
        AkunVpn $akun,
        string $jenis,
        string $aksiAudit,
        string $deskripsi,
        array $data = [],
    ): JsonResponse {
        $bentrok = $akun->operasi()->whereIn('status', ['antre', 'berjalan'])->exists();

        if ($bentrok) {
            return response()->json([
                'message' => 'Masih ada operasi akun yang berjalan. Tunggu sampai selesai.',
            ], 409);
        }

        $operasi = OperasiRouter::create([
            'jenis'       => $jenis,
            'akun_vpn_id' => $akun->id,
            'vps_id'      => $akun->vps_id,
            'status'      => 'antre',
            'payload'     => ['username' => $akun->username, 'ip_admin' => $request->ip()] + $data,
            'dipicu_oleh' => $request->user()->id,
        ]);

        OperasiAkunJob::dispatch($operasi->id, $akun->id, $jenis, $data);

        PencatatAudit::catat($aksiAudit, $deskripsi, $akun);

        return response()->json([
            'message'    => 'Perintah diantrekan dan sedang dikerjakan.',
            'operasi_id' => $operasi->id,
        ], 202);
    }
}
