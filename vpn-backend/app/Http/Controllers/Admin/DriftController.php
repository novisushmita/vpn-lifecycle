<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PeriksaDriftJob;
use App\Jobs\SelesaikanDriftJob;
use App\Models\Drift;
use App\Models\OperasiRouter;
use App\Services\PencatatAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Drift::with('akunVpn:id,username', 'vps:id,nama', 'diselesaikanOleh:id,name')->latest('id');

        if ($status = $request->query('status', 'terbuka')) {
            $q->where('status', $status);
        }

        return response()->json($q->limit(100)->get()->map(fn ($d) => [
            'id'              => $d->id,
            'akun'            => $d->akunVpn?->username,
            'akun_id'         => $d->akun_vpn_id,
            'vps'             => $d->vps?->nama,
            'jenis_objek'     => $d->jenis_objek,
            'atribut'         => $d->atribut,
            'jenis_drift'     => $d->jenis_drift,
            'nilai_db'        => $d->nilai_db,
            'nilai_router'    => $d->nilai_router,
            'status'          => $d->status,
            'resolusi'          => $d->resolusi,
            'terdeteksi_pada'   => $d->terdeteksi_pada?->toIso8601String(),
            'diselesaikan_pada' => $d->diselesaikan_pada?->toIso8601String(),
            'diselesaikan_oleh' => $d->diselesaikanOleh?->name,
        ]));
    }

    /**
     * Menjalankan pemeriksaan on-demand lewat antrean.
     *
     * Pemeriksaan memindai seluruh akun beserta tiga menu firewall sekaligus,
     * sehingga ini operasi paling berat terhadap router dan paling tidak boleh
     * berjalan di dalam request HTTP.
     */
    public function periksa(Request $request): JsonResponse
    {
        $berjalan = OperasiRouter::where('jenis', 'sinkron')
            ->whereIn('status', ['antre', 'berjalan'])
            ->exists();

        if ($berjalan) {
            return response()->json([
                'message' => 'Pemeriksaan sebelumnya masih berjalan. Tunggu sampai selesai.',
            ], 409);
        }

        PeriksaDriftJob::dispatch($request->user()->id);

        PencatatAudit::catat('periksa_drift', 'Menjalankan pemeriksaan router.');

        return response()->json(['message' => 'Pemeriksaan diantrekan dan sedang berjalan.'], 202);
    }

    /** Status pemeriksaan terakhir, dipantau antarmuka setelah menekan tombol. */
    public function status(): JsonResponse
    {
        $terakhir = OperasiRouter::where('jenis', 'sinkron')->latest('id')->first();

        return response()->json([
            'berjalan' => $terakhir && in_array($terakhir->status, ['antre', 'berjalan'], true),
            'status'   => $terakhir?->status,
            'hasil'    => $terakhir?->hasil,
            'pesan_error' => $terakhir?->pesan_error,
            'selesai_pada' => $terakhir?->selesai_pada?->toIso8601String(),
        ]);
    }

    public function selesaikan(Request $request, Drift $drift): JsonResponse
    {
        $data = $request->validate([
            'resolusi' => ['required', 'in:push,pull,abaikan'],
        ]);

        if ($data['resolusi'] === 'abaikan') {
            $drift->update(['status' => 'diabaikan', 'diselesaikan_pada' => now(), 'diselesaikan_oleh' => $request->user()->id]);
            PencatatAudit::catat('resolusi_drift', "Mengabaikan temuan drift #{$drift->id}.", $drift);

            return response()->json(['message' => 'Temuan diabaikan.']);
        }

        if ($drift->status !== 'terbuka') {
            return response()->json(['message' => 'Temuan ini sudah diselesaikan.'], 422);
        }

        $operasi = OperasiRouter::create([
            'jenis'       => 'sinkron',
            'akun_vpn_id' => $drift->akun_vpn_id,
            'vps_id'      => $drift->vps_id,
            'status'      => 'antre',
            'payload'     => ['drift_id' => $drift->id, 'resolusi' => $data['resolusi']],
            'dipicu_oleh' => $request->user()->id,
        ]);

        SelesaikanDriftJob::dispatch($operasi->id, $drift->id, $data['resolusi'], $request->user()->id);

        PencatatAudit::catat(
            'resolusi_drift',
            "Menyelesaikan temuan drift #{$drift->id} dengan {$data['resolusi']}.",
            $drift,
        );

        return response()->json([
            'message'    => 'Perintah diantrekan dan sedang dikerjakan.',
            'operasi_id' => $operasi->id,
        ], 202);
    }
}
