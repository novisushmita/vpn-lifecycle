<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { apiAdmin } from "../../lib/api";

const tab = ref("umum");

/* ---------------- pengaturan umum ---------------- */
const daftar = ref([]);
const nilai = reactive({});
const memuat = ref(false);
const menyimpan = ref(false);
const galat = ref("");
const pesan = ref("");

const berubah = computed(() =>
  daftar.value.filter((p) => String(nilai[p.kunci]) !== String(p.nilai))
);

const daftarUmum = computed(() => daftar.value.filter((p) => p.kunci !== "alamat_server_vpn"));
const alamatServer = computed(() => daftar.value.find((p) => p.kunci === "alamat_server_vpn"));

/* ---------------- tes koneksi router ---------------- */
const mengujiKoneksi = ref(false);
const hasilKoneksi = ref(null);

async function tesKoneksi() {
  mengujiKoneksi.value = true;
  hasilKoneksi.value = null;
  try {
    hasilKoneksi.value = await apiAdmin("admin/pengaturan/tes-koneksi-router", { method: "POST" });
  } catch (e) {
    hasilKoneksi.value = { tersambung: false, pesan: e.message };
  } finally {
    mengujiKoneksi.value = false;
  }
}

/* ---------------- reklaim IP ---------------- */
const ipTersisa = ref(null);
const kandidatIp = ref([]);
const memuatIp = ref(false);
const galatIp = ref("");
const pesanIp = ref("");
const mereklaim = ref(false);
const terpilih = ref([]);

const bisaDireklaim = computed(() => kandidatIp.value.filter((k) => k.bisa_direklaim));
const semuaTerpilih = computed(() =>
  bisaDireklaim.value.length > 0 && bisaDireklaim.value.every((k) => terpilih.value.includes(k.id))
);

function toggleSemua() {
  terpilih.value = semuaTerpilih.value ? [] : bisaDireklaim.value.map((k) => k.id);
}

async function muatKandidatIp() {
  memuatIp.value = true;
  galatIp.value = "";
  try {
    const r = await apiAdmin("admin/pengaturan/ip-reklaim");
    ipTersisa.value = r.ip_tersisa;
    kandidatIp.value = r.kandidat;
    terpilih.value = [];
  } catch (e) {
    galatIp.value = e.message;
  } finally {
    memuatIp.value = false;
  }
}

async function reklaimTerpilih() {
  if (!terpilih.value.length) return;
  mereklaim.value = true;
  galatIp.value = "";
  pesanIp.value = "";
  try {
    const r = await apiAdmin("admin/pengaturan/ip-reklaim", { method: "POST", body: { ids: terpilih.value } });
    pesanIp.value = r.message;
    await muatKandidatIp();
  } catch (e) {
    galatIp.value = e.message;
  } finally {
    mereklaim.value = false;
  }
}

async function muatPengaturan() {
  memuat.value = true;
  galat.value = "";
  try {
    const r = await apiAdmin("admin/pengaturan");
    daftar.value = r.daftar;
    for (const p of r.daftar) nilai[p.kunci] = p.nilai;
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

async function simpan() {
  if (!berubah.value.length) return;
  menyimpan.value = true;
  galat.value = "";
  pesan.value = "";
  try {
    const r = await apiAdmin("admin/pengaturan", {
      method: "PUT",
      body: { pengaturan: berubah.value.map((p) => ({ kunci: p.kunci, nilai: nilai[p.kunci] })) },
    });
    pesan.value = r.message;
    await muatPengaturan();
  } catch (e) {
    galat.value = e.message;
  } finally {
    menyimpan.value = false;
  }
}

function kembalikanBawaan(p) {
  nilai[p.kunci] = p.bawaan;
}

/* ---------------- paket bandwidth ---------------- */
const paket = ref([]);
const memuatPaket = ref(false);
const modal = ref(false);
const sedangEdit = ref(null);
const form = reactive({ nama: "", rx_rate: "", tx_rate: "", ppp_profile: "", keterangan: "", aktif: true });
const errors = reactive({});
const galatPaket = ref("");
const pesanPaket = ref("");

async function muatPaket() {
  memuatPaket.value = true;
  try {
    paket.value = await apiAdmin("admin/paket");
  } catch (e) {
    galatPaket.value = e.message;
  } finally {
    memuatPaket.value = false;
  }
}

function bukaTambah() {
  sedangEdit.value = null;
  Object.assign(form, { nama: "", rx_rate: "", tx_rate: "", ppp_profile: "", keterangan: "", aktif: true });
  Object.keys(errors).forEach((k) => delete errors[k]);
  modal.value = true;
}

function bukaEdit(p) {
  sedangEdit.value = p;
  Object.assign(form, {
    nama: p.nama, rx_rate: p.rx_rate, tx_rate: p.tx_rate,
    ppp_profile: p.ppp_profile, keterangan: p.keterangan || "", aktif: p.aktif,
  });
  Object.keys(errors).forEach((k) => delete errors[k]);
  modal.value = true;
}

/* Nama profile diturunkan dari nama paket supaya admin tidak perlu memikirkan
   dua nama sekaligus, tetapi tetap dapat disunting bila profile di router
   sudah terlanjur bernama lain. */
function usulkanProfile() {
  if (sedangEdit.value) return;
  const bersih = form.nama.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
  form.ppp_profile = bersih ? `vpn-${bersih}` : "";
}

async function simpanPaket() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  galatPaket.value = "";
  try {
    const jalur = sedangEdit.value ? `admin/paket/${sedangEdit.value.id}` : "admin/paket";
    const r = await apiAdmin(jalur, { method: sedangEdit.value ? "PUT" : "POST", body: { ...form } });
    pesanPaket.value = r.message;
    modal.value = false;
    await muatPaket();
  } catch (e) {
    if (e.errors) Object.assign(errors, Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
    else galatPaket.value = e.message;
  }
}

async function hapusPaket(p) {
  galatPaket.value = "";
  pesanPaket.value = "";
  try {
    const r = await apiAdmin(`admin/paket/${p.id}`, { method: "DELETE" });
    pesanPaket.value = r.message;
    await muatPaket();
  } catch (e) {
    galatPaket.value = e.message;
  }
}

async function selaraskan(p) {
  galatPaket.value = "";
  try {
    const r = await apiAdmin(`admin/paket/${p.id}/selaraskan`, { method: "POST" });
    pesanPaket.value = `${r.message} (${p.ppp_profile})`;
  } catch (e) {
    galatPaket.value = e.message;
  }
}

onMounted(async () => {
  await muatPengaturan();
  await muatPaket();
});
</script>

<template>
  <section class="card">
    <h1 class="section-title">Pengaturan</h1>
    <p class="section-subtitle"></p>

    <div class="tabs">
      <button class="tabs__item" :class="{ 'is-active': tab === 'umum' }" @click="tab = 'umum'">
        Umum
      </button>
      <button class="tabs__item" :class="{ 'is-active': tab === 'server' }" @click="tab = 'server'">
        Server VPN
      </button>
      <button class="tabs__item" :class="{ 'is-active': tab === 'reklaim' }" @click="tab = 'reklaim'; if (!kandidatIp.length) muatKandidatIp()">
        Reklaim IP
      </button>
      <button class="tabs__item" :class="{ 'is-active': tab === 'paket' }" @click="tab = 'paket'">
        Paket Bandwidth
      </button>
    </div>

    <!-- ==================== UMUM ==================== -->
    <template v-if="tab === 'umum'">
      <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
      <div v-if="pesan" class="notice notice-success">{{ pesan }}</div>

      <p v-if="memuat" style="color: var(--color-text-faint)">Memuat...</p>

      <div v-else class="form-grid">
        <div v-for="p in daftarUmum" :key="p.kunci" class="field">
          <label class="field-label">{{ p.label }}</label>

          <input
            v-if="p.tipe === 'angka'"
            v-model.number="nilai[p.kunci]"
            type="number"
            class="input mono"
            :min="p.min"
            :max="p.max"
          />
          <input v-else v-model="nilai[p.kunci]" class="input mono" />

          <span class="field-hint">{{ p.bantuan }}</span>
          <span
            v-if="String(nilai[p.kunci]) !== String(p.bawaan)"
            class="field-hint"
          >
            Bawaan: <span class="mono">{{ p.bawaan }}</span>
            <button
              type="button"
              class="btn btn-secondary btn-sm"
              style="margin-left: 6px; height: 20px; padding: 0 7px; font-size: 11px"
              @click="kembalikanBawaan(p)"
            >Kembalikan</button>
          </span>
        </div>
      </div>

      <hr class="divider" />

      <div class="aksi-baris">
        <button class="btn btn-primary" :disabled="menyimpan || !berubah.length" @click="simpan">
          {{ menyimpan ? "Menyimpan..." : berubah.length
              ? `Simpan ${berubah.length} perubahan` : "Tidak ada perubahan" }}
        </button>
        <button class="btn btn-secondary" :disabled="menyimpan" @click="muatPengaturan">
          Batalkan perubahan
        </button>
      </div>

      <div class="notice notice-info" style="margin-top: 16px">
        Selang pemeriksaan dibaca ulang oleh penjadwal pada siklus berikutnya.
        Bila <span class="mono">schedule:work</span> sedang berjalan, hentikan
        dan jalankan kembali agar perubahan selang waktu langsung berlaku.
      </div>
    </template>

    <!-- ==================== SERVER VPN ==================== -->
    <template v-else-if="tab === 'server'">
      <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
      <div v-if="pesan" class="notice notice-success">{{ pesan }}</div>

      <p v-if="memuat" style="color: var(--color-text-faint)">Memuat...</p>

      <template v-else-if="alamatServer">
        <div class="field" style="max-width: 420px">
          <label class="field-label">{{ alamatServer.label }}</label>
          <div style="display: flex; gap: 8px">
            <input v-model="nilai[alamatServer.kunci]" class="input mono" style="flex: 1" />
            <button class="btn btn-secondary" :disabled="mengujiKoneksi" @click="tesKoneksi">
              {{ mengujiKoneksi ? "Menguji..." : "Tes koneksi ke MikroTik" }}
            </button>
          </div>
          <span class="field-hint">{{ alamatServer.bantuan }}</span>
        </div>

        <div v-if="hasilKoneksi" class="notice" :class="hasilKoneksi.tersambung ? 'notice-success' : 'notice-danger'" style="margin-top: 12px; max-width: 420px">
          <template v-if="hasilKoneksi.tersambung">
            Tersambung ke {{ hasilKoneksi.identity }} (RouterOS {{ hasilKoneksi.versi }}, {{ hasilKoneksi.board }}).
          </template>
          <template v-else>{{ hasilKoneksi.pesan }}</template>
        </div>

        <hr class="divider" />

        <div class="aksi-baris">
          <button class="btn btn-primary" :disabled="menyimpan || !berubah.length" @click="simpan">
            {{ menyimpan ? "Menyimpan..." : berubah.length
                ? `Simpan ${berubah.length} perubahan` : "Tidak ada perubahan" }}
          </button>
          <button class="btn btn-secondary" :disabled="menyimpan" @click="muatPengaturan">
            Batalkan perubahan
          </button>
        </div>
      </template>
    </template>

    <!-- ==================== REKLAIM IP ==================== -->
    <template v-else-if="tab === 'reklaim'">
      <p class="section-subtitle">
        IP dari akun yang sudah dihapus tidak otomatis dipakai ulang (lihat
        <span class="mono">ip_tersisa</span>: <strong>{{ ipTersisa ?? "-" }}</strong>).
        Reklaim manual hanya boleh untuk akun yang tidak punya temuan drift terbuka,
        supaya IP yang direklaim dipastikan sudah bersih dari sisa aturan firewall di router.
      </p>

      <div v-if="galatIp" class="notice notice-danger">{{ galatIp }}</div>
      <div v-if="pesanIp" class="notice notice-success">{{ pesanIp }}</div>

      <div class="toolbar" v-if="kandidatIp.length">
        <button class="btn btn-primary btn-sm" :disabled="!terpilih.length || mereklaim" @click="reklaimTerpilih">
          {{ mereklaim ? "Memproses..." : `Reklaim terpilih (${terpilih.length})` }}
        </button>
        <div class="toolbar__spacer"></div>
        <button class="btn btn-secondary btn-sm" @click="muatKandidatIp">Muat ulang</button>
      </div>

      <div class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 30px"><input type="checkbox" :checked="semuaTerpilih" @change="toggleSemua" /></th>
              <th>Username</th>
              <th style="width: 130px">IP</th>
              <th style="width: 130px">Dihapus pada</th>
              <th style="width: 140px">VPS sebelumnya</th>
              <th style="width: 140px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="memuatIp">
              <td colspan="6" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
            </tr>
            <tr v-else-if="!kandidatIp.length">
              <td colspan="6" style="text-align: center; color: var(--color-text-faint)">Tidak ada akun terhapus dengan IP tersisa.</td>
            </tr>
            <tr v-for="k in kandidatIp" :key="k.id">
              <td><input v-if="k.bisa_direklaim" type="checkbox" :value="k.id" v-model="terpilih" /></td>
              <td class="mono">{{ k.username }}</td>
              <td class="mono">{{ k.ip_vpn }}</td>
              <td>{{ k.dihapus_pada ? new Date(k.dihapus_pada).toLocaleString("id-ID") : "-" }}</td>
              <td>{{ k.vps }}</td>
              <td style="text-align: right">
                <span v-if="!k.bisa_direklaim" class="badge badge-neutral" title="Masih ada temuan drift terbuka untuk akun ini">
                  belum bisa direklaim
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ==================== PAKET ==================== -->
    <template v-else>
      <div v-if="galatPaket" class="notice notice-danger">{{ galatPaket }}</div>
      <div v-if="pesanPaket" class="notice notice-success">{{ pesanPaket }}</div>

      <div class="toolbar">
        <button class="btn btn-primary btn-sm" @click="bukaTambah">Tambah paket</button>
        <div class="toolbar__spacer"></div>
        <button class="btn btn-secondary btn-sm" @click="muatPaket">Muat ulang</button>
      </div>

      <div class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th style="width: 110px">Kecepatan</th>
              <th style="width: 150px">PPP profile</th>
              <th>Keterangan</th>
              <th style="width: 70px">Akun</th>
              <th style="width: 240px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="memuatPaket">
              <td colspan="6" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
            </tr>
            <tr v-for="p in paket" :key="p.id">
              <td>
                <strong>{{ p.nama }}</strong>
                <span v-if="!p.aktif" class="badge badge-neutral" style="margin-left: 6px">nonaktif</span>
              </td>
              <td class="mono">{{ p.rate_limit }}</td>
              <td class="mono">{{ p.ppp_profile }}</td>
              <td style="color: var(--color-text-muted)">{{ p.keterangan }}</td>
              <td class="mono">{{ p.jumlah_akun }}</td>
              <td>
                <div class="aksi-baris" style="justify-content: flex-end">
                  <button
                    class="btn btn-secondary btn-sm"
                    title="Pasang ulang profile ini di router"
                    @click="selaraskan(p)"
                  >Selaraskan</button>
                  <button class="btn btn-secondary btn-sm" @click="bukaEdit(p)">Ubah</button>
                  <button class="btn btn-danger btn-sm" @click="hapusPaket(p)">Hapus</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="notice notice-info" style="margin-top: 16px">
        Menambah atau mengubah paket juga membuat dan memperbarui PPP profile
        yang bersangkutan di MikroTik. Tanpa itu, paket hanya menjadi baris di
        basis data dan provisioning akan gagal begitu ada akun memakainya.
      </div>
    </template>
  </section>

  <!-- modal paket -->
  <div v-if="modal" class="modal-overlay" @click.self="modal = false">
    <div class="modal-box" style="max-width: 500px">
      <button class="modal-close" @click="modal = false">×</button>
      <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 14px">
        {{ sedangEdit ? "Ubah paket" : "Tambah paket" }}
      </h3>

      <div class="field">
        <label class="field-label">Nama paket<span class="req">*</span></label>
        <input
          v-model="form.nama"
          class="input"
          :class="{ 'has-error': errors.nama }"
          placeholder="Prioritas"
          @input="usulkanProfile"
        />
        <span v-if="errors.nama" class="field-error">{{ errors.nama }}</span>
      </div>

      <div class="form-grid" style="margin-top: 12px">
        <div class="field">
          <label class="field-label">Kecepatan unduh<span class="req">*</span></label>
          <input v-model="form.rx_rate" class="input mono" :class="{ 'has-error': errors.rx_rate }" placeholder="5M" />
          <span v-if="errors.rx_rate" class="field-error">{{ errors.rx_rate }}</span>
        </div>
        <div class="field">
          <label class="field-label">Kecepatan unggah<span class="req">*</span></label>
          <input v-model="form.tx_rate" class="input mono" :class="{ 'has-error': errors.tx_rate }" placeholder="5M" />
          <span v-if="errors.tx_rate" class="field-error">{{ errors.tx_rate }}</span>
        </div>
      </div>
      <span class="field-hint">Format RouterOS: 512k, 2M, 10M.</span>

      <div class="field" style="margin-top: 12px">
        <label class="field-label">Nama PPP profile<span class="req">*</span></label>
        <input v-model="form.ppp_profile" class="input mono" :class="{ 'has-error': errors.ppp_profile }" />
        <span v-if="errors.ppp_profile" class="field-error">{{ errors.ppp_profile }}</span>
        <span class="field-hint">Nama objek yang dibuat di router. Diusulkan dari nama paket.</span>
      </div>

      <div class="field" style="margin-top: 12px">
        <label class="field-label">Keterangan</label>
        <input v-model="form.keterangan" class="input" placeholder="Untuk siapa paket ini ditujukan" />
      </div>

      <label style="display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 12.5px">
        <input v-model="form.aktif" type="checkbox" />
        Dapat dipilih saat menyetujui pengajuan
      </label>

      <div style="display: flex; gap: 8px; margin-top: 16px">
        <button class="btn btn-secondary" style="flex: 1" @click="modal = false">Batal</button>
        <button class="btn btn-primary" style="flex: 1" @click="simpanPaket">Simpan</button>
      </div>
    </div>
  </div>
</template>
