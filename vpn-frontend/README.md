# Frontend - Layanan Akses VPS / Pengajuan VPN

Frontend dibangun dengan **Vue 3 + Vite + Vue Router**. Tampilan berupa
layout sidebar dan responsif untuk HP, tablet, dan desktop. Data publik dan
dashboard admin sudah terhubung ke API Laravel melalui `src/lib/api.js`.
Konten Panduan dan Bantuan tetap statis.

## Cara menjalankan

Pastikan Node.js sudah terpasang (disarankan versi 18 ke atas).

```bash
# 1. masuk ke folder frontend
cd vpn-frontend

# 2. install dependency (sekali saja / setiap kali package.json berubah)
npm install

# 3. jalankan development server
npm run dev
```

Setelah `npm run dev` berjalan, buka URL yang muncul di terminal
(biasanya `http://localhost:5173`). Halaman pertama yang tampil adalah
**VPS Tersedia**.

Perintah lain yang tersedia:

```bash
npm run build     # build production ke folder dist/
npm run preview   # preview hasil build production secara lokal
```

## Struktur folder

```
vpn-frontend/
├── index.html
├── package.json
├── vite.config.js
└── src/
    ├── main.js               # entry point, mount Vue app + import theme.css
    ├── App.vue                # shell utama: AppSidebar + TopBar + <RouterView />
    │
    ├── router/
    │   └── index.js           # daftar route + judul/breadcrumb tiap halaman
    │
    ├── views/                 # 1 file = 1 halaman menu
    │   ├── VpsTersedia.vue    # tabel VPS tersedia (scroll otomatis jika baris banyak)
    │   ├── PengajuanVpn.vue   # form pengajuan VPN
    │   ├── CekStatus.vue      # menu terpisah untuk mengecek status pengajuan
    │   ├── Panduan.vue        # panduan pengajuan & penggunaan per perangkat
    │   └── Bantuan.vue        # kontak administrator + FAQ
    │
    ├── components/
    │   ├── AppSidebar.vue     # sidebar kiri: logo, pencarian, menu navigasi
    │   └── TopBar.vue         # baris judul halaman (pojok kiri atas) + tombol menu mobile
    │
    ├── data/                  # akses data publik dan konten statis
    │   ├── vpsList.js          # daftar VPS dari GET /api/vps
    │   ├── pengajuan.js        # submit, cek status, dan perpanjangan via API
    │   ├── panduan.js          # konten panduan -> opsional GET /api/panduan
    │   └── kontak.js           # kontak admin & FAQ -> opsional GET /api/kontak-admin
    │
    └── assets/
        └── theme.css           # semua token warna, layout sidebar/topbar, dan komponen UI dasar
```

## Tentang tampilan

- **Layout sidebar + topbar**, mengikuti tema acuan: logo dan nama aplikasi
  di pojok kiri atas sidebar, judul halaman aktif ditampilkan di pojok kiri
  atas area konten (bukan di tengah), dengan format breadcrumb "Judul >
  Judul" seperti contoh yang diberikan.
- **Ikon SVG asli** (bukan emoji) untuk semua menu di sidebar dan tombol
  di topbar, ada di `src/components/Icon.vue`. Ikon menu Pengajuan VPN
  sengaja diberi warna biru berbeda dari ikon lain. Kalau nanti mau pakai
  aset asli dari Icons8, tinggal tempel path SVG-nya menggantikan isi
  `<template v-if="name === '...'">` yang sesuai di file tersebut.
- **Sidebar bisa diciutkan** (icon-only) lewat tombol di bagian bawah
  sidebar, khusus di layar desktop. Statusnya disimpan di localStorage
  jadi tetap ingat pilihan terakhir saat halaman di-refresh. Di HP/tablet,
  sidebar selalu tampil penuh sebagai drawer yang dibuka lewat tombol
  hamburger di topbar (fitur ciutkan tidak relevan di layar sempit).
- Tidak ada kolom pencarian di sidebar dan tidak ada info akun di pojok
  kiri bawah, karena menu ini tidak memerlukan login.
- Jarak antara kartu konten dengan topbar dan sidebar sudah dirapatkan
  supaya tidak terlalu lapang.
- **Tabel VPS Tersedia**: hanya isi tabelnya yang bisa di-scroll (bukan
  seluruh halaman). Header kolom tetap terlihat (sticky) saat baris
  di-scroll ke bawah.
- Kolom status pada tabel VPS sudah dihapus sesuai permintaan.
- Field durasi akses pada form Pengajuan VPN sekarang **wajib diisi**,
  bukan opsional lagi.
- Menu **Cek Status Pengajuan** sekarang halaman/menu tersendiri di
  sidebar, terpisah dari form Pengajuan VPN.
- Kontak administrator di halaman Bantuan hanya menampilkan satu kontak.

## Integrasi dengan backend Laravel

Integrasi yang sudah digunakan:

| File | Fungsi | Endpoint |
|---|---|---|
| `data/vpsList.js` | memuat daftar VPS | `GET /api/vps` |
| `data/pengajuan.js` | mengirim pengajuan | `POST /api/pengajuan` |
| `data/pengajuan.js` | mengecek status | `GET /api/pengajuan/{nomor}` |
| `data/pengajuan.js` | meminta perpanjangan | `POST /api/perpanjangan` |

Base URL dibaca dari `VITE_API_BASE_URL`. Autentikasi admin memakai Bearer
token Laravel Sanctum dan navigation guard pada route admin.

Halaman detail akun admin mendukung edit username, password, paket bandwidth,
dan satu VPS tujuan. Proses edit berjalan melalui queue backend. Password
kosong mempertahankan nilai lama dan admin dapat memilih untuk memutus sesi
aktif agar perubahan langsung berlaku.

Halaman VPS admin menyediakan `Periksa semua VPS`. Tombol ini menjalankan ping
baru terhadap seluruh VPS dan menampilkan progres, hasil terakhir, packet
loss, RTT, waktu pemeriksaan, serta hitungan gagal berturut-turut.

Aturan tampilan: semua teks memakai keluarga font utama yang sama, termasuk
IP dan kredensial, serta teks antarmuka tidak memakai em dash.
