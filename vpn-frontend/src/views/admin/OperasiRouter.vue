<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import Chart from "chart.js/auto";
import { apiAdmin } from "../../lib/api";
import { waktuSingkat } from "../../lib/format";

// Jenis operasi sesuai enum operasi_router. "sinkron" tampil sebagai grafik
// line; sisanya sebagai tabel.
const JENIS = [
  "sinkron", "provision", "edit", "disable", "enable",
  "hapus", "hapus_vps", "expire", "extend", "ping",
];

const jenis = ref("sinkron");
const daftar = ref([]);
const memuat = ref(false);
const galat = ref("");

const modeGrafik = computed(() => jenis.value === "sinkron");

async function muat() {
  memuat.value = true;
  galat.value = "";
  try {
    daftar.value = await apiAdmin(`admin/operasi?jenis=${jenis.value}&limit=200`);
    await nextTick();
    if (modeGrafik.value) gambarGrafik();
    else hancurkanGrafik();
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

/* ---------------- Grafik line: durasi_ms per pemeriksaan ---------------- */

const kanvas = ref(null);
let chart = null;

function hancurkanGrafik() {
  chart?.destroy();
  chart = null;
}

function gambarGrafik() {
  hancurkanGrafik();
  if (!kanvas.value) return;

  // API mengembalikan terbaru dulu; grafik perlu urut waktu.
  const baris = [...daftar.value].reverse();
  const label = baris.map((o) => waktuSingkat(o.selesai_pada || o.dimulai_pada));
  const seri = (status) =>
    baris.map((o) => (o.status === status ? o.durasi_ms : null));

  chart = new Chart(kanvas.value, {
    type: "line",
    data: {
      labels: label,
      datasets: [
        {
          label: "Sukses",
          data: seri("sukses"),
          borderColor: "#0f9d58",
          backgroundColor: "#0f9d58",
          spanGaps: true,
          tension: 0.25,
          pointRadius: 2,
        },
        {
          label: "Gagal",
          data: seri("gagal"),
          borderColor: "#d93025",
          backgroundColor: "#d93025",
          spanGaps: true,
          tension: 0.25,
          pointRadius: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: "index", intersect: false },
      scales: {
        y: { title: { display: true, text: "durasi (ms)" }, beginAtZero: true },
        x: { ticks: { maxTicksLimit: 6, autoSkip: true, font: { size: 10 } } },
      },
      plugins: { legend: { position: "bottom" } },
    },
  });
}

const badgeStatus = (s) =>
  ({ sukses: "badge-success", gagal: "badge-danger", berjalan: "badge-warning", antre: "badge-neutral" }[s] ||
    "badge-neutral");

watch(jenis, muat);
onMounted(muat);
onBeforeUnmount(hancurkanGrafik);
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
      <div class="toolbar__spacer"></div>
      <button class="btn btn-secondary btn-sm" @click="muat">Muat ulang</button>
    </div>

    <p class="section-subtitle" style="margin-top: 4px">
      Jenis Sinkron berjalan terjadwal tiap 10 menit, ditampilkan sebagai grafik
      durasi. Jenis lain sebagai tabel.
    </p>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <!-- Grafik untuk jenis sinkron -->
    <template v-if="modeGrafik">
      <div v-if="memuat" style="color: var(--color-text-faint); font-size: 13px">Memuat...</div>
      <div v-else-if="!daftar.length" style="color: var(--color-text-faint); font-size: 13px">
        Belum ada pemeriksaan sinkronisasi.
      </div>
      <div v-show="daftar.length" class="operasi-grafik">
        <canvas ref="kanvas"></canvas>
      </div>
    </template>

    <!-- Tabel untuk jenis lain -->
    <div v-else class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th style="width: 150px">Selesai</th>
            <th>Akun / VPS</th>
            <th style="width: 82px">Status</th>
            <th style="width: 92px">Durasi</th>
            <th style="width: 56px">Coba</th>
            <th>Dipicu</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">
              Belum ada operasi jenis ini.
            </td>
          </tr>
          <tr v-for="o in daftar" :key="o.id">
            <td class="mono" style="white-space: nowrap">{{ waktuSingkat(o.selesai_pada) }}</td>
            <td class="mono">{{ o.akun || o.vps || "-" }}</td>
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
.operasi-grafik {
  height: 200px;
  margin-top: 8px;
}
</style>
