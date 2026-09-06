<script setup>
import { kontakAdmin, pertanyaanUmum } from "../data/kontak";
</script>

<template>
  <section class="card">
    <h1 class="section-title">Bantuan</h1>
    <p class="section-subtitle">Hubungi administrator jika mengalami kendala terkait akses VPS atau VPN.</p>

    <div class="contact-card">
      <h3 class="contact-card__name">{{ kontakAdmin.nama }}</h3>
      <p class="contact-card__role">{{ kontakAdmin.peran }}</p>
      <dl class="contact-card__meta">
        <div>
          <dt>Email</dt>
          <dd><a :href="`mailto:${kontakAdmin.email}`">{{ kontakAdmin.email }}</a></dd>
        </div>
        <div>
          <dt>Telepon</dt>
          <dd>{{ kontakAdmin.telepon }}</dd>
        </div>
        <div>
          <dt>Jam layanan</dt>
          <dd>{{ kontakAdmin.jamLayanan }}</dd>
        </div>
      </dl>
    </div>
  </section>

  <section class="card">
    <h2 class="section-title" style="font-size: 15px">Pertanyaan umum</h2>
    <div class="faq">
      <details v-for="item in pertanyaanUmum" :key="item.q" class="faq__item">
        <summary>{{ item.q }}</summary>
        <p>{{ item.a }}</p>
      </details>
    </div>
  </section>
</template>

<style scoped>
.contact-card {
  margin-top: 22px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 20px 22px;
  max-width: 380px;
}

.contact-card__name {
  font-size: 14px;
  font-weight: 600;
}

.contact-card__role {
  margin-top: 2px;
  font-size: 12px;
  color: var(--color-text-faint);
}

.contact-card__meta {
  margin: 16px 0 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.contact-card__meta dt {
  font-size: 11px;
  color: var(--color-text-faint);
}

.contact-card__meta dd {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--color-text);
}

.contact-card__meta a {
  color: var(--color-primary);
  text-decoration: none;
}

.faq {
  margin-top: 18px;
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
  font-size: 14px;
  font-weight: 500;
}

.faq__item p {
  margin-top: 8px;
  font-size: 13px;
  color: var(--color-text-muted);
}
</style>
