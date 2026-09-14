/*
 * H3952 — read-only per-account Zoom probe.
 *
 * Decrypts the two existing Zoom OAuth credentials (never writes, never rotates them),
 * refreshes the access token in memory only, and asks Zoom the one question the
 * fresh-link guard asks: does THIS credential see recordings for THIS meeting?
 *
 * Prints statuses and Zoom error codes only — no tokens, no recording URLs.
 *
 * Usage (inside the n8n container):
 *   node zoom_probe.js <meetingId> [<meetingId> ...]
 */
const CryptoJS = require('crypto-js');
const fs = require('node:fs');
// The container has no sqlite3 binary; the host reads the encrypted blobs and
// hands them in as a file. Decryption still happens here, where the key lives.
const BLOBS = JSON.parse(fs.readFileSync('/tmp/h3952_creds.json', 'utf8'));

const KEY = process.env.N8N_ENCRYPTION_KEY;
const CREDS = [
  { id: '47UA7kp1sAv9NCe3', label: 'Zoom Цыди  (Account_1)' },
  { id: 'XJbFogXztImsSVaX', label: 'Zoom ОРС   (Account_2)' },
];

function readCred(id) {
  const out = (BLOBS[id] || '').trim();
  if (!out) throw new Error('no encrypted blob supplied for ' + id);
  const plain = CryptoJS.AES.decrypt(out, KEY).toString(CryptoJS.enc.Utf8);
  return JSON.parse(plain);
}

async function accessToken(c) {
  // Prefer the stored token; refresh in memory when Zoom rejects it. Nothing is written back.
  const stored = c.oauthTokenData && c.oauthTokenData.access_token;
  if (stored) {
    const probe = await fetch('https://api.zoom.us/v2/users/me', {
      headers: { Authorization: `Bearer ${stored}` },
    });
    if (probe.ok) return { token: stored, how: 'stored', me: await probe.json() };
  }
  const refresh = c.oauthTokenData && c.oauthTokenData.refresh_token;
  if (!refresh) return { token: null, how: 'no refresh_token' };
  const basic = Buffer.from(`${c.clientId}:${c.clientSecret}`).toString('base64');
  const r = await fetch('https://zoom.us/oauth/token', {
    method: 'POST',
    headers: {
      Authorization: `Basic ${basic}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: `grant_type=refresh_token&refresh_token=${encodeURIComponent(refresh)}`,
  });
  if (!r.ok) return { token: null, how: `refresh HTTP ${r.status}` };
  const j = await r.json();
  const me = await fetch('https://api.zoom.us/v2/users/me', {
    headers: { Authorization: `Bearer ${j.access_token}` },
  });
  return { token: j.access_token, how: 'refreshed', me: me.ok ? await me.json() : null };
}

(async () => {
  const meetings = process.argv.slice(2);
  for (const spec of CREDS) {
    let c;
    try {
      c = readCred(spec.id);
    } catch (e) {
      console.log(`${spec.label}: cred unreadable (${e.message})`);
      continue;
    }
    const { token, how, me } = await accessToken(c);
    const acct = me ? `${me.account_id} / ${me.email}` : '—';
    console.log(`\n=== ${spec.label} :: token ${how} :: account ${acct}`);
    if (!token) continue;
    for (const m of meetings) {
      const r = await fetch(`https://api.zoom.us/v2/meetings/${m}/recordings`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      let code = '', msg = '', files = 0;
      try {
        const j = await r.json();
        code = j.code ?? '';
        msg = j.message ?? '';
        files = Array.isArray(j.recording_files) ? j.recording_files.length : 0;
      } catch (e) { /* empty body */ }
      console.log(
        `   meeting ${m}: HTTP ${r.status}` +
        (code !== '' ? ` code=${code}` : '') +
        (msg ? ` "${msg}"` : '') +
        ` recording_files=${files}`,
      );
    }
  }
})();
