<script setup>
import { ref } from "vue";
import { panduanPengajuan, panduanPerangkat } from "../data/panduan";

const tab = ref("pengajuan"); // 'pengajuan' | 'penggunaan'
const perangkatAktif = ref(panduanPerangkat[0].perangkat);

function perangkatData(nama) {
  return panduanPerangkat.find((p) => p.perangkat === nama);
}
</script>

<template>
  <section class="card">
    <h1 class="section-title">Panduan</h1>
    <p class="section-subtitle">Panduan pengajuan akses VPN dan panduan penggunaan sesuai perangkat Anda.</p>

    <div class="tabs">
      <button
        class="tabs__item"
        :class="{ 'is-active': tab === 'pengajuan' }"
        @click="tab = 'pengajuan'"
      >
        Panduan pengajuan VPN
      </button>
      <button
        class="tabs__item"
        :class="{ 'is-active': tab === 'penggunaan' }"
        @click="tab = 'penggunaan'"
      >
        Panduan penggunaan VPN
      </button>
    </div>

    <!-- Panduan pengajuan -->
    <div v-if="tab === 'pengajuan'" class="steps">
      <div v-for="langkah in panduanPengajuan" :key="langkah.judul" class="steps__item">
        <h3 class="steps__title">{{ langkah.judul }}</h3>
        <p class="steps__body">{{ langkah.isi }}</p>
      </div>
    </div>

    <!-- Panduan penggunaan per perangkat -->
    <div v-else>
      <div class="device-switch">
        <button
          v-for="p in panduanPerangkat"
          :key="p.perangkat"
          class="device-switch__item"
          :class="{ 'is-active': perangkatAktif === p.perangkat }"
          @click="perangkatAktif = p.perangkat"
        >
          {{ p.perangkat }}
        </button>
      </div>

      <ol class="ordered-list">
        <li v-for="(step, idx) in perangkatData(perangkatAktif).langkah" :key="idx">
          {{ step }}
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.tabs {
  display: flex;
  gap: 8px;
  margin-top: 20px;
  border-bottom: 1px solid var(--color-border);
}

.tabs__item {
  background: none;
  border: none;
  padding: 10px 4px 12px;
  margin-right: 20px;
  font-size: 14px;
  color: var(--color-text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
}

.tabs__item.is-active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  font-weight: 500;
}

.steps {
  margin-top: 22px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.steps__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text);
}

.steps__body {
  margin-top: 4px;
  font-size: 13px;
  color: var(--color-text-muted);
  max-width: 620px;
}

.device-switch {
  display: flex;
  gap: 8px;
  margin-top: 20px;
}

.device-switch__item {
  border: 1px solid var(--color-border-strong);
  background: var(--color-surface);
  border-radius: 999px;
  padding: 6px 16px;
  font-size: 13px;
  cursor: pointer;
  color: var(--color-text-muted);
}

.device-switch__item.is-active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}

.ordered-list {
  margin-top: 18px;
  padding-left: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 13px;
  color: var(--color-text);
  max-width: 620px;
}
</style>
