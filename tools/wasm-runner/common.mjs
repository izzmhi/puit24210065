// Shared helpers for running the PHP backend inside php-wasm (PHP 8.3 compiled to WebAssembly).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

export const here = path.dirname(fileURLToPath(import.meta.url));
const backend = path.resolve(here, '../../backend');

// The real backend is never changed. A copy is made in .work/<name> and only the copy is patched.
export function prepareCopy(name) {
  const work = path.join(here, '.work', name);
  fs.rmSync(work, { recursive: true, force: true });
  fs.mkdirSync(work, { recursive: true });
  fs.cpSync(backend, path.join(work, 'backend'), {
    recursive: true,
    filter: (src) => !/[\\/]\.env$/.test(src),
  });
  fs.copyFileSync(path.join(here, 'compat.php'), path.join(work, 'compat.php'));
  fs.copyFileSync(path.join(here, 'sqlite-schema.sql'), path.join(work, 'sqlite-schema.sql'));
  return work;
}

export function patch(work, file, find, replace) {
  const full = path.join(work, 'backend', file);
  const text = fs.readFileSync(full, 'utf8');
  if (!text.includes(find)) throw new Error(`Cannot patch ${file}: text not found`);
  fs.writeFileSync(full, text.replace(find, replace));
}

export function removeCliGuard(work, file) {
  const full = path.join(work, 'backend', file);
  fs.writeFileSync(full, fs.readFileSync(full, 'utf8').replace(/if \(PHP_SAPI !== 'cli'\) \{[^}]*\}\n/, ''));
}

export async function startPhp(work) {
  const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
  await php.mount('/work', createNodeFsMountHandler(work));
  // The SQLite file lives in php-wasm's in-memory file system (file locks do not work on the mounted folder).
  php.mkdir('/db');
  return php;
}
