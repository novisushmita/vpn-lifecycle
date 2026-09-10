<?php

namespace Tests\Unit;

use App\Services\RouterOs\RouterOsClient;
use PHPUnit\Framework\TestCase;

/**
 * RouterOS melaporkan waktu ping dalam format gabungan satuan, mis. "1ms200us".
 * Salah mengurai ini membuat seluruh angka RTT di Bab 4 keliru.
 */
class RouterOsWaktuTest extends TestCase
{
    private function klien(): RouterOsClient
    {
        return new RouterOsClient('https://contoh', 'u', 'p');
    }

    public function test_mengurai_satuan_tunggal(): void
    {
        $k = $this->klien();

        $this->assertSame(5.0, $k->msDariWaktu('5ms'));
        $this->assertSame(0.56, $k->msDariWaktu('560us'));
        $this->assertSame(2000.0, $k->msDariWaktu('2s'));
    }

    public function test_mengurai_satuan_gabungan(): void
    {
        $k = $this->klien();

        // 1ms + 200us = 1.2ms
        $this->assertSame(1.2, $k->msDariWaktu('1ms200us'));

        // 2s + 100ms = 2100ms
        $this->assertSame(2100.0, $k->msDariWaktu('2s100ms'));
    }

    public function test_mengembalikan_nol_untuk_masukan_tak_dikenal(): void
    {
        $this->assertSame(0.0, $this->klien()->msDariWaktu(''));
        $this->assertSame(0.0, $this->klien()->msDariWaktu('timeout'));
    }
}
