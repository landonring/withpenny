const FALLBACK_VERSION = '2026.03.11.1';

const normalizedWindowVersion = (() => {
    if (typeof window === 'undefined') return '';
    const raw = window.__PENNY_APP_VERSION__;
    return typeof raw === 'string' ? raw.trim() : '';
})();

export const APP_VERSION = normalizedWindowVersion || FALLBACK_VERSION;

