import 'dotenv/config';
import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Capacitor hybrid-wrapper config for the samskrte.ru student cabinet (Wave 1, H824).
 *
 * The app is a native shell around the EXISTING responsive web cabinet shown in a
 * WebView — not a native-UI rebuild. `server.url` points the WebView at the cabinet
 * host; `server.allowNavigation` keeps cabinet pages in-app and lets Capacitor open
 * everything else (Tochka checkout, external http(s), mailto:, tel:) in the system
 * browser automatically — the payments ruling (D3: no native purchase, reader-app).
 *
 * Config-driven host: the URL is NEVER hardcoded. It is selected by CAP_ENV
 * (staging|prod) and read from CAP_URL_STAGING / CAP_URL_PROD (see .env.example).
 * Missing config fails loud at build time rather than shipping a wrong host.
 */

const ENV = (process.env.CAP_ENV ?? 'staging').toLowerCase();

const HOSTS: Record<string, string | undefined> = {
  staging: process.env.CAP_URL_STAGING,
  prod: process.env.CAP_URL_PROD,
};

const serverUrl = HOSTS[ENV];
if (!serverUrl) {
  throw new Error(
    `[capacitor.config] No cabinet URL configured for CAP_ENV="${ENV}". ` +
      `Set CAP_URL_STAGING / CAP_URL_PROD in mobile/.env (copy mobile/.env.example). ` +
      `Never hardcode the host here.`,
  );
}

// Keep the cabinet host (and its subdomains) inside the WebView; anything else
// is treated as external and opened in the system browser by Capacitor.
const cabinetHost = new URL(serverUrl).hostname;
const parts = cabinetHost.split('.');
const registrable = parts.length > 2 ? parts.slice(-2).join('.') : cabinetHost;
const allowNavigation = Array.from(
  new Set([cabinetHost, registrable, `*.${registrable}`]),
);

const config: CapacitorConfig = {
  // Placeholders pending MG branding (Wave 0 / Wave 3) — safe to change before store submission.
  appId: 'ru.samskrte.app',
  appName: 'Кабинет самскрте',
  webDir: 'www',
  server: {
    url: serverUrl,
    allowNavigation,
    // Local offline / load-error fallback shown when the cabinet is unreachable.
    errorPath: 'error.html',
    androidScheme: 'https',
    // HTTPS only in prod; allow cleartext for local staging only when explicitly opted in.
    cleartext: ENV !== 'prod' && process.env.CAP_ALLOW_CLEARTEXT === '1',
  },
  plugins: {
    SplashScreen: {
      launchShowDuration: 1200,
      launchAutoHide: true,
      backgroundColor: '#0b1020',
      showSpinner: true,
      androidSpinnerStyle: 'large',
      spinnerColor: '#c9a24b',
    },
    StatusBar: {
      style: 'DARK',
      backgroundColor: '#0b1020',
      overlaysWebView: false,
    },
  },
};

export default config;
