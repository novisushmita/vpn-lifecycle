<?php

namespace Tests\Unit;

use App\Services\RouterOs\RouterOsClient;
use App\Services\Vpn\PencatatSesi;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Waktu mulai sesi dihitung mundur dari uptime yang dilaporkan router, bukan
 * dari waktu polling. Salah mengurai format uptime membuat seluruh durasi
 * sesi di laporan keliru.
 */
class PencatatSesiTest extends TestCase
{
    private PencatatSesi $pencatat;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-05 12:00:00');
        $this->pencatat = new PencatatSesi(new RouterOsClient('https://contoh', 'u', 'p'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mengurai_satuan_tunggal(): void
    {
        $this->assertSame('2026-09-05 11:59:15', $this->pencatat->mulaiDari('45s')->toDateTimeString());
        $this->assertSame('2026-09-05 11:30:00', $this->pencatat->mulaiDari('30m')->toDateTimeString());
        $this->assertSame('2026-09-05 10:00:00', $this->pencatat->mulaiDari('2h')->toDateTimeString());
    }

    public function test_mengurai_satuan_gabungan(): void
    {
        // 1 jam 2 menit 30 detik
        $this->assertSame('2026-09-05 10:57:30', $this->pencatat->mulaiDari('1h2m30s')->toDateTimeString());

        // 1 hari 3 jam
        $this->assertSame('2026-09-04 09:00:00', $this->pencatat->mulaiDari('1d3h')->toDateTimeString());

        // 1 minggu
        $this->assertSame('2026-08-29 12:00:00', $this->pencatat->mulaiDari('1w')->toDateTimeString());
    }

    public function test_uptime_kosong_dianggap_baru_mulai(): void
    {
        $this->assertSame('2026-09-05 12:00:00', $this->pencatat->mulaiDari(null)->toDateTimeString());
        $this->assertSame('2026-09-05 12:00:00', $this->pencatat->mulaiDari('')->toDateTimeString());
    }
}
