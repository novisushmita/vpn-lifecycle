<script setup>
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import { vpsList, statusMuat } from "../data/vpsList";

const router = useRouter();
const cari = ref("");
const cariDiterapkan = ref("");

const daftarTersaring = computed(() => {
  const q = cariDiterapkan.value.trim().toLowerCase();
  if (!q) return vpsList;
  return vpsList.filter((v) =>
    v.nama?.toLowerCase().includes(q) || v.keterangan?.toLowerCase().includes(q)
  );
});

function terapkanCari() {
  cariDiterapkan.value = cari.value;
}

function ajukanVpn(vps) {
  router.push({ name: "pengajuan-vpn", query: { vps: vps.id } });
}
</script>

<template>
  <section class="card">
    <h1 class="section-title">VPS Tersedia</h1>
    <p class="section-subtitle"></p>

    <form style="display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap" @submit.prevent="terapkanCari">
      <input
        v-model="cari"
        class="input"
        style="max-width: 280px"
        placeholder="Cari nama atau keterangan VPS..."
      />
      <button type="submit" class="btn btn-primary" style="flex: none">Cari</button>
    </form>

    <div class="table-scroll" style="margin-top: 14px">
      <table class="table">
        <thead>
          <tr>
            <th style="width: 26%">Nama VPS</th>
            <th>Keterangan</th>
            <th style="width: 130px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="statusMuat.memuat">
            <td colspan="3" style="text-align: center; color: var(--color-text-faint)">
              Memuat daftar VPS...
            </td>
          </tr>
          <tr v-else-if="statusMuat.galat">
            <td colspan="3" style="text-align: center">
              <span class="field-error">{{ statusMuat.galat }}</span>
            </td>
          </tr>
          <tr v-else-if="!daftarTersaring.length">
            <td colspan="3" style="text-align: center; color: var(--color-text-faint)">
              {{ cariDiterapkan ? "Tidak ada VPS yang cocok." : "Belum ada VPS yang tersedia." }}
            </td>
          </tr>
          <tr v-for="vps in daftarTersaring" :key="vps.id">
            <td><strong>{{ vps.nama }}</strong></td>
            <td style="color: var(--color-text-muted)">{{ vps.keterangan }}</td>
            <td style="text-align: right">
              <button class="btn btn-primary btn-sm" @click="ajukanVpn(vps)">
                Ajukan VPN
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
