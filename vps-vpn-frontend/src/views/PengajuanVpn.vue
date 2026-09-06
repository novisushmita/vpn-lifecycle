<script setup>
import { reactive, ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { vpsList, findVpsById } from "../data/vpsList";
import { submitPengajuan, daftarKeperluanAkses } from "../data/pengajuan";

const route = useRoute();
const router = useRouter();

const form = reactive({
  nama: "",
  identitas: "",
  instansi: "",
  email: "",
  vpsId: "",
  keperluan: "",
  keperluanDetail: "",
  durasiMulai: "",
  durasiSelesai: "",
});

const errors = reactive({});
const isSubmitting = ref(false);
const galatServer = ref("");
const hasilPengajuan = ref(null); // { nomor }

// Kalau datang dari tombol "Ajukan VPN" di tabel VPS, pra-isi pilihan VPS
onMounted(() => {
  const vpsIdFromQuery = route.query.vps;
  if (vpsIdFromQuery && findVpsById(vpsIdFromQuery)) {
    form.vpsId = String(vpsIdFromQuery);
  }
});

function validate() {
  Object.keys(errors).forEach((k) => delete errors[k]);

  if (!form.nama.trim()) errors.nama = "Nama wajib diisi.";
  if (!form.identitas.trim()) errors.identitas = "Nomor identitas wajib diisi.";
  if (!form.instansi.trim()) errors.instansi = "Instansi wajib diisi.";
  if (!form.email.trim()) {
    errors.email = "Email wajib diisi.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = "Format email tidak valid.";
  }
  if (!form.vpsId) errors.vpsId = "Pilih VPS yang akan diakses.";
  if (!form.keperluan) errors.keperluan = "Pilih keperluan akses.";
  if (!form.durasiMulai) errors.durasiMulai = "Tanggal mulai wajib diisi.";
  if (!form.durasiSelesai) errors.durasiSelesai = "Tanggal selesai wajib diisi.";
  if (
    form.durasiMulai &&
    form.durasiSelesai &&
    form.durasiSelesai < form.durasiMulai
  ) {
    errors.durasiSelesai = "Tanggal selesai harus setelah tanggal mulai.";
  }

  return Object.keys(errors).length === 0;
}

async function handleSubmit() {
  if (!validate()) return;

  isSubmitting.value = true;
  galatServer.value = "";
  try {
    const { nomor } = await submitPengajuan({
      nama: form.nama,
      identitas: form.identitas,
      instansi: form.instansi,
      email: form.email,
      vpsId: form.vpsId,
      keperluan: form.keperluan,
      keperluanDetail: form.keperluanDetail,
      durasiMulai: form.durasiMulai,
      durasiSelesai: form.durasiSelesai,
    });
    hasilPengajuan.value = { nomor };
  } catch (e) {
    // Validasi server adalah batas kepercayaan yang sebenarnya; validasi di
    // sisi klien hanya untuk kenyamanan dan bisa saja terlewat.
    if (e.errors) {
      const peta = {
        vps_id: "vpsId",
        durasi_mulai: "durasiMulai",
        durasi_selesai: "durasiSelesai",
      };
      for (const [kunci, pesan] of Object.entries(e.errors)) {
        errors[peta[kunci] || kunci] = pesan[0];
      }
      galatServer.value = "Periksa kembali isian yang ditandai.";
    } else {
      galatServer.value = e.message;
    }
  } finally {
    isSubmitting.value = false;
  }
}

function tutupModal() {
  hasilPengajuan.value = null;
  Object.assign(form, {
    nama: "",
    identitas: "",
    instansi: "",
    email: "",
    vpsId: "",
    keperluan: "",
    keperluanDetail: "",
    durasiMulai: "",
    durasiSelesai: "",
  });
}

function lihatStatus() {
  const nomor = hasilPengajuan.value.nomor;
  tutupModal();
  router.push({ name: "cek-status", query: { nomor } });
}
</script>

<template>
  <section class="card">
    <h1 class="section-title">Pengajuan VPN</h1>
    <p class="section-subtitle">Lengkapi formulir berikut untuk mengajukan akses VPN.</p>

    <form novalidate @submit.prevent="handleSubmit">
      <div class="form-grid" style="margin-top: 22px">
        <div class="field">
          <label class="field-label">Nama<span class="req">*</span></label>
          <input
            v-model="form.nama"
            type="text"
            class="input"
            :class="{ 'has-error': errors.nama }"
            placeholder="Nama lengkap"
          />
          <span v-if="errors.nama" class="field-error">{{ errors.nama }}</span>
        </div>

        <div class="field">
          <label class="field-label">Identitas<span class="req">*</span></label>
          <input
            v-model="form.identitas"
            type="text"
            class="input"
            :class="{ 'has-error': errors.identitas }"
            placeholder="NIP, NIK, atau nomor identitas lain"
          />
          <span v-if="errors.identitas" class="field-error">{{ errors.identitas }}</span>
        </div>

        <div class="field">
          <label class="field-label">Instansi<span class="req">*</span></label>
          <input
            v-model="form.instansi"
            type="text"
            class="input"
            :class="{ 'has-error': errors.instansi }"
            placeholder="Nama instansi / unit kerja"
          />
          <span v-if="errors.instansi" class="field-error">{{ errors.instansi }}</span>
        </div>

        <div class="field">
          <label class="field-label">Email<span class="req">*</span></label>
          <input
            v-model="form.email"
            type="email"
            class="input"
            :class="{ 'has-error': errors.email }"
            placeholder="nama@instansi.go.id"
          />
          <span v-if="errors.email" class="field-error">{{ errors.email }}</span>
        </div>

        <div class="field span-2">
          <label class="field-label">VPS yang dipilih<span class="req">*</span></label>
          <select
            v-model="form.vpsId"
            class="select"
            :class="{ 'has-error': errors.vpsId }"
          >
            <option value="" disabled>Pilih VPS</option>
            <option v-for="vps in vpsList" :key="vps.id" :value="String(vps.id)">
              {{ vps.nama }}
            </option>
          </select>
          <span v-if="errors.vpsId" class="field-error">{{ errors.vpsId }}</span>
        </div>

        <div class="field span-2">
          <label class="field-label">Keperluan akses<span class="req">*</span></label>
          <select
            v-model="form.keperluan"
            class="select"
            :class="{ 'has-error': errors.keperluan }"
          >
            <option value="" disabled>Pilih keperluan akses</option>
            <option v-for="item in daftarKeperluanAkses" :key="item" :value="item">
              {{ item }}
            </option>
          </select>
          <span v-if="errors.keperluan" class="field-error">{{ errors.keperluan }}</span>
        </div>

        <div v-if="form.keperluan === 'Lainnya'" class="field span-2">
          <label class="field-label">Rincian keperluan</label>
          <textarea
            v-model="form.keperluanDetail"
            class="textarea"
            placeholder="Jelaskan keperluan akses Anda"
          ></textarea>
        </div>

        <div class="field">
          <label class="field-label">Durasi akses mulai<span class="req">*</span></label>
          <input
            v-model="form.durasiMulai"
            type="date"
            class="input"
            :class="{ 'has-error': errors.durasiMulai }"
          />
          <span v-if="errors.durasiMulai" class="field-error">{{ errors.durasiMulai }}</span>
        </div>
        <div class="field">
          <label class="field-label">Durasi akses sampai<span class="req">*</span></label>
          <input
            v-model="form.durasiSelesai"
            type="date"
            class="input"
            :class="{ 'has-error': errors.durasiSelesai }"
          />
          <span v-if="errors.durasiSelesai" class="field-error">{{ errors.durasiSelesai }}</span>
        </div>
      </div>

      <hr class="divider" />

      <div v-if="galatServer" class="notice notice-danger">{{ galatServer }}</div>

      <div style="display: flex; justify-content: flex-end">
        <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
          {{ isSubmitting ? "Mengirim..." : "Kirim Pengajuan" }}
        </button>
      </div>
    </form>
  </section>

  <!-- Modal konfirmasi setelah submit -->
  <div v-if="hasilPengajuan" class="modal-overlay" @click.self="tutupModal">
    <div class="modal-box">
      <button class="modal-close" @click="tutupModal">×</button>
      <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 10px">
        Pengajuan berhasil dikirim
      </h3>
      <p style="color: var(--color-text-muted); font-size: 14px; margin-bottom: 16px">
        Simpan nomor pengajuan Anda untuk memeriksa status persetujuan di menu Cek Status Pengajuan.
      </p>
      <div class="nomor-pengajuan">{{ hasilPengajuan.nomor }}</div>
      <div style="display: flex; gap: 10px; margin-top: 20px">
        <button class="btn btn-secondary" style="flex: 1" @click="tutupModal">
          Tutup
        </button>
        <button class="btn btn-primary" style="flex: 1" @click="lihatStatus">
          Cek Status
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nomor-pengajuan {
  background: var(--color-primary-soft);
  color: var(--color-primary);
  border-radius: var(--radius-md);
  padding: 14px;
  text-align: center;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.02em;
}
</style>
