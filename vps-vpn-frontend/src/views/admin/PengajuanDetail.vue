<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiAdmin } from "../../lib/api";
import { tanggal, tanggalWaktu } from "../../lib/format";

const route = useRoute();
const router = useRouter();

const p = ref(null);
const paket = ref([]);
const galat = ref("");
const pesan = ref("");
const memproses = ref(false);

const paketDipilih = ref("");
const modalTolak = ref(false);
const alasan = ref("");
const galatTolak = ref("");

const BADGE = {
  diajukan: { cls: "badge-warning", teks: "Menunggu ditinjau" },
  ditinjau: { cls: "badge-warning", teks: "Sedang ditinjau" },
  disetujui: { cls: "badge-success", teks: "Disetujui" },
  ditolak: { cls: "badge-danger", teks: "Ditolak" },
};
const badge = (s) => BADGE[s] || { cls: "badge-neutral", teks: s };

const bisaDiputuskan = computed(() =>
  p.value && ["diajukan", "ditinjau"].includes(p.value.status)
);

const paketTerpilih = computed(() =>
  paket.value.find((k) => String(k.id) === paketDipilih.value)
);

const lamaHari = computed(() => {
  if (!p.value) return null;
  const a = new Date(p.value.durasi_mulai);
  const b = new Date(p.value.durasi_selesai);
  return Math.round((b - a) / 86400000);
});



async function muat() {
  galat.value = "";
  try {
    // Laravel membungkus resource tunggal dalam { data: ... }, tetapi tidak
    // membungkus response()->json(). Buka bungkusnya di sini supaya template
    // tidak perlu tahu bentuk mana yang datang.
    const hasil = await apiAdmin(`admin/pengajuan/${route.params.id}`);
    p.value = hasil.data ?? hasil;
    if (!paket.value.length) {
      paket.value = await apiAdmin("admin/paket-bandwidth");
      if (paket.value.length) paketDipilih.value = String(paket.value[0].id);
    }
  } catch (e) {
    galat.value = e.message;
  }
}

async function setujui() {
  galat.value = "";
  memproses.value = true;
  try {
    const r = await apiAdmin(`admin/pengajuan/${p.value.id}/setujui`, {
      method: "POST",
      body: { paket_bandwidth_id: Number(paketDipilih.value) },
    });
    pesan.value = r.message;
    await muat();
  } catch (e) {
    galat.value = e.message;
  } finally {
    memproses.value = false;
  }
}

async function tolak() {
  galatTolak.value = "";
  memproses.value = true;
  try {
    await apiAdmin(`admin/pengajuan/${p.value.id}/tolak`, {
      method: "POST",
      body: { alasan_penolakan: alasan.value },
    });
    modalTolak.value = false;
    pesan.value = "Pengajuan ditolak. Alasan sudah dikirim ke email pemohon.";
    await muat();
  } catch (e) {
    galatTolak.value = e.errors?.alasan_penolakan?.[0] || e.message;
  } finally {
    memproses.value = false;
  }
}

onMounted(muat);
</script>

<template>
  <div v-if="galat" class="notice notice-danger">{{ galat }}</div>
  <div v-if="pesan" class="notice notice-success">{{ pesan }}</div>

  <template v-if="p">
    <div class="page-head">
      <button class="btn btn-secondary btn-sm" @click="router.push('/admin/pengajuan')">
        Kembali
      </button>
      <h1 class="page-head__title">{{ p.nomor }}</h1>
      <span class="badge" :class="badge(p.status).cls">{{ badge(p.status).teks }}</span>
      <span v-if="p.jenis === 'perpanjangan'" class="badge badge-neutral">Perpanjangan</span>
      <div class="page-head__meta">
        Diajukan {{ tanggalWaktu(p.dibuat_pada) }}
        <template v-if="p.ditinjau_pada">
          · ditinjau {{ tanggalWaktu(p.ditinjau_pada) }}
          <template v-if="p.ditinjau_oleh"> oleh {{ p.ditinjau_oleh }}</template>
        </template>
      </div>
    </div>

    <div class="detail-layout">
      <!-- ================= kolom kiri: data ================= -->
      <div>
        <section class="card card--tight">
          <h2 class="section-title" style="font-size: 14px">Pemohon</h2>
          <dl class="kv">
            <div class="kv__row"><dt>Nama</dt><dd>{{ p.nama }}</dd></div>
            <div class="kv__row"><dt>Nomor identitas</dt><dd class="mono">{{ p.identitas }}</dd></div>
            <div class="kv__row"><dt>Instansi</dt><dd>{{ p.instansi }}</dd></div>
            <div class="kv__row"><dt>Email</dt><dd class="mono">{{ p.email }}</dd></div>
          </dl>
        </section>

        <section class="card card--tight">
          <h2 class="section-title" style="font-size: 14px">Akses yang diminta</h2>
          <dl class="kv">
            <div class="kv__row">
              <dt>Server tujuan</dt>
              <dd>
                <strong>{{ p.vps?.nama }}</strong>
                <span class="mono" style="color: var(--color-text-faint)">
                  · {{ p.vps?.alamat_ip }}
                </span>
              </dd>
            </div>
            <div class="kv__row"><dt>Keperluan</dt><dd>{{ p.keperluan }}</dd></div>
            <div v-if="p.keperluan_detail" class="kv__row">
              <dt>Rincian</dt><dd>{{ p.keperluan_detail }}</dd>
            </div>
            <div class="kv__row">
              <dt>Masa akses</dt>
              <dd>
                {{ tanggal(p.durasi_mulai) }} sampai {{ tanggal(p.durasi_selesai) }}
                <span style="color: var(--color-text-faint)">({{ lamaHari }} hari)</span>
              </dd>
            </div>
          </dl>
        </section>

        <!-- akun yang sudah terbit -->
        <section v-if="p.akun" class="card card--tight">
          <h2 class="section-title" style="font-size: 14px">Akun VPN yang diterbitkan</h2>
          <dl class="kv">
            <div class="kv__row"><dt>Username</dt><dd class="mono">{{ p.akun.username }}</dd></div>
            <div class="kv__row"><dt>Alamat VPN</dt><dd class="mono">{{ p.akun.ip_vpn }}</dd></div>
            <div class="kv__row">
              <dt>Paket</dt>
              <dd>{{ p.akun.paket?.nama }} <span class="mono">{{ p.akun.paket?.rate_limit }}</span></dd>
            </div>
            <div class="kv__row">
              <dt>Status akun</dt>
              <dd><span class="badge badge-neutral">{{ p.akun.status_label }}</span></dd>
            </div>
          </dl>
          <div style="margin-top: 12px">
            <RouterLink class="btn btn-secondary btn-sm" :to="`/admin/akun/${p.akun.id}`">
              Buka detail akun
            </RouterLink>
          </div>
        </section>
      </div>

      <!-- ================= kolom kanan: keputusan ================= -->
      <aside class="detail-layout__aside">
        <!-- belum diputuskan -->
        <div v-if="bisaDiputuskan" class="panel panel--aksi">
          <div class="panel__head">Keputusan</div>
          <div class="panel__body">
            <div class="field">
              <label class="field-label">Paket bandwidth</label>
              <select v-model="paketDipilih" class="select">
                <option v-for="k in paket" :key="k.id" :value="String(k.id)">
                  {{ k.nama }} ({{ k.rx_rate }}/{{ k.tx_rate }})
                </option>
              </select>
              <span v-if="paketTerpilih" class="field-hint">
                {{ paketTerpilih.keterangan }}
              </span>
            </div>

            <p style="font-size: 12px; color: var(--color-text-muted); margin: 12px 0">
              Menyetujui akan menerbitkan akun, memasang kredensial dan aturan
              firewall di MikroTik, lalu mengirim kredensial ke
              <span class="mono">{{ p.email }}</span>.
            </p>

            <button
              class="btn btn-primary"
              style="width: 100%"
              :disabled="memproses"
              @click="setujui"
            >{{ memproses ? "Memproses..." : "Setujui & Provision" }}</button>

            <button
              class="btn btn-secondary"
              style="width: 100%; margin-top: 7px"
              :disabled="memproses"
              @click="modalTolak = true"
            >Tolak pengajuan</button>
          </div>
        </div>

        <!-- sudah disetujui -->
        <div v-else-if="p.status === 'disetujui'" class="panel">
          <div class="panel__head">Sudah disetujui</div>
          <div class="panel__body" style="font-size: 12.5px; color: var(--color-text-muted)">
            <p style="margin: 0 0 8px">
              Disetujui {{ tanggalWaktu(p.ditinjau_pada) }}<template v-if="p.ditinjau_oleh"> oleh
              {{ p.ditinjau_oleh }}</template>.
            </p>
            <p v-if="p.akun" style="margin: 0">
              Akun <span class="mono">{{ p.akun.username }}</span> sudah diterbitkan
              dan kredensialnya dikirim ke pemohon.
            </p>
            <p v-else style="margin: 0">
              Akun sedang disiapkan. Pastikan queue worker berjalan.
            </p>
          </div>
        </div>

        <!-- sudah ditolak -->
        <div v-else-if="p.status === 'ditolak'" class="panel">
          <div class="panel__head">Ditolak</div>
          <div class="panel__body">
            <p style="font-size: 12.5px; color: var(--color-text-muted); margin: 0 0 8px">
              Ditolak {{ tanggalWaktu(p.ditinjau_pada) }}<template v-if="p.ditinjau_oleh"> oleh
              {{ p.ditinjau_oleh }}</template>.
            </p>
            <div class="notice notice-danger" style="margin: 0">
              <strong>Alasan:</strong> {{ p.alasan_penolakan }}
            </div>
          </div>
        </div>
      </aside>
    </div>
  </template>

  <!-- ================= modal tolak ================= -->
  <div v-if="modalTolak" class="modal-overlay" @click.self="modalTolak = false">
    <div class="modal-box" style="max-width: 460px">
      <button class="modal-close" @click="modalTolak = false">×</button>
      <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 6px">Tolak pengajuan</h3>
      <p style="color: var(--color-text-muted); font-size: 12.5px; margin: 0">
        Alasan di bawah dikirim apa adanya ke email pemohon, jadi tuliskan
        sesuatu yang dapat dia tindak lanjuti.
      </p>

      <div class="field" style="margin-top: 14px">
        <label class="field-label">Alasan penolakan<span class="req">*</span></label>
        <textarea
          v-model="alasan"
          class="textarea"
          :class="{ 'has-error': galatTolak }"
          placeholder="Contoh: keperluan akses belum sesuai kebijakan penggunaan jaringan internal."
        ></textarea>
        <span v-if="galatTolak" class="field-error">{{ galatTolak }}</span>
      </div>

      <div style="display: flex; gap: 8px; margin-top: 14px">
        <button class="btn btn-secondary" style="flex: 1" @click="modalTolak = false">
          Batal
        </button>
        <button class="btn btn-danger" style="flex: 1" :disabled="memproses" @click="tolak">
          {{ memproses ? "Mengirim..." : "Tolak & kirim alasan" }}
        </button>
      </div>
    </div>
  </div>
</template>
