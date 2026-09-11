<x-mail::message>
# Pengajuan Anda diterima

Halo {{ $pengajuan->nama }},

Pengajuan akses VPN Anda sudah kami terima dan sedang menunggu ditinjau
administrator.

<x-mail::panel>
**Nomor pengajuan: {{ $pengajuan->nomor }}**
</x-mail::panel>

Simpan nomor tersebut. Nomor itu dipakai untuk memeriksa status pengajuan
@if ($pengajuan->jenis === 'baru')
dan, nantinya, untuk mengajukan perpanjangan.
@else
ini.
@endif

**Ringkasan**

- Instansi: {{ $pengajuan->instansi }}
- Keperluan: {{ $pengajuan->keperluan }}
- Masa akses diminta: {{ $pengajuan->durasi_mulai->format('d/m/Y') }} sampai {{ $pengajuan->durasi_selesai->format('d/m/Y') }}

Anda akan menerima email berikutnya begitu pengajuan selesai ditinjau.

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
