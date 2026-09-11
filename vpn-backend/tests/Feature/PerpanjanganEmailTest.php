<?php

namespace Tests\Feature;

use App\Enums\StatusAkun;
use App\Jobs\OperasiAkunJob;
use App\Mail\PengajuanDiterima;
use App\Mail\PerpanjanganDisetujui;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Models\User;
use App\Models\Vps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alur perpanjangan (keputusan #9) sebelumnya tidak mengirim email sama
 * sekali: submit tidak dikabari, dan ACC admin pun diam. Dua tes ini menutup
 * celah itu.
 */
class PerpanjanganEmailTest extends TestCase
{
    use RefreshDatabase;

    private function siapkanAkun(): AkunVpn
    {
        $vps   = Vps::create(['nama' => 'A', 'alamat_ip' => '10.10.10.11', 'aktif' => true]);
        $paket = PaketBandwidth::create(['nama' => 'Dasar', 'rx_rate' => '2M', 'tx_rate' => '2M', 'ppp_profile' => 'vpn-dasar', 'aktif' => true]);
        $p = new Pengajuan;
        $p->forceFill([
            'nomor' => 'VPN-TEST-1', 'nama' => 'Uji', 'identitas' => '1', 'instansi' => 'Lab',
            'email' => 'uji@example.test', 'vps_id' => $vps->id, 'keperluan' => 'Uji',
            'durasi_mulai' => '2026-09-01', 'durasi_selesai' => '2026-12-01',
        ])->save();
        $akun = new AkunVpn;
        $akun->forceFill([
            'pengajuan_id' => $p->id, 'vps_id' => $vps->id, 'paket_bandwidth_id' => $paket->id,
            'username' => 'uji', 'password' => 'rahasia', 'ip_vpn' => '10.20.0.10',
            'status' => StatusAkun::Aktif, 'mulai_pada' => '2026-09-01', 'selesai_pada' => '2026-12-01',
            'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3',
        ])->save();

        return $akun;
    }

    public function test_submit_perpanjangan_mengirim_email_diterima(): void
    {
        Mail::fake();
        $akun = $this->siapkanAkun();

        $this->postJson('/api/perpanjangan', [
            'nomor'          => $akun->pengajuan->nomor,
            'durasi_selesai' => '2027-01-01',
            'keperluan'      => 'Lanjut maintenance',
        ])->assertCreated();

        Mail::assertQueued(PengajuanDiterima::class, fn ($mail) => $mail->pengajuan->jenis === 'perpanjangan'
            && $mail->pengajuan->email === $akun->pengajuan->email);
    }

    public function test_perpanjangan_disetujui_mengirim_email_setelah_router_sukses(): void
    {
        Mail::fake();
        $akun = $this->siapkanAkun();

        // Akun masih aktif (belum kedaluwarsa), jadi perpanjang() hanya
        // mengubah tanggal di DB tanpa menyentuh router sama sekali —
        // OperasiAkunJob aman dijalankan langsung tanpa mock router.
        $operasi = OperasiRouter::create([
            'jenis' => 'extend', 'status' => 'antre', 'akun_vpn_id' => $akun->id,
        ]);

        (new OperasiAkunJob($operasi->id, $akun->id, 'extend', ['selesai_pada' => '2027-01-01']))->handle();

        $this->assertSame('2027-01-01', $akun->fresh()->selesai_pada->toDateString());
        $this->assertSame('sukses', $operasi->fresh()->status);
        Mail::assertQueued(PerpanjanganDisetujui::class, fn ($mail) => $mail->akun->id === $akun->id
            && $mail->akun->selesai_pada->toDateString() === '2027-01-01');
    }
}
