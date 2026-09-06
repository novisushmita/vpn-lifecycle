import { createRouter, createWebHistory } from "vue-router";

import VpsTersedia from "../views/VpsTersedia.vue";
import PengajuanVpn from "../views/PengajuanVpn.vue";
import CekStatus from "../views/CekStatus.vue";
import Panduan from "../views/Panduan.vue";
import Bantuan from "../views/Bantuan.vue";

import AdminLayout from "../views/admin/AdminLayout.vue";
import Login from "../views/admin/Login.vue";
import Dashboard from "../views/admin/Dashboard.vue";
import PengajuanList from "../views/admin/PengajuanList.vue";
import PengajuanDetail from "../views/admin/PengajuanDetail.vue";
import VpsList from "../views/admin/VpsList.vue";
import AkunList from "../views/admin/AkunList.vue";
import AkunDetail from "../views/admin/AkunDetail.vue";
import DriftList from "../views/admin/DriftList.vue";
import Pengaturan from "../views/admin/Pengaturan.vue";

import { sudahMasuk, muatAdmin } from "../lib/auth";

const routes = [
  { path: "/", redirect: "/vps-tersedia" },

  // ---------- Halaman publik ----------
  { path: "/vps-tersedia", name: "vps-tersedia", component: VpsTersedia, meta: { title: "VPS Tersedia" } },
  { path: "/pengajuan-vpn", name: "pengajuan-vpn", component: PengajuanVpn, meta: { title: "Pengajuan VPN" } },
  { path: "/cek-status", name: "cek-status", component: CekStatus, meta: { title: "Cek Status Pengajuan" } },
  { path: "/panduan", name: "panduan", component: Panduan, meta: { title: "Panduan" } },
  { path: "/bantuan", name: "bantuan", component: Bantuan, meta: { title: "Bantuan" } },

  // ---------- Login admin: tanpa shell apa pun ----------
  { path: "/admin/login", name: "admin-login", component: Login, meta: { layout: "kosong" } },

  // ---------- Dashboard admin ----------
  {
    path: "/admin",
    component: AdminLayout,
    meta: { layout: "admin", butuhAuth: true },
    children: [
      { path: "", name: "admin-dashboard", component: Dashboard, meta: { title: "Dashboard" } },
      { path: "pengajuan", name: "admin-pengajuan", component: PengajuanList, meta: { title: "Data Pengajuan" } },
      { path: "pengajuan/:id", name: "admin-pengajuan-detail", component: PengajuanDetail, meta: { title: "Detail Pengajuan" } },
      { path: "akun", name: "admin-akun", component: AkunList, meta: { title: "Akun VPN" } },
      { path: "akun/:id", name: "admin-akun-detail", component: AkunDetail, meta: { title: "Detail Akun" } },
      { path: "vps", name: "admin-vps", component: VpsList, meta: { title: "Daftar VPS" } },
      { path: "sinkronisasi", name: "admin-drift", component: DriftList, meta: { title: "Pemeriksaan Router" } },
      { path: "pengaturan", name: "admin-pengaturan", component: Pengaturan, meta: { title: "Pengaturan" } },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach(async (to) => {
  if (!to.matched.some((r) => r.meta.butuhAuth)) return true;

  if (!sudahMasuk()) {
    return { name: "admin-login", query: { lanjut: to.fullPath } };
  }

  // Token bisa saja sudah dicabut di server; pastikan masih sah.
  const admin = await muatAdmin();
  return admin ? true : { name: "admin-login" };
});

export default router;
