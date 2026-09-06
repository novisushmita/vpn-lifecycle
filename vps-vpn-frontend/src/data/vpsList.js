/**
 * Daftar VPS untuk halaman publik.
 *
 * `vpsList` sengaja tetap berupa array (kini reaktif) dan diisi di tempat,
 * sehingga komponen yang melakukan v-for tidak perlu diubah sama sekali
 * ketika sumber datanya berpindah dari dummy ke API.
 *
 * Backend hanya mengirim id, nama, dan keterangan, alamat IP tidak pernah
 * keluar ke endpoint publik.
 */
import { reactive } from "vue";
import { api } from "../lib/api";

export const vpsList = reactive([]);

export const statusMuat = reactive({ memuat: false, galat: "" });

export async function muatVps() {
  statusMuat.memuat = true;
  statusMuat.galat = "";
  try {
    const res = await api("vps");
    vpsList.splice(0, vpsList.length, ...(res.data ?? []));
  } catch (e) {
    statusMuat.galat = e.message;
  } finally {
    statusMuat.memuat = false;
  }
}

// Dimuat sekali saat modul pertama diimpor.
muatVps();

export function findVpsById(id) {
  return vpsList.find((v) => String(v.id) === String(id)) || null;
}
