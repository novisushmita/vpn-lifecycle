<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import Chart from "chart.js/auto";
import { apiAdmin } from "../../lib/api";
import { jam, waktuSingkat } from "../../lib/format";
import OperasiRouter from "./OperasiRouter.vue";

const data = ref(null);
const galat = ref("");

// Default grafik sinkronisasi & drift: 24 jam terakhir (rolling), bukan
// hari kalender — jadi tidak perlu mikirin batas hari/zona waktu.
const JAM_TERAKHIR = 24;

/** RouterOS format: "4w3d2h1m5s", "3h4m5s", "47m11s", dst. */
function detikDariUptime(uptime) {
  const satuan = { w: 604800, d: 86400, h: 3600, m: 60, s: 1 };
  let total = 0;
  for (const [, angka, s] of (uptime || "").matchAll(/(\d+)([wdhms])/g)) {
    total += Number(angka) * satuan[s];
  }
  return total;
}

/* ---------------- donut ringkasan: uptime, akun, VPS, pengajuan, IP ---------------- */
const kanvasUptime = ref(null);
const kanvasAkun = ref(null);
const kanvasVps = ref(null);
const kanvasPengajuan = ref(null);
const kanvasIp = ref(null);
let chartUptime = null;
let chartAkun = null;
let chartVps = null;
let chartPengajuan = null;
let chartIp = null;

const OPSI_DONUT = {
  responsive: true,
  maintainAspectRatio: false,
  cutout: "72%",
  plugins: { legend: { display: false }, tooltip: { enabled: true } },
};

function gambarDonutRingkasan() {
  chartUptime?.destroy();
  chartAkun?.destroy();
  chartVps?.destroy();
  chartPengajuan?.destroy();
  chartIp?.destroy();
  if (!data.value) return;

  // Donut uptime cuma dekorasi (uptime bukan persentase) — penuh hijau kalau
  // tersambung, penuh merah kalau tidak, abu kalau belum pernah dicek.
  const warnaUptime =
    data.value.router.tersambung === true ? "#0f9d58"
      : data.value.router.tersambung === false ? "#d93025"
      : "#c9ccd1";
  chartUptime = new Chart(kanvasUptime.value, {
    type: "doughnut",
    data: { labels: ["Status"], datasets: [{ data: [1], backgroundColor: [warnaUptime], borderWidth: 0 }] },
    options: { ...OPSI_DONUT, plugins: { ...OPSI_DONUT.plugins, tooltip: { enabled: false } } },
  });

  const { akun_aktif, akun_dinonaktifkan, akun_gagal } = data.value.ringkasan;
  chartAkun = new Chart(kanvasAkun.value, {
    type: "doughnut",
    data: {
      labels: ["Aktif", "Nonaktif", "Gagal"],
      datasets: [{ data: [akun_aktif, akun_dinonaktifkan, akun_gagal], backgroundColor: ["#0f9d58", "#c9ccd1", "#d93025"], borderWidth: 0 }],
    },
    options: OPSI_DONUT,
  });

  const { vps_up, vps_down, vps_total } = data.value.ringkasan;
  const vpsLain = Math.max(0, vps_total - vps_up - vps_down); // status "unknown"
  chartVps = new Chart(kanvasVps.value, {
    type: "doughnut",
    data: {
      labels: ["Up", "Down", "Belum dicek"],
      datasets: [{ data: [vps_up, vps_down, vpsLain], backgroundColor: ["#0f9d58", "#d93025", "#c9ccd1"], borderWidth: 0 }],
    },
    options: OPSI_DONUT,
  });

  // Pengajuan menunggu: dekoratif kayak uptime (bukan rasio) — kuning kalau
  // ada yang menunggu, hijau kalau nol.
  const { pengajuan_menunggu } = data.value.ringkasan;
  chartPengajuan = new Chart(kanvasPengajuan.value, {
    type: "doughnut",
    data: { labels: ["Menunggu"], datasets: [{ data: [1], backgroundColor: [pengajuan_menunggu > 0 ? "#f4b400" : "#0f9d58"], borderWidth: 0 }] },
    options: { ...OPSI_DONUT, plugins: { ...OPSI_DONUT.plugins, tooltip: { enabled: false } } },
  });

  const { terpakai: ipTerpakai, tersisa: ipTersisa } = data.value.ip;
  chartIp = new Chart(kanvasIp.value, {
    type: "doughnut",
    data: {
      labels: ["Tersisa", "Terpakai"],
      datasets: [{ data: [ipTersisa, ipTerpakai], backgroundColor: ["#3b82f6", "#c9ccd1"], borderWidth: 0 }],
    },
    options: OPSI_DONUT,
  });
}

/* ---------------- grafik durasi sinkronisasi ---------------- */
const kanvasSinkron = ref(null);
const sinkronKosong = ref(true);
let chartSinkron = null;

async function muatGrafikSinkron() {
  try {
    const semua = await apiAdmin(`admin/operasi?jenis=sinkron&limit=1000&jam_terakhir=${JAM_TERAKHIR}`);
    // Semua baris jenis=sinkron (batch drift, selaraskan paket, hapus profile,
    // resolusi drift manual) — sama seperti waktu masih nempel di Operasi
    // Router. "Status drift" di bawah yang perlu difilter cuma batch drift.
    const batch = [...semua].reverse();

    sinkronKosong.value = batch.length === 0;
    if (sinkronKosong.value) return;

    await nextTick();
    chartSinkron?.destroy();

    const label = batch.map((o) => jam(o.selesai_pada));
    const akhir = label.length - 1;
    const durasi = batch.map((o) => o.durasi_ms);

    const terisi = durasi.filter((d) => d != null);
    const rataRata = terisi.length ? terisi.reduce((a, b) => a + b, 0) / terisi.length : 0;
    const diAtas = (d) => d != null && d > rataRata;

    chartSinkron = new Chart(kanvasSinkron.value, {
      type: "line",
      data: {
        labels: label,
        datasets: [{
          label: "Durasi (ms)",
          data: durasi,
          borderColor: (ctx) => (diAtas(ctx.parsed?.y) ? "#d93025" : "#0f9d58"),
          backgroundColor: "#d93025",
          segment: {
            borderColor: (ctx) => (diAtas(ctx.p0.parsed.y) || diAtas(ctx.p1.parsed.y) ? "#d93025" : "#0f9d58"),
          },
          spanGaps: true,
          tension: 0.25,
          pointRadius: 2,
          pointBackgroundColor: (ctx) => (diAtas(ctx.raw) ? "#d93025" : "#0f9d58"),
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        scales: {
          y: { title: { display: true, text: "durasi (ms)" }, beginAtZero: true },
          x: {
            afterBuildTicks(axis) {
              const target = 6;
              const step = Math.max(1, Math.ceil((axis.ticks.length - 1) / (target - 1)));
              const dipilih = axis.ticks.filter((_, i) => i % step === 0);
              if (dipilih[dipilih.length - 1]?.value !== akhir) dipilih.push({ value: akhir });
              axis.ticks = dipilih;
            },
            ticks: { font: { size: 10 } },
          },
        },
        plugins: { legend: { display: false } },
      },
    });
  } catch (e) {
    console.error("Gagal memuat grafik sinkronisasi:", e);
  }
}

/* ---------------- grafik temuan drift per pemeriksaan ---------------- */
const kanvasDrift = ref(null);
const driftKosong = ref(true);
let chartDrift = null;

async function muatGrafikDrift() {
  try {
    const semua = await apiAdmin(`admin/operasi?jenis=sinkron&limit=1000&jam_terakhir=${JAM_TERAKHIR}`);
    // Cuma baris pemeriksaan terjadwal/batch yang punya hasil.temuan; baris
    // resolusi drift manual per akun/VPS tidak (lihat DriftController::selesaikan).
    const batch = semua.filter((o) => o.hasil?.temuan !== undefined).reverse();

    driftKosong.value = batch.length === 0;
    if (driftKosong.value) return;

    await nextTick();
    chartDrift?.destroy();

    const label = batch.map((o) => jam(o.selesai_pada));
    const temuan = batch.map((o) => o.hasil.temuan);
    const akhir = label.length - 1;

    chartDrift = new Chart(kanvasDrift.value, {
      type: "line",
      data: {
        labels: label,
        datasets: [{
          label: "Tidak sinkron",
          data: temuan,
          borderColor: (ctx) => (ctx.parsed?.y > 0 ? "#d93025" : "#0f9d58"),
          backgroundColor: "#d93025",
          segment: {
            borderColor: (ctx) => (ctx.p0.parsed.y > 0 || ctx.p1.parsed.y > 0 ? "#d93025" : "#0f9d58"),
          },
          tension: 0.25,
          pointRadius: 2,
          pointBackgroundColor: (ctx) => (ctx.raw > 0 ? "#d93025" : "#0f9d58"),
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: {
            afterBuildTicks(axis) {
              // autoSkip bawaan chart.js tidak menjamin tick paling kanan
              // ikut tampil. Ambil beberapa titik spasi rata, lalu paksa
              // titik terakhir (pemeriksaan paling baru) selalu ada.
              const target = 6;
              const step = Math.max(1, Math.ceil((axis.ticks.length - 1) / (target - 1)));
              const dipilih = axis.ticks.filter((_, i) => i % step === 0);
              if (dipilih[dipilih.length - 1]?.value !== akhir) dipilih.push({ value: akhir });
              axis.ticks = dipilih;
            },
            ticks: { font: { size: 10 } },
          },
        },
        plugins: { legend: { display: false } },
      },
    });
  } catch (e) {
    // Grafik gagal muat tidak boleh menutupi dashboard yang lain.
  }
}

/* ---------------- grafik ping otomatis: VPS x waktu ---------------- */
const kanvasPing = ref(null);
const pingKosong = ref(true);
let chartPing = null;

async function muatGrafikPing() {
  try {
    // otomatis_saja=1: cuma ping terjadwal (vps:ping), ping manual "Ping
    // sekarang" tidak masuk buku besar ini sama sekali (lihat PingVpsJob).
    const semua = await apiAdmin(
      `admin/operasi?jenis=ping&otomatis_saja=1&limit=2000&jam_terakhir=${JAM_TERAKHIR}`
    );

    const relevan = semua.filter((o) => o.vps && o.hasil?.status && o.selesai_pada);
    pingKosong.value = relevan.length === 0;
    if (pingKosong.value) return;

    // Urut waktu, lama ke baru.
    relevan.sort((a, b) => new Date(a.selesai_pada) - new Date(b.selesai_pada));

    const nama = [...new Set(relevan.map((o) => o.vps))].sort();

    // Chart.js 4.5.1: batang mengambang (data: {x:[min,max], y:kategori})
    // tidak tergambar sama sekali saat indexAxis:"y" (batang horizontal) --
    // terverifikasi lewat pengujian isolasi. Dipakai gantinya: satu garis
    // per VPS pada sumbu-y kategori, warna tiap ruas ditentukan status ping
    // di titik awal ruas (segment.borderColor), sama seperti pola yang
    // sudah terbukti jalan di muatGrafikSinkron().
    const warna = (status) => (status === "down" ? "#d93025" : "#0f9d58");

    await nextTick();
    chartPing?.destroy();

    chartPing = new Chart(kanvasPing.value, {
      type: "line",
      data: {
        datasets: nama.map((v) => ({
          label: v,
          data: relevan
            .filter((o) => o.vps === v)
            .map((o) => ({ x: new Date(o.selesai_pada).getTime(), y: v, status: o.hasil.status })),
          borderWidth: 14,
          pointRadius: 0,
          tension: 0,
          spanGaps: false,
          segment: { borderColor: (ctx) => warna(ctx.p0.raw.status) },
        })),
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { type: "category", labels: nama, offset: true },
          x: {
            type: "linear",
            ticks: { font: { size: 10 }, maxTicksLimit: 6, callback: (nilai) => jam(new Date(nilai)) },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: (items) => jam(new Date(items[0].raw.x)),
              label: (item) => `${item.raw.y}: ${item.raw.status === "down" ? "Down" : "Up"}`,
            },
          },
        },
      },
    });
  } catch (e) {
    console.error("Gagal memuat grafik ping:", e);
    // Grafik gagal muat tidak boleh menutupi dashboard yang lain.
  }
}

const refOperasiRouter = ref(null);
const memuatSemua = ref(false);

async function muatSemua() {
  memuatSemua.value = true;
  galat.value = "";
  try {
    data.value = await apiAdmin("admin/dashboard");
  } catch (e) {
    galat.value = e.message;
  }
  await nextTick();
  gambarDonutRingkasan();
  await Promise.all([
    muatGrafikSinkron(),
    muatGrafikDrift(),
    muatGrafikPing(),
    refOperasiRouter.value?.muat(),
  ]);
  memuatSemua.value = false;
}

onMounted(muatSemua);

onBeforeUnmount(() => {
  chartUptime?.destroy();
  chartAkun?.destroy();
  chartVps?.destroy();
  chartPengajuan?.destroy();
  chartIp?.destroy();
  chartSinkron?.destroy();
  chartDrift?.destroy();
  chartPing?.destroy();
});
</script>

<template>
  <section class="card">
    <div style="display: flex; align-items: center; gap: 10px">
      <h1 class="section-title" style="margin: 0">Dashboard</h1>
      <div class="toolbar__spacer"></div>
      <button class="btn btn-secondary btn-sm" :disabled="memuatSemua" @click="muatSemua">
        {{ memuatSemua ? "Memuat..." : "Muat ulang" }}
      </button>
    </div>
    <p class="section-subtitle"></p>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <template v-if="data">
      <div
        v-if="data.router.tersambung !== null"
        class="notice"
        :class="data.router.tersambung ? 'notice-success' : 'notice-danger'"
      >
        <strong>MikroTik:</strong>
        <template v-if="data.router.tersambung">
          tersambung, {{ data.router.identity }}, RouterOS {{ data.router.versi }},
          uptime {{ data.router.uptime }}
        </template>
        <template v-else>
          tidak tersambung. {{ data.router.pesan }}
        </template>
      </div>

      <div class="donut-grid">
        <div class="donut-col">
          <div class="donut-wrap">
            <canvas ref="kanvasUptime"></canvas>
            <div class="donut-center">
              <div class="donut-center__nilai">{{ data.router.tersambung ? data.router.uptime : "-" }}</div>
              <div class="donut-center__label">uptime</div>
            </div>
          </div>
          <div class="donut-caption">
            <template v-if="data.router.tersambung && data.router.diperiksa_pada">
              {{ waktuSingkat(new Date(data.router.diperiksa_pada).getTime() - detikDariUptime(data.router.uptime) * 1000) }}
            </template>
            <template v-else>Belum pernah dicek</template>
          </div>
        </div>

        <div class="donut-col">
          <div class="donut-wrap">
            <canvas ref="kanvasAkun"></canvas>
            <div class="donut-center">
              <div class="donut-center__nilai">{{ data.ringkasan.akun_aktif + data.ringkasan.akun_dinonaktifkan + data.ringkasan.akun_gagal }}</div>
              <div class="donut-center__label">akun</div>
            </div>
          </div>
          <div class="donut-caption">
            {{ data.ringkasan.akun_aktif }} aktif &middot; {{ data.ringkasan.akun_dinonaktifkan }} nonaktif &middot; {{ data.ringkasan.akun_gagal }} gagal
          </div>
        </div>

        <div class="donut-col">
          <div class="donut-wrap">
            <canvas ref="kanvasVps"></canvas>
            <div class="donut-center">
              <div class="donut-center__nilai">{{ data.ringkasan.vps_total }}</div>
              <div class="donut-center__label">VPS</div>
            </div>
          </div>
          <div class="donut-caption">
            {{ data.ringkasan.vps_up }} up &middot; {{ data.ringkasan.vps_down }} down
          </div>
        </div>

        <div class="donut-col">
          <div class="donut-wrap">
            <canvas ref="kanvasPengajuan"></canvas>
            <div class="donut-center">
              <div class="donut-center__nilai">{{ data.ringkasan.pengajuan_menunggu }}</div>
              <div class="donut-center__label">menunggu</div>
            </div>
          </div>
          <div class="donut-caption">Pengajuan</div>
        </div>

        <div class="donut-col">
          <div class="donut-wrap">
            <canvas ref="kanvasIp"></canvas>
            <div class="donut-center">
              <div class="donut-center__nilai">{{ data.ip.total }}</div>
              <div class="donut-center__label">total IP</div>
            </div>
          </div>
          <div class="donut-caption">
            {{ data.ip.tersisa }} sisa &middot; {{ data.ip.terpakai }} terpakai
          </div>
        </div>
      </div>

      <div class="grafik-grid" style="margin-top: 30px">
        <div>
          <h3 style="font-size: 14px; font-weight: 600; margin: 0">Status Router</h3>
          <p class="section-subtitle" style="margin-top: 3px">
            Durasi tiap pemeriksaan sinkronisasi terjadwal, 24 jam terakhir.
            Merah = di atas rata-rata, hijau = di bawah atau sama dengan rata-rata.
          </p>
          <p v-if="sinkronKosong" style="color: var(--color-text-faint); font-size: 13px">
            Belum ada pemeriksaan sinkronisasi 24 jam terakhir.
          </p>
          <div v-show="!sinkronKosong" class="operasi-grafik">
            <canvas ref="kanvasSinkron"></canvas>
          </div>
        </div>

        <div>
          <RouterLink to="/admin/sinkronisasi" style="display: block; text-decoration: none; color: inherit">
            <h3 style="font-size: 14px; font-weight: 600; margin: 0">
              Status Data
              <span
                v-if="data.drift.terbuka > 0"
                class="badge badge-danger"
                style="margin-left: 6px"
              >{{ data.drift.terbuka }} terbuka</span>
            </h3>
            <p class="section-subtitle" style="margin-top: 3px">
              Jumlah temuan per pemeriksaan terjadwal (tiap 10 menit), 24 jam terakhir.
              Merah = ada drift saat itu, hijau = DB dan router sinkron.
              <span v-if="data.drift.terakhir_dicek" style="color: var(--color-text-faint)">
                Terakhir dicek {{ waktuSingkat(data.drift.terakhir_dicek) }}.
              </span>
            </p>
          </RouterLink>

          <p v-if="driftKosong" style="color: var(--color-text-faint); font-size: 13px">
            Belum ada pemeriksaan sinkronisasi 24 jam terakhir.
          </p>
          <div v-show="!driftKosong" class="operasi-grafik">
            <canvas ref="kanvasDrift"></canvas>
          </div>
        </div>
      </div>

      <h2 class="section-title" style="font-size: 16px; margin-top: 30px">Status VPS</h2>
      <p class="section-subtitle" style="margin-top: 3px">
        Garis menyambung dari satu pemeriksaan ping ke pemeriksaan berikutnya,
        24 jam terakhir. Hijau = up, merah = down.
      </p>
      <p v-if="pingKosong" style="color: var(--color-text-faint); font-size: 13px">
        Belum ada pemeriksaan ping otomatis.
      </p>
      <div v-show="!pingKosong" class="operasi-grafik">
        <canvas ref="kanvasPing"></canvas>
      </div>

      <OperasiRouter ref="refOperasiRouter" />

      <h2 class="section-title" style="font-size: 16px; margin-top: 30px">
        Aktivitas Admin
      </h2>
      <div class="table-scroll" style="margin-top: 12px">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 168px">Waktu</th>
              <th style="width: 150px">Aksi</th>
              <th>Keterangan</th>
              <th style="width: 130px">Oleh</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!data.audit_terakhir.length">
              <td colspan="4" style="color: var(--color-text-faint)">Belum ada aktivitas.</td>
            </tr>
            <tr v-for="(a, i) in data.audit_terakhir" :key="i">
              <td class="mono" style="white-space: nowrap">{{ waktuSingkat(a.waktu) }}</td>
              <td class="mono">{{ a.aksi }}</td>
              <td style="color: var(--color-text-muted)">{{ a.deskripsi }}</td>
              <td>{{ a.oleh || "-" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.operasi-grafik {
  height: 200px;
  margin-top: 8px;
}

.grafik-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 24px;
  margin-top: 12px;
}

.donut-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 16px;
  margin: 16px 0;
}

.donut-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.donut-wrap {
  position: relative;
  width: 100%;
  max-width: 160px;
  aspect-ratio: 1;
}

.donut-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.donut-center__nilai { font-size: 18px; font-weight: 700; color: var(--color-text); line-height: 1.1; }
.donut-center__label { font-size: 10.5px; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; }

.donut-caption { font-size: 12px; color: var(--color-text-muted); margin-top: 8px; }
</style>
