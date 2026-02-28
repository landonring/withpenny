import { reactive } from 'vue';
import axios from 'axios';
import { ensureAuthReady } from './auth';

export const receiptState = reactive({
    current: null,
    suggestions: null,
    lineItems: [],
    rawText: '',
    loading: false,
    error: '',
});

export async function scanReceipt(file) {
    await ensureAuthReady();
    receiptState.loading = true;
    receiptState.error = '';

    const formData = new FormData();
    formData.append('image', file, file.name || 'receipt.jpg');

    try {
        const { data } = await axios.post('/api/receipts/scan', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        receiptState.current = {
            ...data.receipt,
            image_url: data.image_url,
        };
        receiptState.suggestions = data.suggestions || {};
        receiptState.lineItems = data.line_items || [];
        receiptState.rawText = data.raw_text || '';
        return receiptState.current;
    } catch (error) {
        receiptState.error = error?.response?.data?.message || 'Unable to scan right now.';
        throw error;
    } finally {
        receiptState.loading = false;
    }
}

export async function scanReceiptImages(files) {
    await ensureAuthReady();
    receiptState.loading = true;
    receiptState.error = '';

    const formData = new FormData();
    files.forEach((file) => {
        formData.append('images[]', file, file.name || 'receipt.jpg');
    });

    try {
        const { data } = await axios.post('/api/receipts/scan-images', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        receiptState.current = {
            ...data.receipt,
            image_url: data.image_url,
        };
        receiptState.suggestions = data.suggestions || {};
        receiptState.lineItems = data.line_items || [];
        receiptState.rawText = data.raw_text || '';
        return receiptState.current;
    } catch (error) {
        receiptState.error = error?.response?.data?.message || 'Unable to scan right now.';
        throw error;
    } finally {
        receiptState.loading = false;
    }
}

export async function fetchReceipt(id) {
    await ensureAuthReady();
    receiptState.loading = true;
    receiptState.error = '';

    try {
        const { data } = await axios.get(`/api/receipts/${id}`);
        receiptState.current = {
            ...data.receipt,
            image_url: data.image_url,
        };
        receiptState.suggestions = data.suggestions || {};
        receiptState.lineItems = data.line_items || [];
        receiptState.rawText = data.raw_text || '';
        return receiptState.current;
    } catch (error) {
        receiptState.error = error?.response?.data?.message || 'Unable to load receipt.';
        throw error;
    } finally {
        receiptState.loading = false;
    }
}

export async function discardReceipt(id) {
    await ensureAuthReady();
    receiptState.loading = true;
    receiptState.error = '';

    try {
        await axios.delete(`/api/receipts/${id}`);
        receiptState.current = null;
        receiptState.suggestions = null;
        receiptState.lineItems = [];
        receiptState.rawText = '';
    } finally {
        receiptState.loading = false;
    }
}
