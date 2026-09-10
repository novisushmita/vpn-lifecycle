import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../src/views/admin/VpsList.vue', import.meta.url), 'utf8')
  .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;$/gm, '');
function halaman(apiAdmin) {
  let unmount;
  const context = vm.createContext({
    apiAdmin, ref: value => ({ value }), reactive: value => value,
    onMounted() {}, onBeforeUnmount(fn) { unmount = fn; },
  });
  vm.runInContext(source + '\n globalThis.ui = { periksaSemua, daftar, progres, galat, galatPing, pesan, periksaSemuaBerjalan };', context);
  return { ...context.ui, unmount: () => unmount() };
}

test('memeriksa semua VPS, meneruskan setelah error, dan memperbarui hasil UP/DOWN', async () => {
  const calls = [];
  const ui = halaman(async (path) => {
    calls.push(path);
    if (path === 'admin/vps') return { data: [1, 2, 3].map(id => ({ id, status_terakhir: 'down' })) };
    if (path.endsWith('/2/ping')) throw new Error('Router tidak terhubung');
    return { status_terakhir: path.endsWith('/1/ping') ? 'up' : 'down', ping_terakhir: { status: path.endsWith('/1/ping') ? 'up' : 'down' } };
  });
  await ui.periksaSemua();
  assert.deepEqual(calls, ['admin/vps', 'admin/vps/1/ping', 'admin/vps/2/ping', 'admin/vps/3/ping']);
  assert.equal(ui.daftar.value[0].status_terakhir, 'up');
  assert.equal(ui.galatPing[2], 'Router tidak terhubung');
  assert.equal(ui.progres.value.selesai, 3);
  assert.match(ui.pesan.value, /2 dari 3.*1 tanpa balasan/);
  assert.equal(ui.periksaSemuaBerjalan.value, false);
});

test('daftar kosong tidak mengirim ping', async () => {
  let calls = 0;
  const ui = halaman(async () => { calls++; return { data: [] }; });
  await ui.periksaSemua();
  assert.equal(calls, 1);
  assert.match(ui.pesan.value, /Belum ada VPS/);
});

test('klik ganda dicegah dan pindah halaman menghentikan pengiriman berikutnya', async () => {
  let release;
  let calls = 0;
  const ui = halaman(async () => {
    calls++;
    await new Promise(resolve => { release = resolve; });
    return { data: [{ id: 1 }] };
  });
  const first = ui.periksaSemua();
  await ui.periksaSemua();
  ui.unmount();
  release();
  await first;
  assert.equal(calls, 1);
  assert.equal(ui.periksaSemuaBerjalan.value, false);
});
