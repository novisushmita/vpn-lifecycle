/**
 * Satu-satunya tempat aplikasi berbicara dengan backend Laravel.
 * Memakai fetch bawaan, tidak perlu menambah dependency HTTP client.
 */
import { ref } from "vue";

const BASE = import.meta.env.VITE_API_BASE_URL || "http://127.0.0.1:8000/api";

export const token = ref(localStorage.getItem("token") || "");

export function simpanToken(nilai) {
  token.value = nilai || "";
  if (nilai) localStorage.setItem("token", nilai);
  else localStorage.removeItem("token");
}

/** Galat dari server, membawa daftar kesalahan validasi bila ada. */
export class ApiError extends Error {
  constructor(pesan, status, errors = null) {
    super(pesan);
    this.status = status;
    this.errors = errors;
  }
}

export async function api(path, { method = "GET", body = null, auth = false } = {}) {
  const headers = { Accept: "application/json" };
  if (body) headers["Content-Type"] = "application/json";
  if (auth && token.value) headers.Authorization = `Bearer ${token.value}`;

  let res;
  try {
    res = await fetch(`${BASE}/${path.replace(/^\//, "")}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(
      "Tidak dapat menghubungi server. Pastikan backend berjalan di " + BASE,
      0
    );
  }

  if (res.status === 204) return null;

  const data = await res.json().catch(() => ({}));

  if (res.status === 401 && auth) {
    simpanToken("");
    throw new ApiError("Sesi berakhir. Silakan masuk kembali.", 401);
  }

  if (!res.ok) {
    throw new ApiError(
      data.message || `Permintaan gagal (${res.status}).`,
      res.status,
      data.errors || null
    );
  }

  return data;
}

/** Endpoint dashboard selalu memerlukan token. */
export const apiAdmin = (path, opsi = {}) => api(path, { ...opsi, auth: true });
