<script setup>
import { ref } from "vue";
import { RouterLink, useRoute } from "vue-router";
import Icon from "./Icon.vue";

// Dua menu dropdown. Struktur mengikuti sidebar lama supaya isi tidak berubah,
// hanya bentuknya jadi top bar horizontal ala Dribbble.
const menu = [
  {
    label: "Layanan",
    items: [
      { to: "/vps-tersedia", label: "VPS Tersedia", icon: "server" },
      { to: "/pengajuan-vpn", label: "Pengajuan VPN", icon: "shield" },
      { to: "/cek-status", label: "Cek Status Pengajuan", icon: "search-check" },
    ],
  },
  {
    label: "Bantuan",
    items: [
      { to: "/panduan", label: "Panduan", icon: "book" },
      { to: "/bantuan", label: "Kontak Administrator", icon: "mail" },
    ],
  },
];

const route = useRoute();
const buka = ref(null); // label dropdown yang terbuka (desktop hover/klik)
const menuMobile = ref(false);

function toggle(label) {
  buka.value = buka.value === label ? null : label;
}
function tutup() {
  buka.value = null;
  menuMobile.value = false;
}
</script>

<template>
  <header class="pubnav" @keydown.esc="tutup">
    <RouterLink to="/pengajuan-vpn" class="pubnav__brand" @click="tutup">
      <span class="pubnav__logo">VPN</span>
      <span class="pubnav__brand-name">Pengajuan VPN</span>
    </RouterLink>

    <button
      class="pubnav__burger"
      aria-label="Buka menu"
      @click="menuMobile = !menuMobile"
    >
      <Icon name="menu" :size="20" />
    </button>

    <nav class="pubnav__nav" :class="{ 'is-open': menuMobile }">
      <div
        v-for="grup in menu"
        :key="grup.label"
        class="pubnav__group"
        :class="{ 'is-open': buka === grup.label }"
      >
        <button class="pubnav__trigger" @click="toggle(grup.label)">
          {{ grup.label }}
          <Icon name="chevron-down" :size="15" class="pubnav__caret" />
        </button>

        <div class="pubnav__dropdown">
          <RouterLink
            v-for="item in grup.items"
            :key="item.to"
            :to="item.to"
            class="pubnav__link"
            :class="{ 'is-active': route.path === item.to }"
            @click="tutup"
          >
            <Icon :name="item.icon" :size="17" class="pubnav__link-icon" />
            {{ item.label }}
          </RouterLink>
        </div>
      </div>
    </nav>

    <div
      v-if="buka || menuMobile"
      class="pubnav__scrim"
      @click="tutup"
    ></div>
  </header>
</template>
