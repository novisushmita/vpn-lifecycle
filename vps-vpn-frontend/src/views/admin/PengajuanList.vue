<script setup>
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { apiAdmin } from "../../lib/api";

const route = useRoute();

// Filter awal diambil dari alamat, supaya angka di dashboard dapat
// menautkan langsung ke daftar yang sudah tersaring.
const daftar = ref([]);
const memuat = ref(false);
const galat = ref("");
const filterStatus = ref(route.query.status ?? "");
const cari = ref("");

const BADGE = {
  diajukan: { cls: "badge-warning", teks: "Menunggu ditinjau" },
  ditinjau: { cls: "badge-warning", teks: "Sedang ditinjau" },
  disetujui: { cls: "badge-success", teks: "Disetujui" },
  ditolak: { cls: "badge-danger", teks: "Ditolak" },
};
const badge = (s) => BADGE[s] || { cls: "badge-neutral", teks: s };

async function muat() {
  memuat.value = true;
  galat.value = "";
  try {
    const q = new URLSearchParams();
    if (filterStatus.value) q.set("status", filterStatus.value);
    if (cari.value) q.set("cari", cari.value);
    const res = await apiAdmin(`admin/pengajuan?${q}`);
    daftar.value = res.data ?? [];
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

onMounted(muat);
</script>

<template>
  <section class="card">
    <h1 class="section-title">Data Pengajuan</h1>
    <p class="section-subtitle">
      Buka detail pengajuan untuk meninjau sebelum menyetujui atau menolak.
    </p>

    <div class="toolbar">
      <select v-model="filterStatus" class="select" @change="muat">
        <option value="">Semua status</option>
        <option value="menunggu">Belum diputuskan</option>
        <option value="diajukan">Menunggu ditinjau</option>
        <option value="ditinjau">Sedang ditinjau</option>
        <option value="disetujui">Disetujui</option>
        <option value="ditolak">Ditolak</option>
      </select>
      <input
        v-model="cari"
        class="input"
        placeholder="Cari nomor, nama, instansi"
        @keyup.enter="muat"
      />
      <button class="btn btn-secondary btn-sm" @click="muat">Cari</button>
    </div>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th style="width: 150px">Nomor</th>
            <th>Pemohon</th>
            <th>VPS</th>
            <th style="width: 120px">Status</th>
            <th style="width: 110px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td colspan="5" style="text-align: center; color: var(--color-text-faint)">
              Memuat...
            </td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td colspan="5" style="text-align: center; color: var(--color-text-faint)">
              Tidak ada pengajuan.
            </td>
          </tr>
          <tr v-for="p in daftar" :key="p.id">
            <td class="mono">{{ p.nomor }}</td>
            <td>
              <strong>{{ p.nama }}</strong>
              <div style="font-size: 12px; color: var(--color-text-faint)">
                {{ p.instansi }}
              </div>
            </td>
            <td>{{ p.vps?.nama }}</td>
            <td>
              <span class="badge" :class="badge(p.status).cls">{{ badge(p.status).teks }}</span>
            </td>
            <td style="text-align: right">
              <RouterLink
                class="btn btn-primary btn-sm"
                :to="`/admin/pengajuan/${p.id}`"
              >Detail</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
