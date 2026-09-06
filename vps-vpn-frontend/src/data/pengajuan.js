/**
 * Pengajuan akses VPN, memanggil API Laravel.
 * Bentuk nilai kembalian dipertahankan sama seperti versi dummy sebelumnya
 * supaya komponen tidak perlu diubah.
 */
import { api } from "../lib/api";

/**
 * @returns {Promise<{ nomor: string }>}
 */
export async function submitPengajuan(payload) {
  return api("pengajuan", {
    method: "POST",
    body: {
      nama: payload.nama,
      identitas: payload.identitas,
      instansi: payload.instansi,
      email: payload.email,
      vps_id: payload.vpsId,
      keperluan: payload.keperluan,
      keperluan_detail: payload.keperluanDetail || null,
      durasi_mulai: payload.durasiMulai,
      durasi_selesai: payload.durasiSelesai,
    },
  });
}

/** @returns {Promise<Object|null>} null bila nomor tidak ditemukan */
export async function cekStatusPengajuan(nomor) {
  try {
    return await api(`pengajuan/${encodeURIComponent(nomor.trim())}`);
  } catch (e) {
    if (e.status === 404) return null;
    throw e;
  }
}

/**
 * Permintaan perpanjangan masa akses.
 * Identifikasi memakai nomor pengajuan lama karena pemohon tidak punya login.
 * @returns {Promise<{ nomor: string }>}
 */
export async function ajukanPerpanjangan({ nomor, durasi_selesai, keperluan }) {
  return api("perpanjangan", {
    method: "POST",
    body: { nomor, durasi_selesai, keperluan },
  });
}

export const daftarKeperluanAkses = [
  "Maintenance server",
  "Deployment aplikasi",
  "Pengambilan/pemulihan data",
  "Monitoring dan troubleshooting",
  "Lainnya",
];
