<script setup>
import Icon from "../components/Icon.vue";
import { kontakAdmin, pertanyaanUmum } from "../data/kontak";

const telHref = "tel:" + kontakAdmin.telepon.replace(/[^\d+]/g, "");
</script>

<template>
  <section class="card">
    <h1 class="section-title">Kontak Administrator</h1>
    <p class="section-subtitle">
      Hubungi administrator bila mengalami kendala terkait akses VPS atau VPN.
    </p>

    <div class="kontak">
      <div class="kontak__head">
        <span class="kontak__avatar">{{ kontakAdmin.nama.slice(0, 1) }}</span>
        <div>
          <div class="kontak__name">{{ kontakAdmin.nama }}</div>
          <div class="kontak__role">{{ kontakAdmin.peran }}</div>
        </div>
      </div>

      <ul class="kontak__list">
        <li>
          <Icon name="mail" :size="17" />
          <a :href="`mailto:${kontakAdmin.email}`">{{ kontakAdmin.email }}</a>
        </li>
        <li>
          <Icon name="phone" :size="17" />
          <a :href="telHref">{{ kontakAdmin.telepon }}</a>
        </li>
        <li>
          <Icon name="clock" :size="17" />
          <span>{{ kontakAdmin.jamLayanan }}</span>
        </li>
      </ul>
    </div>
  </section>

  <section class="card">
    <h2 class="section-title" style="font-size: 15px">Pertanyaan umum</h2>
    <div class="faq">
      <details v-for="item in pertanyaanUmum" :key="item.q" class="faq__item">
        <summary>
          {{ item.q }}
          <Icon name="chevron-down" :size="15" class="faq__caret" />
        </summary>
        <p>{{ item.a }}</p>
      </details>
    </div>
  </section>
</template>

<style scoped>
.kontak {
  margin-top: 22px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 20px 22px;
  max-width: 460px;
}

.kontak__head {
  display: flex;
  align-items: center;
  gap: 13px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-border);
}
.kontak__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 15px;
  flex: none;
}
.kontak__name { font-size: 14px; font-weight: 600; }
.kontak__role { margin-top: 2px; font-size: 12px; color: var(--color-text-faint); }

.kontak__list {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.kontak__list li {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--color-text);
}
.kontak__list li :deep(.icon) { color: var(--color-text-faint); flex: none; }
.kontak__list a { color: var(--color-primary); }

.faq {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.faq__item {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 12px 16px;
}
.faq__item summary {
  cursor: pointer;
  font-size: 13.5px;
  font-weight: 500;
  list-style: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.faq__item summary::-webkit-details-marker { display: none; }
.faq__caret { color: var(--color-text-faint); transition: transform 0.15s ease; }
.faq__item[open] .faq__caret { transform: rotate(180deg); }
.faq__item p {
  margin: 9px 0 0;
  font-size: 13px;
  color: var(--color-text-muted);
  line-height: 1.55;
}

@media (max-width: 640px) {
  .kontak { max-width: none; }
}
</style>
