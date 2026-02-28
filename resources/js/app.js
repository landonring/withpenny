import './bootstrap';
import { createApp } from 'vue';
import App from './components/App.vue';
import router from './router';

const showBootError = (message) => {
    if (!message) return;
    let container = document.getElementById('penny-boot-error');
    if (!container) {
        container = document.createElement('div');
        container.id = 'penny-boot-error';
        container.style.position = 'fixed';
        container.style.inset = '0';
        container.style.zIndex = '10000';
        container.style.background = 'rgba(246, 243, 238, 0.98)';
        container.style.display = 'grid';
        container.style.placeItems = 'center';
        container.style.padding = '24px';
        container.innerHTML = `
            <div style="max-width:420px;background:#fff;border-radius:18px;border:1px solid rgba(0,0,0,0.08);padding:20px;display:grid;gap:10px;text-align:center;">
                <div style="font-weight:600;font-size:16px;">Penny hit a snag</div>
                <div style="font-size:12px;color:#6f6f6f;word-break:break-word;">${message}</div>
                <button style="border:none;border-radius:999px;padding:10px 18px;background:#d6cfc3;color:#2b2b2b;font-weight:600;cursor:pointer;" onclick="location.reload()">Reload</button>
            </div>
        `;
        document.body.appendChild(container);
    } else {
        const textNode = container.querySelector('div > div:nth-child(2)');
        if (textNode) {
            textNode.textContent = message;
        }
    }
};

window.addEventListener('error', (event) => {
    if (event?.message) {
        showBootError(event.message);
    }
});

window.addEventListener('unhandledrejection', (event) => {
    const reason = event?.reason;
    const message = typeof reason === 'string' ? reason : reason?.message;
    if (message) {
        showBootError(message);
    }
});

const app = createApp(App);

app.use(router);
app.mount('#app');
app.config.errorHandler = (err) => {
    const message = err?.message || String(err);
    showBootError(message);
    // eslint-disable-next-line no-console
    console.error(err);
};

router.onError((err) => {
    const message = err?.message || String(err);
    showBootError(message);
    // eslint-disable-next-line no-console
    console.error(err);
});

router.isReady().then(() => {
    document.body.classList.add('app-ready');
});

if ('serviceWorker' in navigator) {
    const isLocalhost = ['localhost', '127.0.0.1'].includes(window.location.hostname);
    window.addEventListener('load', () => {
        if (isLocalhost) {
            navigator.serviceWorker.getRegistrations().then((registrations) => {
                registrations.forEach((registration) => registration.unregister());
            });
            if ('caches' in window) {
                caches.keys().then((keys) => {
                    keys.forEach((key) => caches.delete(key));
                });
            }
            return;
        }
        navigator.serviceWorker.register('/sw.js');
    });
}
