import axios from 'axios';

const REQUEST_TIMEOUT = 65000;

function parseFileName(contentDisposition) {
    if (!contentDisposition || typeof contentDisposition !== 'string') return null;

    const utfMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch && utfMatch[1]) {
        try {
            return decodeURIComponent(utfMatch[1].replace(/['"]/g, '').trim());
        } catch {
            return utfMatch[1].replace(/['"]/g, '').trim();
        }
    }

    const fallback = contentDisposition.match(/filename="?([^"]+)"?/i);
    return fallback?.[1]?.trim() || null;
}

async function normalizeBlobError(error) {
    const data = error?.response?.data;
    if (!(data instanceof Blob)) return error;

    try {
        const text = await data.text();
        const parsed = JSON.parse(text);
        if (error.response) {
            error.response.data = parsed;
        }
    } catch {
        // Keep original error payload when response body is not JSON.
    }

    return error;
}

export async function generateSpreadsheet(payload = {}) {
    try {
        const response = await axios.post('/api/spreadsheets/generate', payload, {
            responseType: 'blob',
            timeout: REQUEST_TIMEOUT,
        });

        const blob = new Blob([response.data], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        });
        const url = window.URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        const contentDisposition = response.headers['content-disposition'] || '';
        const fileName = parseFileName(contentDisposition) || `penny-budget-${new Date().toISOString().slice(0, 7)}.xlsx`;

        anchor.href = url;
        anchor.download = fileName;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.URL.revokeObjectURL(url);

        return { fileName };
    } catch (error) {
        throw await normalizeBlobError(error);
    }
}
