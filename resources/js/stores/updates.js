import axios from 'axios';

export async function fetchPublicUpdates(params = {}) {
    const { data } = await axios.get('/api/updates', { params });
    return data;
}

export async function fetchPublicUpdateItem(itemId) {
    const { data } = await axios.get(`/api/updates/items/${itemId}`);
    return data;
}

export async function submitIdea(payload) {
    const { data } = await axios.post('/api/updates/ideas', payload);
    return data;
}

export async function submitBug(payload) {
    const formData = new FormData();
    formData.append('title', payload.title || '');
    formData.append('description', payload.description || '');
    if (payload.email) formData.append('email', payload.email);
    if (payload.browser_notes) formData.append('browser_notes', payload.browser_notes);
    if (payload.screenshot) formData.append('screenshot', payload.screenshot);

    const { data } = await axios.post('/api/updates/bugs', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
}

export async function submitPublicComment(itemId, payload) {
    const { data } = await axios.post(`/api/updates/items/${itemId}/comments`, payload);
    return data;
}

export async function voteFeedbackItem(itemId, direction = 'up') {
    const { data } = await axios.post(`/api/updates/items/${itemId}/vote`, { direction });
    return data;
}

export async function fetchAdminFeedback(params = {}) {
    const { data } = await axios.get('/admin/feedback-items', { params });
    return data;
}

export async function fetchAdminFeedbackItem(itemId) {
    const { data } = await axios.get(`/admin/feedback-items/${itemId}`);
    return data;
}

export async function createAdminFeedbackItem(payload) {
    const { data } = await axios.post('/admin/feedback-items', payload);
    return data;
}

export async function updateAdminFeedbackItem(itemId, payload) {
    const { data } = await axios.patch(`/admin/feedback-items/${itemId}`, payload);
    return data;
}

export async function deleteAdminFeedbackItem(itemId) {
    const { data } = await axios.delete(`/admin/feedback-items/${itemId}`);
    return data;
}

export async function promoteAdminFeedbackItem(itemId, payload = {}) {
    const { data } = await axios.post(`/admin/feedback-items/${itemId}/promote`, payload);
    return data;
}

export async function postAdminFeedbackResponse(itemId, payload) {
    const { data } = await axios.post(`/admin/feedback-items/${itemId}/responses`, payload);
    return data;
}

export async function createAdminRoadmapItem(payload) {
    const { data } = await axios.post('/admin/roadmap-items', payload);
    return data;
}

export async function updateAdminRoadmapItem(roadmapItemId, payload) {
    const { data } = await axios.patch(`/admin/roadmap-items/${roadmapItemId}`, payload);
    return data;
}

export async function deleteAdminRoadmapItem(roadmapItemId) {
    const { data } = await axios.delete(`/admin/roadmap-items/${roadmapItemId}`);
    return data;
}

export async function reorderAdminRoadmapItems(orderedIds) {
    const { data } = await axios.post('/admin/roadmap-items/reorder', { ordered_ids: orderedIds });
    return data;
}

export async function createAdminAnnouncement(payload) {
    const { data } = await axios.post('/admin/announcements', payload);
    return data;
}

export async function updateAdminAnnouncement(announcementId, payload) {
    const { data } = await axios.patch(`/admin/announcements/${announcementId}`, payload);
    return data;
}

export async function deleteAdminAnnouncement(announcementId) {
    const { data } = await axios.delete(`/admin/announcements/${announcementId}`);
    return data;
}

export async function fetchAdminComments(params = {}) {
    const { data } = await axios.get('/admin/comments', { params });
    return data;
}

export async function updateAdminComment(commentId, payload) {
    const { data } = await axios.patch(`/admin/comments/${commentId}`, payload);
    return data;
}

export async function deleteAdminComment(commentId) {
    const { data } = await axios.delete(`/admin/comments/${commentId}`);
    return data;
}
