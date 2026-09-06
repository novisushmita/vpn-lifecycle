<?php

namespace Tests\Feature;

use App\Enums\StatusAkun;
use App\Jobs\EditAkunJob;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Models\User;
use App\Models\Vps;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Services\Vpn\EditAkunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EditAkunTest extends TestCase
{
    use RefreshDatabase;

    private function siapkan(): array
    {
        $user = User::factory()->create();
        $vps = Vps::create(['nama' => 'A', 'alamat_ip' => '10.10.10.11', 'aktif' => true]);
        $baru = Vps::create(['nama' => 'B', 'alamat_ip' => '10.10.10.12', 'aktif' => true]);
        $paket = PaketBandwidth::create(['nama' => 'Dasar', 'rx_rate' => '2M', 'tx_rate' => '2M', 'ppp_profile' => 'vpn-dasar', 'aktif' => true]);
        $paketBaru = PaketBandwidth::create(['nama' => 'Standar', 'rx_rate' => '5M', 'tx_rate' => '5M', 'ppp_profile' => 'vpn-standar', 'aktif' => true]);
        $p = new Pengajuan;
        $p->forceFill(['nomor' => 'VPN-TEST-1', 'nama' => 'Uji', 'identitas' => '1', 'instansi' => 'Lab', 'email' => 'uji@example.test', 'vps_id' => $vps->id, 'keperluan' => 'Uji', 'durasi_mulai' => '2026-09-01', 'durasi_selesai' => '2026-12-01'])->save();
        $akun = new AkunVpn;
        $akun->forceFill(['pengajuan_id' => $p->id, 'vps_id' => $vps->id, 'paket_bandwidth_id' => $paket->id, 'username' => 'lama', 'password' => 'password-lama', 'ip_vpn' => '10.20.0.10', 'status' => StatusAkun::Aktif, 'mulai_pada' => '2026-09-01', 'selesai_pada' => '2026-12-01', 'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3'])->save();
        $data = ['username' => 'baru', 'password' => 'password-baru', 'vps_id' => $baru->id, 'paket_bandwidth_id' => $paketBaru->id, 'putus_sesi' => false];

        return [$user, $akun, $data];
    }

    private function operasi(User $user, AkunVpn $akun): OperasiRouter
    {
        return OperasiRouter::create(['jenis' => 'edit', 'status' => 'antre', 'akun_vpn_id' => $akun->id, 'dipicu_oleh' => $user->id, 'payload' => ['versi' => $akun->updated_at->toISOString()]]);
    }

    private function router(AkunVpn $akun, bool $gagal = false): RouterOsClient
    {
        $router = $this->mock(RouterOsClient::class);
        $tanda = config('routeros.comment_prefix').':akun:'.$akun->id;
        $router->shouldReceive('ambil')->with('ppp/secret', '*1')->andReturn(['comment' => $tanda, 'name' => 'lama', 'password' => '*****', 'profile' => 'vpn-dasar']);
        $router->shouldReceive('ambil')->with('ip/firewall/address-list', '*2')->andReturn(['comment' => $tanda, 'list' => 'lama-list', 'address' => '10.10.10.11']);
        $router->shouldReceive('ambil')->with('ip/firewall/filter', '*3')->andReturn(['comment' => $tanda, 'chain' => 'forward', 'action' => 'accept', 'src-address' => '10.20.0.10', 'dst-address-list' => 'lama-list']);
        if ($gagal) {
            $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['name' => 'baru', 'profile' => 'vpn-standar', 'password' => 'password-baru'])->andReturn([]);
            $router->shouldReceive('ubah')->once()->with('ip/firewall/address-list', '*2', \Mockery::on(fn ($v) => $v['address'] === '10.10.10.12'))->andThrow(new RouterOsException('input password-baru'));
            $router->shouldReceive('ubah')->once()->with('ip/firewall/address-list', '*2', ['list' => 'lama-list', 'address' => '10.10.10.11'])->andReturn([]);
            $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['name' => 'lama', 'profile' => 'vpn-dasar', 'password' => 'password-lama'])->andReturn([]);
        } else {
            $router->shouldReceive('ubah')->times(3)->andReturn([]);
        }

        return $router;
    }

    public function test_edit_diantrekan_dan_tidak_langsung_mengubah_akun(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        Queue::fake();
        $this->actingAs($user)->putJson('/api/admin/akun/'.$akun->id, $data)->assertAccepted();
        Queue::assertPushed(EditAkunJob::class);
        $this->assertSame('lama', $akun->fresh()->username);
        $this->assertStringNotContainsString('password-baru', json_encode(OperasiRouter::first()->payload));
        $this->actingAs($user)->putJson('/api/admin/akun/'.$akun->id, $data)->assertStatus(409);
    }

    public function test_edit_sukses_dan_aman_dijalankan_ulang(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        $op = $this->operasi($user, $akun);
        $service = new EditAkunService($this->router($akun));
        $service->jalankan($op->id, $data);
        $service->jalankan($op->id, $data);
        $akun->refresh();
        $this->assertSame('baru', $akun->username);
        $this->assertSame('password-baru', $akun->password);
        $this->assertSame($data['vps_id'], $akun->vps_id);
        $this->assertSame($data['paket_bandwidth_id'], $akun->paket_bandwidth_id);
        $this->assertSame(StatusAkun::Aktif, $akun->status);
        $this->assertSame('sukses', $op->fresh()->status);
        $this->assertStringNotContainsString('password-baru', DB::table('akun_vpn')->value('password'));
        $this->assertStringNotContainsString('password-baru', json_encode(DB::table('audit_log')->get()));
    }

    public function test_gagal_router_memulihkan_objek_dan_database_tetap(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        $op = $this->operasi($user, $akun);
        (new EditAkunService($this->router($akun, true)))->jalankan($op->id, $data);
        $this->assertSame('lama', $akun->fresh()->username);
        $this->assertSame('password-lama', $akun->fresh()->password);
        $this->assertSame('gagal', $op->fresh()->status);
        $this->assertStringNotContainsString('password-baru', $op->fresh()->pesan_error);
    }

    public function test_password_kosong_dipertahankan_dan_sesi_diputus_sesuai_pilihan(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        $data['password'] = null;
        $data['putus_sesi'] = true;
        $op = $this->operasi($user, $akun);
        $router = $this->router($akun);
        $router->shouldReceive('daftar')->with('ppp/active')->once()->andReturn([['name' => 'lama', '.id' => '*4'], ['name' => 'orang-lain', '.id' => '*5']]);
        $router->shouldReceive('hapus')->with('ppp/active', '*4')->once()->andReturnNull();
        (new EditAkunService($router))->jalankan($op->id, $data);
        $this->assertSame('password-lama', $akun->fresh()->password);
        $this->assertSame('sukses', $op->fresh()->status);
    }

    public function test_validasi_dan_autentikasi(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        $this->putJson('/api/admin/akun/'.$akun->id, $data)->assertUnauthorized();
        $data['username'] = 'nama tidak sah';
        $data['vps_id'] = 9999;
        $data['password'] = 'abc';
        $this->actingAs($user)->putJson('/api/admin/akun/'.$akun->id, $data)->assertUnprocessable()->assertJsonValidationErrors(['username', 'vps_id', 'password']);
    }

    public function test_payload_password_di_antrean_terenkripsi(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        config(['queue.default' => 'database']);
        $this->actingAs($user)->putJson('/api/admin/akun/'.$akun->id, $data)->assertAccepted();
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('password-baru', $payload);
        $command = json_decode($payload, true)['data']['command'];
        $this->assertStringContainsString('password-baru', Crypt::decrypt($command));
    }

    public function test_data_berubah_sejak_diantrekan_tidak_menyentuh_router(): void
    {
        [$user, $akun, $data] = $this->siapkan();
        $op = $this->operasi($user, $akun);
        $akun->forceFill(['updated_at' => now()->addMinute()])->save();
        $router = $this->mock(RouterOsClient::class);
        $router->shouldNotReceive('ambil');
        $router->shouldNotReceive('ubah');
        (new EditAkunService($router))->jalankan($op->id, $data);
        $this->assertSame('gagal', $op->fresh()->status);
        $this->assertSame('lama', $akun->fresh()->username);
    }
}
