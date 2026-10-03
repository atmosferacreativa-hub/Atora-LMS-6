#!/usr/bin/env node
/**
 * Auditoría rápida “demo no se rompe”.
 *
 * Revisa:
 * - enlaces con href="#" / href vacío
 * - enlaces internos que devuelven 404
 * - strings prohibidos en el HTML (ej: "Meridian", "de prueba")
 *
 * Uso:
 *   node scripts/demo-audit.mjs --base https://demo.ejemplo.com
 *   node scripts/demo-audit.mjs --base https://demo.ejemplo.com --paths / /dashboard/ /cursos/
 *   node scripts/demo-audit.mjs --base https://demo.ejemplo.com --forbid Meridian WooCommerce "de prueba"
 */

const args = process.argv.slice(2);

function getFlag(flag) {
  const idx = args.indexOf(flag);
  if (idx === -1) return null;
  return args[idx + 1] ?? null;
}

function getFlagValues(flag) {
  const idx = args.indexOf(flag);
  if (idx === -1) return [];
  const out = [];
  for (let i = idx + 1; i < args.length; i++) {
    const v = args[i];
    if (v.startsWith("--")) break;
    out.push(v);
  }
  return out;
}

function nowIso() {
  return new Date().toISOString();
}

const base = getFlag("--base") ?? getFlag("-b");
if (!base) {
  console.error("Missing --base https://tu-demo.com");
  process.exit(2);
}

const baseUrl = new URL(base);
const paths = getFlagValues("--paths");
const forbid = getFlagValues("--forbid");
const maxLinks = Number(getFlag("--max-links") ?? "250");
const timeoutMs = Number(getFlag("--timeout") ?? "15000");
const outJson = getFlag("--json");
const outMd = getFlag("--md") ?? "demo-audit-report.md";

const defaultForbid = ["Meridian", "de prueba", "Sistema de tema Meridian"];
const forbidList = forbid.length ? forbid : defaultForbid;

const defaultPaths = ["/", "/dashboard/", "/cursos/", "/cuenta/"];
const auditPaths = paths.length ? paths : defaultPaths;

function isProbablyHtml(response) {
  const ct = response.headers.get("content-type") || "";
  return ct.includes("text/html");
}

function visibleText(html) {
  const attrs = [...html.matchAll(/\s(?:title|alt|aria-label|placeholder)="([^"]*)"/gi)].map((m) => m[1]);
  const body = html
    .replace(/<(script|style|noscript|template)\b[\s\S]*?<\/\1>/gi, " ")
    .replace(/<!--[\s\S]*?-->/g, " ")
    .replace(/<[^>]+>/g, " ");
  return `${body} ${attrs.join(" ")}`.replace(/&nbsp;|&#160;/g, " ").replace(/\s+/g, " ");
}

async function fetchWithTimeout(url, init = {}) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal, redirect: "manual" });
  } finally {
    clearTimeout(t);
  }
}

function extractHrefs(html) {
  const hrefs = [];
  const re = /<a\b[^>]*\bhref\s*=\s*(["'])(.*?)\1/gi;
  let m;
  while ((m = re.exec(html))) {
    hrefs.push(m[2]);
    if (hrefs.length >= maxLinks) break;
  }
  return hrefs;
}

function normalizeInternalLink(href, fromUrl) {
  const h = (href || "").trim();
  if (!h) return { kind: "empty", url: null };
  if (h === "#" || h.startsWith("#")) return { kind: "hash", url: null };
  if (h.toLowerCase().startsWith("javascript:")) return { kind: "js", url: null };
  if (h.toLowerCase().startsWith("mailto:") || h.toLowerCase().startsWith("tel:")) {
    return { kind: "external", url: null };
  }

  let u;
  try {
    u = new URL(h, fromUrl);
  } catch {
    return { kind: "invalid", url: null };
  }

  if (u.origin !== baseUrl.origin) {
    return { kind: "external", url: null };
  }

  // Normaliza hash y query para check HTTP
  u.hash = "";
  return { kind: "internal", url: u.toString() };
}

async function checkUrlStatus(url) {
  // HEAD primero; si falla o 405, caer a GET
  try {
    const head = await fetchWithTimeout(url, { method: "HEAD" });
    if (head.status !== 405 && head.status !== 501) return head.status;
  } catch {
    // ignore
  }
  try {
    const get = await fetchWithTimeout(url, { method: "GET" });
    return get.status;
  } catch {
    return 0;
  }
}

async function auditPath(path) {
  const url = new URL(path, baseUrl).toString();
  const res = {
    path,
    url,
    status: 0,
    hash_links: [],
    empty_links: 0,
    invalid_links: [],
    internal_404: [],
    forbidden_hits: [],
    scanned_links: 0,
  };

  let response;
  try {
    response = await fetchWithTimeout(url, { method: "GET" });
  } catch {
    res.status = 0;
    return res;
  }
  res.status = response.status;

  if (!isProbablyHtml(response)) return res;
  const html = await response.text();

  // Forbidden strings: only what a visitor can read (text + title/alt/
  // aria-label), not class names like "meridian-header" or inline scripts.
  const text = visibleText(html);
  for (const s of forbidList) {
    if (!s) continue;
    const re = new RegExp(s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i");
    if (re.test(text)) res.forbidden_hits.push(s);
  }

  const hrefs = extractHrefs(html);
  const seen = new Set();
  const internalUrls = [];

  for (const href of hrefs) {
    const { kind, url: norm } = normalizeInternalLink(href, url);
    if (kind === "hash") {
      res.hash_links.push(href);
      continue;
    }
    if (kind === "empty") {
      res.empty_links++;
      continue;
    }
    if (kind === "invalid") {
      res.invalid_links.push(href);
      continue;
    }
    if (kind !== "internal" || !norm) continue;
    if (seen.has(norm)) continue;
    seen.add(norm);
    internalUrls.push(norm);
  }

  res.scanned_links = internalUrls.length;

  for (const u of internalUrls) {
    const status = await checkUrlStatus(u);
    if (status === 404) res.internal_404.push(u);
  }

  return res;
}

function summarize(results) {
  const summary = {
    base: baseUrl.toString(),
    audited_at: nowIso(),
    paths: results.length,
    pages_non_200: results.filter((r) => r.status !== 200).map((r) => ({ path: r.path, status: r.status, url: r.url })),
    total_hash_links: results.reduce((n, r) => n + r.hash_links.length, 0),
    total_empty_links: results.reduce((n, r) => n + r.empty_links, 0),
    total_404_links: results.reduce((n, r) => n + r.internal_404.length, 0),
    forbidden_pages: results.filter((r) => r.forbidden_hits.length).map((r) => ({ path: r.path, hits: r.forbidden_hits })),
  };
  return summary;
}

function toMarkdown(summary, results) {
  const lines = [];
  lines.push(`# Demo audit`);
  lines.push("");
  lines.push(`- Base: ${summary.base}`);
  lines.push(`- Audited at: ${summary.audited_at}`);
  lines.push(`- Paths: ${summary.paths}`);
  lines.push("");
  lines.push(`## Summary`);
  lines.push("");
  lines.push(`- Non-200 pages: ${summary.pages_non_200.length}`);
  lines.push(`- Hash (#) links: ${summary.total_hash_links}`);
  lines.push(`- Empty links: ${summary.total_empty_links}`);
  lines.push(`- Internal 404 links: ${summary.total_404_links}`);
  lines.push(`- Forbidden string pages: ${summary.forbidden_pages.length}`);

  if (summary.pages_non_200.length) {
    lines.push("");
    lines.push(`## Pages non-200`);
    lines.push("");
    for (const p of summary.pages_non_200) {
      lines.push(`- ${p.status} ${p.path} (${p.url})`);
    }
  }

  if (summary.forbidden_pages.length) {
    lines.push("");
    lines.push(`## Forbidden hits`);
    lines.push("");
    for (const p of summary.forbidden_pages) {
      lines.push(`- ${p.path}: ${p.hits.join(", ")}`);
    }
  }

  lines.push("");
  lines.push(`## Details`);
  lines.push("");
  for (const r of results) {
    lines.push(`### ${r.path}`);
    lines.push("");
    lines.push(`- Status: ${r.status}`);
    lines.push(`- Hash links: ${r.hash_links.length}`);
    lines.push(`- Empty links: ${r.empty_links}`);
    lines.push(`- Internal 404: ${r.internal_404.length}`);
    if (r.hash_links.length) {
      lines.push("");
      lines.push(`**Hash links**`);
      for (const h of r.hash_links.slice(0, 40)) lines.push(`- ${h}`);
      if (r.hash_links.length > 40) lines.push(`- ... (${r.hash_links.length - 40} more)`);
    }
    if (r.internal_404.length) {
      lines.push("");
      lines.push(`**404 links**`);
      for (const u of r.internal_404.slice(0, 40)) lines.push(`- ${u}`);
      if (r.internal_404.length > 40) lines.push(`- ... (${r.internal_404.length - 40} more)`);
    }
    lines.push("");
  }

  return lines.join("\n");
}

const results = [];
for (const p of auditPaths) {
  // eslint-disable-next-line no-await-in-loop
  const r = await auditPath(p);
  results.push(r);
}

const summary = summarize(results);
const payload = { summary, results };

if (outJson) {
  await import("node:fs/promises").then((fs) => fs.writeFile(outJson, JSON.stringify(payload, null, 2), "utf8"));
}

const md = toMarkdown(summary, results);
await import("node:fs/promises").then((fs) => fs.writeFile(outMd, md, "utf8"));

console.log(`OK: wrote ${outMd}`);
if (outJson) console.log(`OK: wrote ${outJson}`);

