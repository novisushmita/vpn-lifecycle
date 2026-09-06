# =====================================================================
#  Uji isolasi per-VPS dari sisi klien VPN.
#  Jalankan di KLIEN-UJI setelah terowongan naik.
# =====================================================================

:put "=================================================="
:put " Akun yang dipakai : bayu.ganteng.a9ki"
:put " Disetujui untuk   : VPS-APP-01 (10.10.10.11)"
:put " TIDAK disetujui   : VPS-DB-02  (10.10.10.12)"
:put "=================================================="
:put ""

:put "[1] Alamat yang diterima dari server VPN"
:foreach a in=[/ip address find interface=vpn-uji] do={
  :put ("    " . [/ip address get $a address])
}
:put ""

:put "[2] Ping VPS-APP-01 (10.10.10.11) - HARUS BERHASIL"
:local a [/ping 10.10.10.11 count=4]
:put ("    balasan diterima: " . $a . " dari 4")
:put ""

:put "[3] Ping VPS-DB-02 (10.10.10.12) - HARUS GAGAL"
:local b [/ping 10.10.10.12 count=4]
:put ("    balasan diterima: " . $b . " dari 4")
:put ""

:put "[4] Ambil halaman web VPS-APP-01 - HARUS BERHASIL"
:do {
  /tool fetch url="http://10.10.10.11" mode=http dst-path=hasil-app.txt
  :put "    berhasil, tersimpan di hasil-app.txt"
} on-error={ :put "    GAGAL (tidak diharapkan)" }
:put ""

:put "[5] Ambil halaman web VPS-DB-02 - HARUS GAGAL"
:do {
  /tool fetch url="http://10.10.10.12" mode=http dst-path=hasil-db.txt
  :put "    BERHASIL (TIDAK DIHARAPKAN - isolasi bocor)"
} on-error={ :put "    gagal seperti yang diharapkan - isolasi bekerja" }
:put ""

:put "=================================================="
:put " Kesimpulan: bila [2] dan [4] berhasil sementara"
:put " [3] dan [5] gagal, isolasi per-VPS terbukti."
:put "=================================================="
