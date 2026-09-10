<x-mail::message>
# Pengajuan tidak dapat disetujui

Halo {{ $pengajuan->nama }},

Setelah ditinjau, pengajuan akses VPN Anda dengan nomor
**{{ $pengajuan->nomor }}** belum dapat kami setujui.

<x-mail::panel>
**Alasan:** {{ $pengajuan->alasan_penolakan }}
</x-mail::panel>

Bila keterangan di atas sudah dilengkapi atau keadaan berubah, Anda dapat
mengajukan permohonan baru.

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
