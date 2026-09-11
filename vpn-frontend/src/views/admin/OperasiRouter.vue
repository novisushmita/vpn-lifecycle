<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { apiAdmin } from "../../lib/api";
import { waktuSingkat } from "../../lib/format";

// "sinkron" sengaja tidak ada di sini — sudah punya grafik "Status drift"
// sendiri di Dashboard. Blok ini murni tabel operasi lifecycle akun/VPS.
const JENIS = [
  "provision", "edit", "disable", "enable",
  "hapus", "hapus_vps", "expire", "extend",
];

const jenis = ref("provision");
const daftar = ref([]);
const memuat = ref(false);
const galat = ref("");

// "hapus_vps" cuma nempel ke VPS (lihat PenghapusVps), jenis lain selalu
// nempel ke akun (dan VPS akun itu ikut kebawa). Jadi kolom Akun VPN cuma
// relevan kalau jenisnya bukan ini.
const HANYA_VPS = ["hapus_vps"];
const tampilkanKolomAkun = computed(() => !HANYA_VPS.includes(jenis.value));

async function muat() {
  memuat.value = true;
  galat.value = "";
  try {
    daftar.value = await apiAdmin(`admin/operasi?jenis=${jenis.value}&limit=200`);
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

const badgeStatus = (s) =>
  ({ sukses: "badge-success", gagal: "badge-danger", berjalan: "badge-warning", antre: "badge-neutral" }[s] ||
    "badge-neutral");

watch(jenis, muat);
onMounted(muat);

defineExpose({ muat });
</script>

<template>
  <div class="operasi-blok">
    <div class="operasi-blok__head">
      <h2 class="section-title" style="font-size: 16px; margin: 0">Operasi Router</h2>
      <select v-model="jenis" class="select select-sm">
        <option v-for="j in JENIS" :key="j" :value="j">
          {{ j === "hapus_vps" ? "hapus VPS" : j }}
        </option>
      </select>
    </div>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <div class="table-scroll" style="margin-top: 12px">
      <table class="table">
        <thead>
          <tr>
            <th style="width: 150px">Selesai</th>
            <th v-if="tampilkanKolomAkun">Akun VPN</th>
            <th>VPS</th>
            <th style="width: 82px">Status</th>
            <th style="width: 92px">Durasi</th>
            <th style="width: 56px">Coba</th>
            <th>Dipicu</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td :colspan="tampilkanKolomAkun ? 8 : 7" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td :colspan="tampilkanKolomAkun ? 8 : 7" style="text-align: center; color: var(--color-text-faint)">
              Belum ada operasi jenis ini.
            </td>
          </tr>
          <tr v-for="o in daftar" :key="o.id">
            <td class="mono" style="white-space: nowrap">{{ waktuSingkat(o.selesai_pada) }}</td>
            <td v-if="tampilkanKolomAkun" class="mono">{{ o.akun || "-" }}</td>
            <td class="mono">{{ o.vps || "-" }}</td>
            <td><span class="badge" :class="badgeStatus(o.status)">{{ o.status }}</span></td>
            <td class="mono">{{ o.durasi_ms != null ? o.durasi_ms + " ms" : "-" }}</td>
            <td class="mono">{{ o.percobaan }}</td>
            <td>{{ o.dipicu_oleh || "penjadwal" }}</td>
            <td class="mono">{{ o.pesan_error || "-" }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.operasi-blok { margin-top: 30px; }
.operasi-blok__head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
</style>
