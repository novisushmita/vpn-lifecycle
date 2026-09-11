<script setup>
import { onMounted, ref } from "vue";
import { apiAdmin } from "../../lib/api";
import { tungguOperasi, labelStatus } from "../../lib/operasi";
import OperasiRouter from "./OperasiRouter.vue";

const daftar = ref([]);
const memuat = ref(false);
const memeriksa = ref(false);
const galat = ref("");
const pesan = ref("");
const filterStatus = ref("terbuka");

const labelJenis = {
  nilai_beda: "Nilai Berbeda",
  hilang_di_router: "Hilang di Router",
  yatim_di_router: "Sisa Tak Terpakai",
};

async function muat() {
  memuat.value = true;
  galat.value = "";
  try {
    daftar.value = await apiAdmin(`admin/drift?status=${filterStatus.value}`);
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

async function periksa() {
  memeriksa.value = true;
  galat.value = "";
  pesan.value = "";
  try {
    // Pemeriksaan berjalan lewat antrean; API membalas 202 lalu kita tunggu.
    await apiAdmin("admin/drift/periksa", { method: "POST" });
    pesan.value = "Pemeriksaan berjalan...";
    for (let i = 0; i < 60; i++) {
      await new Promise((r) => setTimeout(r, 2000));
      const st = await apiAdmin("admin/drift/status");
      if (!st.berjalan) {
        pesan.value = st.status === "gagal"
          ? `Pemeriksaan gagal: ${st.pesan_error || "tanpa keterangan"}.`
          : "Pemeriksaan selesai.";
        break;
      }
    }
    await muat();
  } catch (e) {
    galat.value = e.message;
  } finally {
    memeriksa.value = false;
  }
}

async function selesaikan(d, resolusi) {
  galat.value = "";
  pesan.value = "";
  try {
    const r = await apiAdmin(`admin/drift/${d.id}/selesaikan`, {
      method: "POST",
      body: { resolusi },
    });
    if (r.operasi_id) {
      await tungguOperasi(r.operasi_id, (st) => (pesan.value = labelStatus(st)));
    }
    pesan.value = "Temuan diselesaikan.";
    await muat();
  } catch (e) {
    galat.value = e.message;
  }
}

onMounted(muat);
</script>

<template>
  <section class="card">
    <h1 class="section-title">Pemeriksaan Router</h1>
    <p class="section-subtitle">
      Selisih antara data sistem dan kondisi sebenarnya di MikroTik. Biasanya
      muncul ketika konfigurasi diubah manual lewat Winbox.
    </p>

    <OperasiRouter />

    <div class="toolbar" style="margin-top: 24px">
      <button class="btn btn-primary btn-sm" :disabled="memeriksa" @click="periksa">
        {{ memeriksa ? "Memeriksa..." : "Periksa sekarang" }}
      </button>
      <select v-model="filterStatus" class="select" @change="muat">
        <option value="terbuka">Terbuka</option>
        <option value="diselesaikan">Sudah diselesaikan</option>
        <option value="diabaikan">Diabaikan</option>
      </select>
      <div class="toolbar__spacer"></div>
      <button class="btn btn-secondary btn-sm" @click="muat">Muat ulang</button>
    </div>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
    <div v-if="pesan" class="notice notice-success">{{ pesan }}</div>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>Akun</th>
            <th>Objek</th>
            <th style="width: 140px">Jenis selisih</th>
            <th>Menurut sistem</th>
            <th>Di router</th>
            <th style="width: 200px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td colspan="6" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td colspan="6" style="text-align: center; color: var(--color-text-faint)">
              Tidak ada selisih. Data sistem dan router sinkron.
            </td>
          </tr>
          <tr v-for="d in daftar" :key="d.id">
            <td class="mono">{{ d.akun || "-" }}</td>
            <td class="mono">
              {{ d.jenis_objek }}<span v-if="d.atribut">.{{ d.atribut }}</span>
            </td>
            <td>
              <span
                class="badge"
                :class="d.jenis_drift === 'nilai_beda' ? 'badge-warning' : 'badge-danger'"
              >{{ labelJenis[d.jenis_drift] }}</span>
            </td>
            <td class="mono">{{ d.nilai_db || "-" }}</td>
            <td class="mono">{{ d.nilai_router || "-" }}</td>
            <td>
              <div v-if="d.status === 'terbuka'" class="aksi-baris" style="justify-content: flex-end">
                <button
                  class="btn btn-primary btn-sm"
                  title="Router dikembalikan mengikuti data sistem"
                  @click="selesaikan(d, 'push')"
                >Terapkan ke Router</button>
                <button
                  v-if="d.jenis_drift === 'nilai_beda'"
                  class="btn btn-secondary btn-sm"
                  title="Data sistem menerima perubahan yang ada di router"
                  @click="selesaikan(d, 'pull')"
                >Ikuti Router</button>
                <button class="btn btn-secondary btn-sm" @click="selesaikan(d, 'abaikan')">
                  Abaikan
                </button>
              </div>
              <span v-else class="badge badge-neutral">{{ d.resolusi || d.status }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="notice notice-info" style="margin-top: 20px">
      <strong>Terapkan ke Router</strong> memaksa router kembali mengikuti data
      sistem. <strong>Ikuti Router</strong> menerima perubahan yang dilakukan
      langsung di router sebagai kondisi yang benar, lalu memperbarui data
      sistem. Temuan berjenis Hilang di Router dan Sisa Tak Terpakai hanya
      dapat diselesaikan dengan Terapkan ke Router.
    </div>
  </section>
</template>
