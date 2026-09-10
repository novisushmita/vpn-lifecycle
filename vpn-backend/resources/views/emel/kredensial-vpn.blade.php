<x-mail::message>
# Akun VPN Anda sudah aktif

Halo {{ $akun->pengajuan->nama }},

Pengajuan **{{ $akun->pengajuan->nomor }}** telah disetujui dan akun VPN Anda
sudah aktif.

## Pengaturan koneksi

Pada perangkat Anda, tambahkan VPN bertipe **L2TP/IPsec dengan pre-shared key**:

<x-mail::table>
| Kolom | Isi |
|:----- |:--- |
| Alamat server | `{{ $server }}` |
| Pre-shared key | `{{ $ipsecPsk }}` |
| Username | `{{ $akun->username }}` |
| Password | `{{ $password }}` |
</x-mail::table>

## Masa berlaku

Akses berlaku sampai **{{ $akun->selesai_pada->format('d/m/Y') }}**. Anda akan
kami ingatkan tiga hari sebelum berakhir, dan dapat mengajukan perpanjangan
memakai nomor pengajuan di atas.

## Yang dapat Anda akses

Akun ini hanya diizinkan menjangkau **{{ $akun->vps->nama }}**. Server lain di
jaringan yang sama tidak dapat diakses dengan akun ini.

<x-mail::panel>
Jaga kerahasiaan kredensial di atas. Jangan meneruskan email ini kepada siapa
pun, dan hubungi administrator bila Anda menduga akun ini dipakai orang lain.
</x-mail::panel>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
