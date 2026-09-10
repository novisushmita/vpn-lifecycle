import { ref } from "vue";
import { api, apiAdmin, simpanToken, token } from "./api";

export const admin = ref(null);

export function sudahMasuk() {
  return Boolean(token.value);
}

export async function masuk(email, password) {
  const data = await api("admin/login", {
    method: "POST",
    body: { email, password },
  });

  simpanToken(data.token);
  admin.value = data.user;

  return data.user;
}

export async function keluar() {
  try {
    await apiAdmin("admin/logout", { method: "POST" });
  } catch {
    // token mungkin sudah kedaluwarsa di server; tetap bersihkan di sisi klien
  }
  simpanToken("");
  admin.value = null;
}

/**
 * Memulihkan sesi setelah halaman di-refresh.
 *
 * Hanya 401 (token dicabut/kedaluwarsa) yang mengakhiri sesi. Galat jaringan
 * atau 500 sesaat tidak boleh membuat admin ter-logout: itu bikin dashboard
 * terasa "lepas sendiri" tiap backend tersendat.
 */
export async function muatAdmin() {
  if (!token.value) return null;
  try {
    admin.value = await apiAdmin("admin/me");
  } catch (e) {
    if (e?.status === 401) {
      simpanToken("");
      admin.value = null;
    }
  }
  return admin.value;
}
