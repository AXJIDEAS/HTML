(() => {
    const statusUrl = 'auth-status.php';
    const loginForm = document.querySelector('#login-form');
    const registerForm = document.querySelector('#register-form');
    const notice = document.querySelector('#form-notice');
    const tabs = [...document.querySelectorAll('.mode-tab')];
    const panels = [...document.querySelectorAll('.form-panel')];
    const modeSwitch = document.querySelector('#mode-switch');
    const setupNote = document.querySelector('#setup-note');
    const registerPrompt = document.querySelector('#register-prompt');
    const loginButtonLabel = document.querySelector('#login-button-label');
    let csrfToken = '';
    let setupRequired = false;

    function showNotice(message, kind) {
        notice.textContent = message;
        notice.dataset.kind = kind;
        notice.hidden = false;
    }

    function clearNotice() {
        notice.textContent = '';
        notice.hidden = true;
        delete notice.dataset.kind;
    }

    function setMode(mode) {
        const selectedTab = mode === 'register' ? 'register-tab' : 'login-tab';
        tabs.forEach((tab) => {
            const isSelected = tab.id === selectedTab;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-selected', String(isSelected));
        });
        panels.forEach((panel) => {
            const isSelected = panel.id === `${mode}-panel`;
            panel.hidden = !isSelected;
            panel.classList.toggle('is-active', isSelected);
        });
        clearNotice();
    }

    async function readResponse(response) {
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.success === false) {
            throw new Error(payload?.message || 'No se pudo completar la solicitud. Inténtalo de nuevo.');
        }
        return payload;
    }

    async function submitForm(form, endpoint, button, successCallback) {
        clearNotice();
        if (!form.reportValidity()) return;

        const submitButton = form.querySelector('button[type="submit"]');
        const originalLabel = submitButton.querySelector('span').textContent;
        submitButton.disabled = true;
        submitButton.querySelector('span').textContent = 'Procesando…';

        try {
            const data = new FormData(form);
            data.set('csrf_token', csrfToken);
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: data,
                credentials: 'same-origin',
            });
            const payload = await readResponse(response);
            successCallback(payload);
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            submitButton.disabled = false;
            submitButton.querySelector('span').textContent = originalLabel;
        }
    }

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        if (!setupRequired) setMode(tab.id === 'register-tab' ? 'register' : 'login');
    }));

    document.querySelector('#open-register').addEventListener('click', () => setMode('register'));

    loginForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(loginForm, 'login.php', null, (payload) => {
            window.location.assign(payload.redirect);
        });
    });

    registerForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(registerForm, 'register.php', null, (payload) => {
            registerForm.reset();
            setMode('login');
            showNotice(payload.message, 'success');
        });
    });

    fetch(statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(readResponse)
        .then((payload) => {
            csrfToken = payload.csrf_token;
            setupRequired = payload.setup_required;
            if (payload.redirect) {
                window.location.replace(payload.redirect);
                return;
            }
            if (setupRequired) {
                document.querySelector('#access-eyebrow').textContent = 'Configuración inicial';
                document.querySelector('#access-title').textContent = 'Crear administrador';
                document.querySelector('#access-intro').textContent = 'Configura la cuenta que administrará el equipo Ombara.';
                document.querySelector('#setup-name-field').hidden = false;
                document.querySelector('#setup-token-field').hidden = false;
                document.querySelector('#setup-name').required = true;
                document.querySelector('#setup-token').required = true;
                document.querySelector('#login-password').minLength = 12;
                loginButtonLabel.textContent = 'Crear cuenta administradora';
                modeSwitch.hidden = true;
                setupNote.hidden = false;
                registerForm.parentElement.hidden = true;
            } else {
                registerPrompt.hidden = false;
            }
        })
        .catch((error) => {
            document.querySelector('#register-tab').disabled = true;
            setMode('login');
            showNotice(error.message, 'error');
        });
})();
