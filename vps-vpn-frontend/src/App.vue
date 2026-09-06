<script setup>
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";
import AppSidebar from "./components/AppSidebar.vue";
import TopBar from "./components/TopBar.vue";

const sidebarOpen = ref(false); // drawer di mobile/tablet
const sidebarCollapsed = ref(localStorage.getItem("sidebarCollapsed") === "1");

const route = useRoute();

/**
 * Tiga bentuk tampilan:
 *   kosong -> halaman login, tanpa shell apa pun
 *   admin  -> AdminLayout membawa shell-nya sendiri
 *   publik -> shell bawaan di bawah ini
 */
const bentuk = computed(() => route.meta.layout || "publik");

watch(
  () => route.path,
  () => {
    sidebarOpen.value = false;
  }
);

function toggleCollapse() {
  sidebarCollapsed.value = !sidebarCollapsed.value;
  localStorage.setItem("sidebarCollapsed", sidebarCollapsed.value ? "1" : "0");
}
</script>

<template>
  <RouterView v-if="bentuk !== 'publik'" />

  <div v-else class="app-shell">
    <AppSidebar
      :open="sidebarOpen"
      :collapsed="sidebarCollapsed"
      @close="sidebarOpen = false"
      @toggle-collapse="toggleCollapse"
    />
    <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false"></div>

    <div class="main-area" :class="{ 'is-collapsed': sidebarCollapsed }">
      <TopBar @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <div class="page-shell">
        <RouterView />
      </div>
    </div>
  </div>
</template>
