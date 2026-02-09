import axios from 'axios';

const REQUEST_TIMEOUT = 65000;

function withTimeout(promise, ms = REQUEST_TIMEOUT) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('timeout')), ms);
    });

    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

export async function generateMonthlyReflection(monthKey) {
    const request = axios.post(
        '/api/ai/monthly-reflection',
        { month: monthKey },
        { timeout: REQUEST_TIMEOUT }
    );
    const { data } = await withTimeout(request);
    return data.message;
}

export async function generateWeeklyCheckIn() {
    const request = axios.post('/api/ai/weekly-checkin', {}, { timeout: REQUEST_TIMEOUT });
    const { data } = await withTimeout(request);
    return data.message;
}

export async function sendChatMessage(message) {
    const request = axios.post('/api/ai/chat', { message }, { timeout: REQUEST_TIMEOUT });
    const { data } = await withTimeout(request);
    return data.message;
}
