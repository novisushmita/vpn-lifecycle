/**
 * Format tanggal dan waktu untuk seluruh antarmuka.
 *
 * Satu tempat supaya tidak ada dua gaya penulisan waktu di layar yang sama.
 * Zona waktu dikunci ke Asia/Jakarta, bukan mengikuti perangkat: jejak audit
 * dan riwayat sesi harus terbaca sama oleh siapa pun yang membukanya.
 */
const ZONA = "Asia/Jakarta";

const BULAN = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

function bagian(nilai) {
  // new Date(null) menghasilkan epoch, bukan nilai tidak sah,
  // sehingga kolom kosong perlu ditolak lebih dulu.
  if (nilai === null || nilai === undefined || nilai === "") return null;

  const d = nilai instanceof Date ? nilai : new Date(nilai);
  if (Number.isNaN(d.getTime())) return null;

  // Diambil lewat Intl agar konversi zona waktunya benar,
  // penyusunan katanya dilakukan sendiri supaya bentuknya tetap.
  const p = Object.fromEntries(
    new Intl.DateTimeFormat("en-GB", {
      timeZone: ZONA,
      day: "numeric", month: "numeric", year: "numeric",
      hour: "2-digit", minute: "2-digit", second: "2-digit",
      hour12: false,
    })
      .formatToParts(d)
      .filter((x) => x.type !== "literal")
      .map((x) => [x.type, x.value])
  );

  return {
    hari: Number(p.day),
    bulan: BULAN[Number(p.month) - 1],
    tahun: p.year,
    jam: p.hour === "24" ? "00" : p.hour,
    menit: p.minute,
    detik: p.second,
  };
}

/** 6 September 2026 */
export function tanggal(nilai) {
  const b = bagian(nilai);
  return b ? `${b.hari} ${b.bulan} ${b.tahun}` : "-";
}

/** 6 September 2026 14:31:05 */
export function tanggalWaktu(nilai) {
  const b = bagian(nilai);
  return b ? `${b.hari} ${b.bulan} ${b.tahun} ${b.jam}:${b.menit}:${b.detik}` : "-";
}

/** 14:31:05 */
export function jam(nilai) {
  const b = bagian(nilai);
  return b ? `${b.jam}:${b.menit}:${b.detik}` : "-";
}
