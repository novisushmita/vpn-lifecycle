<?php

namespace Tests\Feature;

use App\Enums\StatusAkun;
use App\Jobs\HapusVpsJob;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Models\User;
use App\Models\Vps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Penghapusan VPS berantai (CLAUDE.md 4.4b).
 *
 * Yang diuji di sini adalah penjaganya, bukan panggilan ke router: admin harus
 * tahu berapa akun yang ikut terhapus sebelum menekan tombol, dan penghapusan
 * harus ditolak selama masih ada operasi router yang berjalan.
 */
class HapusVpsBerantaiTest extends TestCase
{
    use RefreshDatabase;

    private function vpsDenganAkun(int $jumlahAkun = 1): Vps
    {
        $vps   = Vps::create(['nama' => 'VPS-UJI', 'alamat_ip' => '10.10.10.50']);
        $paket = PaketBandwidth::create([
            'nama' => 'Uji', 'rx_rate' => '2M', 'tx_rate' => '2M', 'ppp_profile' => 'vpn-uji',
        ]);

        for ($i = 0; $i < $jumlahAkun; $i++) {
            $pengajuan = new Pengajuan([
                'nama' => "Pemohon {$i}", 'identitas' => '1', 'instansi' => 'X',
                'email' => "u{$i}@x.id", 'vps_id' => $vps->id, 'keperluan' => 'Uji',
                'durasi_mulai' => now()->toDateString(),
                'durasi_selesai' => now()->addDays(30)->toDateString(),
            ]);
            $pengajuan->nomor = "VPN-UJI-{$i}";
            $pengajuan->save();

            AkunVpn::create([
                'pengajuan_id' => $pengajuan->id, 'vps_id' => $vps->id,
                'paket_bandwidth_id' => $paket->id, 'username' => "uji{$i}",
                'password' => 'rahasia', 'ip_vpn' => '10.20.0.' . (10 + $i),
                'mulai_pada' => now()->toDateString(),
                'selesai_pada' => now()->addDays(30)->toDateString(),
            ])->forceFill(['status' => StatusAkun::Aktif])->save();
        }

        return $vps;
    }

    public function test_dampak_melaporkan_jumlah_akun_yang_ikut_terhapus(): void
    {
        $vps = $this->vpsDenganAkun(3);

        $res = $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/vps/{$vps->id}/dampak-hapus");

        $res->assertOk()
            ->assertJsonPath('jumlah_akun', 3)
            ->assertJsonPath('operasi_berjalan', 0)
            ->assertJsonCount(3, 'akun');
    }

    public function test_dampak_menghitung_pengajuan_yang_akan_dibatalkan(): void
    {
        $vps = $this->vpsDenganAkun(0);

        $p = new Pengajuan([
            'nama' => 'Menunggu', 'identitas' => '1', 'instansi' => 'X', 'email' => 'm@x.id',
            'vps_id' => $vps->id, 'keperluan' => 'Uji',
            'durasi_mulai' => now()->toDateString(),
            'durasi_selesai' => now()->addDays(10)->toDateString(),
        ]);
        $p->nomor = 'VPN-UJI-M';
        $p->save();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/vps/{$vps->id}/dampak-hapus")
            ->assertJsonPath('pengajuan_menunggu', 1);
    }

    public function test_penghapusan_diantrekan_bukan_dijalankan_di_request(): void
    {
        Queue::fake();
        $vps = $this->vpsDenganAkun(2);

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/admin/vps/{$vps->id}")
            ->assertStatus(202)
            ->assertJsonPath('jumlah_akun', 2);

        Queue::assertPushed(HapusVpsJob::class);
    }

    public function test_ditolak_selama_masih_ada_operasi_router_berjalan(): void
    {
        Queue::fake();
        $vps = $this->vpsDenganAkun(1);

        OperasiRouter::create([
            'jenis' => 'edit', 'akun_vpn_id' => $vps->akunVpn()->first()->id,
            'vps_id' => $vps->id, 'status' => 'berjalan',
        ]);

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/admin/vps/{$vps->id}")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_penghapusan_ganda_ditolak(): void
    {
        Queue::fake();
        $vps = $this->vpsDenganAkun(1);
        $vps->forceFill(['sedang_dihapus' => true])->save();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/admin/vps/{$vps->id}")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }
}
