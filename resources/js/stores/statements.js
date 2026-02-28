import axios from 'axios';

export async function uploadStatement(file) {
    const formData = new FormData();
    formData.append('file', file);

    const { data } = await axios.post('/api/statements/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return data.import;
}

export async function scanStatementImages(images) {
    const formData = new FormData();
    images.forEach((image, index) => {
        formData.append('images[]', image, image.name || `statement-${index + 1}.pdf`);
    });

    const { data } = await axios.post('/api/statements/scan-images', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return data.import;
}

export async function fetchStatement(importId) {
    const { data } = await axios.get(`/api/statements/${importId}`);
    return data.import;
}

export async function confirmStatement(importId, transactions) {
    const { data } = await axios.post(`/api/statements/${importId}/confirm`, { transactions });
    return data;
}

export async function discardStatement(importId) {
    const { data } = await axios.delete(`/api/statements/${importId}`);
    return data;
}
