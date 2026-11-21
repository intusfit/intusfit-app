// O plugin @capacitor-community/apple-sign-in 7.1.0 pede capacitor-swift-pm "from 7.0.0" (aceita só 7.x), mas este app usa 8.5.1 exato:
// o Xcode não resolveria os pacotes. Sobe a exigência para 8.0.0 depois de cada npm install.
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const arq = resolve(import.meta.dirname, '..', 'node_modules', '@capacitor-community', 'apple-sign-in', 'Package.swift');
try {
  const s = await readFile(arq, 'utf8');
  const n = s.replace('capacitor-swift-pm.git", from: "7.0.0")', 'capacitor-swift-pm.git", from: "8.0.0")');
  if (n !== s) { await writeFile(arq, n, 'utf8'); console.log('apple-sign-in: Package.swift ajustado para Capacitor 8'); }
} catch (e) { console.log('apple-sign-in: nada a ajustar (' + e.code + ')'); }
