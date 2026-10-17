import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function processDir(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (['node_modules', '.git', '.cache', 'temp_actualizacion_extraido'].includes(entry.name)) continue;
      processDir(fullPath);
    } else if (entry.isFile() && (entry.name.endsWith('.bat') || entry.name.endsWith('.cmd'))) {
      let content = fs.readFileSync(fullPath, 'utf8');
      
      // Remove chcp 65001 lines that cause buffer seek desync in cmd.exe
      content = content.replace(/^chcp 65001.*$/gm, ':: codepage standard');
      
      // Ensure CRLF line endings
      const crlfContent = content.replace(/\r?\n/g, '\r\n');
      fs.writeFileSync(fullPath, crlfContent, 'utf8');
      console.log(`[OK] Sanitizado y convertido a CRLF: ${fullPath}`);
    }
  }
}

processDir(__dirname);
console.log('Todos los archivos batch han sido normalizados con éxito.');
