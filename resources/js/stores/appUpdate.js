import { reactive } from 'vue';
import axios from 'axios';
import { APP_VERSION } from '../config/appVersion';
import { authState, ensureAuthReady } from './auth';

const VERSION_SEEN_KEY = 'penny.app.version.seen';
const RELOAD_GUARD_KEY = 'penny.app.update.reloaded.version';
const CHANNEL_NAME = 'penny-app-update';
const CLIENT_ID = `${Date.now()}-${Math.random().toString(36).slice(2)}`;

export const appUpdateState = reactive({
    initialized: false,
    supported: false,
    available: false,
    applying: false,
    currentVersion: APP_VERSION,
    deployedVersion: APP_VERSION,
    targetVersion: null,
    error: '',
    changesHref: '/blog',
});

let registrationRef = null;
let waitingWorkerRef = null;
let broadcastChannel = null;
let activationFallbackTimer = null;
let initialized = false;
let pushSyncedUserId = null;
let shouldReloadOnControllerChange = false;

const safeStorageGet = (storage, key) => {
    try {
        return storage.getItem(key);
    } catch {
        return null;
    }
};

const safeStorageSet = (storage, key, value) => {
    try {
        storage.setItem(key, value);
    } catch {
        // Ignore storage write failures.
    }
};

const safeStorageRemove = (storage, key) => {
    try {
        storage.removeItem(key);
    } catch {
        // Ignore storage delete failures.
    }
};

const clearActivationFallbackTimer = () => {
    if (!activationFallbackTimer) return;
    window.clearTimeout(activationFallbackTimer);
    activationFallbackTimer = null;
};

const shouldShowForVersion = (version) => {
    if (!version) return false;
    return safeStorageGet(window.localStorage, VERSION_SEEN_KEY) !== version;
};

const readWorkerVersion = async (worker) => {
    if (!worker || typeof worker.postMessage !== 'function') return null;

    return await new Promise((resolve) => {
        const timer = window.setTimeout(() => {
            resolve(null);
        }, 1500);

        try {
            const channel = new MessageChannel();
            channel.port1.onmessage = (event) => {
                window.clearTimeout(timer);
                resolve(String(event?.data?.version || '').trim() || null);
            };
            worker.postMessage({ type: 'PENNY_GET_VERSION' }, [channel.port2]);
        } catch {
            window.clearTimeout(timer);
            resolve(null);
        }
    });
};

const publish = (type, payload = {}) => {
    const message = {
        source: CLIENT_ID,
        type,
        payload,
    };

    if (broadcastChannel) {
        try {
            broadcastChannel.postMessage(message);
        } catch {
            // Ignore cross-tab broadcast failures.
        }
    }
};

const markVersionSeen = (version) => {
    if (!version) return;
    safeStorageSet(window.localStorage, VERSION_SEEN_KEY, version);
};

const reloadOnceForVersion = (version) => {
    const target = String(version || APP_VERSION).trim() || APP_VERSION;
    if (safeStorageGet(window.sessionStorage, RELOAD_GUARD_KEY) === target) {
        appUpdateState.applying = false;
        return;
    }

    safeStorageSet(window.sessionStorage, RELOAD_GUARD_KEY, target);
    window.location.reload();
};

const showUpdate = (version) => {
    if (!version || !shouldShowForVersion(version)) {
        return;
    }

    appUpdateState.targetVersion = version;
    appUpdateState.available = true;
    appUpdateState.error = '';
    publish('PENNY_UPDATE_AVAILABLE', { version });
};

const fetchDeployedVersion = async () => {
    const abortController = new AbortController();
    const timeout = window.setTimeout(() => abortController.abort(), 4000);

    try {
        const { data } = await axios.get('/api/version', {
            params: { t: Date.now() },
            signal: abortController.signal,
            headers: {
                'Cache-Control': 'no-cache',
            },
        });
        const version = String(data?.version || '').trim();
        return version || null;
    } catch {
        return null;
    } finally {
        window.clearTimeout(timeout);
    }
};

const urlBase64ToUint8Array = (value) => {
    const padded = `${value}${'='.repeat((4 - (value.length % 4)) % 4)}`;
    const normalized = padded.replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(normalized);
    const output = new Uint8Array(raw.length);
    for (let index = 0; index < raw.length; index += 1) {
        output[index] = raw.charCodeAt(index);
    }
    return output;
};

const syncPushSubscription = async (registration) => {
    if (!registration || typeof window === 'undefined') return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if (!('PushManager' in window)) return;

    await ensureAuthReady();
    const userId = authState.user?.id || null;
    if (!userId || pushSyncedUserId === userId) return;

    try {
        const { data } = await axios.get('/api/notifications/settings');
        const vapidPublicKey = String(data?.vapid_public_key || '').trim();
        if (!vapidPublicKey) return;

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });
        }

        await axios.post('/api/notifications/subscribe', subscription.toJSON());
        pushSyncedUserId = userId;
    } catch {
        // Ignore sync failures; retry on next launch.
    }
};

const attemptWaitingActivation = async (targetVersion) => {
    const registration = registrationRef || await navigator.serviceWorker.getRegistration('/sw.js') || await navigator.serviceWorker.getRegistration();
    if (!registration) {
        reloadOnceForVersion(targetVersion);
        return;
    }

    registrationRef = registration;
    waitingWorkerRef = registration.waiting || waitingWorkerRef;

    if (!waitingWorkerRef) {
        try {
            await registration.update();
            waitingWorkerRef = registration.waiting || waitingWorkerRef;
        } catch {
            // Ignore update check failures and fall back to controlled reload.
        }
    }

    if (!waitingWorkerRef) {
        reloadOnceForVersion(targetVersion);
        return;
    }

    waitingWorkerRef.postMessage({
        type: 'PENNY_SKIP_WAITING',
    });

    clearActivationFallbackTimer();
    activationFallbackTimer = window.setTimeout(() => {
        reloadOnceForVersion(targetVersion);
    }, 10000);
};

const handleServiceWorkerMessage = (event) => {
    const type = String(event?.data?.type || '');
    if (type !== 'PENNY_SW_ACTIVATED') return;
    if (!shouldReloadOnControllerChange) return;

    const version = String(event?.data?.version || '').trim() || appUpdateState.targetVersion || appUpdateState.deployedVersion;
    shouldReloadOnControllerChange = false;
    clearActivationFallbackTimer();
    appUpdateState.available = false;
    appUpdateState.applying = false;

    if (version && appUpdateState.deployedVersion === version) {
        markVersionSeen(version);
    }

    reloadOnceForVersion(version);
};

const bindRegistration = async (registration) => {
    registrationRef = registration;

    const installListener = () => {
        const installing = registration.installing;
        if (!installing) return;

        installing.addEventListener('statechange', async () => {
            if (installing.state !== 'installed') {
                return;
            }

            if (!navigator.serviceWorker.controller) {
                // First install, do not show update UI.
                return;
            }

            waitingWorkerRef = registration.waiting || installing;
            const waitingVersion = await readWorkerVersion(waitingWorkerRef);
            const targetVersion = waitingVersion || appUpdateState.deployedVersion || APP_VERSION;
            showUpdate(targetVersion);
        });
    };

    registration.addEventListener('updatefound', installListener);
    installListener();

    if (registration.waiting && navigator.serviceWorker.controller) {
        waitingWorkerRef = registration.waiting;
        const waitingVersion = await readWorkerVersion(waitingWorkerRef);
        const targetVersion = waitingVersion || appUpdateState.deployedVersion || APP_VERSION;
        showUpdate(targetVersion);
    }
};

const setupCrossTabChannel = () => {
    if (!('BroadcastChannel' in window)) {
        return;
    }

    broadcastChannel = new BroadcastChannel(CHANNEL_NAME);
    broadcastChannel.onmessage = async (event) => {
        const message = event?.data || {};
        if (!message.type || message.source === CLIENT_ID) {
            return;
        }

        if (message.type === 'PENNY_UPDATE_AVAILABLE') {
            const version = String(message.payload?.version || '').trim() || appUpdateState.deployedVersion;
            showUpdate(version);
            return;
        }

        if (message.type === 'PENNY_APPLY_UPDATE') {
            const version = String(message.payload?.version || '').trim() || appUpdateState.targetVersion || appUpdateState.deployedVersion;
            appUpdateState.targetVersion = version;
            appUpdateState.applying = true;
            shouldReloadOnControllerChange = true;
            await attemptWaitingActivation(version);
        }
    };
};

export async function applyAppUpdate() {
    if (!appUpdateState.supported || appUpdateState.applying) return;

    const targetVersion = appUpdateState.targetVersion || appUpdateState.deployedVersion || APP_VERSION;
    appUpdateState.error = '';
    appUpdateState.applying = true;
    shouldReloadOnControllerChange = true;
    publish('PENNY_APPLY_UPDATE', { version: targetVersion });

    try {
        await attemptWaitingActivation(targetVersion);
    } catch (error) {
        appUpdateState.applying = false;
        appUpdateState.error = error?.message || 'Unable to start the update right now.';
    }
}

export async function initAppUpdateManager() {
    if (initialized || typeof window === 'undefined') {
        return;
    }
    initialized = true;

    const isLocalhost = ['localhost', '127.0.0.1'].includes(window.location.hostname);
    if (!('serviceWorker' in navigator)) {
        appUpdateState.initialized = true;
        appUpdateState.supported = false;
        return;
    }

    if (isLocalhost) {
        try {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => registration.unregister()));
            if ('caches' in window) {
                const keys = await caches.keys();
                await Promise.all(keys.map((key) => caches.delete(key)));
            }
        } catch {
            // Ignore local cleanup failures.
        }

        appUpdateState.initialized = true;
        appUpdateState.supported = false;
        return;
    }

    appUpdateState.supported = true;
    setupCrossTabChannel();

    navigator.serviceWorker.addEventListener('message', handleServiceWorkerMessage);
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!shouldReloadOnControllerChange) {
            return;
        }

        shouldReloadOnControllerChange = false;
        const targetVersion = appUpdateState.targetVersion || appUpdateState.deployedVersion || APP_VERSION;
        clearActivationFallbackTimer();
        appUpdateState.available = false;
        appUpdateState.applying = false;
        reloadOnceForVersion(targetVersion);
    });

    const storedVersion = safeStorageGet(window.localStorage, VERSION_SEEN_KEY);
    const deployedVersion = await fetchDeployedVersion() || APP_VERSION;
    appUpdateState.deployedVersion = deployedVersion;

    const sessionReloadVersion = safeStorageGet(window.sessionStorage, RELOAD_GUARD_KEY);
    if (sessionReloadVersion === APP_VERSION || (sessionReloadVersion && APP_VERSION !== deployedVersion)) {
        safeStorageRemove(window.sessionStorage, RELOAD_GUARD_KEY);
    }

    if (!storedVersion) {
        markVersionSeen(deployedVersion);
    }

    try {
        const registration = await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
        await bindRegistration(registration);
        await registration.update();
        await syncPushSubscription(registration);
    } catch {
        appUpdateState.error = 'Unable to register app updates on this device.';
        appUpdateState.initialized = true;
        return;
    }

    const controllerVersion = await readWorkerVersion(navigator.serviceWorker.controller);
    if (controllerVersion && controllerVersion === deployedVersion) {
        markVersionSeen(deployedVersion);
    }

    // First install should not show modal.
    if (!storedVersion) {
        appUpdateState.initialized = true;
        return;
    }

    if (APP_VERSION === deployedVersion) {
        markVersionSeen(deployedVersion);
        appUpdateState.initialized = true;
        return;
    }

    showUpdate(deployedVersion);
    appUpdateState.initialized = true;
}
