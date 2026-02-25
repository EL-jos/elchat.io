/*
(function () {
    const SCRIPT_TAG = document.currentScript;
    if (!SCRIPT_TAG) {
        console.error('[ELChat] Impossible de détecter la balise script');
        return;
    }

    const SITE_ID = SCRIPT_TAG.getAttribute('data-site-id');
    if (!SITE_ID) {
        console.error('[ELChat] site_id manquant');
        return;
    }

    const API_URL = `http://127.0.0.1:8000/api/v1/site/${SITE_ID}/widget/config`;
    const IFRAME_URL = 'http://localhost:4201/elchat/widget?site_id=' + encodeURIComponent(SITE_ID);

    /!* =========================
       CONFIG PAR DÉFAUT (SAFE)
    ========================= *!/
    const DEFAULT_CONFIG = {
        button: {
            html: '<img src="http://localhost:4201/assets/svg/logo_white.svg" style="user-select: none; pointer-events: none" width="70" alt="Chat" />',
            background: '#ed6f01',
            bottom: '20px',
            right: '20px'
        },
        auto_open_delay: 10 // secondes
    };

    let config = DEFAULT_CONFIG;
    let btn = null;
    let iframe = null;
    let autoOpenTimer = null;
    let isOpened = false;

    /!* =========================
       1️⃣ Charger la config depuis backend
    ========================= *!/
    fetch(API_URL)
        .then(res => res.ok ? res.json() : null)
        .then(data => {
            if (data) {
                config = {
                    ...DEFAULT_CONFIG,
                    ...data,
                    button: {
                        ...DEFAULT_CONFIG.button,
                        ...(data.button || {})
                    }
                };
            }

            createButton();
            setupAutoOpen();
        })
        .catch(() => {
            console.warn('[ELChat] Config non trouvée → fallback par défaut');
            createButton();
            setupAutoOpen();
        });

    /!* =========================
       2️⃣ Créer le bouton flottant
    ========================= *!/
    function createButton() {
        if (btn) return;

        btn = document.createElement('button');
        btn.id = 'elchat-btn';
        btn.innerHTML = config.button.html || DEFAULT_CONFIG.button.html;

        Object.assign(btn.style, {
            position: 'fixed',
            bottom: config.button.bottom,
            right: config.button.right,
            zIndex: 9999,
            //padding: '12px 16px',
            width: '70px',
            height: '70px',
            borderRadius: '50%',
            overflow: 'hidden',
            background: config.button.background,
            border: 'none',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '6px',
            boxShadow: '0 4px 6px rgba(0,0,0,0.2)'
        });

        btn.addEventListener('click', openIframe);
        document.body.appendChild(btn);
    }

    /!* =========================
       3️⃣ Auto-open configurable
    ========================= *!/
    function setupAutoOpen() {
        const delay = Number(config.auto_open_delay) || 10;

        if (delay <= 0) return;

        autoOpenTimer = setTimeout(() => {
            if (!isOpened) openIframe();
        }, delay * 1000);
    }

    /!* =========================
       4️⃣ Ouvrir iframe
    ========================= *!/
    function openIframe() {
        if (isOpened) return;
        isOpened = true;

        if (autoOpenTimer) {
            clearTimeout(autoOpenTimer);
            autoOpenTimer = null;
        }

        // supprimer le bouton flottant
        if (btn) {
            btn.remove();
            btn = null;
        }

        // créer iframe
        iframe = document.createElement('iframe');
        iframe.id = 'elchat-iframe';
        iframe.src = IFRAME_URL;
        iframe.onload = () => {
            iframe.contentWindow.postMessage({
                source: 'elchat',
                type: 'SET_SITE_ID',
                siteId: SITE_ID
            }, '*');
        };


        Object.assign(iframe.style, {
            position: 'fixed',
            bottom: '20px',
            right: '20px',
            width: '340px',
            height: '540px',
            border: 'none',
            borderRadius: '12px',
            boxShadow: '0 6px 20px rgba(0,0,0,.3)',
            zIndex: 9999,
            overflow: 'hidden',
            background: '#fff'
        });

        document.body.appendChild(iframe);
    }

    /!* =========================
       5️⃣ postMessage iframe ↔ parent
    ========================= *!/
    window.addEventListener('message', (event) => {
        if (!event.data || event.data.source !== 'elchat') return;

        switch (event.data.type) {
            case 'CLOSE_WIDGET':
                closeIframe();
                break;
        }
    });

    /!* =========================
       6️⃣ Fermer iframe
    ========================= *!/
    function closeIframe() {
        if (iframe) {
            iframe.remove();
            iframe = null;
        }

        isOpened = false;
        createButton();
        setupAutoOpen();
    }
})();
*/

(function () {
    const SCRIPT_TAG = document.currentScript;
    if (!SCRIPT_TAG) {
        console.error('[ELChat] Impossible de détecter la balise script');
        return;
    }

    const SITE_ID = SCRIPT_TAG.getAttribute('data-site-id');
    if (!SITE_ID) {
        console.error('[ELChat] site_id manquant');
        return;
    }

    const API_URL = `https://elchat.promogifts.ma/api/v1/site/${SITE_ID}/widget/config`; // ton endpoint réel
    const IFRAME_URL = 'https://elchat-widget.promogifts.ma/elchat/widget?site_id=' + encodeURIComponent(SITE_ID);
    let userClosed = false; // au top du script

    /* =========================
       CONFIG PAR DÉFAUT (SAFE)
    ========================= */
    const DEFAULT_CONFIG = {
        button: {
            text: '💬 Chat',
            background: '#ff9100',
            color: '#fff',
            position: 'bottom-right',
            offsetX: '1rem',
            offsetY: '1rem',
            html: '<img src="https://elchat-widget.promogifts.ma/assets/svg/logo_white.svg" style="user-select: none; pointer-events: none" width="70" alt="Chat" />',
        },
        auto_open_delay: 5 // pas d'auto-open par défaut
    };

    let config = DEFAULT_CONFIG;
    let btn = null;
    let iframe = null;
    let autoOpenTimer = null;
    let isOpened = false;

    /* =========================
       1️⃣ Charger la config depuis backend
    ========================= */
    fetch(API_URL)
        .then(res => res.ok ? res.json() : null)
        .then(data => {
            if (data && data.success && data.config && data.config.button) {
                const b = data.config.button;
                config.button = {
                    text: b.text || DEFAULT_CONFIG.button.text,
                    background: b.background || DEFAULT_CONFIG.button.background,
                    color: b.color || DEFAULT_CONFIG.button.color,
                    position: b.position || DEFAULT_CONFIG.button.position,
                    offsetX: b.offsetX || DEFAULT_CONFIG.button.offsetX,
                    offsetY: b.offsetY || DEFAULT_CONFIG.button.offsetY
                };
            }
            createButton();
            setupAutoOpen();
        })
        .catch(() => {
            console.warn('[ELChat] Config non trouvée → fallback par défaut');
            createButton();
            setupAutoOpen();
        });

    /* =========================
       2️⃣ Créer le bouton flottant
    ========================= */
    function createButton() {
        if (btn) return;

        btn = document.createElement('button');
        btn.id = 'elchat-btn';
        //btn.innerText = config.button.text;
        btn.innerHTML = config.button.html;
        btn.innerHTML = '<img src="https://elchat-widget.promogifts.ma/assets/svg/logo_white.svg" style="user-select: none; pointer-events: none" width="70" alt="Chat" />';
        btn.setAttribute('aria-label', config.button.text);

        Object.assign(btn.style, {
            position: 'fixed',
            zIndex: 9999,
            width: '60px',
            height: '60px',
            borderRadius: '50%',
            background: config.button.background,
            color: config.button.color,
            border: 'none',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: '0 4px 6px rgba(0,0,0,0.2)'
        });

        // position
        const pos = (config.button.position || 'bottom-right').toLowerCase();
        if (pos.includes('bottom')) btn.style.bottom = config.button.offsetY || '1rem';
        if (pos.includes('top')) btn.style.top = config.button.offsetY || '1rem';
        if (pos.includes('right')) btn.style.right = config.button.offsetX || '1rem';
        if (pos.includes('left')) btn.style.left = config.button.offsetX || '1rem';

        btn.addEventListener('click', openIframe);
        document.body.appendChild(btn);
    }

    /* =========================
       3️⃣ Auto-open configurable
    ========================= */
    function setupAutoOpen() {
        if (userClosed) return; // ✅ ignore auto-open si l'utilisateur a fermé
        const delay = Number(config.auto_open_delay) || 0;
        if (delay <= 0) return;

        autoOpenTimer = setTimeout(() => {
            if (!isOpened) openIframe();
        }, delay * 1000);
    }

    /* =========================
       4️⃣ Ouvrir iframe
    ========================= */
    function openIframe() {
        if (isOpened) return;
        isOpened = true;

        if (autoOpenTimer) {
            clearTimeout(autoOpenTimer);
            autoOpenTimer = null;
        }

        if (btn) {
            btn.remove();
            btn = null;
        }

        iframe = document.createElement('iframe');
        iframe.id = 'elchat-iframe';
        iframe.src = IFRAME_URL;
        iframe.allow = "microphone";
        iframe.allowTransparency = true;
        iframe.sandbox = "allow-scripts allow-same-origin allow-popups allow-forms"

        Object.assign(iframe.style, {
            position: 'fixed',
            bottom: '20px',
            right: '20px',
            width: '360px',
            height: '540px',
            border: 'none',
            borderRadius: '12px',
            boxShadow: '0 6px 20px rgba(0,0,0,.3)',
            zIndex: 9999,
            overflow: 'hidden',
            background: '#fff'
        });

        document.body.appendChild(iframe);
    }

    /* =========================
       5️⃣ postMessage iframe ↔ parent
    ========================= */
    window.addEventListener('message', (event) => {
        if (!event.data || event.data.source !== 'elchat') return;

        switch (event.data.type) {
            case 'CLOSE_WIDGET':
                closeIframe();
                break;
        }
    });

    /* =========================
       6️⃣ Fermer iframe
    ========================= */
    function closeIframe() {
        if (iframe) {
            iframe.remove();
            iframe = null;
        }

        isOpened = false;
        userClosed = true; // ✅ l'utilisateur a fermé
        createButton();
        //setupAutoOpen();
    }
})();
