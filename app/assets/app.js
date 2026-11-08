/**
 * Bootstrap frontend - no dependencies, no inline code (CSP friendly).
 */
(() => {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const $ = (selector, root = document) => root.querySelector(selector);

    async function request(action, options = {}) {
        const method = options.method || 'GET';
        const headers = { Accept: 'application/json' };
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrf;
        }

        const response = await fetch('?a=' + encodeURIComponent(action), {
            method,
            headers,
            credentials: 'same-origin',
            body: method === 'POST' ? JSON.stringify(options.body || {}) : undefined,
            cache: 'no-store',
        });

        let data = {};
        try {
            data = await response.json();
        } catch (error) {
            data = { ok: false, error: { message: 'The server returned an unreadable response.' } };
        }

        if (!response.ok || data.ok === false) {
            const err = new Error((data.error && data.error.message) || 'Request failed.');
            err.status = response.status;
            err.code = data.error && data.error.code;
            err.retryAfter = data.error && data.error.retry_after;
            throw err;
        }

        return data;
    }

    /* ---------------------------------------------------------------- login */

    const loginForm = $('#login-form');
    if (loginForm) {
        const submit = $('#login-submit');
        const errorBox = $('#login-error');

        const showError = (message) => {
            errorBox.textContent = message;
            errorBox.hidden = false;
        };

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            errorBox.hidden = true;

            const username = loginForm.username.value.trim();
            const password = loginForm.password.value;

            if (!username || !password) {
                showError('Enter your username and password.');
                return;
            }

            submit.disabled = true;
            submit.classList.add('is-loading');

            try {
                await request('login', { method: 'POST', body: { username, password } });
                window.location.reload();
            } catch (error) {
                let message = error.message || 'Sign in failed.';
                if (error.retryAfter) {
                    message += ' Try again in ' + error.retryAfter + ' seconds.';
                }
                showError(message);
                submit.disabled = false;
                submit.classList.remove('is-loading');
                loginForm.password.select();
            }
        });
    }

    /* ------------------------------------------------------------ dashboard */

    const dashboard = $('.dashboard');
    if (dashboard) {
        const list = $('#repo-list');
        const filter = $('#repo-filter');
        const refresh = $('#refresh-repos');
        const warning = $('#deploy-warning');
        const source = $('#repo-source');
        const logout = $('#logout');

        let repos = [];
        let deployInfo = null;

        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));

        const when = (iso) => {
            if (!iso) return '';
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) return '';
            return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        };

        function render() {
            const query = (filter.value || '').trim().toLowerCase();
            const visible = repos.filter((repo) => !query
                || repo.name.toLowerCase().includes(query)
                || (repo.description || '').toLowerCase().includes(query));

            if (visible.length === 0) {
                list.innerHTML = '<div class="empty">' + (repos.length === 0
                    ? 'No projects were returned. Check the BootStrapServer GitHub token / owner settings.'
                    : 'No projects match that filter.') + '</div>';
                return;
            }

            list.innerHTML = visible.map((repo) => {
                const badges = [
                    repo.private ? '<span class="badge badge-private">private</span>' : '',
                    repo.fork ? '<span class="badge badge-fork">fork</span>' : '',
                    repo.archived ? '<span class="badge badge-archived">archived</span>' : '',
                    repo.language ? '<span class="badge">' + escapeHtml(repo.language) + '</span>' : '',
                ].join('');

                return '<article class="repo">'
                    + '<div class="repo-head"><span class="repo-name">' + escapeHtml(repo.name) + '</span></div>'
                    + '<p class="repo-desc">' + escapeHtml(repo.description || 'No description.') + '</p>'
                    + '<div class="repo-meta">' + badges
                    + (repo.pushed_at ? '<span class="badge">' + escapeHtml(when(repo.pushed_at)) + '</span>' : '')
                    + '</div>'
                    + '<button type="button" class="btn btn-ghost btn-small" data-repo="'
                    + escapeHtml(repo.name) + '">Deploy project</button>'
                    + '</article>';
            }).join('');
        }

        async function loadRepos(force) {
            list.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
            source.textContent = '';

            try {
                const data = await request('repos' + (force ? '&refresh=1' : ''));
                repos = data.repos || [];
                deployInfo = data.deploy || null;

                const warnings = (deployInfo && deployInfo.warnings) || [];
                if (warnings.length > 0) {
                    warning.textContent = warnings.join(' ');
                    warning.hidden = false;
                } else {
                    warning.hidden = true;
                }

                if (data.message) {
                    source.textContent = data.message;
                } else {
                    source.textContent = repos.length + ' project(s) from ' + (data.owner || 'GitHub') + '.';
                }

                render();
            } catch (error) {
                if (error.status === 401) {
                    window.location.reload();
                    return;
                }
                list.innerHTML = '<div class="empty">' + escapeHtml(error.message || 'Unable to load projects.') + '</div>';
            }
        }

        filter.addEventListener('input', render);
        refresh.addEventListener('click', () => loadRepos(true));

        list.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-repo]');
            if (!button) return;
            await deploy(button.getAttribute('data-repo'), button);
        });

        logout.addEventListener('click', async () => {
            try {
                await request('logout', { method: 'POST' });
            } catch (error) {
                /* ignore */
            }
            window.location.reload();
        });

        /* ------------------------------------------------------------- deploy */

        const overlay = $('#deploy-overlay');
        const overlayRepo = $('#deploy-repo');
        const progress = $('#deploy-progress');
        const successOverlay = $('#success-overlay');

        let progressTimer = null;

        function startProgress() {
            const steps = Array.from(progress.querySelectorAll('li'));
            let index = 0;
            const tick = () => {
                steps.forEach((step, i) => {
                    step.classList.toggle('done', i < index);
                    step.classList.toggle('active', i === index);
                });
            };
            tick();
            progressTimer = window.setInterval(() => {
                if (index < steps.length - 1) {
                    index += 1;
                    tick();
                }
            }, 2600);
        }

        function stopProgress() {
            if (progressTimer) {
                window.clearInterval(progressTimer);
                progressTimer = null;
            }
            progress.querySelectorAll('li').forEach((step) => {
                step.classList.remove('active');
                step.classList.add('done');
            });
        }

        async function deploy(repo, button) {
            let force = false;
            const unknown = (deployInfo && deployInfo.unknown) || [];

            if (unknown.length > 0) {
                force = window.confirm(
                    'The web root also contains files the installer did not create:\n\n'
                    + unknown.join('\n')
                    + '\n\nThey will be DELETED. Continue?'
                );
                if (!force) return;
            }

            if (!window.confirm('Deploy "' + repo + '" and replace this installer?\n\nThe bootstrap files will be removed.')) {
                return;
            }

            button.disabled = true;
            overlayRepo.textContent = repo;
            overlay.hidden = false;
            startProgress();

            try {
                const data = await request('deploy', { method: 'POST', body: { repo, force } });
                stopProgress();
                window.setTimeout(() => {
                    overlay.hidden = true;
                    $('#success-repo').textContent = data.repo;
                    successOverlay.hidden = false;
                }, 600);
            } catch (error) {
                stopProgress();
                overlay.hidden = true;
                button.disabled = false;

                if (error.status === 401) {
                    window.location.reload();
                    return;
                }
                window.alert('Deployment failed:\n\n' + error.message);
            }
        }

        loadRepos(false);
    }
})();
