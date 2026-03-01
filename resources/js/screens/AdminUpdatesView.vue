<template>
    <section class="screen roadmap-admin-screen">
        <div class="roadmap-admin-shell">
            <aside class="roadmap-admin-sidebar" aria-label="Roadmap admin navigation">
                <div class="roadmap-admin-brand">
                    <span class="roadmap-admin-mark">P</span>
                    <div>
                        <p class="roadmap-admin-brand-title">Penny Roadmap</p>
                        <p class="roadmap-admin-brand-sub">Admin Dashboard</p>
                    </div>
                </div>

                <button
                    v-for="item in navItems"
                    :key="item.key"
                    class="roadmap-nav-link"
                    :class="{ 'is-active': section === item.key }"
                    type="button"
                    @click="section = item.key"
                >
                    {{ item.label }}
                </button>

                <router-link class="roadmap-nav-link" to="/admin/users">Users</router-link>
            </aside>

            <main class="roadmap-admin-main">
                <p v-if="message" class="muted">{{ message }}</p>
                <p v-if="error" class="form-error">{{ error }}</p>

                <section v-if="loading" class="roadmap-admin-panel">Loading dashboard…</section>

                <template v-else>
                    <section v-if="section === 'overview'" class="roadmap-stack">
                        <section class="roadmap-admin-panel roadmap-kpis">
                            <article class="roadmap-kpi-card">
                                <p>Feature Requests</p>
                                <strong>{{ items.length }}</strong>
                            </article>
                            <article class="roadmap-kpi-card">
                                <p>Roadmap Items</p>
                                <strong>{{ roadmapItems.length }}</strong>
                            </article>
                            <article class="roadmap-kpi-card">
                                <p>Announcements</p>
                                <strong>{{ announcements.length }}</strong>
                            </article>
                            <article class="roadmap-kpi-card">
                                <p>Comments</p>
                                <strong>{{ comments.length }}</strong>
                            </article>
                        </section>

                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Quick Actions</h2>
                            </div>
                            <div class="roadmap-quick-grid">
                                <button class="roadmap-quick-card" type="button" @click="openCreateFeature">
                                    <span class="roadmap-quick-icon">✦</span>
                                    <strong>Add Feature</strong>
                                    <small>Create a new request quickly.</small>
                                </button>
                                <button class="roadmap-quick-card" type="button" @click="openCreateRoadmap">
                                    <span class="roadmap-quick-icon">↗</span>
                                    <strong>Add New Roadmap</strong>
                                    <small>Define new goals and timelines.</small>
                                </button>
                                <button class="roadmap-quick-card" type="button" @click="openCreateAnnouncement">
                                    <span class="roadmap-quick-icon">✉</span>
                                    <strong>New Announcement</strong>
                                    <small>Broadcast updates to users.</small>
                                </button>
                                <button class="roadmap-quick-card" type="button" @click="exportRoadmapCsv">
                                    <span class="roadmap-quick-icon">⬇</span>
                                    <strong>Export Data</strong>
                                    <small>Download CSV of roadmap data.</small>
                                </button>
                            </div>
                        </section>

                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Recent Activity</h2>
                                <input
                                    v-model="activitySearch"
                                    class="roadmap-activity-search"
                                    type="search"
                                    placeholder="Search activity..."
                                    aria-label="Search activity"
                                />
                            </div>
                            <div class="roadmap-table-wrap">
                                <table class="roadmap-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Source</th>
                                            <th>Status</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="entry in filteredRecentActivity" :key="entry.id">
                                            <td>{{ entry.title }}</td>
                                            <td>{{ entry.source }}</td>
                                            <td>
                                                <span class="status-pill" :class="`status-${entry.statusClass}`">{{ entry.statusLabel }}</span>
                                            </td>
                                            <td>{{ formatDateTime(entry.updated_at) }}</td>
                                        </tr>
                                        <tr v-if="!filteredRecentActivity.length">
                                            <td colspan="4" class="table-empty">Nothing here yet.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>

                    <section v-else-if="section === 'features'" class="roadmap-stack">
                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Feature Requests</h2>
                                <button class="primary-button" type="button" @click="showIdeaForm = !showIdeaForm">
                                    {{ showIdeaForm ? 'Close' : 'Create Feature' }}
                                </button>
                            </div>

                            <form v-if="showIdeaForm" class="admin-create-form" @submit.prevent="createIdea">
                                <label class="field">
                                    <span>Title</span>
                                    <input v-model="newIdea.title" type="text" maxlength="160" required />
                                </label>
                                <label class="field">
                                    <span>Description</span>
                                    <textarea v-model="newIdea.description" rows="4" maxlength="6000" required></textarea>
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="newIdea.status">
                                        <option value="submitted">Submitted</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                                <div class="admin-row-actions">
                                    <button class="primary-button" type="submit" :disabled="creatingIdea">
                                        {{ creatingIdea ? 'Creating…' : 'Create Feature' }}
                                    </button>
                                </div>
                            </form>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Search</span>
                                    <input v-model="ideaSearch" type="search" placeholder="Search title or description" />
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="ideaStatusFilter">
                                        <option value="all">All</option>
                                        <option value="submitted">Submitted</option>
                                        <option value="reported">Reported</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                            </div>

                            <div class="roadmap-table-wrap">
                                <table v-if="filteredIdeas.length" class="roadmap-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Votes</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in filteredIdeas" :key="item.id" :data-feature-row-id="item.id">
                                            <td>{{ item.title }}</td>
                                            <td>{{ item.vote_count }}</td>
                                            <td>{{ formatStatus(item.status) }}</td>
                                            <td>{{ formatDate(item.created_at) }}</td>
                                            <td>
                                                <div class="admin-row-actions">
                                                    <button class="ghost-button" type="button" @click="openIdea(item.id)">Edit</button>
                                                    <button class="ghost-button" type="button" :disabled="itemBusy[item.id]" @click="promoteIdea(item)">
                                                        Promote
                                                    </button>
                                                    <button class="ghost-button danger" type="button" :disabled="itemBusy[item.id]" @click="removeIdea(item)">
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-else class="admin-empty-state">Nothing here yet.</p>
                            </div>
                        </section>

                        <section v-if="selectedIdea && selectedIdea.type !== 'bug'" class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Feature #{{ selectedIdea.id }}</h2>
                                <button class="ghost-button" type="button" @click="selectedIdea = null">Close</button>
                            </div>

                            <label class="field">
                                <span>Title</span>
                                <input v-model="selectedIdea.title" type="text" maxlength="160" />
                            </label>
                            <label class="field">
                                <span>Description</span>
                                <textarea v-model="selectedIdea.description" rows="4" maxlength="6000"></textarea>
                            </label>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Type</span>
                                    <select v-model="selectedIdea.type">
                                        <option value="idea">Idea</option>
                                        <option value="bug">Bug</option>
                                        <option value="improvement">Improvement</option>
                                    </select>
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="selectedIdea.status">
                                        <option value="submitted">Submitted</option>
                                        <option value="reported">Reported</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                                <label class="check-inline">
                                    <input v-model="selectedIdea.comments_locked" type="checkbox" />
                                    <span>Lock comments</span>
                                </label>
                            </div>

                            <label class="field">
                                <span>Official admin response</span>
                                <textarea v-model="selectedIdea.admin_response" rows="3" maxlength="6000"></textarea>
                            </label>

                            <div class="admin-row-actions">
                                <button class="primary-button" type="button" :disabled="savingIdea" @click="saveSelectedIdea">
                                    {{ savingIdea ? 'Saving…' : 'Save' }}
                                </button>
                                <button class="ghost-button" type="button" :disabled="responseBusy" @click="postOfficialResponse">
                                    {{ responseBusy ? 'Posting…' : 'Post response' }}
                                </button>
                                <button class="ghost-button danger" type="button" :disabled="savingIdea" @click="removeIdea(selectedIdea)">
                                    Delete feature
                                </button>
                            </div>

                            <div class="admin-comments-list">
                                <h4>Comments</h4>
                                <p v-if="!selectedIdea.comments?.length" class="admin-empty-state">No comments yet.</p>
                                <article v-for="comment in selectedIdea.comments" :key="comment.id" class="admin-comment-row">
                                    <div>
                                        <strong>{{ comment.author }}</strong>
                                        <p class="muted">{{ formatDateTime(comment.created_at) }}</p>
                                        <p>{{ comment.body }}</p>
                                    </div>
                                    <button class="ghost-button danger" type="button" @click="hardDeleteComment(comment)">Delete</button>
                                </article>
                            </div>
                        </section>
                    </section>

                    <section v-else-if="section === 'bugs'" class="roadmap-stack">
                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Bug Reports</h2>
                                <button class="primary-button" type="button" @click="showBugForm = !showBugForm">
                                    {{ showBugForm ? 'Close' : 'Create Bug' }}
                                </button>
                            </div>

                            <form v-if="showBugForm" class="admin-create-form" @submit.prevent="createBug">
                                <label class="field">
                                    <span>Title</span>
                                    <input v-model="newBug.title" type="text" maxlength="160" required />
                                </label>
                                <label class="field">
                                    <span>Description</span>
                                    <textarea v-model="newBug.description" rows="4" maxlength="6000" required></textarea>
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="newBug.status">
                                        <option value="reported">Reported</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                                <div class="admin-row-actions">
                                    <button class="primary-button" type="submit" :disabled="creatingBug">
                                        {{ creatingBug ? 'Creating…' : 'Create Bug' }}
                                    </button>
                                </div>
                            </form>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Search</span>
                                    <input v-model="bugSearch" type="search" placeholder="Search title or description" />
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="bugStatusFilter">
                                        <option value="all">All</option>
                                        <option value="reported">Reported</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                            </div>

                            <div class="roadmap-table-wrap">
                                <table v-if="filteredBugs.length" class="roadmap-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Votes</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in filteredBugs" :key="item.id" :data-bug-row-id="item.id">
                                            <td>{{ item.title }}</td>
                                            <td>{{ item.vote_count }}</td>
                                            <td>{{ formatStatus(item.status) }}</td>
                                            <td>{{ formatDate(item.created_at) }}</td>
                                            <td>
                                                <div class="admin-row-actions">
                                                    <button class="ghost-button" type="button" @click="openIdea(item.id)">Edit</button>
                                                    <button class="ghost-button" type="button" :disabled="itemBusy[item.id]" @click="promoteIdea(item)">
                                                        Promote
                                                    </button>
                                                    <button class="ghost-button danger" type="button" :disabled="itemBusy[item.id]" @click="removeIdea(item)">
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p v-else class="admin-empty-state">Nothing here yet.</p>
                            </div>
                        </section>

                        <section v-if="selectedIdea && selectedIdea.type === 'bug'" class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Bug #{{ selectedIdea.id }}</h2>
                                <button class="ghost-button" type="button" @click="selectedIdea = null">Close</button>
                            </div>

                            <label class="field">
                                <span>Title</span>
                                <input v-model="selectedIdea.title" type="text" maxlength="160" />
                            </label>
                            <label class="field">
                                <span>Description</span>
                                <textarea v-model="selectedIdea.description" rows="4" maxlength="6000"></textarea>
                            </label>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="selectedIdea.status">
                                        <option value="reported">Reported</option>
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </label>
                                <label class="check-inline">
                                    <input v-model="selectedIdea.comments_locked" type="checkbox" />
                                    <span>Lock comments</span>
                                </label>
                            </div>

                            <label class="field">
                                <span>Official admin response</span>
                                <textarea v-model="selectedIdea.admin_response" rows="3" maxlength="6000"></textarea>
                            </label>

                            <div class="admin-row-actions">
                                <button class="primary-button" type="button" :disabled="savingIdea" @click="saveSelectedIdea">
                                    {{ savingIdea ? 'Saving…' : 'Save' }}
                                </button>
                                <button class="ghost-button" type="button" :disabled="responseBusy" @click="postOfficialResponse">
                                    {{ responseBusy ? 'Posting…' : 'Post response' }}
                                </button>
                                <button class="ghost-button danger" type="button" :disabled="savingIdea" @click="removeIdea(selectedIdea)">
                                    Delete bug
                                </button>
                            </div>

                            <div class="admin-comments-list">
                                <h4>Comments</h4>
                                <p v-if="!selectedIdea.comments?.length" class="admin-empty-state">No comments yet.</p>
                                <article v-for="comment in selectedIdea.comments" :key="comment.id" class="admin-comment-row">
                                    <div>
                                        <strong>{{ comment.author }}</strong>
                                        <p class="muted">{{ formatDateTime(comment.created_at) }}</p>
                                        <p>{{ comment.body }}</p>
                                    </div>
                                    <button class="ghost-button danger" type="button" @click="hardDeleteComment(comment)">Delete</button>
                                </article>
                            </div>
                        </section>
                    </section>

                    <section v-else-if="section === 'roadmap'" class="roadmap-stack">
                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Roadmap Items</h2>
                                <button class="primary-button" type="button" @click="showRoadmapForm = !showRoadmapForm">
                                    {{ showRoadmapForm ? 'Close' : 'Create Roadmap Item' }}
                                </button>
                            </div>

                            <form v-if="showRoadmapForm" class="admin-create-form" @submit.prevent="createRoadmap">
                                <div class="admin-page-filters">
                                    <label class="field-inline">
                                        <span>Title</span>
                                        <input v-model="newRoadmap.title" type="text" maxlength="160" placeholder="Optional if linked" />
                                    </label>
                                    <label class="field-inline">
                                        <span>Status</span>
                                        <select v-model="newRoadmap.status">
                                            <option value="planned">Planned</option>
                                            <option value="in_progress">In Progress</option>
                                            <option value="shipped">Shipped</option>
                                        </select>
                                    </label>
                                    <label class="field-inline">
                                        <span>Link feature</span>
                                        <select v-model="newRoadmap.feedback_item_id">
                                            <option :value="null">None</option>
                                            <option v-for="item in items" :key="`roadmap-link-${item.id}`" :value="item.id">#{{ item.id }} {{ item.title }}</option>
                                        </select>
                                    </label>
                                </div>
                                <label class="field">
                                    <span>Description</span>
                                    <textarea v-model="newRoadmap.description" rows="3" maxlength="6000"></textarea>
                                </label>
                                <div class="admin-row-actions">
                                    <button class="primary-button" type="submit" :disabled="creatingRoadmap">
                                        {{ creatingRoadmap ? 'Creating…' : 'Create Roadmap Item' }}
                                    </button>
                                </div>
                            </form>

                            <div class="roadmap-table-wrap" v-if="roadmapItems.length">
                                <table class="roadmap-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Status</th>
                                            <th>Linked Feature</th>
                                            <th>Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="roadmapItem in roadmapItems" :key="roadmapItem.id" :data-roadmap-row-id="roadmapItem.id">
                                            <td>{{ roadmapItem.title }}</td>
                                            <td>{{ formatStatus(roadmapItem.status) }}</td>
                                            <td>{{ roadmapItem.feedback_item_title || 'None' }}</td>
                                            <td>{{ formatDate(roadmapItem.updated_at || roadmapItem.created_at) }}</td>
                                            <td>
                                                <div class="admin-row-actions">
                                                    <button class="ghost-button" type="button" @click="openRoadmapItem(roadmapItem.id)">Edit</button>
                                                    <button class="ghost-button danger" type="button" :disabled="roadmapBusy[roadmapItem.id]" @click="removeRoadmap(roadmapItem)">
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="admin-empty-cta">
                                <button class="primary-button" type="button" @click="showRoadmapForm = true">Create Roadmap Item</button>
                            </div>
                        </section>

                        <section v-if="selectedRoadmap" class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Roadmap #{{ selectedRoadmap.id }}</h2>
                                <button class="ghost-button" type="button" @click="selectedRoadmap = null">Close</button>
                            </div>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Title</span>
                                    <input v-model="selectedRoadmap.title" type="text" maxlength="160" />
                                </label>
                                <label class="field-inline">
                                    <span>Status</span>
                                    <select v-model="selectedRoadmap.status">
                                        <option value="planned">Planned</option>
                                        <option value="in_progress">In Progress</option>
                                        <option value="shipped">Shipped</option>
                                    </select>
                                </label>
                                <label class="field-inline">
                                    <span>Linked feature</span>
                                    <select v-model="selectedRoadmap.feedback_item_id">
                                        <option :value="null">None</option>
                                        <option v-for="item in items" :key="`link-selected-${selectedRoadmap.id}-${item.id}`" :value="item.id">#{{ item.id }} {{ item.title }}</option>
                                    </select>
                                </label>
                            </div>

                            <label class="field">
                                <span>Description</span>
                                <textarea v-model="selectedRoadmap.description" rows="4" maxlength="6000"></textarea>
                            </label>

                            <div class="admin-row-actions">
                                <button class="primary-button" type="button" :disabled="roadmapBusy[selectedRoadmap.id]" @click="saveRoadmap(selectedRoadmap)">
                                    {{ roadmapBusy[selectedRoadmap.id] ? 'Saving…' : 'Save' }}
                                </button>
                                <button class="ghost-button danger" type="button" :disabled="roadmapBusy[selectedRoadmap.id]" @click="removeRoadmap(selectedRoadmap)">
                                    Delete
                                </button>
                            </div>
                        </section>
                    </section>

                    <section v-else-if="section === 'announcements'" class="roadmap-stack">
                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Announcements</h2>
                                <button class="primary-button" type="button" @click="showAnnouncementForm = !showAnnouncementForm">
                                    {{ showAnnouncementForm ? 'Close' : 'Create Announcement' }}
                                </button>
                            </div>

                            <form v-if="showAnnouncementForm" class="admin-create-form" @submit.prevent="createAnnouncementEntry">
                                <label class="field">
                                    <span>Title</span>
                                    <input v-model="newAnnouncement.title" type="text" maxlength="180" required />
                                </label>
                                <label class="field">
                                    <span>Body</span>
                                    <textarea v-model="newAnnouncement.body" rows="4" maxlength="6000" required></textarea>
                                </label>
                                <div class="admin-page-filters">
                                    <label class="field-inline">
                                        <span>Tags (comma-separated)</span>
                                        <input v-model="newAnnouncement.tags_text" type="text" placeholder="Feature, Improvement" />
                                    </label>
                                    <label class="field-inline">
                                        <span>Linked shipped feature</span>
                                        <select v-model="newAnnouncement.feedback_item_id">
                                            <option :value="null">None</option>
                                            <option v-for="item in shippedIdeas" :key="`ship-${item.id}`" :value="item.id">#{{ item.id }} {{ item.title }}</option>
                                        </select>
                                    </label>
                                    <label class="check-inline">
                                        <input v-model="newAnnouncement.is_published" type="checkbox" />
                                        <span>Published</span>
                                    </label>
                                </div>
                                <div class="admin-row-actions">
                                    <button class="primary-button" type="submit" :disabled="creatingAnnouncement">
                                        {{ creatingAnnouncement ? 'Creating…' : 'Create Announcement' }}
                                    </button>
                                </div>
                            </form>

                            <div class="roadmap-table-wrap" v-if="announcements.length">
                                <table class="roadmap-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Status</th>
                                            <th>Linked Feature</th>
                                            <th>Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="announcement in announcements" :key="announcement.id">
                                            <td>{{ announcement.title }}</td>
                                            <td>{{ announcement.is_published ? 'Published' : 'Draft' }}</td>
                                            <td>{{ announcement.feedback_item_title || 'None' }}</td>
                                            <td>{{ formatDate(announcement.updated_at || announcement.created_at) }}</td>
                                            <td>
                                                <div class="admin-row-actions">
                                                    <button class="ghost-button" type="button" @click="openAnnouncementItem(announcement.id)">Edit</button>
                                                    <button class="ghost-button danger" type="button" :disabled="announcementBusy[announcement.id]" @click="removeAnnouncement(announcement)">
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="admin-empty-cta">
                                <button class="primary-button" type="button" @click="showAnnouncementForm = true">Create Announcement</button>
                            </div>
                        </section>

                        <section v-if="selectedAnnouncement" class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Announcement #{{ selectedAnnouncement.id }}</h2>
                                <button class="ghost-button" type="button" @click="selectedAnnouncement = null">Close</button>
                            </div>

                            <label class="field">
                                <span>Title</span>
                                <input v-model="selectedAnnouncement.title" type="text" maxlength="180" />
                            </label>
                            <label class="field">
                                <span>Body</span>
                                <textarea v-model="selectedAnnouncement.body" rows="5" maxlength="6000"></textarea>
                            </label>
                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Tags</span>
                                    <input v-model="selectedAnnouncement.tags_text" type="text" placeholder="Feature, Bug Fix" />
                                </label>
                                <label class="field-inline">
                                    <span>Linked shipped feature</span>
                                    <select v-model="selectedAnnouncement.feedback_item_id">
                                        <option :value="null">None</option>
                                        <option v-for="item in shippedIdeas" :key="`ship-ann-selected-${selectedAnnouncement.id}-${item.id}`" :value="item.id">#{{ item.id }} {{ item.title }}</option>
                                    </select>
                                </label>
                                <label class="check-inline">
                                    <input v-model="selectedAnnouncement.is_published" type="checkbox" />
                                    <span>Published</span>
                                </label>
                            </div>
                            <div class="admin-row-actions">
                                <button class="primary-button" type="button" :disabled="announcementBusy[selectedAnnouncement.id]" @click="saveAnnouncement(selectedAnnouncement)">
                                    {{ announcementBusy[selectedAnnouncement.id] ? 'Saving…' : 'Save' }}
                                </button>
                                <button class="ghost-button danger" type="button" :disabled="announcementBusy[selectedAnnouncement.id]" @click="removeAnnouncement(selectedAnnouncement)">
                                    Delete
                                </button>
                            </div>
                        </section>
                    </section>

                    <section v-else class="roadmap-stack">
                        <section class="roadmap-admin-panel">
                            <div class="roadmap-admin-panel-head">
                                <h2>Comments</h2>
                            </div>

                            <div class="admin-page-filters">
                                <label class="field-inline">
                                    <span>Filter by feature</span>
                                    <select v-model="commentItemFilter">
                                        <option value="all">All features</option>
                                        <option v-for="item in items" :key="`comment-filter-${item.id}`" :value="String(item.id)">#{{ item.id }} {{ item.title }}</option>
                                    </select>
                                </label>
                            </div>

                            <div class="admin-page-list" v-if="filteredComments.length">
                                <article v-for="comment in filteredComments" :key="comment.id" class="admin-list-row">
                                    <div class="admin-comment-head">
                                        <div>
                                            <strong>{{ comment.author }}</strong>
                                            <p class="muted">{{ comment.feedback_item_title || 'Unknown feature' }} • {{ formatDateTime(comment.created_at) }}</p>
                                        </div>
                                        <div class="admin-comment-flags">
                                            <span v-if="comment.is_admin" class="badge">Admin</span>
                                            <span v-if="comment.is_spam" class="badge">Spam</span>
                                            <span v-if="comment.is_deleted" class="badge">Soft deleted</span>
                                        </div>
                                    </div>
                                    <p>{{ comment.body }}</p>
                                    <div class="admin-row-actions">
                                        <button class="ghost-button" type="button" :disabled="commentBusy[comment.id]" @click="markCommentSpam(comment)">
                                            Mark as spam
                                        </button>
                                        <button
                                            v-if="!comment.is_deleted"
                                            class="ghost-button"
                                            type="button"
                                            :disabled="commentBusy[comment.id]"
                                            @click="softDeleteComment(comment)"
                                        >
                                            Soft delete
                                        </button>
                                        <button
                                            v-else
                                            class="ghost-button"
                                            type="button"
                                            :disabled="commentBusy[comment.id]"
                                            @click="restoreComment(comment)"
                                        >
                                            Restore
                                        </button>
                                        <button class="ghost-button danger" type="button" :disabled="commentBusy[comment.id]" @click="hardDeleteComment(comment)">
                                            Delete permanently
                                        </button>
                                    </div>
                                </article>
                            </div>
                            <p v-else class="admin-empty-state">Nothing here yet.</p>
                        </section>
                    </section>
                </template>
            </main>
        </div>
    </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import {
    createAdminAnnouncement,
    createAdminFeedbackItem,
    createAdminRoadmapItem,
    deleteAdminAnnouncement,
    deleteAdminComment,
    deleteAdminFeedbackItem,
    deleteAdminRoadmapItem,
    fetchAdminFeedback,
    fetchAdminFeedbackItem,
    postAdminFeedbackResponse,
    promoteAdminFeedbackItem,
    reorderAdminRoadmapItems,
    updateAdminAnnouncement,
    updateAdminComment,
    updateAdminFeedbackItem,
    updateAdminRoadmapItem,
} from '../stores/updates';

const navItems = [
    { key: 'overview', label: 'Overview' },
    { key: 'roadmap', label: 'Roadmaps' },
    { key: 'announcements', label: 'Announcements' },
    { key: 'features', label: 'Features' },
    { key: 'bugs', label: 'Bugs' },
    { key: 'comments', label: 'Comments' },
];

const section = ref('overview');
const loading = ref(true);
const error = ref('');
const message = ref('');

const items = ref([]);
const roadmapItems = ref([]);
const announcements = ref([]);
const comments = ref([]);

const ideaSearch = ref('');
const ideaStatusFilter = ref('all');
const bugSearch = ref('');
const bugStatusFilter = ref('all');
const commentItemFilter = ref('all');
const activitySearch = ref('');

const showIdeaForm = ref(false);
const showBugForm = ref(false);
const showRoadmapForm = ref(false);
const showAnnouncementForm = ref(false);

const selectedIdea = ref(null);
const selectedIdeaId = ref(null);
const selectedRoadmap = ref(null);
const selectedRoadmapId = ref(null);
const selectedAnnouncement = ref(null);
const selectedAnnouncementId = ref(null);

const creatingIdea = ref(false);
const creatingBug = ref(false);
const savingIdea = ref(false);
const responseBusy = ref(false);
const creatingRoadmap = ref(false);
const creatingAnnouncement = ref(false);

const draggingRoadmapId = ref(null);

const itemBusy = reactive({});
const roadmapBusy = reactive({});
const announcementBusy = reactive({});
const commentBusy = reactive({});

const newIdea = reactive({
    title: '',
    description: '',
    status: 'submitted',
});

const newBug = reactive({
    title: '',
    description: '',
    status: 'reported',
});

const newRoadmap = reactive({
    title: '',
    description: '',
    status: 'planned',
    feedback_item_id: null,
});

const newAnnouncement = reactive({
    title: '',
    body: '',
    tags_text: '',
    feedback_item_id: null,
    is_published: true,
});

const parseTags = (value) =>
    String(value || '')
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean);

const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString();
};

const formatDateTime = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
};

const formatStatus = (status) => {
    if (!status) return '—';
    return status.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
};

const firstApiErrorMessage = (err, fallback) => {
    const validationErrors = err?.response?.data?.errors;
    if (validationErrors && typeof validationErrors === 'object') {
        const firstField = Object.values(validationErrors).find((messages) => Array.isArray(messages) && messages.length);
        if (firstField?.[0]) return String(firstField[0]);
    }
    return err?.response?.data?.message || fallback;
};

const statusClass = (status) => {
    if (status === 'in_progress') return 'in-progress';
    if (status === 'planned') return 'planned';
    if (status === 'shipped') return 'shipped';
    if (status === 'closed') return 'closed';
    return 'submitted';
};

const featureIdeas = computed(() => items.value.filter((item) => item.type !== 'bug'));
const bugIdeas = computed(() => items.value.filter((item) => item.type === 'bug'));
const shippedIdeas = computed(() => featureIdeas.value.filter((item) => item.status === 'shipped'));

const filteredIdeas = computed(() => {
    const term = ideaSearch.value.trim().toLowerCase();

    return featureIdeas.value.filter((item) => {
        if (ideaStatusFilter.value !== 'all' && item.status !== ideaStatusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        const text = `${item.title || ''} ${item.description || ''}`.toLowerCase();
        return text.includes(term);
    });
});

const filteredBugs = computed(() => {
    const term = bugSearch.value.trim().toLowerCase();

    return bugIdeas.value.filter((item) => {
        if (bugStatusFilter.value !== 'all' && item.status !== bugStatusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        const text = `${item.title || ''} ${item.description || ''}`.toLowerCase();
        return text.includes(term);
    });
});

const filteredComments = computed(() => {
    if (commentItemFilter.value === 'all') {
        return comments.value;
    }

    return comments.value.filter((comment) => String(comment.feedback_item_id) === commentItemFilter.value);
});

const recentActivity = computed(() => {
    const featureRows = featureIdeas.value.map((item) => ({
        id: `feature-${item.id}`,
        title: item.title,
        source: 'Feature',
        statusLabel: formatStatus(item.status),
        statusClass: statusClass(item.status),
        updated_at: item.updated_at || item.created_at,
    }));

    const bugRows = bugIdeas.value.map((item) => ({
        id: `bug-${item.id}`,
        title: item.title,
        source: 'Bug',
        statusLabel: formatStatus(item.status),
        statusClass: statusClass(item.status),
        updated_at: item.updated_at || item.created_at,
    }));

    const roadmapRows = roadmapItems.value.map((item) => ({
        id: `roadmap-${item.id}`,
        title: item.title,
        source: 'Roadmap',
        statusLabel: formatStatus(item.status),
        statusClass: statusClass(item.status),
        updated_at: item.updated_at || item.created_at,
    }));

    const announcementRows = announcements.value.map((item) => ({
        id: `announcement-${item.id}`,
        title: item.title,
        source: 'Announcement',
        statusLabel: item.is_published ? 'Published' : 'Draft',
        statusClass: item.is_published ? 'shipped' : 'submitted',
        updated_at: item.published_at || item.updated_at || item.created_at,
    }));

    return [...featureRows, ...bugRows, ...roadmapRows, ...announcementRows]
        .sort((a, b) => new Date(b.updated_at || 0).getTime() - new Date(a.updated_at || 0).getTime())
        .slice(0, 10);
});

const filteredRecentActivity = computed(() => {
    const term = activitySearch.value.trim().toLowerCase();
    if (!term) return recentActivity.value;

    return recentActivity.value.filter((entry) => {
        const haystack = `${entry.title} ${entry.source} ${entry.statusLabel}`.toLowerCase();
        return haystack.includes(term);
    });
});

const openCreateFeature = () => {
    section.value = 'features';
    showIdeaForm.value = true;
};

const openCreateRoadmap = () => {
    section.value = 'roadmap';
    showRoadmapForm.value = true;
};

const openCreateAnnouncement = () => {
    section.value = 'announcements';
    showAnnouncementForm.value = true;
};

const exportRoadmapCsv = () => {
    const rows = [
        ['type', 'id', 'title', 'status', 'updated_at'],
        ...items.value.map((item) => [item.type === 'bug' ? 'bug' : 'feature', item.id, item.title, item.status, item.updated_at || item.created_at]),
        ...roadmapItems.value.map((item) => ['roadmap', item.id, item.title, item.status, item.updated_at || item.created_at]),
        ...announcements.value.map((item) => ['announcement', item.id, item.title, item.is_published ? 'published' : 'draft', item.updated_at || item.created_at]),
    ];

    const csv = rows
        .map((row) => row.map((value) => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))
        .join('\\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `penny-roadmap-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
};

const loadData = async () => {
    loading.value = true;
    error.value = '';

    try {
        const payload = await fetchAdminFeedback({ include_deleted_comments: 1 });

        items.value = payload.items || [];
        roadmapItems.value = payload.roadmap_items || [];
        announcements.value = (payload.announcements || []).map((entry) => ({
            ...entry,
            tags_text: Array.isArray(entry.tags) ? entry.tags.join(', ') : '',
        }));
        comments.value = payload.comments || [];

        if (selectedIdeaId.value) {
            const exists = items.value.some((item) => item.id === selectedIdeaId.value);
            if (exists) {
                await openIdea(selectedIdeaId.value);
            } else {
                selectedIdeaId.value = null;
                selectedIdea.value = null;
            }
        }

        if (selectedRoadmapId.value) {
            const nextRoadmap = roadmapItems.value.find((item) => item.id === selectedRoadmapId.value);
            selectedRoadmap.value = nextRoadmap ? { ...nextRoadmap } : null;
            if (!nextRoadmap) selectedRoadmapId.value = null;
        }

        if (selectedAnnouncementId.value) {
            const nextAnnouncement = announcements.value.find((item) => item.id === selectedAnnouncementId.value);
            selectedAnnouncement.value = nextAnnouncement ? { ...nextAnnouncement } : null;
            if (!nextAnnouncement) selectedAnnouncementId.value = null;
        }
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to load roadmap feedback admin data right now.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadData();
});

const createIdea = async () => {
    creatingIdea.value = true;
    error.value = '';

    try {
        const title = String(newIdea.title || '').trim();
        const description = String(newIdea.description || '').trim();
        const response = await createAdminFeedbackItem({
            title,
            description,
            status: newIdea.status,
            type: 'idea',
        });
        const created = response?.item || null;

        newIdea.title = '';
        newIdea.description = '';
        newIdea.status = 'submitted';
        showIdeaForm.value = false;
        section.value = 'features';
        ideaSearch.value = '';
        ideaStatusFilter.value = 'all';
        message.value = 'Feature created.';

        if (created?.id) {
            const hasItem = items.value.some((item) => item.id === created.id);
            if (!hasItem) {
                items.value = [created, ...items.value];
            }
            selectedIdeaId.value = created.id;
            selectedIdea.value = created;
            await nextTick();
            const row = document.querySelector(`[data-feature-row-id="${created.id}"]`);
            row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        await loadData();
    } catch (err) {
        error.value = firstApiErrorMessage(err, 'Unable to create feature right now.');
    } finally {
        creatingIdea.value = false;
    }
};

const createBug = async () => {
    creatingBug.value = true;
    error.value = '';

    try {
        const title = String(newBug.title || '').trim();
        const description = String(newBug.description || '').trim();
        const response = await createAdminFeedbackItem({
            title,
            description,
            status: newBug.status,
            type: 'bug',
        });
        const created = response?.item || null;

        newBug.title = '';
        newBug.description = '';
        newBug.status = 'reported';
        showBugForm.value = false;
        section.value = 'bugs';
        bugSearch.value = '';
        bugStatusFilter.value = 'all';
        message.value = 'Bug created.';

        if (created?.id) {
            const hasItem = items.value.some((item) => item.id === created.id);
            if (!hasItem) {
                items.value = [created, ...items.value];
            }
            selectedIdeaId.value = created.id;
            selectedIdea.value = created;
            await nextTick();
            const row = document.querySelector(`[data-bug-row-id="${created.id}"]`);
            row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        await loadData();
    } catch (err) {
        error.value = firstApiErrorMessage(err, 'Unable to create bug right now.');
    } finally {
        creatingBug.value = false;
    }
};

const openIdea = async (id) => {
    selectedIdeaId.value = Number(id);
    error.value = '';

    try {
        const payload = await fetchAdminFeedbackItem(selectedIdeaId.value);
        selectedIdea.value = payload.item;
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to load item details.';
    }
};

const saveSelectedIdea = async () => {
    if (!selectedIdea.value?.id) return;

    savingIdea.value = true;
    error.value = '';

    try {
        const response = await updateAdminFeedbackItem(selectedIdea.value.id, {
            title: selectedIdea.value.title,
            description: selectedIdea.value.description,
            type: selectedIdea.value.type,
            status: selectedIdea.value.status,
            comments_locked: selectedIdea.value.comments_locked,
            admin_response: selectedIdea.value.admin_response,
        });

        selectedIdea.value = {
            ...selectedIdea.value,
            ...response.item,
            comments: selectedIdea.value.comments || [],
        };

        message.value = selectedIdea.value?.type === 'bug' ? 'Bug updated.' : 'Feature updated.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save this item right now.';
    } finally {
        savingIdea.value = false;
    }
};

const removeIdea = async (item) => {
    if (!item?.id) return;
    const itemLabel = item?.type === 'bug' ? 'bug' : 'feature';
    if (!window.confirm(`Delete ${itemLabel} #${item.id}?`)) return;

    itemBusy[item.id] = true;
    error.value = '';

    try {
        await deleteAdminFeedbackItem(item.id);

        if (selectedIdeaId.value === item.id) {
            selectedIdeaId.value = null;
            selectedIdea.value = null;
        }

        message.value = item?.type === 'bug' ? 'Bug deleted.' : 'Feature deleted.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete this item right now.';
    } finally {
        itemBusy[item.id] = false;
    }
};

const promoteIdea = async (item) => {
    if (!item?.id) return;

    itemBusy[item.id] = true;
    error.value = '';

    try {
        await promoteAdminFeedbackItem(item.id, { status: 'planned' });
        message.value = item?.type === 'bug' ? 'Bug promoted to roadmap.' : 'Feature promoted to roadmap.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to promote this item right now.';
    } finally {
        itemBusy[item.id] = false;
    }
};

const postOfficialResponse = async () => {
    if (!selectedIdea.value?.id) return;

    const body = String(selectedIdea.value.admin_response || '').trim();
    if (!body) {
        error.value = 'Write a response first.';
        return;
    }

    responseBusy.value = true;
    error.value = '';

    try {
        const response = await postAdminFeedbackResponse(selectedIdea.value.id, { body });
        selectedIdea.value.comments = [response.comment, ...(selectedIdea.value.comments || [])];
        message.value = 'Official response posted.';
        await loadData();
        await openIdea(selectedIdea.value.id);
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to post an official response right now.';
    } finally {
        responseBusy.value = false;
    }
};

const createRoadmap = async () => {
    creatingRoadmap.value = true;
    error.value = '';

    try {
        const title = String(newRoadmap.title || '').trim();
        const description = String(newRoadmap.description || '').trim();
        const payload = {
            status: newRoadmap.status,
            feedback_item_id: newRoadmap.feedback_item_id,
        };

        // Title is optional only when linked to a feature; avoid sending empty strings.
        if (title.length > 0) payload.title = title;
        if (description.length > 0) payload.description = description;

        if (!payload.feedback_item_id && !payload.title) {
            error.value = 'Add a title or link a feature before creating a roadmap item.';
            return;
        }

        const response = await createAdminRoadmapItem(payload);
        const created = response?.roadmap_item || null;

        newRoadmap.title = '';
        newRoadmap.description = '';
        newRoadmap.status = 'planned';
        newRoadmap.feedback_item_id = null;
        showRoadmapForm.value = true;
        section.value = 'roadmap';
        message.value = 'Roadmap item created.';

        if (created?.id) {
            const hasItem = roadmapItems.value.some((item) => item.id === created.id);
            if (!hasItem) {
                roadmapItems.value = [...roadmapItems.value, created]
                    .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
            }
            selectedRoadmapId.value = created.id;
            selectedRoadmap.value = { ...created };
            await nextTick();
            const row = document.querySelector(`[data-roadmap-row-id="${created.id}"]`);
            row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        await loadData();
    } catch (err) {
        error.value = firstApiErrorMessage(err, 'Unable to create roadmap item right now.');
    } finally {
        creatingRoadmap.value = false;
    }
};

const openRoadmapItem = (id) => {
    selectedRoadmapId.value = Number(id);
    const next = roadmapItems.value.find((item) => item.id === selectedRoadmapId.value);
    selectedRoadmap.value = next ? { ...next } : null;
};

const saveRoadmap = async (item) => {
    if (!item?.id) return;

    roadmapBusy[item.id] = true;
    error.value = '';

    try {
        await updateAdminRoadmapItem(item.id, {
            title: item.title,
            description: item.description,
            status: item.status,
            feedback_item_id: item.feedback_item_id,
        });

        message.value = 'Roadmap item saved.';
        await loadData();
        if (selectedRoadmapId.value === item.id) {
            openRoadmapItem(item.id);
        }
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save roadmap item right now.';
    } finally {
        roadmapBusy[item.id] = false;
    }
};

const removeRoadmap = async (item) => {
    if (!item?.id) return;
    if (!window.confirm(`Delete roadmap item #${item.id}?`)) return;

    roadmapBusy[item.id] = true;
    error.value = '';

    try {
        await deleteAdminRoadmapItem(item.id);
        if (selectedRoadmapId.value === item.id) {
            selectedRoadmapId.value = null;
            selectedRoadmap.value = null;
        }
        message.value = 'Roadmap item deleted.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete roadmap item right now.';
    } finally {
        roadmapBusy[item.id] = false;
    }
};

const onRoadmapDragStart = (id) => {
    draggingRoadmapId.value = Number(id);
};

const onRoadmapDrop = async (targetId) => {
    const sourceId = draggingRoadmapId.value;
    if (!sourceId || sourceId === Number(targetId)) return;

    const next = [...roadmapItems.value];
    const sourceIndex = next.findIndex((entry) => Number(entry.id) === sourceId);
    const targetIndex = next.findIndex((entry) => Number(entry.id) === Number(targetId));
    if (sourceIndex < 0 || targetIndex < 0) return;

    const [moved] = next.splice(sourceIndex, 1);
    next.splice(targetIndex, 0, moved);
    roadmapItems.value = next;

    try {
        await reorderAdminRoadmapItems(next.map((entry) => entry.id));
        message.value = 'Roadmap order updated.';
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to reorder roadmap items right now.';
        await loadData();
    } finally {
        draggingRoadmapId.value = null;
    }
};

const createAnnouncementEntry = async () => {
    creatingAnnouncement.value = true;
    error.value = '';

    try {
        await createAdminAnnouncement({
            title: newAnnouncement.title,
            body: newAnnouncement.body,
            tags: parseTags(newAnnouncement.tags_text),
            feedback_item_id: newAnnouncement.feedback_item_id,
            is_published: newAnnouncement.is_published,
        });

        newAnnouncement.title = '';
        newAnnouncement.body = '';
        newAnnouncement.tags_text = '';
        newAnnouncement.feedback_item_id = null;
        newAnnouncement.is_published = true;
        showAnnouncementForm.value = false;
        message.value = 'Announcement created.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to create announcement right now.';
    } finally {
        creatingAnnouncement.value = false;
    }
};

const openAnnouncementItem = (id) => {
    selectedAnnouncementId.value = Number(id);
    const next = announcements.value.find((item) => item.id === selectedAnnouncementId.value);
    selectedAnnouncement.value = next ? { ...next } : null;
};

const saveAnnouncement = async (announcement) => {
    if (!announcement?.id) return;

    announcementBusy[announcement.id] = true;
    error.value = '';

    try {
        await updateAdminAnnouncement(announcement.id, {
            title: announcement.title,
            body: announcement.body,
            tags: parseTags(announcement.tags_text),
            feedback_item_id: announcement.feedback_item_id,
            is_published: announcement.is_published,
        });

        message.value = 'Announcement saved.';
        await loadData();
        if (selectedAnnouncementId.value === announcement.id) {
            openAnnouncementItem(announcement.id);
        }
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to save announcement right now.';
    } finally {
        announcementBusy[announcement.id] = false;
    }
};

const removeAnnouncement = async (announcement) => {
    if (!announcement?.id) return;
    if (!window.confirm(`Delete announcement #${announcement.id}?`)) return;

    announcementBusy[announcement.id] = true;
    error.value = '';

    try {
        await deleteAdminAnnouncement(announcement.id);
        if (selectedAnnouncementId.value === announcement.id) {
            selectedAnnouncementId.value = null;
            selectedAnnouncement.value = null;
        }
        message.value = 'Announcement deleted.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete announcement right now.';
    } finally {
        announcementBusy[announcement.id] = false;
    }
};

const markCommentSpam = async (comment) => {
    if (!comment?.id) return;

    commentBusy[comment.id] = true;
    error.value = '';

    try {
        await updateAdminComment(comment.id, { is_spam: true });
        message.value = 'Comment marked as spam.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to update comment right now.';
    } finally {
        commentBusy[comment.id] = false;
    }
};

const softDeleteComment = async (comment) => {
    if (!comment?.id) return;

    commentBusy[comment.id] = true;
    error.value = '';

    try {
        await updateAdminComment(comment.id, { soft_delete: true });
        message.value = 'Comment soft-deleted.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to soft delete comment right now.';
    } finally {
        commentBusy[comment.id] = false;
    }
};

const restoreComment = async (comment) => {
    if (!comment?.id) return;

    commentBusy[comment.id] = true;
    error.value = '';

    try {
        await updateAdminComment(comment.id, { restore: true });
        message.value = 'Comment restored.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to restore comment right now.';
    } finally {
        commentBusy[comment.id] = false;
    }
};

const hardDeleteComment = async (comment) => {
    if (!comment?.id) return;
    if (!window.confirm('Delete this comment permanently?')) return;

    commentBusy[comment.id] = true;
    error.value = '';

    try {
        await deleteAdminComment(comment.id);

        if (selectedIdea.value?.comments) {
            selectedIdea.value.comments = selectedIdea.value.comments.filter((entry) => entry.id !== comment.id);
        }

        message.value = 'Comment deleted permanently.';
        await loadData();
    } catch (err) {
        error.value = err?.response?.data?.message || 'Unable to delete comment right now.';
    } finally {
        commentBusy[comment.id] = false;
    }
};
</script>

<style scoped>
:global(.main-content:not(.marketing-content) > .roadmap-admin-screen) {
    width: 100%;
    max-width: none;
    margin: 0;
}

.roadmap-admin-screen {
    background: #f5f2ea;
    min-height: calc(100vh - 72px);
}

.roadmap-admin-shell {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 16px;
}

.roadmap-admin-sidebar {
    background: #f7f3eb;
    border: 1px solid rgba(47, 58, 51, 0.14);
    border-radius: 18px;
    padding: 14px;
    display: grid;
    align-content: start;
    gap: 8px;
    position: sticky;
    top: 14px;
}

.roadmap-admin-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 6px 12px;
    margin-bottom: 4px;
    border-bottom: 1px solid rgba(47, 58, 51, 0.12);
}

.roadmap-admin-mark {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid rgba(47, 58, 51, 0.2);
    display: grid;
    place-items: center;
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    color: #2f3a33;
    background: #eef2eb;
}

.roadmap-admin-brand-title {
    margin: 0;
    font-weight: 700;
    color: #2f3a33;
}

.roadmap-admin-brand-sub {
    margin: 2px 0 0;
    font-size: 12px;
    color: #6e756d;
}

.roadmap-nav-link {
    display: block;
    width: 100%;
    text-align: left;
    border: 1px solid rgba(47, 58, 51, 0.14);
    border-radius: 12px;
    padding: 10px 12px;
    background: #fff;
    color: #3f4b42;
    font-size: 14px;
    text-decoration: none;
}

.roadmap-nav-link.is-active,
.roadmap-nav-link.router-link-active {
    border-color: rgba(101, 130, 107, 0.46);
    background: #e6eee6;
    color: #2f3a33;
    font-weight: 600;
}

.roadmap-admin-main {
    display: grid;
    gap: 12px;
    align-content: start;
    align-items: start;
}

.roadmap-stack {
    display: grid;
    gap: 12px;
    align-content: start;
    align-items: start;
}

.roadmap-admin-panel {
    border: 1px solid rgba(47, 58, 51, 0.14);
    border-radius: 16px;
    background: #fff;
    padding: 14px;
    display: grid;
    gap: 10px;
    align-content: start;
    min-height: 0;
}

.roadmap-admin-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.roadmap-admin-panel-head h2 {
    margin: 0;
    font-size: 20px;
    color: #2f3a33;
}

.roadmap-activity-search {
    width: min(260px, 48vw);
    border: 1px solid rgba(47, 58, 51, 0.16);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 13px;
    background: #f9faf7;
}

.roadmap-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.roadmap-kpi-card {
    border: 1px solid rgba(47, 58, 51, 0.12);
    border-radius: 12px;
    padding: 10px;
    background: #f7f8f5;
    display: grid;
    gap: 4px;
}

.roadmap-kpi-card p {
    margin: 0;
    font-size: 12px;
    color: #677168;
}

.roadmap-kpi-card strong {
    font-size: 28px;
    line-height: 1;
    color: #2f3a33;
}

.roadmap-quick-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.roadmap-quick-card {
    border: 1px solid rgba(47, 58, 51, 0.12);
    border-radius: 12px;
    padding: 12px;
    text-align: left;
    background: #f7f8f5;
    display: grid;
    gap: 4px;
}

.roadmap-quick-card strong {
    color: #2f3a33;
}

.roadmap-quick-card small {
    color: #70786f;
}

.roadmap-quick-icon {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    border: 1px solid rgba(101, 130, 107, 0.35);
    background: #e8efe6;
    color: #47624e;
    display: grid;
    place-items: center;
    font-size: 14px;
    margin-bottom: 2px;
}

.roadmap-table-wrap {
    overflow-x: auto;
}

.roadmap-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 620px;
}

.roadmap-table th,
.roadmap-table td {
    text-align: left;
    padding: 10px 8px;
    border-bottom: 1px solid rgba(47, 58, 51, 0.08);
    font-size: 13px;
}

.roadmap-table th {
    color: #6c746c;
    font-weight: 600;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    border: 1px solid rgba(47, 58, 51, 0.14);
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 11px;
    color: #2f3a33;
    background: #edf0ea;
}

.status-pill.status-submitted {
    background: #ecebe8;
    color: #5a5d59;
}

.status-pill.status-planned {
    background: #e6ede5;
    color: #4c5f4f;
}

.status-pill.status-in-progress {
    background: #dce7db;
    color: #435946;
}

.status-pill.status-shipped {
    background: #e4ebf3;
    color: #445869;
}

.status-pill.status-closed {
    background: #e9e9e6;
    color: #5e5f5b;
}

.admin-create-form,
.admin-list-row {
    border: 1px solid rgba(47, 58, 51, 0.12);
    border-radius: 12px;
    padding: 12px;
    background: #fff;
    display: grid;
    gap: 10px;
}

.admin-detail-pane {
    border: 1px solid rgba(101, 130, 107, 0.3);
    border-radius: 12px;
    padding: 12px;
    background: #f8faf7;
    display: grid;
    gap: 10px;
}

.admin-page-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    align-items: end;
}

.field,
.field-inline {
    display: grid;
    gap: 6px;
    font-size: 13px;
    color: #697169;
}

.field input,
.field textarea,
.field-inline input,
.field-inline select {
    width: 100%;
    border: 1px solid rgba(47, 58, 51, 0.15);
    background: #fff;
    border-radius: 10px;
    padding: 10px 12px;
}

.check-inline {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #647064;
    font-size: 13px;
}

.admin-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.ghost-button.danger {
    border-color: rgba(140, 26, 26, 0.25);
    color: #8b2f2f;
}

.admin-empty-state,
.table-empty {
    margin: 0;
    color: #6f776f;
    font-size: 14px;
}

.admin-empty-cta {
    border: 1px dashed rgba(47, 58, 51, 0.22);
    border-radius: 14px;
    padding: 34px 20px;
    display: grid;
    place-items: center;
    background: #fbfcfa;
}

.admin-row-head {
    display: flex;
    align-items: center;
    gap: 10px;
}

.drag-handle {
    font-size: 18px;
    color: #717971;
    cursor: grab;
}

.admin-comments-list {
    display: grid;
    gap: 8px;
}

.admin-comments-list h4 {
    margin: 0;
}

.admin-comment-row {
    border: 1px solid rgba(47, 58, 51, 0.12);
    border-radius: 10px;
    padding: 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.admin-comment-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.admin-comment-flags {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.badge {
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 11px;
    border: 1px solid rgba(47, 58, 51, 0.14);
    background: #edf0ea;
}

@media (max-width: 1100px) {
    .roadmap-admin-shell {
        grid-template-columns: 1fr;
    }

    .roadmap-admin-sidebar {
        position: static;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .roadmap-admin-brand {
        grid-column: 1 / -1;
    }

    .roadmap-kpis,
    .roadmap-quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .roadmap-admin-sidebar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .roadmap-kpis,
    .roadmap-quick-grid {
        grid-template-columns: 1fr;
    }
}
</style>
