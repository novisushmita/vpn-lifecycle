<script setup>
import { ref, onMounted } from "vue";
import { useRoute } from "vue-router";
import { cekStatusPengajuan, ajukanPerpanjangan } from "../data/pengajuan";

const route = useRoute();

const nomorCek = ref("");
const statusHasil = ref(null); // object atau 'not-found'
const isChecking = ref(false);

async function cekStatus() {
  if (!nomorCek.value.trim()) return;
  isChecking.value = true;
  statusHasil.value = null;
  try {
    const result = await cekStatusPengajuan(nomorCek.value);
    statusHasil.value = result || "not-found";
  } finally {
    isChecking.value = false;
  }
}

onMounted(() => {
  if (route.query.nomor) {
    nomorCek.value = String(route.query.nomor);
    cekStatus();
  }
});

// Nilai status mengikuti backend: diajukan, ditinjau, disetujui, ditolak.
const statusBadge = {
  diajukan: { text: "Diajukan", cls: "badge-warning" },
  ditinjau: { text: "Sedang Ditinjau", cls: "badge-warning" },
  disetujui: { text: "Disetujui", cls: "badge-success" },
  ditolak: { text: "Ditolak", cls: "badge-danger" },
};

const badge = (status) =>
  statusBadge[status] || { text: status, cls: "badge-warning" };

// --- perpanjangan masa akses ---
const modalPerpanjang = ref(false);
const formPerpanjang = ref({ durasi_selesai: "", keperluan: "" });
const galatPerpanjang = ref("");
const hasilPerpanjang = ref("");
const mengirim = ref(false);

async function kirimPerpanjangan() {
  galatPerpanjang.value = "";
  mengirim.value = true;
  try {
    const r = await ajukanPerpanjangan({
      nomor: statusHasil.value.nomor,
      ...formPerpanjang.value,
    });
    hasilPerpanjang.value = r.nomor;
    modalPerpanjang.value = false;
  } catch (e) {
    galatPerpanjang.value = e.message;
  } finally {
    mengirim.value = false;
  }
}
</script>

<template>
  <section class="card">
    <h1 class="section-title">Cek Status Pengajuan</h1>
    <p class="section-subtitle">Masukkan nomor pengajuan yang Anda terima setelah mengirim formulir Pengajuan VPN.</p>

    <div class="cek-status__form">
      <input
        v-model="nomorCek"
        type="text"
        class="input"
        placeholder="Contoh: VPN-2026-0001"
        @keyup.enter="cekStatus"
      />
      <button class="btn btn-primary" :disabled="isChecking" @click="cekStatus">
        {{ isChecking ? "Mencari..." : "Cek Status" }}
      </button>
    </div>

    <div v-if="statusHasil === 'not-found'" style="margin-top: 16px">
      <span class="field-error">Nomor pengajuan tidak ditemukan. Periksa kembali penulisannya.</span>
    </div>

    <div v-else-if="statusHasil" class="status-result">
      <div class="status-result__row">
        <span>Nomor</span>
        <strong>{{ statusHasil.nomor }}</strong>
      </div>
      <div class="status-result__row">
        <span>Nama</span>
        <strong>{{ statusHasil.nama }}</strong>
      </div>
      <div class="status-result__row">
        <span>Instansi</span>
        <strong>{{ statusHasil.instansi }}</strong>
      </div>
      <div class="status-result__row">
        <span>VPS</span>
        <strong>{{ statusHasil.vps }}</strong>
      </div>
      <div class="status-result__row">
        <span>Status</span>
        <span class="badge" :class="badge(statusHasil.status).cls">
          {{ badge(statusHasil.status).text }}
        </span>
      </div>
      <div class="status-result__row">
        <span>Catatan</span>
        <span style="color: var(--color-text-muted)">{{ statusHasil.catatan }}</span>
      </div>
      <div v-if="statusHasil.berlaku_sampai" class="status-result__row">
        <span>Berlaku sampai</span>
        <strong>{{ statusHasil.berlaku_sampai }}</strong>
      </div>
    </div>

    <div v-if="hasilPerpanjang" class="notice notice-success">
      Permintaan perpanjangan terkirim dengan nomor
      <strong>{{ hasilPerpanjang }}</strong>. Simpan nomor ini untuk memeriksa
      statusnya.
    </div>

    <div v-if="statusHasil && statusHasil.berlaku_sampai && !hasilPerpanjang" style="margin-top: 16px">
      <button class="btn btn-secondary btn-sm" @click="modalPerpanjang = true">
        Ajukan perpanjangan
      </button>
    </div>
  </section>

  <div v-if="modalPerpanjang" class="modal-overlay" @click.self="modalPerpanjang = false">
    <div class="modal-box">
      <button class="modal-close" @click="modalPerpanjang = false">×</button>
      <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 6px">
        Ajukan perpanjangan
      </h3>
      <p style="color: var(--color-text-muted); font-size: 14px">
        Akses saat ini berlaku sampai
        <strong>{{ statusHasil?.berlaku_sampai }}</strong>.
      </p>

      <div class="field" style="margin-top: 14px">
        <label class="field-label">Perpanjang sampai<span class="req">*</span></label>
        <input v-model="formPerpanjang.durasi_selesai" type="date" class="input" />
      </div>

      <div class="field" style="margin-top: 12px">
        <label class="field-label">Alasan perpanjangan<span class="req">*</span></label>
        <input
          v-model="formPerpanjang.keperluan"
          class="input"
          placeholder="Contoh: pekerjaan maintenance belum selesai"
        />
      </div>

      <div v-if="galatPerpanjang" class="notice notice-danger">{{ galatPerpanjang }}</div>

      <div style="display: flex; gap: 10px; margin-top: 16px">
        <button class="btn btn-secondary" style="flex: 1" @click="modalPerpanjang = false">
          Batal
        </button>
        <button
          class="btn btn-primary"
          style="flex: 1"
          :disabled="mengirim"
          @click="kirimPerpanjangan"
        >{{ mengirim ? "Mengirim..." : "Kirim" }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cek-status__form {
  display: flex;
  gap: 10px;
  margin-top: 20px;
  max-width: 420px;
}

@media (max-width: 480px) {
  .cek-status__form {
    flex-direction: column;
  }
}

.status-result {
  margin-top: 24px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: 480px;
}

.status-result__row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  font-size: 13px;
}

.status-result__row span:first-child {
  color: var(--color-text-faint);
}
</style>
