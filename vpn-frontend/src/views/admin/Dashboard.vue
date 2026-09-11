<script setup>
import { onMounted, ref } from "vue";
import { apiAdmin } from "../../lib/api";
import { waktuSingkat } from "../../lib/format";
import OperasiRouter from "./OperasiRouter.vue";

const data = ref(null);
const galat = ref("");
const memeriksaKoneksi = ref(false);

async function muat() {
  try {
    data.value = await apiAdmin("admin/dashboard");
  } catch (e) {
    galat.value = e.message;
  }
}

/** Cek koneksi router sekarang, di luar jadwal tiap menit. */
async function cekKoneksiSekarang() {
  memeriksaKoneksi.value = true;
  galat.value = "";
  const sebelum = data.value?.router?.diperiksa_pada;
  try {
    await apiAdmin("admin/dashboard/cek-koneksi", { method: "POST" });
    // Job berjalan di queue; tunggu sampai cache-nya benar-benar berubah.
    for (let i = 0; i < 15; i++) {
      await new Promise((r) => setTimeout(r, 1000));
      await muat();
      if (data.value?.router?.diperiksa_pada !== sebelum) break;
    }
  } catch (e) {
    galat.value = e.message;
  } finally {
    memeriksaKoneksi.value = false;
  }
}

onMounted(muat);
</script>

<template>
  <section class="card">
    <h1 class="section-title">Dashboard</h1>
    <p class="section-subtitle"></p>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <template v-if="data">
      <div
        class="notice"
        :class="data.router.tersambung === true ? 'notice-success' : data.router.tersambung === false ? 'notice-danger' : 'notice-info'"
        style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap"
      >
        <div style="flex: 1; min-width: 200px">
          <strong>MikroTik:</strong>
          <template v-if="data.router.tersambung">
            tersambung, {{ data.router.identity }}, RouterOS {{ data.router.versi }},
            uptime {{ data.router.uptime }}
          </template>
          <template v-else>
            tidak tersambung. {{ data.router.pesan }}
          </template>
          <span v-if="data.router.diperiksa_pada" style="opacity: .7">
            · diperiksa {{ waktuSingkat(data.router.diperiksa_pada) }}
          </span>
        </div>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="memeriksaKoneksi"
          @click="cekKoneksiSekarang"
        >{{ memeriksaKoneksi ? "Memeriksa..." : "Cek sekarang" }}</button>
      </div>

      <div class="stat-grid">
        <RouterLink
          class="stat"
          :class="{ 'stat--warn': data.ringkasan.pengajuan_menunggu > 0 }"
          to="/admin/pengajuan?status=menunggu"
        >
          <div class="stat__label">Pengajuan menunggu</div>
          <div class="stat__value">{{ data.ringkasan.pengajuan_menunggu }}</div>
        </RouterLink>

        <RouterLink class="stat stat--ok" to="/admin/akun?status=aktif">
          <div class="stat__label">Akun aktif</div>
          <div class="stat__value">{{ data.ringkasan.akun_aktif }}</div>
        </RouterLink>

        <RouterLink class="stat" to="/admin/akun?status=dinonaktifkan">
          <div class="stat__label">Akun nonaktif</div>
          <div class="stat__value">{{ data.ringkasan.akun_dinonaktifkan }}</div>
        </RouterLink>

        <RouterLink
          class="stat"
          :class="{ 'stat--danger': data.ringkasan.akun_gagal > 0 }"
          to="/admin/akun?status=gagal_provision"
        >
          <div class="stat__label">Gagal diproses</div>
          <div class="stat__value">{{ data.ringkasan.akun_gagal }}</div>
        </RouterLink>

        <RouterLink class="stat" to="/admin/vps">
          <div class="stat__label">Total VPS</div>
          <div class="stat__value">{{ data.ringkasan.vps_total }}</div>
        </RouterLink>

        <RouterLink
          class="stat"
          :class="{ 'stat--danger': data.ringkasan.vps_down > 0 }"
          to="/admin/vps"
        >
          <div class="stat__label">VPS tidak merespons</div>
          <div class="stat__value">{{ data.ringkasan.vps_down }}</div>
        </RouterLink>

        <!-- Tidak punya halaman tujuan, jadi sengaja tidak dapat diklik. -->
        <div class="stat stat--statis">
          <div class="stat__label">Sisa alamat IP</div>
          <div class="stat__value">{{ data.ringkasan.ip_tersisa }}</div>
        </div>
      </div>

      <OperasiRouter />

      <h2 class="section-title" style="font-size: 16px; margin-top: 30px">
        Jejak aktivitas admin
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
