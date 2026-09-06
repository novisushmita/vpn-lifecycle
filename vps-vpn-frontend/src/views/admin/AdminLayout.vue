<script setup>
import { ref, watch } from "vue";
import { useRoute } from "vue-router";
import AdminSidebar from "../../components/admin/AdminSidebar.vue";
import TopBar from "../../components/TopBar.vue";

const sidebarOpen = ref(false);
const route = useRoute();

watch(() => route.path, () => (sidebarOpen.value = false));
</script>

<template>
  <div class="app-shell">
    <AdminSidebar :open="sidebarOpen" @close="sidebarOpen = false" />
    <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false"></div>

    <div class="main-area">
      <TopBar @toggle-sidebar="sidebarOpen = !sidebarOpen" />
      <div class="page-shell">
        <RouterView />
      </div>
    </div>
  </div>
</template>
