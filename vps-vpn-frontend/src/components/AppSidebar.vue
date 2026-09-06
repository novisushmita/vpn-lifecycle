<script setup>
import { RouterLink, useRoute } from "vue-router";
import Icon from "./Icon.vue";

defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  collapsed: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["close", "toggle-collapse"]);

const menuLayanan = [
  { to: "/vps-tersedia", label: "VPS Tersedia", icon: "server" },
  { to: "/pengajuan-vpn", label: "Pengajuan VPN", icon: "shield", accent: true },
  { to: "/cek-status", label: "Cek Status Pengajuan", icon: "search-check" },
];

const menuBantuan = [
  { to: "/panduan", label: "Panduan", icon: "book" },
  { to: "/bantuan", label: "Bantuan", icon: "help-circle" },
];

const route = useRoute();
</script>

<template>
  <aside class="sidebar" :class="{ 'is-open': open, 'is-collapsed': collapsed }">
    <div class="sidebar__brand">
      <span class="sidebar__logo">VPN</span>
      <div class="sidebar__brand-text">
        <div class="sidebar__brand-name">Layanan Akses VPS</div>
        <div class="sidebar__brand-sub">Portal Pengajuan VPN</div>
      </div>
    </div>

    <nav class="sidebar__nav">
      <div class="sidebar__section-label">Layanan</div>
      <RouterLink
        v-for="item in menuLayanan"
        :key="item.to"
        :to="item.to"
        class="sidebar__item"
        :class="{ 'is-active': route.path === item.to }"
        :title="item.label"
        @click="emit('close')"
      >
        <Icon :name="item.icon" :size="19" :class="{ 'sidebar__item-icon--accent': item.accent }" class="sidebar__item-icon" />
        <span class="sidebar__item-label">{{ item.label }}</span>
      </RouterLink>

      <div class="sidebar__section-label">Bantuan</div>
      <RouterLink
        v-for="item in menuBantuan"
        :key="item.to"
        :to="item.to"
        class="sidebar__item"
        :class="{ 'is-active': route.path === item.to }"
        :title="item.label"
        @click="emit('close')"
      >
        <Icon :name="item.icon" :size="19" class="sidebar__item-icon" />
        <span class="sidebar__item-label">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <button
      class="sidebar__collapse-btn"
      :title="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
      @click="emit('toggle-collapse')"
    >
      <Icon :name="collapsed ? 'chevrons-right' : 'chevrons-left'" :size="18" />
      <span class="sidebar__item-label">Ciutkan menu</span>
    </button>
  </aside>
</template>
