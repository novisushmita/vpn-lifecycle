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

/** Memulihkan sesi setelah halaman di-refresh. */
export async function muatAdmin() {
  if (!token.value) return null;
  try {
    admin.value = await apiAdmin("admin/me");
  } catch {
    admin.value = null;
  }
  return admin.value;
}
