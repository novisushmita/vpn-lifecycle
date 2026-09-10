<script setup>
import { computed } from "vue";
import { useRoute } from "vue-router";
import PublicNav from "./components/PublicNav.vue";

const route = useRoute();

/**
 * Tiga bentuk tampilan:
 *   kosong -> halaman login, tanpa shell apa pun
 *   admin  -> AdminLayout membawa shell-nya sendiri
 *   publik -> top bar horizontal ala Dribbble, tanpa sidebar
 */
const bentuk = computed(() => route.meta.layout || "publik");
</script>

<template>
  <RouterView v-if="bentuk !== 'publik'" />

  <div v-else class="public-shell">
    <PublicNav />
    <main class="public-main">
      <RouterView />
    </main>
  </div>
</template>
