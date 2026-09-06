<?php

namespace Tests\Unit;

use App\Enums\StatusAkun;
use PHPUnit\Framework\TestCase;

class StatusAkunTest extends TestCase
{
    public function test_alur_provisioning_normal(): void
    {
        $this->assertTrue(StatusAkun::MenungguProvision->bisaPindahKe(StatusAkun::Provisioning));
        $this->assertTrue(StatusAkun::Provisioning->bisaPindahKe(StatusAkun::Aktif));
    }

    public function test_retry_setelah_provisioning_gagal(): void
    {
        $this->assertTrue(StatusAkun::Provisioning->bisaPindahKe(StatusAkun::GagalProvision));
        $this->assertTrue(StatusAkun::GagalProvision->bisaPindahKe(StatusAkun::Provisioning));
    }

    public function test_admin_bisa_menonaktifkan_lalu_mengaktifkan_kembali(): void
    {
        $this->assertTrue(StatusAkun::Aktif->bisaPindahKe(StatusAkun::Dinonaktifkan));
        $this->assertTrue(StatusAkun::Dinonaktifkan->bisaPindahKe(StatusAkun::Aktif));
    }

    public function test_perpanjangan_mengembalikan_akun_kedaluwarsa_jadi_aktif(): void
    {
        $this->assertTrue(StatusAkun::AkanKedaluwarsa->bisaPindahKe(StatusAkun::Aktif));
        $this->assertTrue(StatusAkun::Kedaluwarsa->bisaPindahKe(StatusAkun::Aktif));
    }

    public function test_akun_aktif_boleh_langsung_kedaluwarsa(): void
    {
        // Akun berdurasi sangat pendek bisa lewat tanggal sebelum scheduler
        // H-3 sempat menandainya akan_kedaluwarsa.
        $this->assertTrue(StatusAkun::Aktif->bisaPindahKe(StatusAkun::Kedaluwarsa));
    }

    public function test_akun_belum_provision_boleh_langsung_dihapus(): void
    {
        // Dibutuhkan penghapusan VPS berantai: akun yang belum pernah
        // diprovision tidak punya objek apa pun di router.
        $this->assertTrue(StatusAkun::MenungguProvision->bisaPindahKe(StatusAkun::Dihapus));
    }

    public function test_menolak_lompatan_yang_melewati_provisioning(): void
    {
        $this->assertFalse(StatusAkun::MenungguProvision->bisaPindahKe(StatusAkun::Aktif));
    }

    public function test_menolak_akun_kedaluwarsa_dinonaktifkan(): void
    {
        $this->assertFalse(StatusAkun::Kedaluwarsa->bisaPindahKe(StatusAkun::Dinonaktifkan));
    }

    public function test_status_dihapus_adalah_kondisi_akhir(): void
    {
        $this->assertEmpty(StatusAkun::Dihapus->transisiSah());

        foreach (StatusAkun::cases() as $status) {
            $this->assertFalse(
                StatusAkun::Dihapus->bisaPindahKe($status),
                "Akun yang sudah dihapus tidak boleh pindah ke {$status->value}",
            );
        }
    }

    public function test_status_yang_secretnya_harus_enabled_di_router(): void
    {
        $this->assertTrue(StatusAkun::Aktif->seharusnyaEnabledDiRouter());
        $this->assertTrue(StatusAkun::AkanKedaluwarsa->seharusnyaEnabledDiRouter());
        $this->assertFalse(StatusAkun::Dinonaktifkan->seharusnyaEnabledDiRouter());
        $this->assertFalse(StatusAkun::Kedaluwarsa->seharusnyaEnabledDiRouter());
    }

    public function test_status_yang_objeknya_masih_harus_ada_di_router(): void
    {
        // Dipakai job sinkron untuk membedakan drift 'hilang' dari 'yatim'.
        $this->assertTrue(StatusAkun::Dinonaktifkan->seharusnyaAdaDiRouter());
        $this->assertTrue(StatusAkun::Kedaluwarsa->seharusnyaAdaDiRouter());
        $this->assertFalse(StatusAkun::MenungguProvision->seharusnyaAdaDiRouter());
        $this->assertFalse(StatusAkun::Dihapus->seharusnyaAdaDiRouter());
    }
}
