<script setup>
import { computed, ref } from "vue";
import { panduanPengajuan, panduanPerangkat } from "../data/panduan";

const tab = ref("pengajuan"); // 'pengajuan' | 'penggunaan'
const perangkatAktif = ref(panduanPerangkat[0].perangkat);

const perangkat = computed(() =>
  panduanPerangkat.find((p) => p.perangkat === perangkatAktif.value)
);

// Judul di data berawalan nomor ("1. ..."); pisahkan agar dirender sebagai
// lencana angka, bukan ikut di teks judul.
function pecah(judul) {
  const m = judul.match(/^\s*(\d+)\.\s*(.*)$/);
  return m ? { no: m[1], teks: m[2] } : { no: null, teks: judul };
}
</script>

<template>
  <section class="card">
    <h1 class="section-title">Panduan</h1>
    <p class="section-subtitle"></p>

    <div class="ptabs">
      <button
        class="ptabs__item"
        :class="{ 'is-active': tab === 'pengajuan' }"
        @click="tab = 'pengajuan'"
      >
        Pengajuan VPN
      </button>
      <button
        class="ptabs__item"
        :class="{ 'is-active': tab === 'penggunaan' }"
        @click="tab = 'penggunaan'"
      >
        Penggunaan VPN
      </button>
    </div>

    <!-- Panduan pengajuan -->
    <ol v-if="tab === 'pengajuan'" class="pguide">
      <li v-for="langkah in panduanPengajuan" :key="langkah.judul" class="pguide__item">
        <span class="pguide__no">{{ pecah(langkah.judul).no }}</span>
        <div class="pguide__body">
          <h3 class="pguide__title">{{ pecah(langkah.judul).teks }}</h3>
          <p class="pguide__text">{{ langkah.isi }}</p>
        </div>
      </li>
    </ol>

    <!-- Panduan penggunaan per perangkat -->
    <div v-else class="pakai">
      <span class="pakai__label">Pilih perangkat</span>
      <div class="pakai__switch">
        <button
          v-for="p in panduanPerangkat"
          :key="p.perangkat"
          class="pakai__opsi"
          :class="{ 'is-active': perangkatAktif === p.perangkat }"
          @click="perangkatAktif = p.perangkat"
        >
          {{ p.perangkat }}
        </button>
      </div>

      <ol class="pguide">
        <li v-for="(step, idx) in perangkat.langkah" :key="idx" class="pguide__item">
          <span class="pguide__no">{{ idx + 1 }}</span>
          <div class="pguide__body">
            <p class="pguide__text">{{ step }}</p>
          </div>
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.ptabs {
  display: flex;
  gap: 28px;
  margin-top: 20px;
  border-bottom: 1px solid var(--color-border);
}
.ptabs__item {
  background: none;
  border: none;
  padding: 8px 2px 12px;
  font-size: 13.5px;
  color: var(--color-text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
}
.ptabs__item.is-active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  font-weight: 500;
}

.pguide {
  list-style: none;
  margin: 22px 0 0;
  padding: 0;
  max-width: 640px;
}
.pguide__item {
  display: flex;
  gap: 14px;
  padding: 14px 0;
  border-bottom: 1px solid var(--color-border);
}
.pguide__item:last-child { border-bottom: none; }
.pguide__no {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  display: grid;
  place-items: center;
  font-size: 12px;
  font-weight: 700;
  flex: none;
}
.pguide__body { min-width: 0; }
.pguide__title { font-size: 13.5px; font-weight: 600; color: var(--color-text); }
.pguide__text {
  font-size: 13px;
  color: var(--color-text-muted);
  line-height: 1.55;
}
.pguide__title + .pguide__text { margin-top: 3px; }

.pakai { margin-top: 22px; }
.pakai__label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-text-faint);
  margin-bottom: 6px;
}

.pakai__switch { display: flex; flex-wrap: wrap; gap: 20px; }
.pakai__opsi {
  background: none;
  border: none;
  padding: 4px 0;
  font-size: 13px;
  cursor: pointer;
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.pakai__opsi.is-active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  font-weight: 500;
}
</style>
