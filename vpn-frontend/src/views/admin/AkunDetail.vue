<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiAdmin } from "../../lib/api";
import { tungguOperasi, labelStatus } from "../../lib/operasi";
import { waktuSingkat } from "../../lib/format";
import Icon from "../../components/Icon.vue";

const route = useRoute();
const router = useRouter();

const a = ref(null);
const operasi = ref([]);
const sesi = ref([]);
const kredensial = ref(null);
const galat = ref("");
const pesan = ref("");
const memproses = ref(false);
const modalHapus = ref(false);
const modalEdit = ref(false);
const pilihanVps = ref([]);
const pilihanPaket = ref([]);
const editErrors = ref({});
const editForm = reactive({ username: "", password: "", vps_id: null, paket_bandwidth_id: null, putus_sesi: false });
let timerEdit;
let ditutup = false;
onBeforeUnmount(() => { ditutup = true; clearTimeout(timerEdit); });

async function bukaEdit() {
  galat.value = "";
  editErrors.value = {};
  try {
    const [vps, paket] = await Promise.all([apiAdmin("admin/vps"), apiAdmin("admin/paket-bandwidth")]);
    pilihanVps.value = (vps.data ?? vps).filter(v => v.aktif && !v.sedang_dihapus);
    pilihanPaket.value = paket;
    Object.assign(editForm, { username: a.value.username, password: "", vps_id: a.value.vps_id, paket_bandwidth_id: a.value.paket_bandwidth_id, putus_sesi: false });
    modalEdit.value = true;
  } catch (e) { galat.value = e.message; }
}

async function pantauEdit(id, mulai = Date.now()) {
  if (ditutup) return;
  try {
    operasi.value = await apiAdmin(`admin/akun/${route.params.id}/operasi`);
    const op = operasi.value.find(o => o.id === id);
    if (op && ["sukses", "gagal"].includes(op.status)) {
      memproses.value = false;
      kredensial.value = null;
      await muat();
      if (op.status === "sukses") pesan.value = "Perubahan akun berhasil diterapkan ke MikroTik.";
      else { pesan.value = ""; galat.value = op.pesan_error || "Edit gagal. Buka Edit akun untuk mencoba kembali."; }
      return;
    }
    if (Date.now() - mulai > 180000) {
      pesan.value = "Edit belum selesai. Muat kembali halaman untuk melanjutkan pemeriksaan status.";
      return;
    }
    timerEdit = setTimeout(() => pantauEdit(id, mulai), 2000);
  } catch (e) { galat.value = `Status edit belum dapat diperiksa: ${e.message}. Muat kembali halaman untuk memeriksa hasil.`; }
}

async function simpanEdit() {
  memproses.value = true;
  galat.value = "";
  pesan.value = "";
  editErrors.value = {};
  try {
    const hasil = await apiAdmin(`admin/akun/${a.value.id}`, { method: "PUT", body: { ...editForm } });
    editForm.password = "";
    modalEdit.value = false;
    pesan.value = "Perubahan sedang diproses. Data akun diperbarui setelah MikroTik berhasil diubah.";
    await pantauEdit(hasil.operasi_id);
  } catch (e) {
    memproses.value = false;
    editErrors.value = e.errors ?? {};
    galat.value = e.message;
  }
}

const warna = {
  aktif: "badge-success",
  dinonaktifkan: "badge-neutral",
  gagal_provision: "badge-danger",
  kedaluwarsa: "badge-danger",
  akan_kedaluwarsa: "badge-warning",
};

async function muat() {
  try {
    const hasil = await apiAdmin(`admin/akun/${route.params.id}`);
    a.value = hasil.data ?? hasil;
    operasi.value = await apiAdmin(`admin/akun/${route.params.id}/operasi`);
    sesi.value = await apiAdmin(`admin/akun/${route.params.id}/sesi`);
  } catch (e) {
    galat.value = e.message;
  }
}

async function aksi(jalur, sukses) {
  galat.value = "";
  pesan.value = "";
  memproses.value = true;
  try {
    // Operasi ke router berjalan di antrean: balasan berisi nomor operasi,
    // bukan hasil akhir. Antarmuka menunggu baris itu selesai.
    const r = await apiAdmin(`admin/akun/${a.value.id}/${jalur}`, { method: "POST" });
    if (r.operasi_id) {
      await tungguOperasi(r.operasi_id, (st) => (pesan.value = labelStatus(st)));
    }
    pesan.value = sukses;
    await muat();
  } catch (e) {
    galat.value = e.message;
    await muat();
  } finally {
    memproses.value = false;
  }
}

async function lihatKredensial() {
  galat.value = "";
  try {
    kredensial.value = await apiAdmin(`admin/akun/${a.value.id}/kredensial`);
  } catch (e) {
    galat.value = e.message;
  }
}

async function hapus() {
  memproses.value = true;
  galat.value = "";
  try {
    const r = await apiAdmin(`admin/akun/${a.value.id}`, { method: "DELETE" });
    if (r.operasi_id) await tungguOperasi(r.operasi_id);
    router.push("/admin/akun");
  } catch (e) {
    galat.value = e.message;
    modalHapus.value = false;
  } finally {
    memproses.value = false;
  }
}

function durasi(detik) {
  if (detik === null || detik === undefined) return "-";
  const j = Math.floor(detik / 3600);
  const m = Math.floor((detik % 3600) / 60);
  const d = Math.floor(detik % 60);
  if (j > 0) return `${j} jam ${m} mnt`;
  if (m > 0) return `${m} mnt ${d} dtk`;
  return `${d} dtk`;
}

/* Penanda waktu yang berdetak, dipakai menghitung uptime sesi yang sedang
   berjalan. durasi_detik di basis data baru terisi ketika sesi berakhir,
   sehingga sesi aktif harus dihitung dari waktu mulainya. */
const sekarang = ref(Date.now());
let jamTangan = null;

/** Durasi satu baris sesi: yang selesai memakai nilai tersimpan,
 *  yang masih berjalan dihitung dari mulai_pada sampai sekarang. */
function durasiSesi(s) {
  if (!s.aktif) return durasi(s.durasi_detik);
  return durasi(Math.max(0, Math.floor((sekarang.value - new Date(s.mulai_pada)) / 1000)));
}

function ukuran(bytes) {
  if (!bytes) return "0 B";
  const satuan = ["B", "KB", "MB", "GB"];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 3);
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${satuan[i]}`;
}

const totalDurasi = () =>
  sesi.value.reduce((t, s) => {
    if (s.aktif) return t + Math.floor((sekarang.value - new Date(s.mulai_pada)) / 1000);
    return t + (s.durasi_detik || 0);
  }, 0);

onMounted(async () => {
  // Cukup sekali per detik: hanya untuk menampilkan uptime berjalan.
  jamTangan = setInterval(() => (sekarang.value = Date.now()), 1000);
  await muat();
  const pending = operasi.value.find(o => o.jenis === "edit" && ["antre", "berjalan"].includes(o.status));
  if (pending) { memproses.value = true; await pantauEdit(pending.id); }
});

// Hentikan jam saat meninggalkan halaman supaya tidak ada interval menggantung.
onBeforeUnmount(() => clearInterval(jamTangan));
</script>

<template>
  <section class="card">
    <button class="btn-kembali" @click="router.back()">
      <Icon name="chevrons-left" :size="15" /> Kembali
    </button>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
    <div v-if="pesan" class="notice notice-success">{{ pesan }}</div>

    <template v-if="a">
      <h1 class="section-title mono" style="margin-top: 16px">
        {{ a.username }}
        <span class="badge" :class="warna[a.status]" style="margin-left: 8px">
          {{ a.status_label }}
        </span>
      </h1>

      <div v-if="a.pesan_error" class="notice notice-danger">
        <strong>Penyebab kegagalan:</strong> {{ a.pesan_error }}
      </div>

      <div class="detail-grid">
        <div class="detail-item">
          <div class="detail-item__label">VPS yang boleh diakses</div>
          <div class="detail-item__value">
            {{ a.vps?.nama }}
            <span class="mono" style="color: var(--color-text-faint)">({{ a.vps?.alamat_ip }})</span>
          </div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Alamat VPN</div>
          <div class="detail-item__value mono">{{ a.ip_vpn }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Paket bandwidth</div>
          <div class="detail-item__value">{{ a.paket?.nama }}, {{ a.paket?.rate_limit }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Masa berlaku</div>
          <div class="detail-item__value">{{ a.mulai_pada }} sampai {{ a.selesai_pada }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Nomor pengajuan</div>
          <div class="detail-item__value mono">{{ a.pengajuan_nomor }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Terakhir disinkron</div>
          <div class="detail-item__value">
            {{ waktuSingkat(a.disinkron_pada) }}
          </div>
        </div>
      </div>

      <!-- Status sinkronisasi: identitas objek di router -->
      <hr class="divider" />
      <h2 class="section-title" style="font-size: 16px">Objek di router</h2>
      <div class="detail-grid">
        <div class="detail-item">
          <div class="detail-item__label">PPP secret</div>
          <div class="detail-item__value mono">{{ a.router.secret_id || "-" }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Address list</div>
          <div class="detail-item__value mono">{{ a.router.address_list_id || "-" }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Aturan firewall</div>
          <div class="detail-item__value mono">{{ a.router.firewall_id || "-" }}</div>
        </div>
      </div>

      <!-- Kredensial: dicatat audit setiap kali dibuka -->
      <hr class="divider" />
      <h2 class="section-title" style="font-size: 16px">Kredensial</h2>
      <p class="section-subtitle">
        Setiap kali kredensial ditampilkan, tindakan ini dicatat pada jejak audit.
      </p>
      <div v-if="!kredensial" style="margin-top: 10px">
        <button class="btn btn-warning btn-sm" @click="lihatKredensial">
          Tampilkan kredensial
        </button>
      </div>
      <div v-else class="detail-grid">
        <div class="detail-item">
          <div class="detail-item__label">Server VPN</div>
          <div class="detail-item__value mono">{{ kredensial.server }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Username</div>
          <div class="detail-item__value mono">{{ kredensial.username }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-item__label">Password</div>
          <div class="detail-item__value mono">{{ kredensial.password }}</div>
        </div>
      </div>

      <!-- Transisi siklus hidup -->
      <hr class="divider" />
      <h2 class="section-title" style="font-size: 16px">Tindakan</h2>
      <div class="aksi-baris" style="margin-top: 10px">
        <button v-if="['aktif', 'akan_kedaluwarsa', 'dinonaktifkan', 'kedaluwarsa'].includes(a.status)" class="btn btn-secondary" :disabled="memproses" @click="bukaEdit">Edit akun</button>
        <button
          v-if="a.status === 'aktif' || a.status === 'akan_kedaluwarsa'"
          class="btn btn-warning"
          :disabled="memproses"
          @click="aksi('nonaktifkan', 'Akun dinonaktifkan dan sesi aktif diputus.')"
        >Nonaktifkan</button>

        <button
          v-if="a.status === 'dinonaktifkan'"
          class="btn btn-primary"
          :disabled="memproses"
          @click="aksi('aktifkan', 'Akun diaktifkan kembali.')"
        >Aktifkan</button>

        <button
          v-if="a.status === 'gagal_provision'"
          class="btn btn-primary"
          :disabled="memproses"
          @click="aksi('ulangi-provision', 'Proses dijalankan ulang.')"
        >Ulangi Proses</button>

        <button class="btn btn-danger" :disabled="memproses" @click="modalHapus = true">
          Hapus akun
        </button>
      </div>

      <!-- Riwayat sesi koneksi -->
      <hr class="divider" />
      <h2 class="section-title" style="font-size: 16px">Riwayat sesi koneksi</h2>
      <p class="section-subtitle">
        Hanya metadata sesi yang dicatat: waktu, alamat, dan volume data.
        Isi komunikasi tidak direkam.
        <template v-if="sesi.length">
          Total pemakaian {{ durasi(totalDurasi()) }} dari {{ sesi.length }} sesi.
        </template>
      </p>
      <div class="table-scroll" style="margin-top: 12px">
        <table class="table">
          <thead>
            <tr>
              <th>Mulai</th><th>Selesai</th><th>Durasi</th>
              <th>Dari IP</th><th>Download</th><th>Upload</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!sesi.length">
              <td colspan="6" style="color: var(--color-text-faint)">
                Belum pernah ada koneksi.
              </td>
            </tr>
            <tr v-for="s in sesi" :key="s.id">
              <td>{{ waktuSingkat(s.mulai_pada) }}</td>
              <td>
                <span v-if="s.aktif" class="badge badge-success">sedang terhubung</span>
                <template v-else>{{ waktuSingkat(s.selesai_pada) }}</template>
              </td>
              <td :style="s.aktif ? 'color: var(--color-success); font-weight: 500' : ''">
                {{ durasiSesi(s) }}
              </td>
              <td class="mono">{{ s.ip_asal || "-" }}</td>
              <td class="mono">{{ ukuran(s.bytes_in) }}</td>
              <td class="mono">{{ ukuran(s.bytes_out) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Riwayat operasi -->
      <hr class="divider" />
      <h2 class="section-title" style="font-size: 16px">Riwayat operasi router</h2>
      <div class="table-scroll" style="margin-top: 12px">
        <table class="table">
          <thead>
            <tr><th>Jenis</th><th>Status</th><th>Durasi</th><th>Waktu</th><th>Galat</th></tr>
          </thead>
          <tbody>
            <tr v-if="!operasi.length">
              <td colspan="5" style="color: var(--color-text-faint)">Belum ada operasi.</td>
            </tr>
            <tr v-for="o in operasi" :key="o.id">
              <td class="mono">{{ o.jenis }}</td>
              <td>
                <span class="badge" :class="o.status === 'sukses' ? 'badge-success' : o.status === 'gagal' ? 'badge-danger' : 'badge-warning'">
                  {{ o.status }}
                </span>
              </td>
              <td class="mono">{{ o.durasi_ms }} ms</td>
              <td style="color: var(--color-text-muted)">
                {{ waktuSingkat(o.created_at) }}
              </td>
              <td style="color: #a3352b; font-size: 12px">{{ o.pesan_error }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>

  <div v-if="modalEdit" class="modal-overlay">
    <form class="modal-box" @submit.prevent="simpanEdit">
      <h3>Edit akun VPN</h3>
      <p class="section-subtitle">Ganti kredensial, paket bandwidth, atau VPS tujuan akun.</p>
      <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
      <div class="field">
        <label class="field-label" for="edit-username">Username</label>
        <input id="edit-username" v-model="editForm.username" class="input" required maxlength="64" pattern="[a-zA-Z0-9._-]+" :disabled="memproses" />
        <span class="field-error">{{ editErrors.username?.[0] }}</span>
      </div>
      <div class="field">
        <label class="field-label" for="edit-password">Password baru</label>
        <input id="edit-password" v-model="editForm.password" class="input" type="password" autocomplete="new-password" minlength="8" maxlength="128" :disabled="memproses" />
        <small>Kosongkan untuk mempertahankan password lama. Password baru minimal 8 karakter.</small>
        <span class="field-error">{{ editErrors.password?.[0] }}</span>
      </div>
      <div class="field">
        <label class="field-label" for="edit-paket">Paket bandwidth</label>
        <select id="edit-paket" v-model="editForm.paket_bandwidth_id" class="input" required :disabled="memproses">
          <option v-for="p in pilihanPaket" :key="p.id" :value="p.id">{{ p.nama }} ({{ p.rx_rate }}/{{ p.tx_rate }})</option>
        </select>
        <span class="field-error">{{ editErrors.paket_bandwidth_id?.[0] }}</span>
      </div>
      <div class="field">
        <label class="field-label" for="edit-vps">VPS tujuan</label>
        <select id="edit-vps" v-model="editForm.vps_id" class="input" required :disabled="memproses">
          <option v-for="v in pilihanVps" :key="v.id" :value="v.id">{{ v.nama }} ({{ v.alamat_ip }})</option>
        </select>
        <small>Memilih VPS baru mengganti tujuan akses akun ini.</small>
        <span class="field-error">{{ editErrors.vps_id?.[0] }}</span>
      </div>
      <label style="display: flex; gap: 8px; margin-top: 14px">
        <input v-model="editForm.putus_sesi" type="checkbox" :disabled="memproses" />
        Putuskan sesi aktif sekarang agar perubahan langsung berlaku
      </label>
      <p class="section-subtitle">Jika tidak dicentang, perubahan kredensial dan bandwidth berlaku saat pengguna menyambungkan VPN kembali.</p>
      <div class="aksi-baris" style="margin-top: 16px">
        <button type="button" class="btn btn-secondary" :disabled="memproses" @click="modalEdit = false; editForm.password = ''">Batal</button>
        <button type="submit" class="btn btn-primary" :disabled="memproses">{{ memproses ? 'Mengirim...' : 'Simpan perubahan' }}</button>
      </div>
    </form>
  </div>

  <div v-if="modalHapus" class="modal-overlay" @click.self="modalHapus = false">
    <div class="modal-box">
      <button class="modal-close" @click="modalHapus = false">×</button>
      <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 10px">Hapus akun VPN</h3>
      <p style="color: var(--color-text-muted); font-size: 14px">
        Kredensial, entri address list, dan aturan firewall milik akun
        <strong class="mono">{{ a?.username }}</strong> akan dihapus dari MikroTik.
        Sesi yang sedang berjalan diputus. Riwayat sesi dan jejak audit tetap disimpan.
      </p>
      <div style="display: flex; gap: 10px; margin-top: 18px">
        <button class="btn btn-secondary" style="flex: 1" @click="modalHapus = false">Batal</button>
        <button class="btn btn-danger" style="flex: 1" :disabled="memproses" @click="hapus">
          {{ memproses ? "Menghapus..." : "Hapus" }}
        </button>
      </div>
    </div>
  </div>
</template>
