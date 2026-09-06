<script setup>
import { onMounted, ref } from "vue";
import { apiAdmin } from "../../lib/api";
import { tanggalWaktu } from "../../lib/format";

const data = ref(null);
const galat = ref("");

onMounted(async () => {
  try {
    data.value = await apiAdmin("admin/dashboard");
  } catch (e) {
    galat.value = e.message;
  }
});
</script>

<template>
  <section class="card">
    <h1 class="section-title">Dashboard</h1>
    <p class="section-subtitle">Ringkasan kondisi sistem dan router.</p>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

    <template v-if="data">
      <div
        class="notice"
        :class="data.router.tersambung === true ? 'notice-success' : data.router.tersambung === false ? 'notice-danger' : 'notice-info'"
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

      <h2 class="section-title" style="font-size: 16px; margin-top: 30px">
        Operasi router terakhir
      </h2>
      <div class="table-scroll" style="margin-top: 12px">
        <table class="table">
          <thead>
            <tr><th>Jenis</th><th>Status</th><th>Durasi</th><th>Waktu</th></tr>
          </thead>
          <tbody>
            <tr v-if="!data.operasi_terakhir.length">
              <td colspan="4" style="color: var(--color-text-faint)">Belum ada operasi.</td>
            </tr>
            <tr v-for="o in data.operasi_terakhir" :key="o.id">
              <td class="mono">{{ o.jenis }}</td>
              <td>
                <span
                  class="badge"
                  :class="o.status === 'sukses' ? 'badge-success' : o.status === 'gagal' ? 'badge-danger' : 'badge-warning'"
                >{{ o.status }}</span>
              </td>
              <td class="mono">{{ o.durasi_ms }} ms</td>
              <td class="mono" style="white-space: nowrap; color: var(--color-text-muted)">
                {{ tanggalWaktu(o.created_at) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

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
              <td class="mono" style="white-space: nowrap">{{ tanggalWaktu(a.waktu) }}</td>
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
