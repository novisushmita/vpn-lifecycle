<?php

namespace Tests\Unit;

use App\Services\Vpn\PencatatSesi;
use PHPUnit\Framework\TestCase;

/**
 * Penghitung byte tidak berada di /ppp active — di sana hanya ada
 * limit-bytes-* yang berarti batas kuota, bukan pemakaian. Angka sebenarnya
 * diambil dari interface dinamis <l2tp-USERNAME>.
 */
class PencatatSesiByteTest extends TestCase
{
    public function test_mengurai_interface_sesi_l2tp(): void
    {
        $hasil = PencatatSesi::byteDariInterface([
            'name' => '<l2tp-bayu.ganteng.a9ki>',
            'type' => 'l2tp-in',
            'rx-byte' => '11274',
            'tx-byte' => '2561',
        ]);

        $this->assertSame('bayu.ganteng.a9ki', $hasil['username']);

        // Arah dilihat dari sisi KLIEN, bukan router:
        // yang dikirim router (tx) adalah unduhan klien.
        $this->assertSame(2561, $hasil['in']);
        $this->assertSame(11274, $hasil['out']);
    }

    public function test_mengabaikan_interface_selain_sesi(): void
    {
        $this->assertNull(PencatatSesi::byteDariInterface([
            'name' => 'ether1', 'type' => 'ether', 'rx-byte' => '999',
        ]));

        $this->assertNull(PencatatSesi::byteDariInterface([
            'name' => 'vpn-uji', 'type' => 'l2tp-out', 'rx-byte' => '999',
        ]));
    }

    public function test_penghitung_kosong_dianggap_nol(): void
    {
        $hasil = PencatatSesi::byteDariInterface([
            'name' => '<l2tp-baru.konek>', 'type' => 'l2tp-in',
        ]);

        $this->assertSame(0, $hasil['in']);
        $this->assertSame(0, $hasil['out']);
    }
}
