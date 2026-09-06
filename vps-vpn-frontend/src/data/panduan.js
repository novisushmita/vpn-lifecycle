/**
 * DUMMY DATA untuk konten halaman Panduan.
 * Nanti bisa diganti dengan GET /api/panduan bila konten ini
 * ingin dikelola dari backend (mis. lewat CMS sederhana di Laravel).
 */

export const panduanPengajuan = [
  {
    judul: "1. Pilih VPS yang akan diakses",
    isi: "Buka menu VPS Tersedia, cari VPS yang Anda perlukan, lalu klik tombol \"Ajukan VPN\" pada baris VPS tersebut.",
  },
  {
    judul: "2. Lengkapi formulir pengajuan",
    isi: "Isi data diri, instansi, email aktif, keperluan akses, serta durasi akses yang dibutuhkan (jika diperlukan).",
  },
  {
    judul: "3. Kirim pengajuan",
    isi: "Periksa kembali data yang diisi, lalu klik \"Kirim Pengajuan\". Sistem akan menampilkan nomor pengajuan.",
  },
  {
    judul: "4. Simpan nomor pengajuan",
    isi: "Gunakan nomor pengajuan tersebut untuk memeriksa status persetujuan pada halaman yang sama.",
  },
  {
    judul: "5. Tunggu persetujuan admin",
    isi: "Admin akan memverifikasi pengajuan. Konfigurasi VPN akan dikirimkan ke email yang terdaftar setelah disetujui.",
  },
];

export const panduanPerangkat = [
  {
    perangkat: "Windows",
    langkah: [
      "Unduh aplikasi klien VPN sesuai tautan yang dikirim melalui email.",
      "Install aplikasi, lalu impor berkas konfigurasi (.ovpn) yang diterima.",
      "Buka aplikasi, pilih profil yang baru diimpor, lalu klik Connect.",
      "Masukkan username dan password yang diberikan admin.",
    ],
  },
  {
    perangkat: "macOS",
    langkah: [
      "Unduh aplikasi klien VPN dari App Store atau tautan resmi.",
      "Buka Preferensi Sistem > Jaringan, tambahkan profil baru.",
      "Impor berkas konfigurasi yang dikirimkan melalui email.",
      "Hubungkan dan masukkan kredensial akses.",
    ],
  },
  {
    perangkat: "Android / iOS",
    langkah: [
      "Install aplikasi klien VPN dari Play Store / App Store.",
      "Impor berkas konfigurasi dari email (dapat melalui berbagi berkas antar aplikasi).",
      "Aktifkan profil VPN, lalu masukkan username dan password.",
      "Pastikan koneksi internet stabil sebelum menyambungkan VPN.",
    ],
  },
];
