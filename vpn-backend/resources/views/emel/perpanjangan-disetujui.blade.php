<x-mail::message>
# Perpanjangan akses disetujui

Halo {{ $akun->pengajuan->nama }},

Permintaan perpanjangan akses VPN Anda (username **{{ $akun->username }}**)
telah disetujui.

<x-mail::panel>
Masa akses baru berlaku sampai **{{ $akun->selesai_pada->format('d/m/Y') }}**.
</x-mail::panel>

Kredensial (username dan password) tidak berubah. Bila akun sempat
kedaluwarsa dan dinonaktifkan, akses Anda sudah diaktifkan kembali.

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
