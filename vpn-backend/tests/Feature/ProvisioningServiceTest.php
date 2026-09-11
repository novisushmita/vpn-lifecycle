<?php

namespace Tests\Feature;

use App\Enums\StatusAkun;
use App\Models\AkunVpn;
use App\Models\OperasiRouter;
use App\Models\PaketBandwidth;
use App\Models\Pengajuan;
use App\Models\Vps;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsException;
use App\Services\Vpn\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Uji tiga aturan yang wajib berlaku pada seluruh method ProvisioningService:
 * idempoten, rollback saat gagal (status DB tidak ikut berubah), dan
 * tercatat di operasi_router lengkap dengan durasi.
 *
 * Router selalu di-mock: kelas ini sudah dirancang tidak menyentuh basis data
 * di dalam RouterOsClient sehingga bisa diuji tanpa router sungguhan.
 */
class ProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOLAK = 'vpnlc:sistem - tolak default';

    protected function setUp(): void
    {
        parent::setUp();
        config(['routeros.aturan_tolak' => self::TOLAK, 'routeros.comment_prefix' => 'vpnlc']);
    }

    private function buatAkun(array $override = []): AkunVpn
    {
        $vps = Vps::create(['nama' => 'VPS-A', 'alamat_ip' => '10.10.10.11', 'aktif' => true]);
        $paket = PaketBandwidth::create(['nama' => 'Dasar', 'rx_rate' => '2M', 'tx_rate' => '2M', 'ppp_profile' => 'vpn-dasar', 'aktif' => true]);
        $p = new Pengajuan;
        $p->forceFill(['nomor' => 'VPN-TEST-'.uniqid(), 'nama' => 'Uji', 'identitas' => '1', 'instansi' => 'Lab', 'email' => 'uji@example.test', 'vps_id' => $vps->id, 'keperluan' => 'Uji', 'durasi_mulai' => '2026-09-01', 'durasi_selesai' => '2026-12-01'])->save();

        $akun = new AkunVpn;
        $akun->forceFill(array_merge([
            'pengajuan_id' => $p->id,
            'vps_id' => $vps->id,
            'paket_bandwidth_id' => $paket->id,
            'username' => 'budi.uji',
            'password' => 'rahasia',
            'ip_vpn' => '10.20.0.10',
            'status' => StatusAkun::MenungguProvision,
            'mulai_pada' => '2026-09-01',
            'selesai_pada' => '2026-12-01',
        ], $override))->save();

        return $akun;
    }

    private function idAturanTolak(RouterOsClient $router): void
    {
        $router->shouldReceive('daftar')->with('ip/firewall/filter')
            ->andReturn([['comment' => self::TOLAK, '.id' => '*99']]);
    }

    /* ------------------------------------------------------------- PROVISION */

    public function test_provision_membuat_tiga_objek_berurutan_dan_mengaktifkan_akun(): void
    {
        $akun = $this->buatAkun();
        $router = $this->mock(RouterOsClient::class);
        $this->idAturanTolak($router);
        $router->shouldReceive('buat')->once()->with('ppp/secret', \Mockery::on(fn ($d) => $d['name'] === 'budi.uji' && $d['remote-address'] === '10.20.0.10'))->andReturn(['ret' => '*1']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/address-list', \Mockery::on(fn ($d) => $d['address'] === '10.10.10.11'))->andReturn(['ret' => '*2']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/filter', \Mockery::on(fn ($d) => $d['src-address'] === '10.20.0.10' && $d['place-before'] === '*99'))->andReturn(['ret' => '*3']);

        $hasil = (new ProvisioningService($router))->provision($akun);

        $this->assertSame(StatusAkun::Aktif, $hasil->status);
        $this->assertSame('*1', $hasil->router_secret_id);
        $this->assertSame('*2', $hasil->router_addresslist_id);
        $this->assertSame('*3', $hasil->router_firewall_id);
        $this->assertNotNull($hasil->disinkron_pada);

        $operasi = OperasiRouter::where('akun_vpn_id', $akun->id)->first();
        $this->assertSame('provision', $operasi->jenis);
        $this->assertSame('sukses', $operasi->status);
        $this->assertNotNull($operasi->durasi_ms);
    }

    public function test_provision_idempoten_tidak_membuat_ulang_objek_yang_sudah_ada(): void
    {
        $akun = $this->buatAkun(['router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('ambil')->with('ppp/secret', '*1')->andReturn(['name' => 'budi.uji']);
        $router->shouldReceive('ambil')->with('ip/firewall/address-list', '*2')->andReturn(['list' => 'x']);
        $router->shouldReceive('ambil')->with('ip/firewall/filter', '*3')->andReturn(['action' => 'accept']);
        $router->shouldNotReceive('buat');

        $hasil = (new ProvisioningService($router))->provision($akun);

        $this->assertSame(StatusAkun::Aktif, $hasil->status);
        $this->assertSame('*1', $hasil->router_secret_id);
    }

    public function test_provision_gagal_di_langkah_terakhir_membatalkan_langkah_sebelumnya(): void
    {
        $akun = $this->buatAkun();
        $router = $this->mock(RouterOsClient::class);
        $this->idAturanTolak($router);
        $router->shouldReceive('buat')->once()->with('ppp/secret', \Mockery::any())->andReturn(['ret' => '*1']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/address-list', \Mockery::any())->andReturn(['ret' => '*2']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/filter', \Mockery::any())->andThrow(new RouterOsException('router menolak'));
        // Rollback dari langkah 3 mundur ke 1: hanya *2 dan *1 yang perlu dibuang.
        $router->shouldReceive('hapus')->once()->with('ip/firewall/address-list', '*2')->andReturnNull();
        $router->shouldReceive('hapus')->once()->with('ppp/secret', '*1')->andReturnNull();

        try {
            (new ProvisioningService($router))->provision($akun);
            $this->fail('Seharusnya melempar RouterOsException.');
        } catch (RouterOsException) {
            // diharapkan
        }

        $fresh = $akun->fresh();
        $this->assertSame(StatusAkun::GagalProvision, $fresh->status);
        $this->assertSame('router menolak', $fresh->pesan_error);

        $operasi = OperasiRouter::where('akun_vpn_id', $akun->id)->first();
        $this->assertSame('gagal', $operasi->status);
        $this->assertStringNotContainsString('rahasia', $operasi->pesan_error);
    }

    public function test_provision_menolak_transisi_dari_status_yang_tidak_sah(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif]);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldNotReceive('buat');

        $this->expectException(RuntimeException::class);
        (new ProvisioningService($router))->provision($akun);
    }

    /* -------------------------------------------------------------- PERBAIKI */

    public function test_perbaiki_menyertakan_disabled_saat_membuat_ulang_secret(): void
    {
        // Regresi: perbaiki() sempat tidak menyertakan `disabled` saat membuat
        // secret dari nol, sehingga akun nonaktif kembali aktif di router.
        $akun = $this->buatAkun(['status' => StatusAkun::Dinonaktifkan]);
        $router = $this->mock(RouterOsClient::class);
        $this->idAturanTolak($router);
        $router->shouldReceive('buat')->once()->with('ip/firewall/address-list', \Mockery::any())->andReturn(['ret' => '*2']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/filter', \Mockery::any())->andReturn(['ret' => '*3']);
        $router->shouldReceive('buat')->once()->with('ppp/secret', \Mockery::on(fn ($d) => $d['disabled'] === 'true'))->andReturn(['ret' => '*1']);
        $router->shouldReceive('ubah')->with('ip/firewall/address-list', '*2', \Mockery::any())->andReturn([]);

        $hasil = (new ProvisioningService($router))->perbaiki($akun);

        $this->assertSame(StatusAkun::Dinonaktifkan, $hasil->status, 'perbaiki() tidak boleh mengubah status');
    }

    public function test_perbaiki_pada_akun_aktif_mengirim_disabled_false(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('ambil')->with('ppp/secret', '*1')->andReturn(['name' => 'budi.uji']);
        $router->shouldReceive('ambil')->with('ip/firewall/address-list', '*2')->andReturn(['list' => 'x']);
        $router->shouldReceive('ambil')->with('ip/firewall/filter', '*3')->andReturn(['action' => 'accept']);
        $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', \Mockery::on(fn ($d) => $d['disabled'] === 'false'))->andReturn([]);
        $router->shouldReceive('ubah')->once()->with('ip/firewall/address-list', '*2', \Mockery::any())->andReturn([]);

        (new ProvisioningService($router))->perbaiki($akun);
    }

    /* -------------------------------------------------------------- DISABLE */

    public function test_nonaktifkan_mengubah_router_dan_memutus_sesi_aktif(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['disabled' => 'true'])->andReturn([]);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([
            ['name' => 'budi.uji', '.id' => '*a'],
            ['name' => 'orang.lain', '.id' => '*b'],
        ]);
        $router->shouldReceive('hapus')->once()->with('ppp/active', '*a')->andReturnNull();

        $hasil = (new ProvisioningService($router))->nonaktifkan($akun);

        $this->assertSame(StatusAkun::Dinonaktifkan, $hasil->status);
        $operasi = OperasiRouter::where('akun_vpn_id', $akun->id)->first();
        $this->assertSame(1, $operasi->hasil['sesi_diputus']);
    }

    /* --------------------------------------------------------------- ENABLE */

    public function test_aktifkan_menolak_akun_yang_sudah_lewat_tanggal_selesai(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Dinonaktifkan, 'selesai_pada' => now()->subDay()->toDateString()]);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldNotReceive('ubah');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('perpanjangan');
        (new ProvisioningService($router))->aktifkan($akun);
    }

    public function test_aktifkan_memasang_ulang_objek_yang_hilang_lalu_mengaktifkan_secret(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Dinonaktifkan, 'router_secret_id' => '*1']);
        $router = $this->mock(RouterOsClient::class);
        $this->idAturanTolak($router);
        $router->shouldReceive('buat')->once()->with('ip/firewall/address-list', \Mockery::any())->andReturn(['ret' => '*2']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/filter', \Mockery::any())->andReturn(['ret' => '*3']);
        $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['disabled' => 'false'])->andReturn([]);

        $hasil = (new ProvisioningService($router))->aktifkan($akun);

        $this->assertSame(StatusAkun::Aktif, $hasil->status);
        $this->assertSame('*2', $hasil->router_addresslist_id);
        $this->assertSame('*3', $hasil->router_firewall_id);
    }

    /* ---------------------------------------------------------------- HAPUS */

    public function test_hapus_membuang_objek_urutan_terbalik_lalu_soft_delete(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([]);
        $urutan = [];
        $router->shouldReceive('hapus')->times(3)->andReturnUsing(function ($path) use (&$urutan) {
            $urutan[] = $path;
        });

        $hasil = (new ProvisioningService($router))->hapus($akun, 'admin');

        $this->assertSame(['ip/firewall/filter', 'ip/firewall/address-list', 'ppp/secret'], $urutan);
        $this->assertSame(StatusAkun::Dihapus, $hasil->status);
        $this->assertSame('admin', $hasil->alasan_penghapusan);
        $this->assertTrue($hasil->trashed());
    }

    public function test_hapus_menerima_404_sebagai_sudah_tidak_ada(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([]);
        $router->shouldReceive('hapus')->with('ip/firewall/filter', '*3')->andThrow(new RouterOsException('tidak ada', 404));
        $router->shouldReceive('hapus')->with('ip/firewall/address-list', '*2')->andReturnNull();
        $router->shouldReceive('hapus')->with('ppp/secret', '*1')->andReturnNull();

        $hasil = (new ProvisioningService($router))->hapus($akun);

        $this->assertSame(StatusAkun::Dihapus, $hasil->status);
        $this->assertTrue($hasil->trashed());
    }

    public function test_hapus_gagal_sebagian_tidak_menghapus_baris_dan_tidak_mengubah_status(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1', 'router_addresslist_id' => '*2', 'router_firewall_id' => '*3']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([]);
        $router->shouldReceive('hapus')->with('ip/firewall/filter', '*3')->andThrow(new RouterOsException('gagal', 500));
        $router->shouldReceive('hapus')->with('ip/firewall/address-list', '*2')->andReturnNull();
        $router->shouldReceive('hapus')->with('ppp/secret', '*1')->andReturnNull();

        try {
            (new ProvisioningService($router))->hapus($akun);
            $this->fail('Seharusnya melempar RouterOsException.');
        } catch (RouterOsException) {
            // diharapkan
        }

        $fresh = $akun->fresh();
        $this->assertSame(StatusAkun::Aktif, $fresh->status, 'Status tidak boleh berubah bila sebagian objek gagal dihapus');
        $this->assertFalse($fresh->trashed());
    }

    /* ----------------------------------------------------------- KEDALUWARSA */

    public function test_kedaluwarsakan_menonaktifkan_secret_dan_memutus_sesi(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['disabled' => 'true'])->andReturn([]);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([]);

        $hasil = (new ProvisioningService($router))->kedaluwarsakan($akun);

        $this->assertSame(StatusAkun::Kedaluwarsa, $hasil->status);
    }

    /* ----------------------------------------------------------- PERPANJANGAN */

    public function test_perpanjang_menolak_tanggal_yang_tidak_lebih_lambat(): void
    {
        $akun = $this->buatAkun(['selesai_pada' => '2026-12-01']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldNotReceive('ubah');

        $this->expectException(RuntimeException::class);
        (new ProvisioningService($router))->perpanjang($akun, '2026-11-01');
    }

    public function test_perpanjang_menghidupkan_kembali_akun_kedaluwarsa(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Kedaluwarsa, 'router_secret_id' => '*1', 'peringatan_h3_dikirim_pada' => now()]);
        $router = $this->mock(RouterOsClient::class);
        $this->idAturanTolak($router);
        $router->shouldReceive('buat')->once()->with('ip/firewall/address-list', \Mockery::any())->andReturn(['ret' => '*2']);
        $router->shouldReceive('buat')->once()->with('ip/firewall/filter', \Mockery::any())->andReturn(['ret' => '*3']);
        $router->shouldReceive('ubah')->once()->with('ppp/secret', '*1', ['disabled' => 'false'])->andReturn([]);

        $hasil = (new ProvisioningService($router))->perpanjang($akun, '2027-01-01');

        $this->assertSame(StatusAkun::Aktif, $hasil->status);
        $this->assertSame('2027-01-01', $hasil->selesai_pada->toDateString());
        $this->assertNull($hasil->peringatan_h3_dikirim_pada, 'Peringatan H-3 harus direset agar terkirim lagi');
    }

    public function test_perpanjang_akun_aktif_hanya_mengubah_tanggal(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif]);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldNotReceive('buat');
        $router->shouldNotReceive('ubah');

        $hasil = (new ProvisioningService($router))->perpanjang($akun, '2027-01-01');

        $this->assertSame(StatusAkun::Aktif, $hasil->status);
        $this->assertSame('2027-01-01', $hasil->selesai_pada->toDateString());
    }

    /* -------------------------------------------------------------------- LOG */

    public function test_pakai_operasi_memakai_baris_yang_sudah_dibuat_pemanggil(): void
    {
        $akun = $this->buatAkun(['status' => StatusAkun::Aktif, 'router_secret_id' => '*1']);
        $operasi = OperasiRouter::create(['jenis' => 'disable', 'akun_vpn_id' => $akun->id, 'status' => 'antre']);
        $router = $this->mock(RouterOsClient::class);
        $router->shouldReceive('ubah')->andReturn([]);
        $router->shouldReceive('daftar')->with('ppp/active')->andReturn([]);

        (new ProvisioningService($router))->pakaiOperasi($operasi)->nonaktifkan($akun);

        $this->assertSame(1, OperasiRouter::where('akun_vpn_id', $akun->id)->count(), 'Tidak boleh ada baris operasi ganda');
        $this->assertSame('sukses', $operasi->fresh()->status);
    }
}
