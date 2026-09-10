<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import { masuk } from "../../lib/auth";

const router = useRouter();
const email = ref("");
const password = ref("");
const galat = ref("");
const memproses = ref(false);

async function handleMasuk() {
  galat.value = "";
  memproses.value = true;
  try {
    await masuk(email.value, password.value);
    router.push("/admin");
  } catch (e) {
    galat.value = e.errors?.email?.[0] || e.message;
  } finally {
    memproses.value = false;
  }
}
</script>

<template>
  <div class="login-wrap">
    <section class="card login-card">
      <div class="login-brand">
        <span class="sidebar__logo login-logo">VPN</span>
        <h1 class="section-title" style="margin: 0">Masuk sebagai Administrator</h1>
      </div>

      <form novalidate @submit.prevent="handleMasuk">
        <div class="field" style="margin-top: 22px">
          <label class="field-label">Email</label>
          <input
            v-model="email"
            type="email"
            class="input"
            placeholder="admin@vpn.local"
            autocomplete="username"
          />
        </div>

        <div class="field" style="margin-top: 14px">
          <label class="field-label">Kata Sandi</label>
          <input
            v-model="password"
            type="password"
            class="input"
            autocomplete="current-password"
          />
        </div>

        <div v-if="galat" class="notice notice-danger">{{ galat }}</div>

        <button
          type="submit"
          class="btn btn-primary"
          style="width: 100%; margin-top: 18px"
          :disabled="memproses"
        >
          {{ memproses ? "Memproses..." : "Masuk" }}
        </button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--color-bg, #f5f6f8);
}
.login-card { width: 100%; max-width: 400px; }
.login-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
}
.login-logo { width: 44px; height: 44px; font-size: 14px; }
</style>
