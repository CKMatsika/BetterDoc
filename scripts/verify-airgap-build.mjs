import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const targets = [
    path.join(root, 'public', 'build'),
    path.join(root, 'resources', 'views'),
];

const allowedNamespaces = [
    'http://www.w3.org/2000/svg',
    'http://www.w3.org/1999/xlink',
    'http://www.w3.org/XML/1998/namespace',
    'https://www.w3.org/2000/svg',
];

const urlRegex = /https?:\/\/[^\s"'`)<>\\]+/gi;
const licenseCommentRegex = /\/\*![\s\S]*?\*\//g;
const violations = [];

function scanFile(filePath) {
    const ext = path.extname(filePath).toLowerCase();
    if (!['.js', '.mjs', '.css', '.html', '.php', '.svg', '.json', '.webmanifest'].includes(ext)) {
        return;
    }

    const raw = fs.readFileSync(filePath, 'utf8');
    // Strip MIT/OSS license header comments (/*! ... */) before checking external runtime URLs
    const content = raw.replace(licenseCommentRegex, '');
    const matches = content.matchAll(urlRegex);

    for (const match of matches) {
        const url = match[0];
        if (allowedNamespaces.some((ns) => url.startsWith(ns))) {
            continue;
        }
        if (/fonts\.(googleapis|gstatic|bunny)|cdn\.|unpkg\.com|jsdelivr|cdnjs|gravatar|google-analytics|googletagmanager|pusher\.com/i.test(url)) {
            violations.push({ file: path.relative(root, filePath), url, severity: 'CRITICAL_EXTERNAL_DEPENDENCY' });
        } else if (filePath.includes(`${path.sep}resources${path.sep}views${path.sep}`) || ext === '.css' || ext === '.html') {
            violations.push({ file: path.relative(root, filePath), url, severity: 'EXTERNAL_URL_IN_TEMPLATE_OR_STYLE' });
        }
    }
}

function walk(dir) {
    if (!fs.existsSync(dir)) {
        return;
    }
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full);
        } else {
            scanFile(full);
        }
    }
}

for (const dir of targets) {
    walk(dir);
}

if (violations.length > 0) {
    console.error('❌ AIR-GAP BUILD VERIFICATION FAILED — External URLs detected:');
    for (const v of violations) {
        console.error(`  [${v.severity}] ${v.file} -> ${v.url}`);
    }
    process.exit(1);
}

console.log('✅ Air-gap build verification passed: 0 external CDN/font/API URLs detected in compiled assets or views.');
