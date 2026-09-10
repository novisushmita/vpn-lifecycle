<?php

namespace Tests\Unit;

use App\Http\Resources\VpsAdminResource;
use App\Models\Vps;
use App\Models\VpsHealthCheck;
use Illuminate\Http\Request;
use Tests\TestCase;

class VpsAdminResourceTest extends TestCase
{
    public function test_ping_gagal_tetap_terlihat_saat_status_utama_masih_up(): void
    {
        $vps = new Vps;
        $vps->forceFill(['status_terakhir' => 'up', 'gagal_berturut' => 2]);
        $vps->setRelation('pingTerakhir', new VpsHealthCheck([
            'status' => 'down', 'packet_loss' => 100, 'rtt_avg_ms' => null,
            'checked_at' => '2026-09-06 10:00:00',
        ]));

        $data = (new VpsAdminResource($vps))->toArray(new Request);

        $this->assertSame('up', $data['status_terakhir']);
        $this->assertSame(2, $data['gagal_berturut']);
        $this->assertSame('down', $data['ping_terakhir']['status']);
        $this->assertSame(100, $data['ping_terakhir']['packet_loss']);
        $this->assertNull($data['ping_terakhir']['rtt_avg_ms']);
        $this->assertNotNull($data['ping_terakhir']['checked_at']);
    }

    public function test_vps_belum_diperiksa_tidak_mengarang_hasil_ping(): void
    {
        $vps = new Vps;
        $vps->setRelation('pingTerakhir', null);

        $data = (new VpsAdminResource($vps))->toArray(new Request);

        $this->assertNull($data['ping_terakhir']);
        $this->assertSame('unknown', $data['status_terakhir']);
    }

    public function test_ping_berhasil_mempertahankan_loss_dan_rtt_nol(): void
    {
        $vps = new Vps;
        $vps->setRelation('pingTerakhir', new VpsHealthCheck([
            'status' => 'up', 'packet_loss' => 33, 'rtt_avg_ms' => 0,
            'checked_at' => '2026-09-06 10:00:00',
        ]));

        $data = (new VpsAdminResource($vps))->toArray(new Request);

        $this->assertSame('up', $data['ping_terakhir']['status']);
        $this->assertSame(33, $data['ping_terakhir']['packet_loss']);
        $this->assertSame(0.0, $data['ping_terakhir']['rtt_avg_ms']);
    }
}
