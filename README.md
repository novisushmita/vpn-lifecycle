# Sistem Manajemen Siklus Hidup Akun VPN

Tugas Akhir — otomasi **seluruh** siklus hidup akun VPN pada MikroTik
RouterOS, dari pengajuan sampai penghapusan, dengan jaminan konsistensi antara
basis data dan router pada setiap transisi.

## Latar belakang singkat

Penelitian terdahulu berhenti pada otomasi **provisioning** — cara membuat
akun secara otomatis. Padahal siklus hidup sebuah akun punya tujuh transisi,
dan enam di antaranya tidak pernah dibahas:

| Transisi | Dibahas jurnal terdahulu |
|---|---|
| `CREATE` provisioning otomatis | ya |
| `UPDATE` ganti password / paket / VPS | tidak |
| `DISABLE` nonaktifkan sementara | tidak |
| `ENABLE` aktifkan kembali | tidak |
| `DELETE` hapus + bersihkan sisa | tidak |
| `EXPIRE` nonaktif otomatis by tanggal | tidak |
| `EXTEND` perpanjangan masa akses | tidak |

Setiap transisi wajib (1) mengubah state di basis data, (2) merambat ke
MikroTik, (3) dijamin konsisten antara keduanya.

## Dokumen

| Berkas | Isi |
|---|---|
| **`CLAUDE.md`** | Acuan sistem: keputusan terkunci, spesifikasi fitur, skema basis data, batasan masalah, metrik evaluasi. **Baca ini lebih dulu.** |
| **`SETUP.md`** | Pemasangan lingkungan, konfigurasi CHR, cara menjalankan, uji isolasi, pemecahan masalah |
| `chr-setup.rsc` | Skrip konfigurasi RouterOS, tinggal diimpor |
| `KREDENSIAL.local.md` | Nilai kredensial asli — **tidak ikut repositori** |

## Struktur

```
vps-vpn-backend/     Laravel 13 — API, layanan siklus hidup, penjadwal
vps-vpn-frontend/    Vue 3 + Vite — halaman publik dan dashboard admin
```

## Arsitektur lingkungan uji

```
Kali (host)                                VirtualBox
├── Laravel  :8000  ──host-only──►  CHR gateway ──vpslan──► VPS-APP-01 10.10.10.11
├── Vue      :5173                   192.168.56.10          VPS-DB-02  10.10.10.12
├── MariaDB  :3306                   10.10.10.1
└── queue worker + scheduler         klien VPN 10.20.0.0/24
```

## Menjalankan

```bash
cd vps-vpn-backend  && php artisan serve --host=0.0.0.0 --port=8000
cd vps-vpn-backend  && php artisan queue:work        # WAJIB, memproses provisioning
cd vps-vpn-backend  && php artisan schedule:work     # log sesi, ping, drift, kedaluwarsa
cd vps-vpn-frontend && npm run dev -- --host 0.0.0.0
```

Rincian pemasangan dari nol ada di `SETUP.md`.

## Perintah artisan

| Perintah | Fungsi |
|---|---|
| `router:cek [--ping=IP]` | Preflight kesiapan router |
| `vpn:uji-siklus [--simpan]` | Demo siklus hidup penuh + ukur durasi tiap operasi |
| `vpn:polling-sesi` | Selaraskan log sesi dengan `/ppp active` |
| `vps:ping` | Ketersediaan VPS dari sisi router |
| `vpn:sinkron [--daftar]` | Deteksi drift basis data vs router |
| `vpn:kedaluwarsa [--dry-run]` | H-3, kedaluwarsa, pembersihan H+30 |

## Fitur pembeda

1. **Kontrol siklus hidup penuh oleh admin** — edit, disable, enable, hapus;
   semuanya merambat ke router, bukan sekadar mengubah basis data.
2. **Deteksi dan rekonsiliasi drift** — pola *desired state reconciliation*.
   Selisih antara basis data dan router dicatat sebagai temuan, admin memilih
   *push* (router ikut basis data) atau *pull* (basis data ikut router).
3. **Kedaluwarsa dan perpanjangan otomatis** — menutup lubang keamanan berupa
   akun kedaluwarsa yang masih hidup di router.

## Pengujian

```bash
cd vps-vpn-backend && php artisan test
```

Sumber data Bab 4 terkumpul otomatis di tabel `operasi_router`: kolom
`durasi_ms` per jenis operasi memberi angka terukur tanpa stopwatch manual.
