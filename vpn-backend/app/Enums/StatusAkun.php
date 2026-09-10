<?php

namespace App\Enums;

/**
 * State machine akun VPN (CLAUDE.md bagian 2).
 *
 * Transisi yang sah didefinisikan di satu tempat supaya tidak ada job atau
 * controller yang memindahkan status secara sembarangan. Setiap perpindahan
 * status akun WAJIB lewat bisaPindahKe() lebih dulu.
 */
enum StatusAkun: string
{
    case MenungguProvision = 'menunggu_provision';
    case Provisioning      = 'provisioning';
    case Aktif             = 'aktif';
    case Dinonaktifkan     = 'dinonaktifkan';
    case GagalProvision    = 'gagal_provision';
    case AkanKedaluwarsa   = 'akan_kedaluwarsa';
    case Kedaluwarsa       = 'kedaluwarsa';
    case Dihapus           = 'dihapus';

    /** @return self[] */
    public function transisiSah(): array
    {
        return match ($this) {
            // Dihapus diizinkan: akun yang belum pernah diprovision tidak
            // punya objek apa pun di router, dan penghapusan VPS berantai
            // harus dapat membersihkannya.
            self::MenungguProvision => [self::Provisioning, self::Dihapus],
            self::Provisioning      => [self::Aktif, self::GagalProvision],
            self::GagalProvision    => [self::Provisioning, self::Dihapus],
            // Kedaluwarsa boleh langsung dari Aktif: akun berdurasi sangat
            // pendek bisa lewat tanggal sebelum scheduler H-3 sempat berjalan.
            self::Aktif             => [self::Dinonaktifkan, self::AkanKedaluwarsa, self::Kedaluwarsa, self::Dihapus],
            self::Dinonaktifkan     => [self::Aktif, self::Dihapus],
            self::AkanKedaluwarsa   => [self::Aktif, self::Kedaluwarsa, self::Dihapus],
            self::Kedaluwarsa       => [self::Aktif, self::Dihapus], // Aktif hanya lewat perpanjangan
            self::Dihapus           => [],
        };
    }

    public function bisaPindahKe(self $tujuan): bool
    {
        return in_array($tujuan, $this->transisiSah(), true);
    }

    /** Status di mana /ppp secret seharusnya ADA dan ENABLED di router. */
    public function seharusnyaEnabledDiRouter(): bool
    {
        return $this === self::Aktif || $this === self::AkanKedaluwarsa;
    }

    /** Status di mana objek akun masih seharusnya ADA di router (enabled atau tidak). */
    public function seharusnyaAdaDiRouter(): bool
    {
        return ! in_array($this, [self::MenungguProvision, self::Dihapus], true);
    }

    /**
     * Label untuk layar. Nilai enum di basis data TIDAK ikut berubah, hanya
     * kata yang dibaca pengguna, sehingga skema dan API tetap sama.
     */
    public function label(): string
    {
        return match ($this) {
            self::MenungguProvision => 'Menunggu Diproses',
            self::Provisioning      => 'Sedang Diproses',
            self::Aktif             => 'Aktif',
            self::Dinonaktifkan     => 'Nonaktif',
            self::GagalProvision    => 'Gagal Diproses',
            self::AkanKedaluwarsa   => 'Segera Berakhir',
            self::Kedaluwarsa       => 'Berakhir',
            self::Dihapus           => 'Dihapus',
        };
    }
}
