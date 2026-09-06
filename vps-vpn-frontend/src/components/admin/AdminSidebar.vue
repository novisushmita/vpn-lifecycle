<script setup>
import { ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import Icon from "../Icon.vue";
import { admin, keluar } from "../../lib/auth";

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(["close"]);

const route = useRoute();
const router = useRouter();

const menu = [
  { to: "/admin", label: "Dashboard", icon: "server", tepat: true },
  { to: "/admin/pengajuan", label: "Data Pengajuan", icon: "shield", accent: true },
  { to: "/admin/akun", label: "Akun VPN", icon: "search-check" },
  { to: "/admin/vps", label: "Daftar VPS", icon: "book" },
  { to: "/admin/sinkronisasi", label: "Pemeriksaan Router", icon: "help-circle" },
  { to: "/admin/pengaturan", label: "Pengaturan", icon: "book" },
];

const aktif = (item) =>
  item.tepat ? route.path === item.to : route.path.startsWith(item.to);

const modalKeluar = ref(false);
const memproses = ref(false);

async function handleKeluar() {
  memproses.value = true;
  try {
    await keluar();
    router.push("/admin/login");
  } finally {
    memproses.value = false;
    modalKeluar.value = false;
  }
}
</script>

<template>
  <aside class="sidebar" :class="{ 'is-open': open }">
    <div class="sidebar__brand">
      <span class="sidebar__logo">VPN</span>
      <div class="sidebar__brand-text">
        <div class="sidebar__brand-name">Dashboard Admin</div>
        <div class="sidebar__brand-sub">Manajemen Akses VPN</div>
      </div>
    </div>

    <nav class="sidebar__nav">
      <div class="sidebar__section-label">Kelola</div>
      <RouterLink
        v-for="item in menu"
        :key="item.to"
        :to="item.to"
        class="sidebar__item"
        :class="{ 'is-active': aktif(item) }"
        @click="emit('close')"
      >
        <Icon
          :name="item.icon"
          :size="19"
          class="sidebar__item-icon"
          :class="{ 'sidebar__item-icon--accent': item.accent }"
        />
        <span class="sidebar__item-label">{{ item.label }}</span>
      </RouterLink>

      <div class="sidebar__section-label">Lainnya</div>
      <RouterLink to="/vps-tersedia" class="sidebar__item" @click="emit('close')">
        <Icon name="help-circle" :size="19" class="sidebar__item-icon" />
        <span class="sidebar__item-label">Halaman Publik</span>
      </RouterLink>
    </nav>

    <button class="sidebar__collapse-btn" @click="modalKeluar = true">
      <Icon name="chevrons-left" :size="18" />
      <span class="sidebar__item-label">
        Keluar{{ admin ? ` (${admin.nama})` : "" }}
      </span>
    </button>
  </aside>

  <div v-if="modalKeluar" class="modal-overlay" @click.self="modalKeluar = false">
    <div class="modal-box" style="max-width: 380px">
      <button class="modal-close" @click="modalKeluar = false">&times;</button>
      <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 6px">Keluar dari dashboard</h3>
      <p style="color: var(--color-text-muted); font-size: 12.5px; margin: 0">
        Sesi <strong>{{ admin?.nama || admin?.email }}</strong> akan diakhiri dan
        Anda perlu masuk kembali untuk mengelola akun VPN.
      </p>
      <p style="color: var(--color-text-muted); font-size: 12.5px; margin: 8px 0 0">
        Pekerjaan yang sedang berjalan di antrean tidak terpengaruh.
      </p>

      <div style="display: flex; gap: 8px; margin-top: 16px">
        <button class="btn btn-secondary" style="flex: 1" @click="modalKeluar = false">
          Batal
        </button>
        <button class="btn btn-danger" style="flex: 1" :disabled="memproses" @click="handleKeluar">
          {{ memproses ? "Keluar..." : "Keluar" }}
        </button>
      </div>
    </div>
  </div>
</template>
