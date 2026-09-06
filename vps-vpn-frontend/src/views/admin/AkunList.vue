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

const warna = {
  aktif: "badge-success",
  dinonaktifkan: "badge-neutral",
  gagal_provision: "badge-danger",
  kedaluwarsa: "badge-danger",
  akan_kedaluwarsa: "badge-warning",
  provisioning: "badge-warning",
  menunggu_provision: "badge-warning",
};

async function muat() {
  memuat.value = true;
  galat.value = "";
  try {
    const q = new URLSearchParams();
    if (filterStatus.value) q.set("status", filterStatus.value);
    if (cari.value) q.set("cari", cari.value);
    const res = await apiAdmin(`admin/akun?${q}`);
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
    <h1 class="section-title">Akun VPN</h1>
    <p class="section-subtitle">
      Seluruh akun yang pernah diterbitkan beserta kondisinya di router.
    </p>

    <div class="toolbar">
      <select v-model="filterStatus" class="select" @change="muat">
        <option value="">Semua status</option>
        <option value="aktif">Aktif</option>
        <option value="dinonaktifkan">Nonaktif</option>
        <option value="gagal_provision">Gagal diproses</option>
        <option value="akan_kedaluwarsa">Segera berakhir</option>
        <option value="kedaluwarsa">Berakhir</option>
      </select>
      <input v-model="cari" class="input" placeholder="Cari username atau IP" @keyup.enter="muat" />
      <button class="btn btn-secondary btn-sm" @click="muat">Cari</button>
    </div>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>Username</th>
            <th style="width: 110px">Alamat VPN</th>
            <th>VPS</th>
            <th style="width: 110px">Paket</th>
            <th style="width: 140px">Status</th>
            <th style="width: 110px">Sisa</th>
            <th style="width: 90px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">
              Belum ada akun VPN.
            </td>
          </tr>
          <tr v-for="a in daftar" :key="a.id">
            <td class="mono"><strong>{{ a.username }}</strong></td>
            <td class="mono">{{ a.ip_vpn }}</td>
            <td>{{ a.vps?.nama }}</td>
            <td class="mono">{{ a.paket?.rate_limit }}</td>
            <td><span class="badge" :class="warna[a.status]">{{ a.status_label }}</span></td>
            <td>
              <span v-if="a.sisa_hari !== null" :style="a.sisa_hari <= 3 ? 'color:#a3352b' : ''">
                {{ a.sisa_hari }} hari
              </span>
            </td>
            <td style="text-align: right">
              <RouterLink class="btn btn-primary btn-sm" :to="`/admin/akun/${a.id}`">Detail</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
