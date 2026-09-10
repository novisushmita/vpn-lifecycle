<script setup>
import { ref, watch } from "vue";
import { useRoute } from "vue-router";
import AdminSidebar from "../../components/admin/AdminSidebar.vue";
import AdminTopBar from "../../components/admin/AdminTopBar.vue";

const sidebarOpen = ref(false); // drawer di mobile
const sidebarCollapsed = ref(localStorage.getItem("adminSidebarCollapsed") === "1");
const route = useRoute();

watch(() => route.path, () => (sidebarOpen.value = false));

function toggleCollapse() {
  sidebarCollapsed.value = !sidebarCollapsed.value;
  localStorage.setItem("adminSidebarCollapsed", sidebarCollapsed.value ? "1" : "0");
}
</script>

<template>
  <div class="app-shell">
    <AdminSidebar
      :open="sidebarOpen"
      :collapsed="sidebarCollapsed"
      @close="sidebarOpen = false"
      @toggle-collapse="toggleCollapse"
    />
    <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false"></div>

    <div class="main-area" :class="{ 'is-collapsed': sidebarCollapsed }">
      <AdminTopBar @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <div class="page-shell">
        <RouterView />
      </div>
    </div>
  </div>
</template>
