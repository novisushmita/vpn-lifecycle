<script setup>
defineProps({
  name: { type: String, required: true },
  size: { type: [String, Number], default: 20 },
});
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    class="icon"
    aria-hidden="true"
  >
    <!-- VPS Tersedia: server rack -->
    <template v-if="name === 'server'">
      <rect x="3" y="4" width="18" height="6" rx="1.6" stroke="currentColor" stroke-width="1.6" />
      <rect x="3" y="14" width="18" height="6" rx="1.6" stroke="currentColor" stroke-width="1.6" />
      <circle cx="7" cy="7" r="0.9" fill="currentColor" />
      <circle cx="7" cy="17" r="0.9" fill="currentColor" />
    </template>

    <!-- Pengajuan VPN: shield check -->
    <template v-else-if="name === 'shield'">
      <path
        d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linejoin="round"
      />
      <path
        d="M9 12l2 2 4-4"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </template>

    <!-- Cek Status Pengajuan: search with a check -->
    <template v-else-if="name === 'search-check'">
      <circle cx="10.2" cy="10.2" r="6.2" stroke="currentColor" stroke-width="1.6" />
      <path
        d="M7.8 10.2l1.6 1.6 3.2-3.4"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
      <path d="M15 15l5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
    </template>

    <!-- Panduan: open book -->
    <template v-else-if="name === 'book'">
      <path
        d="M4 5.6C4 4.7 4.7 4 5.6 4H12v16H5.6A1.6 1.6 0 014 18.4V5.6z"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linejoin="round"
      />
      <path
        d="M20 5.6c0-.9-.7-1.6-1.6-1.6H12v16h6.4a1.6 1.6 0 001.6-1.6V5.6z"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linejoin="round"
      />
    </template>

    <!-- Bantuan: help circle -->
    <template v-else-if="name === 'help-circle'">
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
      <path
        d="M9.5 9.4a2.5 2.5 0 114.4 1.6c-.6.6-1.4.9-1.4 2.1"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
      <circle cx="12" cy="16.3" r="0.95" fill="currentColor" />
    </template>

    <!-- Hamburger menu (mobile topbar) -->
    <template v-else-if="name === 'menu'">
      <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
    </template>

    <!-- Sidebar collapse (points left) -->
    <template v-else-if="name === 'chevrons-left'">
      <path
        d="M13 6l-6 6 6 6M19 6l-6 6 6 6"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </template>

    <!-- Sidebar expand (points right) -->
    <template v-else-if="name === 'chevrons-right'">
      <path
        d="M11 6l6 6-6 6M5 6l6 6-6 6"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </template>

    <!-- Breadcrumb separator -->
    <template v-else-if="name === 'chevron-right'">
      <path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
    </template>

    <!-- Dropdown indicator -->
    <template v-else-if="name === 'chevron-down'">
      <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
    </template>

    <!-- Kontak administrator: mail -->
    <template v-else-if="name === 'mail'">
      <rect x="3" y="5" width="18" height="14" rx="1.8" stroke="currentColor" stroke-width="1.6" />
      <path d="M4 7l8 6 8-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
    </template>

    <!-- Telepon -->
    <template v-else-if="name === 'phone'">
      <path
        d="M6.5 4h3l1.4 3.5-2 1.4a11 11 0 005.2 5.2l1.4-2 3.5 1.4v3a2 2 0 01-2.2 2A16 16 0 014.5 6.2 2 2 0 016.5 4z"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linejoin="round"
      />
    </template>

    <!-- Jam layanan -->
    <template v-else-if="name === 'clock'">
      <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
      <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
    </template>
  </svg>
</template>

<style scoped>
.icon {
  display: block;
  flex-shrink: 0;
}
</style>
