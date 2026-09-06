/**
 * Pemantau operasi router yang berjalan di antrean.
 *
 * Seluruh operasi ke router kini dijalankan lewat antrean, sehingga API
 * membalas 202 dengan nomor operasi, bukan hasil akhirnya. Antarmuka menunggu
 * baris operasi_router berubah status.
 */
import { apiAdmin } from "./api";

const SELANG = 1200;
const BATAS_MS = 120000;

/**
 * @param {number} operasiId
 * @param {(status: string) => void} [onKabar] dipanggil tiap perubahan status
 * @returns {Promise<object>} baris operasi saat selesai
 */
export async function tungguOperasi(operasiId, onKabar) {
  const mulai = Date.now();
  let statusTerakhir = null;

  for (;;) {
    if (Date.now() - mulai > BATAS_MS) {
      throw new Error(
        "Operasi belum selesai setelah dua menit. Periksa apakah queue worker berjalan."
      );
    }

    const baris = await apiAdmin(`admin/operasi/${operasiId}`);

    if (baris.status !== statusTerakhir) {
      statusTerakhir = baris.status;
      onKabar?.(baris.status);
    }

    if (baris.status === "sukses") return baris;
    if (baris.status === "gagal") {
      throw new Error(baris.pesan_error || "Operasi gagal tanpa keterangan.");
    }

    await new Promise((r) => setTimeout(r, SELANG));
  }
}

/** Kata yang ditampilkan ke admin untuk tiap status antrean. */
export function labelStatus(status) {
  return {
    antre: "Menunggu giliran...",
    berjalan: "Sedang dikerjakan...",
    sukses: "Selesai",
    gagal: "Gagal",
  }[status] || status;
}
