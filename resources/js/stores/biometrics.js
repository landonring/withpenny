import { reactive } from 'vue';
import axios from 'axios';
import { applyAuthPayload, authState } from './auth';

const ENABLED_KEY = 'penny.biometric.enabled';
const DISMISSED_KEY = 'penny.biometric.dismissed';

export const biometricsState = reactive({
    supported: false,
    enabled: false,
    ready: false,
    busy: false,
});

export function isBiometricEnabledLocal() {
    return localStorage.getItem(ENABLED_KEY) === 'true';
}

export function isBiometricDismissed() {
    return localStorage.getItem(DISMISSED_KEY) === 'true';
}

export function markBiometricDismissed() {
    localStorage.setItem(DISMISSED_KEY, 'true');
}

export function clearBiometricLocalState() {
    localStorage.removeItem(ENABLED_KEY);
    localStorage.removeItem(DISMISSED_KEY);
}

const markBiometricEnabled = () => {
    localStorage.setItem(ENABLED_KEY, 'true');
    localStorage.removeItem(DISMISSED_KEY);
};

async function ensureCsrf(force = false) {
    if (!force && window.axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) {
        return;
    }
    try {
        const { data } = await axios.get('/api/csrf');
        if (data?.csrf_token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = data.csrf_token;
        }
    } catch {
        // ignore
    }
}

export async function checkBiometricSupport() {
    if (typeof window === 'undefined' || !window.isSecureContext) {
        biometricsState.supported = false;
        return false;
    }

    if (!window.PublicKeyCredential || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
        biometricsState.supported = false;
        return false;
    }

    try {
        biometricsState.supported = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        biometricsState.supported = false;
    }

    return biometricsState.supported;
}

export async function refreshBiometricStatus() {
    if (!authState.user) {
        const localEnabled = isBiometricEnabledLocal();
        biometricsState.enabled = localEnabled;
        return localEnabled;
    }

    try {
        const { data } = await axios.get('/api/webauthn/status');
        biometricsState.enabled = !!data.enabled;
        if (biometricsState.enabled) {
            markBiometricEnabled();
        } else {
            clearBiometricLocalState();
        }
        return biometricsState.enabled;
    } catch {
        return false;
    }
}

export async function enableBiometrics() {
    await ensureCsrf(true);
    const supported = await checkBiometricSupport();
    if (!supported) {
        throw new Error('Passkeys aren’t available on this device.');
    }

    await refreshBiometricStatus();

    biometricsState.busy = true;

    try {
        const { data } = await axios.post('/api/webauthn/register/options');
        const publicKey = normalizePublicKey(data);
        const credential = await navigator.credentials.create({ publicKey });

        if (!credential) {
            throw new Error('Passkey didn’t work this time. You can sign in another way.');
        }

        const response = credential.response;
        const payload = {
            id: credential.id,
            rawId: bufferToBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: bufferToBase64Url(response.clientDataJSON),
                attestationObject: bufferToBase64Url(response.attestationObject),
            },
            transports: typeof response.getTransports === 'function' ? response.getTransports() : [],
        };

        await axios.post('/api/webauthn/register/verify', payload);
        biometricsState.enabled = true;
        markBiometricEnabled();
        return true;
    } finally {
        biometricsState.busy = false;
    }
}

export async function disableBiometrics() {
    await axios.delete('/api/webauthn');
    biometricsState.enabled = false;
    clearBiometricLocalState();
}

export async function loginWithBiometrics() {
    await ensureCsrf(true);
    const supported = await checkBiometricSupport();
    if (!supported) {
        throw new Error('Passkeys aren’t available on this device.');
    }

    if (!isBiometricEnabledLocal()) {
        await refreshBiometricStatus();
    }

    const { data } = await axios.post('/api/webauthn/authenticate/options');
    const publicKey = normalizePublicKey(data);
    const assertion = await navigator.credentials.get({ publicKey });

    if (!assertion) {
        throw new Error('Passkey didn’t work this time. You can sign in another way.');
    }

    const response = assertion.response;
    const payload = {
        id: assertion.id,
        rawId: bufferToBase64Url(assertion.rawId),
        type: assertion.type,
        response: {
            clientDataJSON: bufferToBase64Url(response.clientDataJSON),
            authenticatorData: bufferToBase64Url(response.authenticatorData),
            signature: bufferToBase64Url(response.signature),
            userHandle: response.userHandle ? bufferToBase64Url(response.userHandle) : null,
        },
        remember: true,
    };

    const result = await axios.post('/api/webauthn/authenticate/verify', payload);
    applyAuthPayload(result.data);
    authState.justLoggedIn = false;
    return result.data.user;
}

function normalizePublicKey(options) {
    const publicKey = options.publicKey || options;
    const normalized = { ...publicKey };

    if (publicKey.challenge) {
        normalized.challenge = toBuffer(publicKey.challenge);
    }

    if (publicKey.user?.id) {
        normalized.user = {
            ...publicKey.user,
            id: toBuffer(publicKey.user.id),
        };
    }

    if (publicKey.excludeCredentials) {
        normalized.excludeCredentials = publicKey.excludeCredentials.map((cred) => ({
            ...cred,
            id: toBuffer(cred.id),
        }));
    }

    if (publicKey.allowCredentials) {
        normalized.allowCredentials = publicKey.allowCredentials.map((cred) => ({
            ...cred,
            id: toBuffer(cred.id),
        }));
    }

    return normalized;
}

function toBuffer(value) {
    if (value instanceof ArrayBuffer) {
        return value;
    }

    if (ArrayBuffer.isView(value)) {
        return value.buffer;
    }

    if (typeof value === 'string') {
        return base64UrlToBuffer(value);
    }

    return new ArrayBuffer(0);
}

function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach((b) => {
        binary += String.fromCharCode(b);
    });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function base64UrlToBuffer(base64url) {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const pad = base64.length % 4;
    const padded = base64 + (pad ? '='.repeat(4 - pad) : '');
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}
