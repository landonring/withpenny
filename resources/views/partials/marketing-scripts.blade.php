<script>
    document.querySelectorAll('a[href^="#"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href') || '';
            if (!href.startsWith('#')) return;
            const targetId = href.slice(1);
            const target = document.getElementById(targetId);
            if (!target) return;
            event.preventDefault();
            history.replaceState(null, '', `#${targetId}`);
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    document.querySelectorAll('a[href^="/#"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (window.location.pathname !== '/') return;
            const href = link.getAttribute('href') || '';
            const targetId = href.replace('/#', '');
            const target = document.getElementById(targetId);
            if (!target) return;
            event.preventDefault();
            history.replaceState(null, '', `#${targetId}`);
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    const primaryCta = document.getElementById('primary-cta');
    if (primaryCta) {
        primaryCta.addEventListener('click', () => {
            const ua = navigator.userAgent.toLowerCase();
            const isMobile = ua.includes('mobile') || ua.includes('iphone') || ua.includes('ipad') || ua.includes('android');
            if (isMobile) {
                window.location.href = '/login';
                return;
            }
            const pricingSection = document.getElementById('pricing');
            if (pricingSection) {
                pricingSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.replaceState(null, '', '#pricing');
                return;
            }
            window.location.href = '/#pricing';
        });
    }

    const loginCta = document.getElementById('login-cta');
    const loginCtaMobile = document.getElementById('login-cta-mobile');
    const billingError = document.getElementById('billing-error');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    let csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    let sessionUser = null;

    const isMobileDevice = () => {
        const ua = navigator.userAgent.toLowerCase();
        return ua.includes('mobile') || ua.includes('iphone') || ua.includes('ipad') || ua.includes('android');
    };

    if (loginCta) {
        loginCta.addEventListener('click', () => {
            const loggedIn = loginCta.dataset.loggedIn === 'true';
            if (loggedIn) {
                window.location.href = '/app';
                return;
            }
            window.location.href = '/login';
        });
    }

    const setBillingError = (message) => {
        if (!billingError) return;
        billingError.textContent = message || '';
    };

    const ensureSession = async () => {
        try {
            const response = await fetch('/api/me', { credentials: 'same-origin' });
            if (!response.ok) return null;
            const data = await response.json();
            if (data && data.user) {
                sessionUser = data.user;
                if (loginCta) {
                    loginCta.textContent = 'Dashboard';
                    loginCta.dataset.loggedIn = 'true';
                }
                if (loginCtaMobile) {
                    loginCtaMobile.textContent = 'Dashboard';
                    loginCtaMobile.href = '/app';
                }
                if (data.csrf_token) {
                    csrfToken = data.csrf_token;
                }
                return data.user;
            }
        } catch (error) {
            // ignore
        }
        sessionUser = null;
        if (loginCta) {
            loginCta.textContent = 'Login';
            loginCta.dataset.loggedIn = 'false';
        }
        if (loginCtaMobile) {
            loginCtaMobile.textContent = 'Login';
            loginCtaMobile.href = '/login';
        }
        return null;
    };

    const ensureCsrf = async () => {
        if (csrfToken) return csrfToken;
        try {
            const response = await fetch('/api/csrf', { credentials: 'same-origin' });
            if (!response.ok) return '';
            const data = await response.json();
            if (data?.csrf_token) {
                csrfToken = data.csrf_token;
            }
        } catch (error) {
            // ignore
        }
        return csrfToken;
    };

    ensureSession();

    const billingToggle = document.querySelector('[data-billing-toggle]');
    if (billingToggle) {
        const buttons = Array.from(billingToggle.querySelectorAll('[data-billing]'));
        const toggleButton = billingToggle.querySelector('[data-billing-toggle-button]');
        const priceEls = document.querySelectorAll('[data-price]');
        const unitEls = document.querySelectorAll('[data-unit]');
        const setBilling = (mode) => {
            billingToggle.classList.toggle('is-annual', mode === 'annual');
            buttons.forEach((button) => {
                const isActive = button.dataset.billing === mode;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            if (toggleButton) {
                toggleButton.setAttribute('aria-pressed', mode === 'annual' ? 'true' : 'false');
            }
            priceEls.forEach((el) => {
                const next = el.dataset[mode];
                if (next) el.textContent = next;
            });
            unitEls.forEach((el) => {
                const next = el.dataset[`${mode}Unit`];
                if (next) el.textContent = next;
            });
        };
        buttons.forEach((button) => {
            button.addEventListener('click', () => setBilling(button.dataset.billing));
        });
        if (toggleButton) {
            toggleButton.addEventListener('click', () => {
                const next = billingToggle.classList.contains('is-annual') ? 'monthly' : 'annual';
                setBilling(next);
            });
        }
        setBilling('monthly');
    }

    const planButtons = document.querySelectorAll('[data-plan]');
    if (planButtons.length) {
        planButtons.forEach((button) => {
            button.addEventListener('click', async () => {
                const plan = button.dataset.plan || '';
                if (!plan) return;

                setBillingError('');
                const interval = billingToggle && billingToggle.classList.contains('is-annual') ? 'yearly' : 'monthly';
                const user = sessionUser || (await ensureSession());

                if (plan === 'starter') {
                    if (user && isMobileDevice()) {
                        window.location.href = '/app';
                        return;
                    }
                    window.location.href = '/register?redirect=/app';
                    return;
                }

                if (!user) {
                    const params = new URLSearchParams();
                    if (plan !== 'starter') {
                        params.set('plan', plan);
                        params.set('interval', interval);
                    }
                    params.set('redirect', '/app');
                    window.location.href = `/register?${params.toString()}`;
                    return;
                }

                button.disabled = true;
                try {
                    const token = await ensureCsrf();
                    const response = await fetch('/api/billing/checkout', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token || '',
                        },
                        body: JSON.stringify({ plan, interval }),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        setBillingError(data?.message || 'Unable to start billing right now.');
                        return;
                    }
                    if (data?.url) {
                        window.location.href = data.url;
                        return;
                    }
                    if (data?.status === 'swapped') {
                        setBillingError('Plan updated. Your subscription is now active.');
                        return;
                    }
                    if (data?.status === 'already_subscribed') {
                        setBillingError('You are already on this plan.');
                        return;
                    }
                } catch (error) {
                    setBillingError('Unable to start billing right now.');
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    const mobileMenu = document.querySelector('[data-mobile-menu]');
    const mobileOpen = document.querySelector('[data-mobile-menu-open]');
    const mobileClose = document.querySelector('[data-mobile-menu-close]');
    if (mobileMenu && mobileOpen && mobileClose) {
        const openMenu = () => {
            mobileMenu.classList.add('is-open');
            document.body.classList.add('menu-open');
            mobileOpen.setAttribute('aria-expanded', 'true');
            mobileMenu.setAttribute('aria-hidden', 'false');
        };
        const closeMenu = () => {
            mobileMenu.classList.remove('is-open');
            document.body.classList.remove('menu-open');
            mobileOpen.setAttribute('aria-expanded', 'false');
            mobileMenu.setAttribute('aria-hidden', 'true');
        };
        mobileOpen.addEventListener('click', openMenu);
        mobileClose.addEventListener('click', closeMenu);
        mobileMenu.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', closeMenu);
        });
    }

    const shareButtons = document.querySelectorAll('[data-share-button]');
    if (shareButtons.length) {
        shareButtons.forEach((button) => {
            button.addEventListener('click', async () => {
                const metaTitle = document.querySelector('meta[property="og:title"]');
                const metaSite = document.querySelector('meta[property="og:site_name"]');
                const title = button.dataset.shareTitle || metaTitle?.content || document.title;
                const text = metaSite?.content || 'Penny';
                const url = window.location.href;

                if (navigator.share) {
                    try {
                        await navigator.share({ title, text, url });
                        return;
                    } catch (error) {
                        // ignore and fall back
                    }
                }

                try {
                    await navigator.clipboard.writeText(url);
                    button.classList.add('shared');
                    setTimeout(() => button.classList.remove('shared'), 1500);
                } catch (error) {
                    window.prompt('Copy this link', url);
                }
            });
        });
    }

    const tocRoot = document.querySelector('[data-article-toc]');
    if (tocRoot) {
        const toggle = tocRoot.querySelector('.article-toc-toggle');
        const panel = tocRoot.querySelector('.article-toc-panel');
        const list = tocRoot.querySelector('[data-article-toc-list]');
        const headings = Array.from(document.querySelectorAll('.article-content h2, .article-content h3'));

        if (!headings.length || !toggle || !panel || !list) {
            tocRoot.remove();
        } else {
            const used = new Set();
            const slugify = (text) =>
                text
                    .toLowerCase()
                    .replace(/[^a-z0-9\\s-]/g, '')
                    .trim()
                    .replace(/\\s+/g, '-');

            const ensureId = (heading) => {
                const base = slugify(heading.textContent || '') || 'section';
                let id = heading.id || base;
                let index = 2;
                while (used.has(id) || document.getElementById(id)) {
                    id = `${base}-${index++}`;
                }
                used.add(id);
                heading.id = id;
                return id;
            };

            let currentSublist = null;

            const createLink = (heading, className) => {
                const text = (heading.textContent || '').trim();
                if (!text) return null;
                const id = ensureId(heading);
                const link = document.createElement('a');
                link.href = `#${id}`;
                link.className = className;
                link.textContent = text;
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    history.replaceState(null, '', `#${id}`);
                    const target = document.getElementById(id);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    setOpen(false);
                });
                return link;
            };

            const startSection = (heading) => {
                const section = document.createElement('div');
                section.className = 'article-toc-section';
                const titleLink = createLink(heading, 'article-toc-link article-toc-heading');
                if (!titleLink) return;
                const sublist = document.createElement('div');
                sublist.className = 'article-toc-sublist';
                section.append(titleLink, sublist);
                list.appendChild(section);
                currentSublist = sublist;
            };

            headings.forEach((heading) => {
                if (heading.tagName === 'H2' || !currentSublist) {
                    startSection(heading);
                    return;
                }
                const subLink = createLink(heading, 'article-toc-link is-sub');
                if (subLink) {
                    currentSublist.appendChild(subLink);
                }
            });

            const setOpen = (open) => {
                tocRoot.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.setAttribute('aria-hidden', open ? 'false' : 'true');
            };

            toggle.addEventListener('click', () => {
                const open = !tocRoot.classList.contains('is-open');
                setOpen(open);
            });

            document.addEventListener('click', (event) => {
                if (!tocRoot.classList.contains('is-open')) return;
                if (tocRoot.contains(event.target)) return;
                setOpen(false);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });
        }
    }

    (() => {
        const trigger = document.querySelector('[data-roadmap-open]');
        const overlay = document.querySelector('[data-roadmap-overlay]');
        if (!trigger || !overlay) return;

        const panel = overlay.querySelector('.roadmap-panel');
        const closeButton = overlay.querySelector('[data-roadmap-close]');
        const tabs = Array.from(overlay.querySelectorAll('[data-roadmap-tab]'));
        const views = Array.from(overlay.querySelectorAll('[data-roadmap-view]'));

        const ideasList = overlay.querySelector('[data-roadmap-ideas-list]');
        const bugsList = overlay.querySelector('[data-roadmap-bugs-list]');
        const roadmapList = overlay.querySelector('[data-roadmap-roadmap-list]');
        const announcementsList = overlay.querySelector('[data-roadmap-announcements-list]');
        const ideasEmpty = overlay.querySelector('[data-roadmap-ideas-empty]');
        const bugsEmpty = overlay.querySelector('[data-roadmap-bugs-empty]');
        const roadmapEmpty = overlay.querySelector('[data-roadmap-roadmap-empty]');
        const announcementsEmpty = overlay.querySelector('[data-roadmap-announcements-empty]');
        const inlineError = overlay.querySelector('[data-roadmap-inline-error]');
        const detailContainer = overlay.querySelector('[data-roadmap-detail]');
        const backToIdeas = overlay.querySelector('[data-roadmap-back]');
        const openFormButton = overlay.querySelector('[data-roadmap-open-form]');
        const openBugFormButtons = Array.from(overlay.querySelectorAll('[data-roadmap-open-bug-form]'));
        const cancelFormButton = overlay.querySelector('[data-roadmap-form-cancel]');
        const form = overlay.querySelector('[data-roadmap-form]');
        const formTitle = overlay.querySelector('[data-roadmap-form-title]');
        const formSummaryLabel = overlay.querySelector('[data-roadmap-form-summary-label]');
        const formDescriptionLabel = overlay.querySelector('[data-roadmap-form-description-label]');
        const formSummary = overlay.querySelector('[data-roadmap-form-summary]');
        const formDescription = overlay.querySelector('[data-roadmap-form-description]');
        const formBrowserWrap = overlay.querySelector('[data-roadmap-form-browser-wrap]');
        const formBrowserNotes = overlay.querySelector('[data-roadmap-form-browser-notes]');
        const formScreenshotWrap = overlay.querySelector('[data-roadmap-form-screenshot-wrap]');
        const formScreenshot = overlay.querySelector('[data-roadmap-form-screenshot]');
        const formSubmit = overlay.querySelector('[data-roadmap-form-submit]');
        const formError = overlay.querySelector('[data-roadmap-form-error]');
        const formSuccess = overlay.querySelector('[data-roadmap-form-success]');
        const formSuccessText = overlay.querySelector('[data-roadmap-form-success-text]');
        const topicChips = Array.from(overlay.querySelectorAll('[data-roadmap-topic]'));

        const signInModal = overlay.querySelector('[data-roadmap-signin-modal]');
        const signInLogin = overlay.querySelector('[data-roadmap-signin-login]');
        const signInRegister = overlay.querySelector('[data-roadmap-signin-register]');
        const signInCancel = overlay.querySelector('[data-roadmap-signin-cancel]');
        const signInTitle = overlay.querySelector('[data-roadmap-signin-title]');
        const signInCopy = overlay.querySelector('[data-roadmap-signin-copy]');

        const PENDING_VOTE_KEY = 'penny:roadmap:pending-vote';
        const POLL_INTERVAL_MS = 10000;

        const state = {
            open: false,
            loaded: false,
            loading: false,
            activeTab: 'ideas',
            activeView: 'ideas',
            items: [],
            roadmapItems: [],
            announcements: [],
            selectedDetail: null,
            detailLoading: false,
            selectedTopics: new Set(['planning']),
            formMode: 'idea',
            voting: new Set(),
            commentSubmitting: new Set(),
            commentDeleting: new Set(),
            signInPromptOpen: false,
            signInPromptMode: 'vote',
            pendingVoteIdeaId: null,
            commentErrors: {},
            syncTimer: null,
            errorTimer: null,
        };

        const escapeHtml = (value) =>
            String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

        const statusLabel = (status) => {
            if (status === 'in_progress') return 'In Progress';
            if (status === 'submitted' || status === 'reported') return 'Submitted';
            if (status === 'planned') return 'Planned';
            if (status === 'shipped') return 'Shipped';
            return 'Closed';
        };

        const statusClass = (status) => {
            if (status === 'in_progress') return 'in_progress';
            if (status === 'planned') return 'planned';
            if (status === 'shipped') return 'shipped';
            if (status === 'closed') return 'closed';
            return 'submitted';
        };

        const typeTag = (type) => {
            if (type === 'bug') return 'Bug';
            if (type === 'improvement') return 'Improvement';
            return 'Feature';
        };

        const formatDate = (value) => {
            if (!value) return 'Today';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return 'Today';
            return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
        };

        const excerpt = (value, max = 180) => {
            const text = String(value || '').trim();
            if (!text) return '';
            if (text.length <= max) return text;
            return `${text.slice(0, max - 1).trimEnd()}…`;
        };

        const getItemById = (id) => state.items.find((entry) => Number(entry.id) === Number(id));
        const getRoadmapById = (id) => state.roadmapItems.find((entry) => Number(entry.id) === Number(id));
        const getAnnouncementById = (id) => state.announcements.find((entry) => Number(entry.id) === Number(id));
        const commentScopeKey = (type, id) => `${type}:${Number(id || 0)}`;
        const getCommentError = (type, id) => state.commentErrors[commentScopeKey(type, id)] || '';
        const setCommentError = (type, id, message = '') => {
            state.commentErrors[commentScopeKey(type, id)] = String(message || '');
        };
        const isCommentSubmitting = (type, id) => state.commentSubmitting.has(commentScopeKey(type, id));
        const commentBasePath = (type, id) => {
            const entityId = Number(id || 0);
            if (!entityId) return '';
            if (type === 'roadmap') return `/api/updates/roadmap-items/${entityId}/comments`;
            if (type === 'announcement') return `/api/updates/announcements/${entityId}/comments`;
            return `/api/updates/items/${entityId}/comments`;
        };

        const mergeItem = (payloadItem) => {
            if (!payloadItem?.id) return;
            const index = state.items.findIndex((entry) => Number(entry.id) === Number(payloadItem.id));
            if (index === -1) {
                state.items.unshift(payloadItem);
            } else {
                state.items[index] = {
                    ...state.items[index],
                    ...payloadItem,
                };
            }
        };

        const mergeItemsFromSync = (incomingItems) => {
            const nextItems = Array.isArray(incomingItems) ? incomingItems : [];
            const existingMap = new Map(state.items.map((entry) => [Number(entry.id), entry]));

            return nextItems.map((entry) => {
                const existing = existingMap.get(Number(entry.id));
                if (!existing) return entry;

                const merged = {
                    ...existing,
                    ...entry,
                };

                if (Array.isArray(existing.comments)) {
                    merged.comments = existing.comments;
                }

                return merged;
            });
        };

        const setInlineError = (message = '') => {
            if (!inlineError) return;
            if (state.errorTimer) {
                window.clearTimeout(state.errorTimer);
                state.errorTimer = null;
            }

            const trimmed = String(message || '').trim();
            if (!trimmed) {
                inlineError.hidden = true;
                inlineError.textContent = '';
                return;
            }

            inlineError.hidden = false;
            inlineError.textContent = trimmed;
            state.errorTimer = window.setTimeout(() => {
                inlineError.hidden = true;
                inlineError.textContent = '';
            }, 4200);
        };

        const setTabSelection = (tabName) => {
            tabs.forEach((tab) => {
                const active = tab.dataset.roadmapTab === tabName;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        };

        const setView = (viewName) => {
            state.activeView = viewName;
            views.forEach((view) => {
                view.classList.toggle('is-active', view.dataset.roadmapView === viewName);
            });
        };

        const roadmapOrder = { in_progress: 0, planned: 1, shipped: 2 };
        const sortedRoadmapItems = () =>
            [...state.roadmapItems].sort((a, b) => {
                const statusDelta = (roadmapOrder[a.status] ?? 99) - (roadmapOrder[b.status] ?? 99);
                if (statusDelta !== 0) return statusDelta;
                const orderDelta = Number(a.sort_order || 0) - Number(b.sort_order || 0);
                if (orderDelta !== 0) return orderDelta;
                return Number(b.id || 0) - Number(a.id || 0);
            });

        const renderSkeleton = (container, count = 4) => {
            container.innerHTML = Array.from({ length: count })
                .map(
                    () => `
                        <article class="roadmap-card roadmap-card-skeleton" aria-hidden="true">
                            <div class="roadmap-skeleton-block vote"></div>
                            <div class="roadmap-skeleton-main">
                                <div class="roadmap-skeleton-line short"></div>
                                <div class="roadmap-skeleton-line"></div>
                                <div class="roadmap-skeleton-line tiny"></div>
                            </div>
                        </article>
                    `
                )
                .join('');
        };

        const setDetailBackLabel = (tabName) => {
            if (!backToIdeas) return;
            if (tabName === 'bugs') {
                backToIdeas.textContent = '← Back to Bugs';
                return;
            }
            if (tabName === 'roadmap') {
                backToIdeas.textContent = '← Back to Roadmap';
                return;
            }
            if (tabName === 'announcements') {
                backToIdeas.textContent = '← Back to Announcements';
                return;
            }
            backToIdeas.textContent = '← Back to Ideas';
        };

        const updateFormValidity = () => {
            if (!formSubmit) return;
            const valid =
                !!(formSummary?.value || '').trim()
                && !!(formDescription?.value || '').trim()
                && state.selectedTopics.size > 0
                && !state.loading;
            formSubmit.disabled = !valid;
        };

        const updateTopicChips = () => {
            topicChips.forEach((chip) => {
                const topic = chip.dataset.roadmapTopic || '';
                const active = state.selectedTopics.has(topic);
                chip.classList.toggle('is-active', active);
                chip.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            updateFormValidity();
        };

        const setFormError = (message = '', success = false) => {
            if (!formError) return;
            const trimmed = String(message || '').trim();
            if (!trimmed) {
                formError.hidden = true;
                formError.textContent = '';
                formError.classList.remove('is-success');
                return;
            }
            formError.hidden = false;
            formError.textContent = trimmed;
            formError.classList.toggle('is-success', !!success);
        };

        const setFormMode = (mode = 'idea') => {
            const normalizedMode = mode === 'bug' ? 'bug' : 'idea';
            state.formMode = normalizedMode;
            const isBugMode = normalizedMode === 'bug';

            if (formTitle) {
                formTitle.textContent = isBugMode ? 'Report a Bug' : 'Suggest a Feature';
            }
            if (formSummaryLabel) {
                formSummaryLabel.textContent = isBugMode ? 'Bug summary' : 'One-line summary';
            }
            if (formDescriptionLabel) {
                formDescriptionLabel.textContent = isBugMode ? 'What happened' : 'Description';
            }
            if (formSubmit) {
                formSubmit.textContent = isBugMode ? 'Submit bug report' : 'Submit';
            }
            if (formSuccessText) {
                formSuccessText.textContent = isBugMode
                    ? 'Your bug report has been submitted.'
                    : 'Your idea has been submitted.';
            }

            if (formBrowserWrap) {
                formBrowserWrap.hidden = !isBugMode;
            }
            if (formScreenshotWrap) {
                formScreenshotWrap.hidden = !isBugMode;
            }
        };

        const showSignInPrompt = (ideaId = null, mode = 'vote') => {
            if (!signInModal) return;
            state.signInPromptMode = mode === 'suggest' ? 'suggest' : 'vote';
            state.signInPromptOpen = true;
            state.pendingVoteIdeaId = state.signInPromptMode === 'vote' ? Number(ideaId) || null : null;

            if (signInTitle && signInCopy) {
                if (state.signInPromptMode === 'suggest') {
                    signInTitle.textContent = 'Sign in to suggest a feature';
                    signInCopy.textContent = 'Feature ideas are public and clearly attributed to the submitting user.';
                } else {
                    signInTitle.textContent = 'Sign in to vote';
                    signInCopy.textContent = 'Your vote will be saved right after you sign in.';
                }
            }

            signInModal.hidden = false;
            signInModal.classList.add('is-open');
        };

        const closeSignInPrompt = () => {
            if (!signInModal || !state.signInPromptOpen) return;
            state.signInPromptOpen = false;
            signInModal.classList.remove('is-open');
            window.setTimeout(() => {
                if (!state.signInPromptOpen) {
                    signInModal.hidden = true;
                }
            }, 220);
        };

        const writePendingVote = (ideaId) => {
            if (!ideaId) return;
            const payload = {
                idea_id: Number(ideaId),
                path: `${window.location.pathname}${window.location.search}`,
                at: Date.now(),
            };
            try {
                window.localStorage.setItem(PENDING_VOTE_KEY, JSON.stringify(payload));
            } catch (error) {
                // ignore storage failures
            }
        };

        const readPendingVote = () => {
            try {
                const raw = window.localStorage.getItem(PENDING_VOTE_KEY);
                if (!raw) return null;
                return JSON.parse(raw);
            } catch (error) {
                return null;
            }
        };

        const clearPendingVote = () => {
            try {
                window.localStorage.removeItem(PENDING_VOTE_KEY);
            } catch (error) {
                // ignore storage failures
            }
        };

        const openLoginFromPrompt = (mode) => {
            if (state.signInPromptMode === 'vote' && state.pendingVoteIdeaId) {
                writePendingVote(state.pendingVoteIdeaId);
            }
            const redirect = `${window.location.pathname}${window.location.search}`;
            const base = mode === 'register' ? '/register' : '/login';
            window.location.href = `${base}?redirect=${encodeURIComponent(redirect)}`;
        };

        const showTab = (tabName) => {
            state.activeTab = tabName;
            state.selectedDetail = null;
            setTabSelection(tabName);
            setView(tabName);
        };

        const renderIdeas = () => {
            if (state.loading && !state.loaded) {
                ideasEmpty.hidden = true;
                renderSkeleton(ideasList, 5);
                return;
            }

            const items = state.items.filter((item) => String(item.type || '').toLowerCase() !== 'bug');
            ideasEmpty.hidden = items.length > 0;
            ideasList.innerHTML = '';
            if (!items.length) return;

            ideasList.innerHTML = items
                .map((item) => `
                    <article class="roadmap-card roadmap-idea-card" data-roadmap-open-idea="${item.id}" tabindex="0" role="button" aria-label="Open ${escapeHtml(item.title)}">
                        <div class="roadmap-vote-box ${item.user_vote ? 'is-voted' : ''}">
                            <button
                                class="roadmap-vote-toggle ${item.user_vote ? 'is-voted' : ''}"
                                type="button"
                                data-roadmap-vote-toggle
                                data-roadmap-vote-id="${item.id}"
                                ${state.voting.has(item.id) ? 'disabled' : ''}
                                aria-label="${item.user_vote ? 'Remove vote' : 'Upvote'}"
                            >
                                <span class="roadmap-vote-up-arrow" aria-hidden="true"></span>
                            </button>
                            <span class="roadmap-vote-count">${Number(item.vote_count || 0)}</span>
                            <button
                                class="roadmap-vote-down ${item.user_vote ? 'is-ready' : ''}"
                                type="button"
                                data-roadmap-vote-down
                                data-roadmap-vote-id="${item.id}"
                                ${!item.user_vote || state.voting.has(item.id) ? 'disabled' : ''}
                                aria-label="Remove vote"
                            >
                                <span class="roadmap-vote-down-arrow" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="roadmap-card-main">
                            <div class="roadmap-card-top">
                                <h3 class="roadmap-card-title">${escapeHtml(item.title)}</h3>
                                <span class="roadmap-status ${statusClass(item.status)}">${statusLabel(item.status)}</span>
                            </div>
                            <p class="roadmap-card-desc">${escapeHtml(excerpt(item.description_preview || item.description, 190))}</p>
                            <div class="roadmap-meta">
                                <div class="roadmap-meta-left">
                                    <span>${escapeHtml(item.author_name || 'Penny Community')}</span>
                                    <span>•</span>
                                    <span>${formatDate(item.created_at)}</span>
                                    <span class="roadmap-tag">${typeTag(item.type)}</span>
                                </div>
                                <span class="roadmap-comments" aria-label="${Number(item.comment_count || 0)} comments">
                                    <span class="material-symbols-outlined" aria-hidden="true">chat_bubble_outline</span>
                                    ${Number(item.comment_count || 0)}
                                </span>
                            </div>
                        </div>
                    </article>
                `)
                .join('');

            ideasList.querySelectorAll('[data-roadmap-open-idea]').forEach((card) => {
                const itemId = Number(card.dataset.roadmapOpenIdea || 0);
                card.addEventListener('click', () => openIdeaDetail(itemId));
                card.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    openIdeaDetail(itemId);
                });
            });

            ideasList.querySelectorAll('[data-roadmap-vote-toggle]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    voteForIdea(Number(button.dataset.roadmapVoteId || 0), { direction: 'up' });
                });
            });

            ideasList.querySelectorAll('[data-roadmap-vote-down]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    voteForIdea(Number(button.dataset.roadmapVoteId || 0), { direction: 'down' });
                });
            });
        };

        const renderBugs = () => {
            if (state.loading && !state.loaded) {
                bugsEmpty.hidden = true;
                renderSkeleton(bugsList, 5);
                return;
            }

            const items = state.items.filter((item) => String(item.type || '').toLowerCase() === 'bug');
            bugsEmpty.hidden = items.length > 0;
            bugsList.innerHTML = '';
            if (!items.length) return;

            bugsList.innerHTML = items
                .map((item) => `
                    <article class="roadmap-card roadmap-idea-card" data-roadmap-open-idea="${item.id}" tabindex="0" role="button" aria-label="Open ${escapeHtml(item.title)}">
                        <div class="roadmap-vote-box ${item.user_vote ? 'is-voted' : ''}">
                            <button
                                class="roadmap-vote-toggle ${item.user_vote ? 'is-voted' : ''}"
                                type="button"
                                data-roadmap-vote-toggle
                                data-roadmap-vote-id="${item.id}"
                                ${state.voting.has(item.id) ? 'disabled' : ''}
                                aria-label="${item.user_vote ? 'Remove vote' : 'Upvote'}"
                            >
                                <span class="roadmap-vote-up-arrow" aria-hidden="true"></span>
                            </button>
                            <span class="roadmap-vote-count">${Number(item.vote_count || 0)}</span>
                            <button
                                class="roadmap-vote-down ${item.user_vote ? 'is-ready' : ''}"
                                type="button"
                                data-roadmap-vote-down
                                data-roadmap-vote-id="${item.id}"
                                ${!item.user_vote || state.voting.has(item.id) ? 'disabled' : ''}
                                aria-label="Remove vote"
                            >
                                <span class="roadmap-vote-down-arrow" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="roadmap-card-main">
                            <div class="roadmap-card-top">
                                <h3 class="roadmap-card-title">${escapeHtml(item.title)}</h3>
                                <span class="roadmap-status ${statusClass(item.status)}">${statusLabel(item.status)}</span>
                            </div>
                            <p class="roadmap-card-desc">${escapeHtml(excerpt(item.description_preview || item.description, 190))}</p>
                            <div class="roadmap-meta">
                                <div class="roadmap-meta-left">
                                    <span>${escapeHtml(item.author_name || 'Penny Community')}</span>
                                    <span>•</span>
                                    <span>${formatDate(item.created_at)}</span>
                                    <span class="roadmap-tag">${typeTag(item.type)}</span>
                                </div>
                                <span class="roadmap-comments" aria-label="${Number(item.comment_count || 0)} comments">
                                    <span class="material-symbols-outlined" aria-hidden="true">chat_bubble_outline</span>
                                    ${Number(item.comment_count || 0)}
                                </span>
                            </div>
                        </div>
                    </article>
                `)
                .join('');

            bugsList.querySelectorAll('[data-roadmap-open-idea]').forEach((card) => {
                const itemId = Number(card.dataset.roadmapOpenIdea || 0);
                card.addEventListener('click', () => openIdeaDetail(itemId));
                card.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    openIdeaDetail(itemId);
                });
            });

            bugsList.querySelectorAll('[data-roadmap-vote-toggle]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    voteForIdea(Number(button.dataset.roadmapVoteId || 0), { direction: 'up' });
                });
            });

            bugsList.querySelectorAll('[data-roadmap-vote-down]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    voteForIdea(Number(button.dataset.roadmapVoteId || 0), { direction: 'down' });
                });
            });
        };

        const renderRoadmap = () => {
            if (state.loading && !state.loaded) {
                roadmapEmpty.hidden = true;
                renderSkeleton(roadmapList, 4);
                return;
            }

            const items = sortedRoadmapItems();
            roadmapEmpty.hidden = items.length > 0;
            roadmapList.innerHTML = '';
            if (!items.length) return;

            roadmapList.innerHTML = items
                .map((roadmapItem) => `
                    <article class="roadmap-card roadmap-only-card" data-roadmap-open-roadmap="${roadmapItem.id}" tabindex="0" role="button" aria-label="Open ${escapeHtml(roadmapItem.title)}">
                        <div class="roadmap-card-top">
                            <h3 class="roadmap-card-title">${escapeHtml(roadmapItem.title)}</h3>
                            <span class="roadmap-status ${statusClass(roadmapItem.status)}">${statusLabel(roadmapItem.status)}</span>
                        </div>
                        <p class="roadmap-card-desc">${escapeHtml(excerpt(roadmapItem.description || '', 220))}</p>
                        <div class="roadmap-meta">
                            <div class="roadmap-meta-left">
                                <span>Penny Team</span>
                                <span>•</span>
                                <span>${formatDate(roadmapItem.updated_at || roadmapItem.created_at)}</span>
                            </div>
                            <span class="roadmap-comments" aria-label="${Number(roadmapItem.comment_count || 0)} comments">
                                <span class="material-symbols-outlined" aria-hidden="true">chat_bubble_outline</span>
                                ${Number(roadmapItem.comment_count || 0)}
                            </span>
                        </div>
                    </article>
                `)
                .join('');

            roadmapList.querySelectorAll('[data-roadmap-open-roadmap]').forEach((card) => {
                const roadmapId = Number(card.dataset.roadmapOpenRoadmap || 0);
                card.addEventListener('click', () => openRoadmapDetail(roadmapId));
                card.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    openRoadmapDetail(roadmapId);
                });
            });
        };

        const renderAnnouncements = () => {
            if (state.loading && !state.loaded) {
                announcementsEmpty.hidden = true;
                renderSkeleton(announcementsList, 3);
                return;
            }

            const entries = state.announcements;
            announcementsEmpty.hidden = entries.length > 0;
            announcementsList.innerHTML = '';
            if (!entries.length) return;

            announcementsList.innerHTML = entries
                .map((entry) => `
                    <article class="roadmap-card roadmap-only-card" data-roadmap-open-announcement="${entry.id}" tabindex="0" role="button" aria-label="Open ${escapeHtml(entry.title)}">
                        <div class="roadmap-card-top">
                            <h3 class="roadmap-card-title">${escapeHtml(entry.title)}</h3>
                            <span class="roadmap-status shipped">Update</span>
                        </div>
                        <p class="roadmap-card-desc">${escapeHtml(excerpt(entry.body, 210))}</p>
                        <div class="roadmap-meta">
                            <div class="roadmap-meta-left">
                                <span>Penny</span>
                                <span>•</span>
                                <span>${formatDate(entry.published_at || entry.created_at)}</span>
                            </div>
                            <span class="roadmap-comments" aria-label="${Number(entry.comment_count || 0)} comments">
                                <span class="material-symbols-outlined" aria-hidden="true">chat_bubble_outline</span>
                                ${Number(entry.comment_count || 0)}
                            </span>
                        </div>
                    </article>
                `)
                .join('');

            announcementsList.querySelectorAll('[data-roadmap-open-announcement]').forEach((card) => {
                const announcementId = Number(card.dataset.roadmapOpenAnnouncement || 0);
                card.addEventListener('click', () => openAnnouncementDetail(announcementId));
                card.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    openAnnouncementDetail(announcementId);
                });
            });
        };

        const renderIdeaDetail = (item) => {
            const comments = Array.isArray(item.comments) ? item.comments : [];
            const commentError = getCommentError('idea', item.id);
            detailContainer.innerHTML = `
                <div class="roadmap-detail-head">
                    <div class="roadmap-detail-vote ${item.user_vote ? 'is-voted' : ''}">
                        <button
                            class="roadmap-vote-toggle roadmap-vote-toggle-large ${item.user_vote ? 'is-voted' : ''}"
                            type="button"
                            data-roadmap-vote-toggle
                            data-roadmap-vote-id="${item.id}"
                            ${state.voting.has(item.id) ? 'disabled' : ''}
                            aria-label="${item.user_vote ? 'Remove vote' : 'Upvote'}"
                        >
                            <span class="roadmap-vote-up-arrow" aria-hidden="true"></span>
                        </button>
                        <span class="roadmap-vote-count roadmap-vote-count-large">${Number(item.vote_count || 0)}</span>
                        <button
                            class="roadmap-vote-down ${item.user_vote ? 'is-ready' : ''}"
                            type="button"
                            data-roadmap-vote-down
                            data-roadmap-vote-id="${item.id}"
                            ${!item.user_vote || state.voting.has(item.id) ? 'disabled' : ''}
                            aria-label="Remove vote"
                        >
                            <span class="roadmap-vote-down-arrow" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div>
                        <h3 class="roadmap-detail-title">${escapeHtml(item.title)}</h3>
                        <div class="roadmap-detail-tags">
                            <span class="roadmap-status ${statusClass(item.status)}">${statusLabel(item.status)}</span>
                            <span class="roadmap-tag">${typeTag(item.type)}</span>
                            <span class="roadmap-tag">${escapeHtml(item.author_name || 'Penny Community')}</span>
                            <span class="roadmap-tag">${formatDate(item.updated_at || item.created_at)}</span>
                        </div>
                    </div>
                </div>
                <p class="roadmap-detail-desc">${escapeHtml(item.description || '')}</p>
                <section class="roadmap-comments-block">
                    <h4 class="roadmap-comments-title">Comments (${comments.length})</h4>
                    ${
                        comments.length
                            ? comments
                                  .map(
                                      (comment) => `
                                          <article class="roadmap-comment ${comment.is_admin ? 'is-admin' : ''}">
                                              <div class="roadmap-comment-head">
                                                  <strong>${escapeHtml(comment.author)}</strong>
                                                  <span>${formatDate(comment.created_at)}</span>
                                              </div>
                                              <p>${escapeHtml(comment.body)}</p>
                                              ${
                                                  comment.can_delete
                                                      ? `<button class="roadmap-comment-delete" type="button" data-roadmap-delete-comment="${comment.id}" ${
                                                            state.commentDeleting.has(comment.id) ? 'disabled' : ''
                                                        }>Delete</button>`
                                                      : ''
                                              }
                                          </article>
                                      `
                                  )
                                  .join('')
                            : '<p class="roadmap-empty">No comments yet.</p>'
                    }
                    ${
                        item.comments_locked
                            ? '<p class="roadmap-empty">Comments are currently locked for this idea.</p>'
                            : `
                                <form class="roadmap-comment-form" data-roadmap-comment-form>
                                    <textarea maxlength="1000" placeholder="Add a comment…" required data-roadmap-comment-input></textarea>
                                    <button class="roadmap-primary" type="submit" data-roadmap-comment-submit disabled>Submit</button>
                                </form>
                            `
                    }
                    <p class="roadmap-comment-error" data-roadmap-comment-error ${commentError ? '' : 'hidden'}>${escapeHtml(commentError)}</p>
                </section>
            `;

            const voteToggle = detailContainer.querySelector('[data-roadmap-vote-toggle]');
            if (voteToggle) {
                voteToggle.addEventListener('click', () => voteForIdea(item.id, { direction: 'up' }));
            }

            const voteDown = detailContainer.querySelector('[data-roadmap-vote-down]');
            if (voteDown) {
                voteDown.addEventListener('click', () => voteForIdea(item.id, { direction: 'down' }));
            }

            detailContainer.querySelectorAll('[data-roadmap-delete-comment]').forEach((button) => {
                button.addEventListener('click', () => {
                    deleteComment('idea', item.id, Number(button.dataset.roadmapDeleteComment || 0));
                });
            });

            const commentForm = detailContainer.querySelector('[data-roadmap-comment-form]');
            const commentInput = detailContainer.querySelector('[data-roadmap-comment-input]');
            const commentSubmit = detailContainer.querySelector('[data-roadmap-comment-submit]');

            if (commentForm && commentInput && commentSubmit) {
                const syncSubmitState = () => {
                    const value = (commentInput.value || '').trim();
                    commentSubmit.disabled = !value || isCommentSubmitting('idea', item.id);
                };
                commentInput.addEventListener('input', syncSubmitState);
                syncSubmitState();
                commentForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    submitComment('idea', item.id, commentInput);
                });
            }
        };

        const renderRoadmapDetail = (roadmapItem) => {
            const comments = Array.isArray(roadmapItem.comments) ? roadmapItem.comments : [];
            const commentError = getCommentError('roadmap', roadmapItem.id);
            detailContainer.innerHTML = `
                <div class="roadmap-detail-head roadmap-detail-head-no-vote">
                    <div>
                        <h3 class="roadmap-detail-title">${escapeHtml(roadmapItem.title)}</h3>
                        <div class="roadmap-detail-tags">
                            <span class="roadmap-status ${statusClass(roadmapItem.status)}">${statusLabel(roadmapItem.status)}</span>
                            <span class="roadmap-tag">${formatDate(roadmapItem.updated_at || roadmapItem.created_at)}</span>
                            ${
                                roadmapItem.feedback_item_id
                                && roadmapItem.feedback_item_title
                                && !String(roadmapItem.feedback_item_title).startsWith('Roadmap:')
                                && !String(roadmapItem.feedback_item_title).startsWith('Announcement:')
                                    ? `<span class="roadmap-tag">Linked to idea #${Number(roadmapItem.feedback_item_id)}</span>`
                                    : ''
                            }
                        </div>
                    </div>
                </div>
                <p class="roadmap-detail-desc">${escapeHtml(roadmapItem.description || '')}</p>
                <section class="roadmap-comments-block">
                    <h4 class="roadmap-comments-title">Comments (${comments.length})</h4>
                    ${
                        comments.length
                            ? comments
                                  .map(
                                      (comment) => `
                                          <article class="roadmap-comment ${comment.is_admin ? 'is-admin' : ''}">
                                              <div class="roadmap-comment-head">
                                                  <strong>${escapeHtml(comment.author)}</strong>
                                                  <span>${formatDate(comment.created_at)}</span>
                                              </div>
                                              <p>${escapeHtml(comment.body)}</p>
                                              ${
                                                  comment.can_delete
                                                      ? `<button class="roadmap-comment-delete" type="button" data-roadmap-delete-comment="${comment.id}" ${
                                                            state.commentDeleting.has(comment.id) ? 'disabled' : ''
                                                        }>Delete</button>`
                                                      : ''
                                              }
                                          </article>
                                      `
                                  )
                                  .join('')
                            : '<p class="roadmap-empty">No comments yet.</p>'
                    }
                    ${
                        roadmapItem.comments_locked
                            ? '<p class="roadmap-empty">Comments are currently locked for this roadmap item.</p>'
                            : `
                                <form class="roadmap-comment-form" data-roadmap-comment-form>
                                    <textarea maxlength="1000" placeholder="Add a comment…" required data-roadmap-comment-input></textarea>
                                    <button class="roadmap-primary" type="submit" data-roadmap-comment-submit disabled>Submit</button>
                                </form>
                            `
                    }
                    <p class="roadmap-comment-error" data-roadmap-comment-error ${commentError ? '' : 'hidden'}>${escapeHtml(commentError)}</p>
                </section>
            `;

            detailContainer.querySelectorAll('[data-roadmap-delete-comment]').forEach((button) => {
                button.addEventListener('click', () => {
                    deleteComment('roadmap', roadmapItem.id, Number(button.dataset.roadmapDeleteComment || 0));
                });
            });

            const commentForm = detailContainer.querySelector('[data-roadmap-comment-form]');
            const commentInput = detailContainer.querySelector('[data-roadmap-comment-input]');
            const commentSubmit = detailContainer.querySelector('[data-roadmap-comment-submit]');

            if (commentForm && commentInput && commentSubmit) {
                const syncSubmitState = () => {
                    const value = (commentInput.value || '').trim();
                    commentSubmit.disabled = !value || isCommentSubmitting('roadmap', roadmapItem.id);
                };
                commentInput.addEventListener('input', syncSubmitState);
                syncSubmitState();
                commentForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    submitComment('roadmap', roadmapItem.id, commentInput);
                });
            }
        };

        const renderAnnouncementDetail = (announcement) => {
            const comments = Array.isArray(announcement.comments) ? announcement.comments : [];
            const commentError = getCommentError('announcement', announcement.id);
            detailContainer.innerHTML = `
                <div class="roadmap-detail-head roadmap-detail-head-no-vote">
                    <div>
                        <h3 class="roadmap-detail-title">${escapeHtml(announcement.title)}</h3>
                        <div class="roadmap-detail-tags">
                            <span class="roadmap-status shipped">Update</span>
                            <span class="roadmap-tag">${formatDate(announcement.published_at || announcement.created_at)}</span>
                            ${(Array.isArray(announcement.tags) ? announcement.tags : [])
                                .map((tag) => `<span class="roadmap-tag">${escapeHtml(tag)}</span>`)
                                .join('')}
                        </div>
                    </div>
                </div>
                <p class="roadmap-detail-desc">${escapeHtml(announcement.body || '')}</p>
                <section class="roadmap-comments-block">
                    <h4 class="roadmap-comments-title">Comments (${comments.length})</h4>
                    ${
                        comments.length
                            ? comments
                                  .map(
                                      (comment) => `
                                          <article class="roadmap-comment ${comment.is_admin ? 'is-admin' : ''}">
                                              <div class="roadmap-comment-head">
                                                  <strong>${escapeHtml(comment.author)}</strong>
                                                  <span>${formatDate(comment.created_at)}</span>
                                              </div>
                                              <p>${escapeHtml(comment.body)}</p>
                                              ${
                                                  comment.can_delete
                                                      ? `<button class="roadmap-comment-delete" type="button" data-roadmap-delete-comment="${comment.id}" ${
                                                            state.commentDeleting.has(comment.id) ? 'disabled' : ''
                                                        }>Delete</button>`
                                                      : ''
                                              }
                                          </article>
                                      `
                                  )
                                  .join('')
                            : '<p class="roadmap-empty">No comments yet.</p>'
                    }
                    ${
                        announcement.comments_locked
                            ? '<p class="roadmap-empty">Comments are currently locked for this announcement.</p>'
                            : `
                                <form class="roadmap-comment-form" data-roadmap-comment-form>
                                    <textarea maxlength="1000" placeholder="Add a comment…" required data-roadmap-comment-input></textarea>
                                    <button class="roadmap-primary" type="submit" data-roadmap-comment-submit disabled>Submit</button>
                                </form>
                            `
                    }
                    <p class="roadmap-comment-error" data-roadmap-comment-error ${commentError ? '' : 'hidden'}>${escapeHtml(commentError)}</p>
                </section>
            `;

            detailContainer.querySelectorAll('[data-roadmap-delete-comment]').forEach((button) => {
                button.addEventListener('click', () => {
                    deleteComment('announcement', announcement.id, Number(button.dataset.roadmapDeleteComment || 0));
                });
            });

            const commentForm = detailContainer.querySelector('[data-roadmap-comment-form]');
            const commentInput = detailContainer.querySelector('[data-roadmap-comment-input]');
            const commentSubmit = detailContainer.querySelector('[data-roadmap-comment-submit]');

            if (commentForm && commentInput && commentSubmit) {
                const syncSubmitState = () => {
                    const value = (commentInput.value || '').trim();
                    commentSubmit.disabled = !value || isCommentSubmitting('announcement', announcement.id);
                };
                commentInput.addEventListener('input', syncSubmitState);
                syncSubmitState();
                commentForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    submitComment('announcement', announcement.id, commentInput);
                });
            }
        };

        const renderDetail = () => {
            if (!state.selectedDetail) {
                setView(state.activeTab);
                return;
            }

            setDetailBackLabel(state.selectedDetail.fromTab || 'ideas');

            if (state.detailLoading) {
                detailContainer.innerHTML = `
                    <article class="roadmap-card roadmap-card-skeleton" aria-hidden="true">
                        <div class="roadmap-skeleton-main">
                            <div class="roadmap-skeleton-line short"></div>
                            <div class="roadmap-skeleton-line"></div>
                            <div class="roadmap-skeleton-line"></div>
                        </div>
                    </article>
                `;
                return;
            }

            if (state.selectedDetail.type === 'idea') {
                const item = getItemById(state.selectedDetail.id);
                if (!item) {
                    const fallbackTab = state.selectedDetail?.fromTab === 'bugs' ? 'bugs' : 'ideas';
                    showTab(fallbackTab);
                    return;
                }
                renderIdeaDetail(item);
                return;
            }

            if (state.selectedDetail.type === 'roadmap') {
                const roadmapItem = state.selectedDetail.payload || getRoadmapById(state.selectedDetail.id);
                if (!roadmapItem) {
                    showTab('roadmap');
                    return;
                }
                renderRoadmapDetail(roadmapItem);
                return;
            }

            if (state.selectedDetail.type === 'announcement') {
                const announcement = state.selectedDetail.payload || getAnnouncementById(state.selectedDetail.id);
                if (!announcement) {
                    showTab('announcements');
                    return;
                }
                renderAnnouncementDetail(announcement);
            }
        };

        const renderAll = () => {
            renderIdeas();
            renderBugs();
            renderRoadmap();
            renderAnnouncements();
            if (state.activeView === 'detail') {
                renderDetail();
            }
        };

        const openIdeaDetail = async (itemId) => {
            const id = Number(itemId || 0);
            if (!id) return;

            state.selectedDetail = {
                type: 'idea',
                id,
                fromTab: state.activeTab || 'ideas',
            };
            state.detailLoading = true;
            setView('detail');
            renderDetail();

            try {
                const response = await fetch(`/api/updates/items/${id}`, { credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.item) {
                    setInlineError(payload?.message || 'Unable to load this idea right now.');
                    return;
                }
                mergeItem(payload.item);
            } catch (error) {
                setInlineError('Unable to load this idea right now.');
            } finally {
                state.detailLoading = false;
                renderAll();
            }
        };

        const openRoadmapDetail = async (roadmapId) => {
            const id = Number(roadmapId || 0);
            if (!id) return;
            state.selectedDetail = {
                type: 'roadmap',
                id,
                fromTab: 'roadmap',
                payload: null,
            };
            state.detailLoading = true;
            setView('detail');
            renderDetail();

            try {
                const response = await fetch(`/api/updates/roadmap-items/${id}`, { credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.item) {
                    setInlineError(payload?.message || 'Unable to load this roadmap item right now.');
                    return;
                }

                const index = state.roadmapItems.findIndex((entry) => Number(entry.id) === id);
                if (index !== -1) {
                    state.roadmapItems[index] = {
                        ...state.roadmapItems[index],
                        ...payload.item,
                    };
                }

                if (state.selectedDetail?.type === 'roadmap' && Number(state.selectedDetail.id) === id) {
                    state.selectedDetail = {
                        ...state.selectedDetail,
                        payload: payload.item,
                    };
                }
            } catch (error) {
                setInlineError('Unable to load this roadmap item right now.');
            } finally {
                state.detailLoading = false;
                renderAll();
            }
        };

        const openAnnouncementDetail = async (announcementId) => {
            const id = Number(announcementId || 0);
            if (!id) return;
            state.selectedDetail = {
                type: 'announcement',
                id,
                fromTab: 'announcements',
                payload: null,
            };
            state.detailLoading = true;
            setView('detail');
            renderDetail();

            try {
                const response = await fetch(`/api/updates/announcements/${id}`, { credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.item) {
                    setInlineError(payload?.message || 'Unable to load this announcement right now.');
                    return;
                }

                const index = state.announcements.findIndex((entry) => Number(entry.id) === id);
                if (index !== -1) {
                    state.announcements[index] = {
                        ...state.announcements[index],
                        ...payload.item,
                    };
                }

                if (state.selectedDetail?.type === 'announcement' && Number(state.selectedDetail.id) === id) {
                    state.selectedDetail = {
                        ...state.selectedDetail,
                        payload: payload.item,
                    };
                }
            } catch (error) {
                setInlineError('Unable to load this announcement right now.');
            } finally {
                state.detailLoading = false;
                renderAll();
            }
        };

        const voteForIdea = async (ideaId, options = {}) => {
            const id = Number(ideaId || 0);
            const item = getItemById(id);
            if (!item || state.voting.has(id)) return;

            const requestedDirection = options.direction === 'down' ? 'down' : 'up';
            if (requestedDirection === 'down' && !item.user_vote) return;
            if (requestedDirection === 'up' && item.user_vote) return;

            const previous = {
                vote_count: Number(item.vote_count || 0),
                user_vote: item.user_vote || null,
                has_voted: !!item.user_vote,
            };

            const direction = requestedDirection;
            item.user_vote = direction === 'up' ? 'up' : null;
            item.has_voted = direction === 'up';
            item.vote_count = Math.max(0, Number(item.vote_count || 0) + (direction === 'up' ? 1 : -1));
            state.voting.add(id);
            renderAll();

            try {
                const token = await ensureCsrf();
                const response = await fetch(`/api/updates/items/${id}/vote`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token || '',
                    },
                    body: JSON.stringify({ direction }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    item.vote_count = previous.vote_count;
                    item.user_vote = previous.user_vote;
                    item.has_voted = previous.has_voted;
                    setInlineError(payload?.message || 'Unable to save your vote right now.');
                    return;
                }

                if (typeof payload.vote_count === 'number') {
                    item.vote_count = payload.vote_count;
                }
                item.user_vote = payload.user_vote || null;
                item.has_voted = !!item.user_vote;
                if (options.fromPending) {
                    clearPendingVote();
                }
            } catch (error) {
                item.vote_count = previous.vote_count;
                item.user_vote = previous.user_vote;
                item.has_voted = previous.has_voted;
                setInlineError('Unable to save your vote right now.');
            } finally {
                state.voting.delete(id);
                renderAll();
            }
        };

        const syncCommentCount = (type, entityId, count) => {
            const safeCount = Math.max(0, Number(count || 0));
            if (type === 'idea') {
                const item = getItemById(entityId);
                if (item) item.comment_count = safeCount;
                return;
            }
            if (type === 'roadmap') {
                const item = getRoadmapById(entityId);
                if (item) item.comment_count = safeCount;
                return;
            }
            if (type === 'announcement') {
                const item = getAnnouncementById(entityId);
                if (item) item.comment_count = safeCount;
            }
        };

        const getCommentTarget = (type, entityId) => {
            if (type === 'idea') {
                return getItemById(entityId);
            }
            if (type === 'roadmap') {
                if (state.selectedDetail && state.selectedDetail.type === type && Number(state.selectedDetail.id) === Number(entityId)) {
                    return state.selectedDetail.payload || getRoadmapById(entityId);
                }
                return getRoadmapById(entityId);
            }
            if (type === 'announcement') {
                if (state.selectedDetail && state.selectedDetail.type === type && Number(state.selectedDetail.id) === Number(entityId)) {
                    return state.selectedDetail.payload || getAnnouncementById(entityId);
                }
                return getAnnouncementById(entityId);
            }
            return null;
        };

        const submitComment = async (type, entityId, commentInput) => {
            const id = Number(entityId || 0);
            const scope = commentScopeKey(type, id);
            const target = getCommentTarget(type, id);
            if (!target || !commentInput || isCommentSubmitting(type, id)) return;

            const body = String(commentInput.value || '').trim();
            if (!body) return;

            setCommentError(type, id, '');
            state.commentSubmitting.add(scope);
            const previousComments = Array.isArray(target.comments) ? [...target.comments] : [];
            const previousCount = Number(target.comment_count || 0);

            const tempId = `temp-${Date.now()}`;
            const optimisticComment = {
                id: tempId,
                author: sessionUser?.name || 'You',
                body,
                is_admin: false,
                can_delete: false,
                created_at: new Date().toISOString(),
            };

            target.comments = [optimisticComment, ...previousComments];
            target.comment_count = previousCount + 1;
            syncCommentCount(type, id, target.comment_count);
            renderAll();

            try {
                const token = await ensureCsrf();
                const endpoint = commentBasePath(type, id);
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token || '',
                    },
                    body: JSON.stringify({ body }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.comment) {
                    target.comments = previousComments;
                    target.comment_count = previousCount;
                    syncCommentCount(type, id, previousCount);
                    setCommentError(type, id, payload?.message || 'Unable to submit your comment right now.');
                    renderAll();
                    return;
                }

                target.comments = (target.comments || []).map((entry) => (entry.id === tempId ? payload.comment : entry));
                if (typeof payload.comment_count === 'number') {
                    target.comment_count = payload.comment_count;
                    syncCommentCount(type, id, payload.comment_count);
                }
                setCommentError(type, id, '');
            } catch (error) {
                target.comments = previousComments;
                target.comment_count = previousCount;
                syncCommentCount(type, id, previousCount);
                setCommentError(type, id, 'Unable to submit your comment right now.');
            } finally {
                state.commentSubmitting.delete(scope);
                renderAll();
            }
        };

        const deleteComment = async (type, entityId, commentId) => {
            const id = Number(commentId || 0);
            const entryId = Number(entityId || 0);
            const target = getCommentTarget(type, entryId);
            if (!target || !id || state.commentDeleting.has(id)) return;

            const currentComments = Array.isArray(target.comments) ? target.comments : [];
            const previous = [...currentComments];
            const previousCount = Number(target.comment_count || 0);
            const next = previous.filter((entry) => Number(entry.id) !== id);
            if (next.length === previous.length) return;

            state.commentDeleting.add(id);
            target.comments = next;
            target.comment_count = Math.max(0, previousCount - 1);
            syncCommentCount(type, entryId, target.comment_count);
            setCommentError(type, entryId, '');
            renderAll();

            try {
                const token = await ensureCsrf();
                const endpoint = `${commentBasePath(type, entryId)}/${id}`;
                const response = await fetch(endpoint, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': token || '',
                    },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    target.comments = previous;
                    target.comment_count = previousCount;
                    syncCommentCount(type, entryId, previousCount);
                    setCommentError(type, entryId, payload?.message || 'Unable to delete comment right now.');
                } else if (typeof payload.comment_count === 'number') {
                    target.comment_count = payload.comment_count;
                    syncCommentCount(type, entryId, payload.comment_count);
                }
            } catch (error) {
                target.comments = previous;
                target.comment_count = previousCount;
                syncCommentCount(type, entryId, previousCount);
                setCommentError(type, entryId, 'Unable to delete comment right now.');
            } finally {
                state.commentDeleting.delete(id);
                renderAll();
            }
        };

        const loadRoadmapData = async (options = {}) => {
            if (state.loading) return;
            const silent = !!options.silent;
            state.loading = true;
            if (!silent) renderAll();

            try {
                const response = await fetch('/api/updates?sort=top&type=all', { credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload?.message || 'Unable to load roadmap right now.');
                }

                state.items = mergeItemsFromSync(payload.items);
                state.roadmapItems = Array.isArray(payload.roadmap_items) ? payload.roadmap_items : [];
                state.announcements = Array.isArray(payload.announcements) ? payload.announcements : [];
                state.loaded = true;

                if (state.selectedDetail?.type === 'idea' && !getItemById(state.selectedDetail.id)) {
                    const fallbackTab = state.selectedDetail?.fromTab === 'bugs' ? 'bugs' : 'ideas';
                    showTab(fallbackTab);
                    setInlineError('That idea is no longer available.');
                }
                if (state.selectedDetail?.type === 'roadmap' && !getRoadmapById(state.selectedDetail.id)) {
                    showTab('roadmap');
                }
                if (state.selectedDetail?.type === 'announcement' && !getAnnouncementById(state.selectedDetail.id)) {
                    showTab('announcements');
                }
            } catch (error) {
                setInlineError(error?.message || 'Unable to load roadmap right now.');
            } finally {
                state.loading = false;
                renderAll();
            }
        };

        const resetFormState = (mode = 'idea') => {
            setFormMode(mode);
            if (formSummary) formSummary.value = '';
            if (formDescription) formDescription.value = '';
            if (formBrowserNotes) formBrowserNotes.value = '';
            if (formScreenshot) formScreenshot.value = '';
            state.selectedTopics = new Set([mode === 'bug' ? 'bug' : 'planning']);
            updateTopicChips();
            setFormError('');
            if (formSuccess) formSuccess.hidden = true;
            if (form) form.hidden = false;
            updateFormValidity();
        };

        const openForm = async (options = {}) => {
            const mode = options.mode === 'bug' ? 'bug' : 'idea';
            const requiresAuth = mode === 'idea' && !options.skipAuth;

            if (requiresAuth) {
                const user = sessionUser || (await ensureSession());
                if (!user) {
                    showSignInPrompt(null, 'suggest');
                    return;
                }
            }
            state.selectedDetail = null;
            setTabSelection('ideas');
            setView('form');
            resetFormState(mode);
            if (formSummary) formSummary.focus();
        };

        const closeForm = () => {
            showTab('ideas');
            resetFormState('idea');
        };

        const submitIdeaForm = async (event) => {
            event.preventDefault();
            if (!formSummary || !formDescription) return;

            const isBugMode = state.formMode === 'bug';
            let user = sessionUser;
            if (!isBugMode) {
                user = sessionUser || (await ensureSession());
                if (!user) {
                    setFormError('Please sign in to suggest a feature.');
                    showSignInPrompt(null, 'suggest');
                    updateFormValidity();
                    return;
                }
            }

            const title = formSummary.value.trim();
            const description = formDescription.value.trim();
            const topics = Array.from(state.selectedTopics);
            if (!title || !description || !topics.length) {
                updateFormValidity();
                return;
            }

            setFormError('');
            if (formSubmit) formSubmit.disabled = true;

            try {
                const token = await ensureCsrf();
                let response;
                if (isBugMode) {
                    const formData = new FormData();
                    formData.append('title', title);
                    formData.append('description', description);
                    if (formBrowserNotes?.value?.trim()) {
                        formData.append('browser_notes', formBrowserNotes.value.trim());
                    }
                    const screenshotFile = formScreenshot?.files?.[0] || null;
                    if (screenshotFile) {
                        formData.append('screenshot', screenshotFile);
                    }

                    response = await fetch('/api/updates/bugs', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-TOKEN': token || '',
                        },
                        body: formData,
                    });
                } else {
                    response = await fetch('/api/updates/ideas', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token || '',
                        },
                        body: JSON.stringify({
                            title,
                            description,
                        }),
                    });
                }
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.item) {
                    if (response.status === 401 && !isBugMode) {
                        showSignInPrompt(null, 'suggest');
                    }
                    setFormError(payload?.message || `Unable to submit your ${isBugMode ? 'bug report' : 'idea'} right now.`);
                    return;
                }

                mergeItem(payload.item);
                renderAll();

                if (form) form.hidden = true;
                if (formSuccess) formSuccess.hidden = false;
                window.setTimeout(() => {
                    showTab(isBugMode ? 'bugs' : 'ideas');
                    resetFormState('idea');
                    renderAll();
                }, 850);
            } catch (error) {
                setFormError(`Unable to submit your ${isBugMode ? 'bug report' : 'idea'} right now.`);
            } finally {
                updateFormValidity();
            }
        };

        const startSync = () => {
            if (state.syncTimer) return;
            state.syncTimer = window.setInterval(() => {
                if (!state.open) return;
                loadRoadmapData({ silent: true });
            }, POLL_INTERVAL_MS);
        };

        const stopSync = () => {
            if (!state.syncTimer) return;
            window.clearInterval(state.syncTimer);
            state.syncTimer = null;
        };

        const openPanel = async (options = {}) => {
            if (state.open) return;
            state.open = true;
            overlay.hidden = false;
            requestAnimationFrame(() => overlay.classList.add('is-open'));
            document.body.classList.add('roadmap-open');
            overlay.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            if (options.focusPanel !== false) {
                panel.focus();
            }
            startSync();
            await loadRoadmapData();
        };

        const closePanel = () => {
            if (!state.open) return;
            state.open = false;
            closeSignInPrompt();
            overlay.classList.remove('is-open');
            document.body.classList.remove('roadmap-open');
            overlay.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            stopSync();
            window.setTimeout(() => {
                if (!state.open) {
                    overlay.hidden = true;
                }
            }, 280);
            trigger.focus();
        };

        const applyPendingVote = async () => {
            const pending = readPendingVote();
            if (!pending || !pending.idea_id) return;

            const currentPath = `${window.location.pathname}${window.location.search}`;
            if (pending.path && pending.path !== currentPath) return;

            const user = sessionUser || (await ensureSession());
            if (!user) return;

            clearPendingVote();
            await openPanel({ focusPanel: false });
            await voteForIdea(Number(pending.idea_id), { fromPending: true });
        };

        trigger.addEventListener('click', () => {
            openPanel();
        });

        if (closeButton) {
            closeButton.addEventListener('click', closePanel);
        }

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                closePanel();
                return;
            }
            if (state.signInPromptOpen && signInModal && event.target === signInModal) {
                closeSignInPrompt();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (state.signInPromptOpen) {
                closeSignInPrompt();
                return;
            }
            if (state.open) {
                closePanel();
            }
        });

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => showTab(tab.dataset.roadmapTab || 'ideas'));
        });

        if (backToIdeas) {
            backToIdeas.addEventListener('click', () => {
                const fallbackTab = state.selectedDetail?.fromTab || 'ideas';
                showTab(fallbackTab);
            });
        }

        if (openFormButton) {
            openFormButton.addEventListener('click', () => openForm({ mode: 'idea' }));
        }

        openBugFormButtons.forEach((button) => {
            button.addEventListener('click', () => openForm({ mode: 'bug', skipAuth: true }));
        });

        if (cancelFormButton) {
            cancelFormButton.addEventListener('click', closeForm);
        }

        topicChips.forEach((chip) => {
            chip.addEventListener('click', () => {
                const topic = chip.dataset.roadmapTopic || '';
                if (!topic) return;
                if (state.selectedTopics.has(topic)) {
                    state.selectedTopics.delete(topic);
                } else {
                    state.selectedTopics.add(topic);
                }
                updateTopicChips();
            });
        });

        if (formSummary) {
            formSummary.addEventListener('input', updateFormValidity);
        }

        if (formDescription) {
            formDescription.addEventListener('input', updateFormValidity);
        }

        if (form) {
            form.addEventListener('submit', submitIdeaForm);
        }

        if (signInCancel) {
            signInCancel.addEventListener('click', closeSignInPrompt);
        }
        if (signInLogin) {
            signInLogin.addEventListener('click', () => openLoginFromPrompt('login'));
        }
        if (signInRegister) {
            signInRegister.addEventListener('click', () => openLoginFromPrompt('register'));
        }

        resetFormState('idea');
        applyPendingVote();
    })();

    document.querySelectorAll('[data-audio-player]').forEach((player) => {
        const audio = player.querySelector('[data-audio]');
        const playButton = player.querySelector('[data-audio-play]');
        if (!audio || !playButton) return;

        playButton.addEventListener('click', async () => {
            if (audio.paused) {
                try {
                    await audio.play();
                    playButton.classList.add('is-playing');
                    playButton.setAttribute('aria-pressed', 'true');
                } catch (error) {
                    // ignore
                }
            } else {
                audio.pause();
                playButton.classList.remove('is-playing');
                playButton.setAttribute('aria-pressed', 'false');
            }
        });

        audio.addEventListener('ended', () => {
            playButton.classList.remove('is-playing');
            playButton.setAttribute('aria-pressed', 'false');
        });
    });
</script>
