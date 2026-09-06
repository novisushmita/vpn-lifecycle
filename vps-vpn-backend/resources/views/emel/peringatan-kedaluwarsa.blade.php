<x-mail::message>
# Akses VPN Anda akan berakhir

Halo {{ $akun->pengajuan->nama }},

Akses VPN dengan username **{{ $akun->username }}** akan berakhir dalam
**{{ $sisaHari }} hari**, yaitu pada
**{{ $akun->selesai_pada->format('d/m/Y') }}**.

Setelah tanggal tersebut, akun akan dinonaktifkan secara otomatis dan koneksi
yang sedang berjalan diputus.

<x-mail::panel>
Bila masih membutuhkan akses, ajukan perpanjangan memakai nomor pengajuan
**{{ $akun->pengajuan->nomor }}** pada halaman cek status.
</x-mail::panel>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
