<script setup>
import { useRoute } from "vue-router";
import Icon from "../Icon.vue";

defineEmits(["toggle-sidebar"]);

const route = useRoute();
</script>

<template>
  <header class="topbar">
    <button class="topbar__menu-btn" aria-label="Buka menu" @click="$emit('toggle-sidebar')">
      <Icon name="menu" :size="20" />
    </button>
    <div class="topbar__breadcrumb">
      <template v-if="route.meta.grup">
        <span class="topbar__breadcrumb-parent">{{ route.meta.grup }}</span>
        <Icon name="chevron-right" :size="15" class="topbar__breadcrumb-sep" />
      </template>
      <span class="topbar__breadcrumb-current">{{ route.meta.title }}</span>
    </div>
  </header>
</template>
