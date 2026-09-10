<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from "vue";
import { apiAdmin } from "../../lib/api";
import { waktuSingkat } from "../../lib/format";
import { tungguOperasi } from "../../lib/operasi";

const daftar = ref([]);
const memuat = ref(false);
const galat = ref("");
const pesan = ref("");
const pingGagal = ref(false);

// konfirmasi penghapusan berantai
const modalHapus = ref(false);
const dampak = ref(null);
const memuatDampak = ref(false);
const menghapus = ref(false);
const pingBerjalan = ref(null);
const periksaSemuaBerjalan = ref(false);
const progres = ref({ selesai: 0, total: 0 });
const galatPing = reactive({});
let halamanAktif = true;
onBeforeUnmount(() => { halamanAktif = false; });

const modal = ref(false);
const sedangEdit = ref(null);
const form = reactive({ nama: "", alamat_ip: "", keterangan: "", aktif: true });
const errors = reactive({});

async function muat() {
  galat.value = "";
  pesan.value = "";
  memuat.value = true;
  try {
    const res = await apiAdmin("admin/vps");
    daftar.value = res.data ?? [];
  } catch (e) {
    galat.value = e.message;
  } finally {
    memuat.value = false;
  }
}

function bukaTambah() {
  sedangEdit.value = null;
  Object.assign(form, { nama: "", alamat_ip: "", keterangan: "", aktif: true });
  Object.keys(errors).forEach((k) => delete errors[k]);
  modal.value = true;
}

function bukaEdit(v) {
  sedangEdit.value = v;
  Object.assign(form, {
    nama: v.nama,
    alamat_ip: v.alamat_ip,
    keterangan: v.keterangan || "",
    aktif: v.aktif,
  });
  Object.keys(errors).forEach((k) => delete errors[k]);
  modal.value = true;
}

async function simpan() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  galat.value = "";
  try {
    if (sedangEdit.value) {
      await apiAdmin(`admin/vps/${sedangEdit.value.id}`, { method: "PUT", body: { ...form } });
      pesan.value = "VPS diperbarui.";
    } else {
      await apiAdmin("admin/vps", { method: "POST", body: { ...form } });
      pesan.value = "VPS ditambahkan.";
    }
    modal.value = false;
    await muat();
  } catch (e) {
    if (e.errors) Object.assign(errors, Object.fromEntries(
      Object.entries(e.errors).map(([k, v]) => [k, v[0]])
    ));
    else galat.value = e.message;
  }
}

async function bukaHapus(v) {
  galat.value = "";
  pesan.value = "";
  dampak.value = null;
  modalHapus.value = true;
  memuatDampak.value = true;
  try {
    dampak.value = await apiAdmin(`admin/vps/${v.id}/dampak-hapus`);
  } catch (e) {
    galat.value = e.message;
    modalHapus.value = false;
  } finally {
    memuatDampak.value = false;
  }
}

async function hapus() {
  menghapus.value = true;
  galat.value = "";
  try {
    const r = await apiAdmin(`admin/vps/${dampak.value.vps.id}`, { method: "DELETE" });
    pesan.value = r.jumlah_akun
      ? `${r.message} ${r.jumlah_akun} akun VPN sedang dibersihkan dari router.`
      : r.message;
    modalHapus.value = false;
    await muat();
  } catch (e) {
    galat.value = e.message;
  } finally {
    menghapus.value = false;
  }
}

async function ping(v) {
  pingBerjalan.value = v.id;
  galat.value = "";
  pesan.value = "";
  pingGagal.value = false;
  delete galatPing[v.id];
  try {
    // Ping dijalankan di antrean; tunggu operasinya selesai lalu baca
    // baris VPS yang sudah diperbarui.
    const antre = await apiAdmin(`admin/vps/${v.id}/ping`, { method: "POST" });
    if (antre.operasi_id) await tungguOperasi(antre.operasi_id);
    const segar = await apiAdmin(`admin/vps/${v.id}`);
    const data = segar.data ?? segar;
    Object.assign(v, data);
    pingGagal.value = data.ping_terakhir?.status === "down";
    pesan.value = `Ping ${v.nama}: ${hasilPing(data)}.`;
    if (pingGagal.value) {
      pesan.value += ` Gagal ${data.gagal_berturut} kali berturut-turut (ambang DOWN: 3).`;
    }
  } catch (e) {
    galatPing[v.id] = e.message;
    galat.value = `Pemeriksaan ${v.nama} gagal: ${e.message}. Hasil terakhir belum diperbarui.`;
  } finally {
    pingBerjalan.value = null;
  }
}

async function periksaSemua() {
  if (periksaSemuaBerjalan.value || pingBerjalan.value !== null) return;
  periksaSemuaBerjalan.value = true;
  galat.value = "";
  pesan.value = "";
  pingGagal.value = false;
  progres.value = { selesai: 0, total: 0 };
  let gagal = 0;
  let tanpaBalasan = 0;
  try {
    // Ambil daftar terbaru agar VPS yang baru ditambahkan ikut diperiksa.
    const res = await apiAdmin("admin/vps");
    if (!halamanAktif) return;
    daftar.value = res.data ?? [];
    const target = daftar.value.filter(v => !v.sedang_dihapus);
    progres.value.total = target.length;
    for (const v of target) {
      if (!halamanAktif) return;
      pingBerjalan.value = v.id;
      delete galatPing[v.id];
      try {
        const hasil = await apiAdmin(`admin/vps/${v.id}/ping`, { method: "POST" });
        Object.assign(v, hasil.data ?? hasil);
        if (v.ping_terakhir?.status === "down") tanpaBalasan++;
      } catch (e) {
        galatPing[v.id] = e.message;
        gagal++;
      }
      progres.value.selesai++;
    }
    pingGagal.value = gagal > 0 || tanpaBalasan > 0;
    pesan.value = target.length
      ? `Pemeriksaan selesai: ${target.length - gagal} dari ${target.length} VPS berhasil diperiksa; ${tanpaBalasan} tanpa balasan.`
      : "Belum ada VPS yang dapat diperiksa.";
    if (gagal) galat.value = `${gagal} VPS gagal diperiksa. Hasil lamanya belum diperbarui; lihat pesan pada baris VPS.`;
  } catch (e) {
    galat.value = `Tidak dapat mengambil daftar VPS: ${e.message}`;
  } finally {
    pingBerjalan.value = null;
    periksaSemuaBerjalan.value = false;
  }
}

function hasilPing(v) {
  const p = v.ping_terakhir;
  if (!p) return "Belum diperiksa";
  return `Loss ${p.packet_loss}%` +
    (p.rtt_avg_ms != null ? `, RTT ${p.rtt_avg_ms} ms` : "");
}

function waktuPing(v) {
  const waktu = v.ping_terakhir?.checked_at;
  return waktu ? waktuSingkat(waktu) : "Belum diperiksa";
}

onMounted(muat);
</script>

<template>
  <section class="card">
    <h1 class="section-title">Daftar VPS</h1>
    <p class="section-subtitle"></p>

    <div class="toolbar">
      <button class="btn btn-primary btn-sm" :disabled="periksaSemuaBerjalan" @click="bukaTambah">Tambah VPS</button>
      <div class="toolbar__spacer"></div>
      <button class="btn btn-secondary btn-sm" :disabled="memuat || pingBerjalan !== null || periksaSemuaBerjalan" @click="periksaSemua">
        {{ periksaSemuaBerjalan ? `Memeriksa ${progres.selesai}/${progres.total} VPS…` : "Periksa semua VPS" }}
      </button>
    </div>

    <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
    <div v-if="periksaSemuaBerjalan" class="notice" role="status" aria-live="polite">
      Memeriksa {{ progres.selesai }} dari {{ progres.total }} VPS. Tunggu sampai pemeriksaan selesai.
    </div>
    <div v-if="pesan" class="notice" :class="pingGagal ? 'notice-danger' : 'notice-success'" role="status">{{ pesan }}</div>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>Nama</th>
            <th style="width: 130px">Alamat IP</th>
            <th>Keterangan</th>
            <th style="width: 100px">Status</th>
            <th style="min-width: 190px">Ping terakhir</th>
            <th style="width: 80px">Akun</th>
            <th style="width: 210px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="memuat">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">Memuat...</td>
          </tr>
          <tr v-else-if="!daftar.length">
            <td colspan="7" style="text-align: center; color: var(--color-text-faint)">
              Belum ada VPS. Tambahkan lebih dulu agar bisa diajukan pengguna.
            </td>
          </tr>
          <tr v-for="v in daftar" :key="v.id">
            <td>
              <strong>{{ v.nama }}</strong>
              <span v-if="!v.aktif" class="badge badge-neutral" style="margin-left: 6px">
                nonaktif
              </span>
              <span v-if="v.sedang_dihapus" class="badge badge-danger" style="margin-left: 6px">
                sedang dihapus
              </span>
            </td>
            <td class="mono">{{ v.alamat_ip }}</td>
            <td style="color: var(--color-text-muted)">{{ v.keterangan }}</td>
            <td>
              <span
                class="badge"
                :class="v.status_terakhir === 'up' ? 'badge-success' : v.status_terakhir === 'down' ? 'badge-danger' : 'badge-neutral'"
              >{{ v.status_terakhir.toUpperCase() }}</span>
            </td>
            <td>
              <div v-if="pingBerjalan === v.id" role="status">Sedang memeriksa…</div>
              <div v-if="galatPing[v.id]" style="color: var(--color-danger, #b91c1c)">
                Pemeriksaan gagal: {{ galatPing[v.id] }}. Data di bawah adalah hasil sebelumnya.
              </div>
              <div>{{ hasilPing(v) }}</div>
              <div v-if="v.gagal_berturut > 0" style="margin-top: 4px; color: var(--color-danger, #b91c1c)">
                {{ v.gagal_berturut }} Pemerikasaan Gagal
              </div>
              <div style="margin-top: 4px; font-size: 11px; color: var(--color-text-muted)">
                Diperiksa: {{ waktuPing(v) }}
              </div>
            </td>
            <td class="mono">{{ v.jumlah_akun ?? 0 }}</td>
            <td>
              <div class="aksi-baris" style="justify-content: flex-end">
                <button
                  class="btn btn-secondary btn-sm"
                  :disabled="pingBerjalan !== null || memuat || periksaSemuaBerjalan"
                  @click="ping(v)"
                >{{ pingBerjalan === v.id ? "..." : "Ping" }}</button>
                <button class="btn btn-secondary btn-sm" :disabled="periksaSemuaBerjalan" @click="bukaEdit(v)">Ubah</button>
                <button class="btn btn-danger btn-sm" :disabled="periksaSemuaBerjalan" @click="bukaHapus(v)">Hapus</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <div v-if="modalHapus" class="modal-overlay" @click.self="modalHapus = false">
    <div class="modal-box">
      <button class="modal-close" @click="modalHapus = false">×</button>
      <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 10px">
        Hapus VPS{{ dampak ? ` ${dampak.vps.nama}` : "" }}
      </h3>

      <p v-if="memuatDampak" style="color: var(--color-text-muted)">Memeriksa dampak...</p>

      <template v-else-if="dampak">
        <div v-if="dampak.operasi_berjalan > 0" class="notice notice-danger">
          Masih ada {{ dampak.operasi_berjalan }} operasi router yang berjalan untuk
          VPS ini. Tunggu sampai selesai sebelum menghapus.
        </div>

        <template v-else>
          <div v-if="dampak.jumlah_akun > 0" class="notice notice-danger">
            <strong>{{ dampak.jumlah_akun }} akun VPN akan ikut dihapus.</strong>
            Kredensial, entri address list, dan aturan firewall milik setiap akun
            dibersihkan dari MikroTik lebih dulu, dan sesi yang sedang berjalan diputus.
            Pemiliknya akan langsung kehilangan akses.
          </div>
          <div v-else class="notice notice-info">
            Tidak ada akun VPN yang terikat pada VPS ini.
          </div>

          <div v-if="dampak.jumlah_akun > 0" class="table-scroll" style="margin-top: 12px">
            <table class="table">
              <thead><tr><th>Username</th><th>Pemohon</th><th style="width: 130px">Status</th></tr></thead>
              <tbody>
                <tr v-for="a in dampak.akun" :key="a.id">
                  <td class="mono">{{ a.username }}</td>
                  <td>{{ a.pemohon || "-" }}</td>
                  <td><span class="badge badge-neutral">{{ a.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="dampak.pengajuan_menunggu > 0" class="notice notice-warn"
               style="background: var(--color-primary-soft); color: var(--color-primary)">
            {{ dampak.pengajuan_menunggu }} pengajuan yang masih menunggu untuk VPS ini
            akan otomatis ditolak dengan alasan VPS dihapus.
          </div>

          <p style="font-size: 13px; color: var(--color-text-muted); margin-top: 12px">
            Riwayat sesi dan jejak audit tetap disimpan. VPS baru dihapus setelah
            seluruh akun berhasil dibersihkan dari router.
          </p>
        </template>
      </template>

      <div style="display: flex; gap: 10px; margin-top: 18px">
        <button class="btn btn-secondary" style="flex: 1" @click="modalHapus = false">Batal</button>
        <button
          class="btn btn-danger"
          style="flex: 1"
          :disabled="menghapus || memuatDampak || !dampak || dampak.operasi_berjalan > 0"
          @click="hapus"
        >
          {{ menghapus ? "Memproses..." : (dampak && dampak.jumlah_akun > 0
              ? `Hapus VPS & ${dampak.jumlah_akun} akun` : "Hapus VPS") }}
        </button>
      </div>
    </div>
  </div>

  <div v-if="modal" class="modal-overlay" @click.self="modal = false">
    <div class="modal-box">
      <button class="modal-close" @click="modal = false">×</button>
      <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 14px">
        {{ sedangEdit ? "Ubah VPS" : "Tambah VPS" }}
      </h3>

      <div class="field">
        <label class="field-label">Nama VPS<span class="req">*</span></label>
        <input v-model="form.nama" class="input" :class="{ 'has-error': errors.nama }" placeholder="VPS-APP-01" />
        <span v-if="errors.nama" class="field-error">{{ errors.nama }}</span>
      </div>

      <div class="field" style="margin-top: 12px">
        <label class="field-label">Alamat IP<span class="req">*</span></label>
        <input v-model="form.alamat_ip" class="input mono" :class="{ 'has-error': errors.alamat_ip }" placeholder="10.10.10.11" />
        <span v-if="errors.alamat_ip" class="field-error">{{ errors.alamat_ip }}</span>
      </div>

      <div class="field" style="margin-top: 12px">
        <label class="field-label">Keterangan</label>
        <textarea v-model="form.keterangan" class="textarea" placeholder="Fungsi VPS ini"></textarea>
      </div>

      <label style="display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 13px">
        <input v-model="form.aktif" type="checkbox" />
        Tampilkan di form pengajuan publik
      </label>

      <div style="display: flex; gap: 10px; margin-top: 18px">
        <button class="btn btn-secondary" style="flex: 1" @click="modal = false">Batal</button>
        <button class="btn btn-primary" style="flex: 1" @click="simpan">Simpan</button>
      </div>
    </div>
  </div>
</template>
