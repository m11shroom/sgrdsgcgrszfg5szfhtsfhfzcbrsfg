// ========== Global Variables ==========
let currentUser = null;
let _logoutLocked = false;
let releases = [];
let tickets = [];
let transactions = [];
let currentReleaseData = { cover: null, tracks: [] };
let currentStep = 1;
let usdRubRate = 95;
const TARIFF_PRO_RUB = 3000;
const TARIFF_PREMIUM_RUB = 5000;

async function fetchUsdRubRate() {
    try {
        const r = await fetch('exchange_rate.php?_=' + Date.now());
        const d = await r.json();
        if (d && d.success && typeof d.rate === 'number' && d.rate > 0) {
            usdRubRate = d.rate;
            updateTariffUsdPrices();
        }
    } catch (e) { console.warn('Exchange rate fetch failed', e); }
}

function rubToUsd(rub) { return Math.round(rub / usdRubRate); }

function updateTariffUsdPrices() {
    const proUsd = rubToUsd(TARIFF_PRO_RUB);
    const premUsd = rubToUsd(TARIFF_PREMIUM_RUB);
    const proSpan = document.querySelector('[data-tariff="pro-usd"]');
    const premSpan = document.querySelector('[data-tariff="premium-usd"]');
    if (proSpan) proSpan.textContent = proUsd;
    if (premSpan) premSpan.textContent = premUsd;
}

let currentAudio = null;
let currentAudioId = null;

// ========== Data Init ==========
function initData() {
    releases = [];
    tickets = [];
    transactions = [];
    localStorage.setItem('wm_releases', JSON.stringify(releases));
    localStorage.setItem('wm_tickets', JSON.stringify(tickets));
    localStorage.setItem('wm_transactions', JSON.stringify(transactions));
    localStorage.setItem('wm_demo_setup', 'true');
}

function loadData() {
    const ticketsData = localStorage.getItem('wm_tickets');
    const transactionsData = localStorage.getItem('wm_transactions');
    const currentUserData = localStorage.getItem('wm_current_user');
    function safeParse(data, fallback) {
        if (!data) return fallback;
        try { return JSON.parse(data); } catch (e) { return fallback; }
    }
    let rawReleases = safeParse(localStorage.getItem('wm_releases'), []);
    releases = rawReleases.map(release => ({
        ...release,
        status: release.status === 'need_contract_sign' ? 'pending' : release.status
    }));
    tickets = safeParse(ticketsData, []);
    transactions = safeParse(transactionsData, []);
    currentUser = safeParse(currentUserData, null);
    if (currentUser) {
        if (typeof currentUser.balance !== 'number') currentUser.balance = 0;
        if (!currentUser.role) currentUser.role = 'user';
    }
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function getVisibleReleases() {
    if (!currentUser || !Array.isArray(releases)) return [];
    return releases.filter(r => r.userId === currentUser.id);
}

function saveData() {
    try {
        localStorage.setItem('wm_releases', JSON.stringify(releases));
        localStorage.setItem('wm_tickets', JSON.stringify(tickets));
        localStorage.setItem('wm_transactions', JSON.stringify(transactions));
        if (currentUser) localStorage.setItem('wm_current_user', JSON.stringify(currentUser));
        return true;
    } catch (e) {
        if (e.name === 'QuotaExceededError') { console.error('localStorage quota exceeded:', e); return false; }
        throw e;
    }
}

// ========== Normalize release status ==========
function normalizeReleaseStatus(status) {
    if (!status) return 'pending';
    if (status === 'need_contract_sign') return 'pending';
    return status;
}

function getNormalizedStatus(release) {
    if (!release || !release.status) return 'pending';
    return release.status === 'need_contract_sign' ? 'pending' : release.status;
}

// ========== Tariff Handling ==========
let pendingTariffCode = null;

function handleTariffSelection(code, priceRub) {
    if (!currentUser) {
        showNotification('Error', 'Please log in first', 'error');
        return;
    }
    showPaymentMethodModal(code, priceRub);
}

function showPaymentMethodModal(code, priceRub) {
    if (!currentUser || !currentUser.id) { showNotification('Error', 'Please log in first', 'error'); return; }
    pendingTariffCode = code;
    const nameEl = document.getElementById('paymentModalTariffName');
    if (nameEl) nameEl.textContent = code === 'pro' ? 'Pro' : 'Premium';
    const upgradeInfoDiv = document.getElementById('paymentUpgradeInfo');
    if (upgradeInfoDiv) {
        if (currentUser.tariff_until && new Date(currentUser.tariff_until) > new Date()) {
            const endDate = new Date(currentUser.tariff_until).toLocaleDateString();
            upgradeInfoDiv.style.display = 'block';
            upgradeInfoDiv.innerHTML = `⚠️ Your new plan will start after the current one expires (${endDate}).`;
        } else {
            upgradeInfoDiv.style.display = 'none';
        }
    }
    document.getElementById('paymentMethodModal').style.display = 'block';
}

function closePaymentMethodModal() {
    document.getElementById('paymentMethodModal').style.display = 'none';
    pendingTariffCode = null;
}

// ========== Email Verification ==========
let pendingRegistration = null;

function showVerificationStep(email) {
    document.getElementById('registerForm').classList.add('hidden');
    document.getElementById('verificationForm').classList.remove('hidden');
    document.getElementById('verificationEmailLabel').textContent = email;
}

function hideVerificationStep() {
    document.getElementById('verificationForm').classList.add('hidden');
    document.getElementById('registerForm').classList.remove('hidden');
    pendingRegistration = null;
}

async function resendVerificationCode() {
    if (!pendingRegistration) return;
    try {
        const response = await fetch('verify_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resend', email: pendingRegistration.email })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) {
            showNotification('Code Sent', 'A new verification code has been sent to your email.', 'success');
        } else {
            showNotification('Error', (data && data.error) || 'Could not resend code', 'error');
        }
    } catch (e) {
        showNotification('Error', 'Could not connect to server', 'error');
    }
}

async function submitVerificationCode() {
    const code = document.getElementById('verificationCode').value.trim();
    if (!code) { showNotification('Error', 'Please enter the verification code', 'error'); return; }
    if (!pendingRegistration) { showNotification('Error', 'Session expired. Please register again.', 'error'); hideVerificationStep(); return; }
    try {
        const response = await fetch('verify_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'verify',
                email: pendingRegistration.email,
                code: code,
                registrationData: pendingRegistration
            })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) {
            showNotification('Account Created', 'Your email is verified! You can now log in.', 'success');
            hideVerificationStep();
            showAuthForm('login');
            document.getElementById('registerForm').reset();
            document.getElementById('verificationCode').value = '';
        } else {
            showNotification('Error', (data && data.error) || 'Invalid or expired code', 'error');
        }
    } catch (e) {
        showNotification('Error', 'Could not connect to server', 'error');
    }
}

// ========== Auth ==========
function showAuthForm(formType) {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const verificationForm = document.getElementById('verificationForm');
    const loginTab = document.getElementById('loginTab');
    const registerTab = document.getElementById('registerTab');
    loginForm.classList.add('hidden');
    registerForm.classList.add('hidden');
    if (verificationForm) verificationForm.classList.add('hidden');
    loginTab.classList.remove('active');
    registerTab.classList.remove('active');
    if (formType === 'login') {
        loginForm.classList.remove('hidden');
        loginTab.classList.add('active');
    } else {
        registerForm.classList.remove('hidden');
        registerTab.classList.add('active');
    }
}

async function login(event) {
    event.preventDefault();
    const email = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value;
    if (!email || !password) { showNotification('Error', 'Please enter your email and password', 'error'); return; }
    try {
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email, password })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && data.user) {
            if (data.ip_changed) {
                showNotification('Security Notice', 'A sign-in from a new location was detected. A confirmation email has been sent.', 'warning');
            }
            currentUser = data.user;
            if (typeof currentUser.balance !== 'number') currentUser.balance = 0;
            if (!currentUser.role) currentUser.role = 'user';
            if (currentUser.tariff_id) currentUser.tariff_id = parseInt(currentUser.tariff_id, 10);
            localStorage.setItem('wm_current_user', JSON.stringify(currentUser));
            const nameForHello = currentUser.nickname || currentUser.email;

            _logoutLocked = true;
            setTimeout(() => { _logoutLocked = false; }, 5000);

            showApp();
            updateSidebarUser();
            loadDashboardData();
            showNotification('Welcome', `You have successfully logged in, ${nameForHello}!`, 'success');
            setTimeout(() => { document.getElementById('loginForm').reset(); }, 100);

            syncReleasesFromServerSafe();
            syncTicketsFromServerSafe();
            refreshCurrentUserProfile();
        } else {
            const errorMessage = (data && data.error) || 'Incorrect email or password';
            showNotification('Error', errorMessage, 'error');
        }
    } catch (e) {
        console.error('Auth error', e);
        showNotification('Error', 'Could not connect to the authentication server', 'error');
    }
}

async function syncReleasesFromServerSafe() {
    try {
        const response = await fetch('releases.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'list' })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && Array.isArray(data.releases)) {
            releases = data.releases.map(r => ({
                ...r,
                status: r.status === 'need_contract_sign' ? 'pending' : (r.status || 'pending'),
                genres: Array.isArray(r.genres) ? r.genres : [],
            }));
            saveData();
            updateDashboardStats();
            loadRecentReleases();
        }
    } catch (e) { console.warn('Background release sync failed', e); }
}

async function syncTicketsFromServerSafe() {
    try {
        const response = await fetch('tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'list' })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && Array.isArray(data.tickets)) {
            tickets = data.tickets;
            saveData();
        }
    } catch (e) { console.warn('Background ticket sync failed', e); }
}

async function register(event) {
    event.preventDefault();
    const email = document.getElementById('registerEmail').value.trim();
    const password = document.getElementById('registerPassword').value;
    const confirmPassword = document.getElementById('registerConfirmPassword').value;
    const nickname = document.getElementById('registerNickname').value.trim();
    const vk = document.getElementById('registerVK').value.trim();
    const telegram = document.getElementById('registerTelegram').value.trim();
    if (!email || !password || !nickname) { showNotification('Error', 'Please fill in all required fields', 'error'); return; }
    if (password.length < 6) { showNotification('Error', 'Password must be at least 6 characters', 'error'); return; }
    if (password !== confirmPassword) { showNotification('Error', 'Passwords do not match', 'error'); return; }
    try {
        const response = await fetch('verify_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', email })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) {
            pendingRegistration = { email, password, nickname, vk, telegram };
            showVerificationStep(email);
            showNotification('Code Sent', `A verification code has been sent to ${email}`, 'info');
        } else {
            const errorMessage = (data && data.error) || 'Registration error';
            showNotification('Error', errorMessage, 'error');
        }
    } catch (e) {
        console.error('Register error', e);
        showNotification('Error', 'Could not connect to the registration server', 'error');
    }
}

async function logout() {
    try {
        await fetch('logout.php', { method: 'POST', credentials: 'include' });
    } catch (e) { }
    currentUser = null;
    localStorage.removeItem('wm_current_user');
    showAuth();
    showNotification('Logged out', 'You have been logged out', 'info');
}

async function logoutSilent() {
    if (_logoutLocked) { console.warn('logoutSilent blocked — just logged in'); return; }
    try {
        await fetch('logout.php', { method: 'POST', credentials: 'include' });
    } catch (e) { }
    currentUser = null;
    localStorage.removeItem('wm_current_user');
    showAuth();
}

function isAuthError(response, data) {
    if (!response) return false;
    if (response.status === 401) return true;
    const err = data && data.error;
    return err && (err === 'Authorization required.' || err.toLowerCase().indexOf('auth') >= 0);
}

// ========== UI ==========
function showAuth() {
    document.getElementById('authPage').style.display = 'flex';
    document.getElementById('appContainer').style.display = 'none';
}

function showApp() {
    document.getElementById('authPage').style.display = 'none';
    document.getElementById('appContainer').style.display = 'flex';
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && overlay) {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar && overlay) {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

async function showPage(pageName) {
    closeSidebar();
    document.querySelectorAll('.page').forEach(page => page.classList.add('hidden'));
    const targetPage = document.getElementById(pageName + 'Page');
    if (targetPage) targetPage.classList.remove('hidden');
    document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
    const menuItem = document.querySelector(`[onclick="showPage('${pageName}')"]`);
    if (menuItem) menuItem.classList.add('active');
    const pageTitles = {
        dashboard: 'Dashboard', releases: 'My Releases', analytics: 'Analytics',
        tariffs: 'Plans', subscriptions: 'My Subscriptions', balance: 'Balance',
        support: 'Support', profile: 'Profile', legal: 'Legal Information', tools: 'Tools',
        pitching: 'Pitching', calendar: 'Release Calendar', drafts: 'Drafts'
    };
    const pageTitle = document.getElementById('pageTitle');
    if (pageTitle && pageTitles[pageName]) pageTitle.textContent = pageTitles[pageName];
    switch(pageName) {
        case 'dashboard': loadDashboardData(); break;
        case 'releases': loadReleasesPage(); break;
        case 'analytics': loadAnalyticsPage(); break;
        case 'tariffs': loadTariffsPage(); break;
        case 'balance': loadBalancePage(); break;
        case 'subscriptions': loadSubscriptionsPage(); break;
        case 'support': await syncTicketsFromServer(); loadSupportPage(); break;
        case 'profile': loadProfilePage(); break;
        case 'tools': loadToolsPage(); break;
    }
}

// ========== Dashboard ==========
function loadDashboardData() {
    const welcomeUser = document.getElementById('welcomeUser');
    if (welcomeUser) welcomeUser.textContent = currentUser ? (currentUser.nickname || currentUser.email) : 'User';
    updateDashboardStats();
    loadRecentReleases();
    loadTariffsPage();
}

async function syncReleasesFromServer() {
    try {
        const response = await fetch('releases.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'list' })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && Array.isArray(data.releases)) {
            releases = data.releases.map(release => ({
                id: release.id,
                userId: release.userId,
                title: release.title,
                artist: release.artist,
                featuring: release.featuring || '',
                format: release.format || 'single',
                releaseDate: release.releaseDate || null,
                language: release.language || 'en',
                genres: Array.isArray(release.genres) ? release.genres : [],
                status: release.status === 'need_contract_sign' ? 'pending' : (release.status || 'pending'),
                statusComment: release.statusComment || '',
                createdAt: release.createdAt || new Date().toISOString(),
                upc: release.upc || '',
                cover: release.cover || null,
                wishes: release.wishes || '',
                authorName: release.authorName || null,
                authorEmail: release.authorEmail || null,
                tracks: release.tracks
            }));
            saveData();
            updateDashboardStats();
            loadRecentReleases();
        } else {
            console.warn('Could not load releases:', (data && data.error) || response.status);
        }
    } catch (e) {
        console.warn('Exception fetching releases from server', e);
    }
}

async function syncTicketsFromServer() {
    try {
        const response = await fetch('tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'list' })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && Array.isArray(data.tickets)) {
            tickets = data.tickets;
            saveData();
        } else {
            console.warn('Could not load tickets:', (data && data.error) || response.status);
        }
    } catch (e) { console.warn('Error loading tickets from server', e); }
}

function updateDashboardStats() {
    const visibleReleases = getVisibleReleases();
    document.getElementById('totalReleases').textContent = visibleReleases.length;
    document.getElementById('moderationReleases').textContent = visibleReleases.filter(r => getNormalizedStatus(r) === 'pending').length;
    document.getElementById('approvedReleases').textContent = visibleReleases.filter(r => getNormalizedStatus(r) === 'approved').length;
    const balance = (currentUser && typeof currentUser.balance === 'number') ? currentUser.balance : 0;
    document.getElementById('dashboardBalance').textContent = `₽${balance.toFixed(2)}`;
}

function loadRecentReleases() {
    const visibleReleases = getVisibleReleases();
    const recentReleases = [...visibleReleases].sort((a, b) => {
        const dateA = a.createdAt ? new Date(a.createdAt).getTime() : 0;
        const dateB = b.createdAt ? new Date(b.createdAt).getTime() : 0;
        if (isNaN(dateA)) return 1;
        if (isNaN(dateB)) return -1;
        return dateB - dateA;
    }).slice(0, 6);
    const container = document.getElementById('recentReleasesContainer');
    if (recentReleases.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-compact-disc"></i></div>
                <h4 class="empty-title">No releases yet</h4>
                <p class="empty-description">Your releases will appear here after submission.</p>
                <button class="btn btn-primary mt-md" onclick="openNewReleaseModal()">
                    <i class="fas fa-plus"></i> Create First Release
                </button>
            </div>
        `;
        return;
    }
    container.innerHTML = `
        <div class="releases-grid">
            ${recentReleases.map(release => `
                <div class="release-card" onclick="showReleaseDetails(${release.id})">
                    <div class="release-cover">
                        ${release.cover ? `<img src="${release.cover}" alt="${escapeHtml(release.title)}">` : `<div class="release-cover-placeholder"><i class="fas fa-compact-disc"></i></div>`}
                    </div>
                    <div class="release-info">
                        <div class="release-title">${escapeHtml(release.title)}</div>
                        <div class="release-artist">${escapeHtml(release.artist)}</div>
                        <div class="release-status status-${getNormalizedStatus(release)}">${getStatusText(release.status)}</div>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

// ========== Releases ==========
function loadReleasesPage() {
    const userReleases = getVisibleReleases();
    const container = document.getElementById('releasesList');
    if (userReleases.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1">
                <div class="empty-icon"><i class="fas fa-music"></i></div>
                <h4 class="empty-title">No releases yet</h4>
                <p class="empty-description">Create your first release</p>
                <button class="btn btn-primary mt-md" onclick="openNewReleaseModal()">
                    <i class="fas fa-plus"></i> Create Release
                </button>
            </div>
        `;
    } else {
        container.innerHTML = userReleases.map(release => `
            <div class="release-card" onclick="showReleaseDetails(${release.id})">
                <div class="release-cover">
                    ${release.cover ? `<img src="${release.cover}" alt="${escapeHtml(release.title)}">` : `<div class="release-cover-placeholder"><i class="fas fa-compact-disc"></i></div>`}
                </div>
                <div class="release-info">
                    <div class="release-title">${escapeHtml(release.title)}</div>
                    <div class="release-artist">ID: ${release.id} · ${escapeHtml(release.artist)}</div>
                    <div class="release-status status-${getNormalizedStatus(release)}">${getStatusText(release.status)}</div>
                </div>
            </div>
        `).join('');
    }
}

function filterReleases(status) {
    const buttons = document.querySelectorAll('#releasesPage .btn-group .btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    const visibleReleases = getVisibleReleases();
    let filteredReleases = visibleReleases;
    if (status !== 'all') {
        filteredReleases = visibleReleases.filter(r => getNormalizedStatus(r) === status);
    }
    const container = document.getElementById('releasesList');
    if (filteredReleases.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1">
                <div class="empty-icon"><i class="fas fa-music"></i></div>
                <h4 class="empty-title">Nothing found</h4>
                <p class="empty-description">${getEmptyMessageForStatus(status)}</p>
                ${status === 'all' ? `<button class="btn btn-primary mt-md" onclick="openNewReleaseModal()"><i class="fas fa-plus"></i> Create Release</button>` : ''}
            </div>
        `;
    } else {
        container.innerHTML = filteredReleases.map(release => `
            <div class="release-card" onclick="showReleaseDetails(${release.id})">
                <div class="release-cover">
                    ${release.cover ? `<img src="${release.cover}" alt="${escapeHtml(release.title)}">` : `<div class="release-cover-placeholder"><i class="fas fa-compact-disc"></i></div>`}
                </div>
                <div class="release-info">
                    <div class="release-title">${escapeHtml(release.title)}</div>
                    <div class="release-artist">ID: ${release.id} · ${escapeHtml(release.artist)}</div>
                    <div class="release-status status-${getNormalizedStatus(release)}">${getStatusText(release.status)}</div>
                </div>
            </div>
        `).join('');
    }
}

function getEmptyMessageForStatus(status) {
    switch(status) {
        case 'pending': return 'No releases pending moderation';
        case 'approved': return 'No approved releases';
        case 'rejected': return 'No rejected releases';
        default: return 'You have no releases yet';
    }
}

function searchReleases(query) {
    if (!query.trim()) { filterReleases('all'); return; }
    const visibleReleases = getVisibleReleases();
    const searchResults = visibleReleases.filter(release =>
        release.title.toLowerCase().includes(query.toLowerCase()) ||
        release.artist.toLowerCase().includes(query.toLowerCase()) ||
        (Array.isArray(release.genres) && release.genres.some(genre => genre.toLowerCase().includes(query.toLowerCase())))
    );
    const container = document.getElementById('releasesList');
    if (searchResults.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1">
                <div class="empty-icon"><i class="fas fa-search"></i></div>
                <h4 class="empty-title">Nothing found</h4>
                <p class="empty-description">No results for "${escapeHtml(query)}"</p>
            </div>
        `;
    } else {
        container.innerHTML = searchResults.map(release => `
            <div class="release-card" onclick="showReleaseDetails(${release.id})">
                <div class="release-cover">
                    ${release.cover ? `<img src="${release.cover}" alt="${escapeHtml(release.title)}">` : `<div class="release-cover-placeholder"><i class="fas fa-compact-disc"></i></div>`}
                </div>
                <div class="release-info">
                    <div class="release-title">${escapeHtml(release.title)}</div>
                    <div class="release-artist">${escapeHtml(release.artist)}</div>
                    <div class="release-status status-${getNormalizedStatus(release)}">${getStatusText(release.status)}</div>
                </div>
            </div>
        `).join('');
    }
}

// ========== New Release ==========
function openNewReleaseModal() {
    if (!currentUser) {
        showNotification('Error', 'You must be logged in to create a release.', 'error');
        return;
    }
    document.getElementById('licenseModal').style.display = 'block';
}

function acceptLicenseAndOpenReleaseForm() {
    const agreeCheckbox = document.getElementById('licenseAgreeCheckbox');
    if (!agreeCheckbox.checked) {
        showNotification('Error', 'You must accept the license agreement', 'error');
        return;
    }
    document.getElementById('licenseModal').style.display = 'none';
    agreeCheckbox.checked = false;
    resetReleaseForm();
    const modal = document.getElementById('newReleaseModal');
    modal.style.display = 'block';
    document.getElementById('releaseArtist').value = currentUser.nickname;
    document.getElementById('releaseDate').value = new Date().toISOString().split('T')[0];
    setActiveStep(1);
    addTrackForm();
}

function closeLicenseModal() {
    document.getElementById('licenseModal').style.display = 'none';
    document.getElementById('licenseAgreeCheckbox').checked = false;
}

function closeNewReleaseModal() {
    document.getElementById('newReleaseModal').style.display = 'none';
    resetReleaseForm();
}

function setActiveStep(step) {
    currentStep = step;
    document.querySelectorAll('.step').forEach(stepEl => {
        stepEl.classList.remove('active');
        if (parseInt(stepEl.dataset.step) === step) stepEl.classList.add('active');
    });
    document.querySelectorAll('.step-content').forEach(content => content.classList.add('hidden'));
    document.getElementById(`step${step}Content`).classList.remove('hidden');
}

function nextStep(step) {
    if (!validateStep(currentStep)) return;
    setActiveStep(step);
    if (step === 4) generateReleaseSummary();
}

function prevStep(step) { setActiveStep(step); }

function validateStep(step) {
    switch(step) {
        case 1:
            if (!currentReleaseData.cover) { showNotification('Error', 'Please upload a cover image', 'error'); return false; }
            return true;
        case 2:
            const title = document.getElementById('releaseTitle').value.trim();
            const artist = document.getElementById('releaseArtist').value.trim();
            const date = document.getElementById('releaseDate').value;
            const genre = document.getElementById('releaseGenre');
            const selectedGenres = Array.from(genre.selectedOptions).map(option => option.value);
            if (!title || !artist || !date || selectedGenres.length === 0) { showNotification('Error', 'Please fill in all required fields', 'error'); return false; }
            return true;
        case 3:
            if (currentReleaseData.tracks.length === 0) { showNotification('Error', 'Add at least one track', 'error'); return false; }
            for (const track of currentReleaseData.tracks) {
                if (!track.title || !track.artist || !track.composer || !track.lyricists) { showNotification('Error', 'Fill in all required fields for each track', 'error'); return false; }
            }
            const format = document.getElementById('releaseFormat').value;
            const trackCount = currentReleaseData.tracks.length;
            let error = '';
            switch(format) {
                case 'single': if (trackCount !== 1) error = 'A single must contain exactly 1 track.'; break;
                case 'maxi': if (trackCount < 2 || trackCount > 3) error = 'A Maxi Single must contain 2–3 tracks.'; break;
                case 'ep': if (trackCount < 3 || trackCount > 7) error = 'An EP must contain 3–7 tracks.'; break;
                case 'album': if (trackCount < 7) error = 'An album must contain at least 7 tracks.'; break;
            }
            if (error) { showNotification('Error', error, 'error'); return false; }
            return true;
        default: return true;
    }
}

function validateTrackCount() {
    const format = document.getElementById('releaseFormat').value;
    const trackCount = currentReleaseData.tracks.length;
    const infoElement = document.getElementById('trackCountInfo');
    const validationElement = document.getElementById('trackCountValidation');
    if (!infoElement || !validationElement) return;
    infoElement.textContent = `Tracks added: ${trackCount}`;
    let message = '';
    switch(format) {
        case 'single': if (trackCount !== 1) message = 'A single must contain exactly 1 track'; break;
        case 'maxi': if (trackCount < 2 || trackCount > 3) message = 'A Maxi Single must contain 2–3 tracks'; break;
        case 'ep': if (trackCount < 3 || trackCount > 7) message = 'An EP must contain 3–7 tracks'; break;
        case 'album': if (trackCount < 7) message = 'An album must contain at least 7 tracks'; break;
    }
    if (message) { validationElement.textContent = message; validationElement.style.color = 'var(--gray-40)'; }
    else { validationElement.textContent = 'Track count matches the format'; validationElement.style.color = 'var(--gray-30)'; }
}

async function handleCoverUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        showNotification('Error', 'Invalid file format. Allowed: JPG, PNG, WebP', 'error');
        return;
    }
    if (file.size > 30 * 1024 * 1024) {
        showNotification('Error', 'File too large. Maximum size: 30MB', 'error');
        return;
    }
    const checkDimensions = () => new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = function() {
            URL.revokeObjectURL(url);
            if (this.width !== 1500 || this.height !== 1500) {
                reject(new Error(`Cover must be exactly 1500×1500 px. Your image: ${this.width}×${this.height} px`));
            } else {
                resolve();
            }
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Could not read image')); };
        img.src = url;
    });
    try {
        await checkDimensions();
    } catch (err) {
        showNotification('Error', err.message, 'error');
        event.target.value = '';
        return;
    }
    try {
        const coverUrl = await uploadFileToServer(file, 'cover');
        currentReleaseData.cover = coverUrl;
        const preview = document.getElementById('coverPreview');
        const previewImg = preview.querySelector('img');
        previewImg.src = coverUrl;
        preview.classList.remove('hidden');
        document.getElementById('coverText').textContent = file.name;
        showNotification('Success', 'Cover uploaded', 'success');
    } catch (err) {
        if (err.message === 'AUTH_REQUIRED') return;
        showNotification('Error', err.message || 'Error uploading cover', 'error');
        event.target.value = '';
    }
}

let trackIdCounter = 0;

function addTrackForm() {
    trackIdCounter++;
    const trackId = trackIdCounter;
    const tracksList = document.getElementById('tracksListForm');
    const trackForm = document.createElement('div');
    trackForm.className = 'track-form-item';
    trackForm.id = `trackItem${trackId}`;
    trackForm.innerHTML = `
        <div class="track-form-header-inner" onclick="toggleTrackForm(${trackId})">
            <div class="flex items-center gap-md">
                <span class="track-title font-semibold text-white">Track ${trackId}</span>
            </div>
            <i class="fas fa-chevron-down track-chevron"></i>
        </div>
        <div class="track-form-body open" id="trackBody${trackId}">
            <div class="track-form-fields">
                <div class="form-group">
                    <label class="form-label">Track Title *</label>
                    <input type="text" class="form-control" placeholder="Track title" required oninput="updateTrackField(${trackId}, 'title', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">Artist *</label>
                    <input type="text" class="form-control" value="${escapeHtml(currentUser ? currentUser.nickname : '')}" required oninput="updateTrackField(${trackId}, 'artist', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">Composer *</label>
                    <input type="text" class="form-control" placeholder="Composer name" required oninput="updateTrackField(${trackId}, 'composer', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">Lyricist *</label>
                    <div class="flex gap-sm">
                        <input type="text" class="form-control flex-1" placeholder="Lyricist name" required id="lyricists${trackId}" oninput="updateTrackField(${trackId}, 'lyricists', this.value)">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setNoLyrics(${trackId})">Instrumental</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Producer</label>
                    <input type="text" class="form-control" placeholder="Producer name (optional)" oninput="updateTrackField(${trackId}, 'producer', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">Duration</label>
                    <input type="text" class="form-control" placeholder="e.g. 3:45" oninput="updateTrackField(${trackId}, 'duration', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">ISRC</label>
                    <input type="text" class="form-control" placeholder="RUZCE0800001" maxlength="12" oninput="updateTrackField(${trackId}, 'isrc', this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" id="explicit${trackId}" onchange="updateTrackField(${trackId}, 'explicit', this.checked)" style="margin-right: 0.5rem;">
                        Explicit (contains profanity)
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Audio File *</label>
                    <input type="file" id="trackAudioInput${trackId}" accept=".mp3,.wav,.ogg,.flac" class="hidden" onchange="attachTrackAudio(${trackId}, event)">
                    <button type="button" class="btn btn-secondary" style="width:100%" onclick="document.getElementById('trackAudioInput${trackId}').click()">
                        <i class="fas fa-upload"></i> <span id="trackAudioLabel${trackId}">Choose Audio File</span>
                    </button>
                </div>
            </div>
            <button type="button" class="btn btn-danger mt-md" onclick="removeTrackForm(${trackId})">
                <i class="fas fa-trash"></i> Delete Track
            </button>
        </div>
    `;
    tracksList.appendChild(trackForm);
    currentReleaseData.tracks.push({
        id: trackId,
        title: '',
        artist: currentUser ? currentUser.nickname : '',
        composer: '',
        lyricists: '',
        producer: '',
        duration: '',
        isrc: '',
        explicit: false,
        audioFile: null
    });
    validateTrackCount();
}

function attachTrackAudio(trackId, event) {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.name.match(/\.(mp3|wav|ogg|flac)$/i)) {
        showNotification('Error', 'Invalid format: ' + file.name + '. Allowed: MP3, WAV, OGG, FLAC', 'error');
        event.target.value = '';
        return;
    }
    if (file.size > 200 * 1024 * 1024) {
        showNotification('Error', 'File too large: ' + file.name + '. Maximum: 200MB', 'error');
        event.target.value = '';
        return;
    }

    // Прикрепляем файл к треку в currentReleaseData
    const track = currentReleaseData.tracks.find(t => t.id === trackId);
    if (track) {
        track.audioFile = file;
    }

    // Обновляем подпись кнопки
    const label = document.getElementById('trackAudioLabel' + trackId);
    if (label) label.textContent = file.name;
}

async function handleAudioUpload(event) {
    const files = Array.from(event.target.files);
    if (!files.length) return;
    for (const file of files) {
        const validTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/x-wav', 'audio/x-flac'];
        if (!validTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|ogg|flac)$/i)) {
            showNotification('Error', `Invalid format: ${file.name}. Allowed: MP3, WAV, OGG, FLAC`, 'error');
            continue;
        }
        if (file.size > 200 * 1024 * 1024) {
            showNotification('Error', `File too large: ${file.name}. Maximum: 200MB`, 'error');
            continue;
        }
        const trackId = ++trackIdCounter;
        const fileName = file.name.replace(/\.[^/.]+$/, '');
        const tracksList = document.getElementById('tracksListForm');
        const trackForm = document.createElement('div');
        trackForm.className = 'track-form-item';
        trackForm.id = `trackItem${trackId}`;
        trackForm.innerHTML = `
            <div class="track-form-header-inner" onclick="toggleTrackForm(${trackId})">
                <div class="flex items-center gap-md">
                    <span class="track-title font-semibold text-white">${escapeHtml(fileName)}</span>
                </div>
                <i class="fas fa-chevron-down track-chevron"></i>
            </div>
            <div class="track-form-body open" id="trackBody${trackId}">
                <div class="track-form-fields">
                    <div class="form-group">
                        <label class="form-label">Track Title *</label>
                        <input type="text" class="form-control" value="${escapeHtml(fileName)}" required oninput="updateTrackField(${trackId}, 'title', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Artist *</label>
                        <input type="text" class="form-control" value="${escapeHtml(currentUser ? currentUser.nickname : '')}" required oninput="updateTrackField(${trackId}, 'artist', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Composer *</label>
                        <input type="text" class="form-control" placeholder="Composer name" required oninput="updateTrackField(${trackId}, 'composer', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lyricist *</label>
                        <div class="flex gap-sm">
                            <input type="text" class="form-control flex-1" placeholder="Lyricist name" required id="lyricists${trackId}" oninput="updateTrackField(${trackId}, 'lyricists', this.value)">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="setNoLyrics(${trackId})">Instrumental</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Producer</label>
                        <input type="text" class="form-control" placeholder="Producer name (optional)" oninput="updateTrackField(${trackId}, 'producer', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duration</label>
                        <input type="text" class="form-control" placeholder="e.g. 3:45" oninput="updateTrackField(${trackId}, 'duration', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ISRC</label>
                        <input type="text" class="form-control" placeholder="RUZCE0800001" maxlength="12" oninput="updateTrackField(${trackId}, 'isrc', this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <input type="checkbox" id="explicit${trackId}" onchange="updateTrackField(${trackId}, 'explicit', this.checked)" style="margin-right: 0.5rem;">
                            Explicit (contains profanity)
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Audio File</label>
                        <div class="text-sm text-muted">Uploaded: ${escapeHtml(file.name)}</div>
                    </div>
                </div>
                <button type="button" class="btn btn-danger mt-md" onclick="removeTrackForm(${trackId})">
                    <i class="fas fa-trash"></i> Delete Track
                </button>
            </div>
        `;
        tracksList.appendChild(trackForm);
        currentReleaseData.tracks.push({
            id: trackId,
            title: fileName,
            artist: currentUser ? currentUser.nickname : '',
            composer: '',
            lyricists: '',
            producer: '',
            duration: '',
            isrc: '',
            explicit: false,
            audioFile: file
        });
    }
    showNotification('Success', `${files.length} track(s) loaded`, 'success');
    validateTrackCount();
}

function toggleTrackForm(trackId) {
    const trackBody = document.getElementById(`trackBody${trackId}`);
    const chevron = trackBody.parentElement.querySelector('.track-chevron');
    trackBody.classList.toggle('open');
    chevron.classList.toggle('open');
}

function updateTrackField(trackId, field, value) {
    const track = currentReleaseData.tracks.find(t => t.id === trackId);
    if (track) {
        track[field] = value;
        const trackElement = document.querySelector(`#trackBody${trackId}`).parentElement;
        const titleElement = trackElement.querySelector('.track-title');
        if (titleElement && field === 'title' && value) titleElement.textContent = value;
    }
}

function setNoLyrics(trackId) {
    const input = document.getElementById(`lyricists${trackId}`);
    input.value = 'Instrumental';
    updateTrackField(trackId, 'lyricists', 'Instrumental');
}

function removeTrackForm(trackId) {
    const trackElement = document.querySelector(`#trackBody${trackId}`).parentElement;
    if (trackElement) trackElement.remove();
    currentReleaseData.tracks = currentReleaseData.tracks.filter(track => track.id !== trackId);
    validateTrackCount();
}

function generateReleaseSummary() {
    const title = document.getElementById('releaseTitle').value;
    const artist = document.getElementById('releaseArtist').value;
    const featuring = document.getElementById('releaseFeaturing').value;
    const format = document.getElementById('releaseFormat').value;
    const date = document.getElementById('releaseDate').value;
    const language = document.getElementById('releaseLanguage').value;
    const genre = document.getElementById('releaseGenre');
    const selectedGenres = Array.from(genre.selectedOptions).map(option => option.text);
    const wishes = document.getElementById('releaseWishes').value;
    const formatText = { 'single': 'Single', 'maxi': 'Maxi Single', 'ep': 'EP', 'album': 'Album' }[format];
    const languageText = { 'ru': 'Russian', 'en': 'English', 'other': 'Other' }[language];
    const summary = document.getElementById('releaseSummary');
    summary.innerHTML = `
        <div class="space-y-sm">
            <div><strong>Title:</strong> ${escapeHtml(title)}</div>
            <div><strong>Artist:</strong> ${escapeHtml(artist)}</div>
            ${featuring ? `<div><strong>Feat.:</strong> ${escapeHtml(featuring)}</div>` : ''}
            <div><strong>Format:</strong> ${formatText}</div>
            <div><strong>Release Date:</strong> ${date}</div>
            <div><strong>Language:</strong> ${languageText}</div>
            <div><strong>Genres:</strong> ${selectedGenres.join(', ')}</div>
            <div><strong>Track count:</strong> ${currentReleaseData.tracks.length}</div>
            ${wishes ? `<div><strong>Notes:</strong> ${escapeHtml(wishes)}</div>` : ''}
            <div class="mt-md"><strong>Tracks:</strong>
                ${currentReleaseData.tracks.map((track, index) => `<div class="mt-sm text-sm">${index + 1}. ${escapeHtml(track.title)} (${escapeHtml(track.artist)}) | Dur: ${escapeHtml(track.duration || '—')} | Prod: ${escapeHtml(track.producer || '—')}</div>`).join('')}
            </div>
        </div>
    `;
}

async function uploadFileToServer(file, type) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);
    const response = await fetch('upload.php', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    });
    const text = await response.text();
    let data = {};
    try { data = JSON.parse(text); } catch (e) { console.error('upload.php returned non-JSON:', text); }
    if (!response.ok || !data.success || !data.url) {
        const msg = (data && data.error) || `File upload error (code ${response.status})`;
        throw new Error(msg);
    }
    return data.url;
}

async function submitRelease() {
    const title = document.getElementById('releaseTitle').value.trim();
    const artist = document.getElementById('releaseArtist').value.trim();
    const featuring = document.getElementById('releaseFeaturing').value.trim();
    const format = document.getElementById('releaseFormat').value;
    const date = document.getElementById('releaseDate').value;
    const language = document.getElementById('releaseLanguage').value;
    const genre = document.getElementById('releaseGenre');
    const selectedGenres = Array.from(genre.selectedOptions).map(option => option.value);
    const wishes = document.getElementById('releaseWishes').value.trim();

    for (const track of currentReleaseData.tracks) {
        if (!track.audioFile) {
            showNotification('Error', `Track "${track.title}" is missing an audio file`, 'error');
            return;
        }
    }

    const totalTracks = currentReleaseData.tracks.length;
    const overlay = document.getElementById('uploadProgressOverlay');
    const barFill = document.getElementById('uploadProgressBarFill');
    const progressText = document.getElementById('uploadProgressText');
    const submitBtn = document.getElementById('submitReleaseBtn');
    if (overlay) overlay.classList.remove('hidden');
    if (submitBtn) submitBtn.disabled = true;

    function updateUploadProgress(done, total) {
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        if (barFill) barFill.style.width = pct + '%';
        if (progressText) progressText.textContent = done + ' of ' + total;
    }

    let audioUrls;
    try {
        audioUrls = [];
        for (let i = 0; i < totalTracks; i++) {
            updateUploadProgress(i, totalTracks);
            const url = await uploadFileToServer(currentReleaseData.tracks[i].audioFile, 'audio');
            audioUrls.push(url);
        }
        updateUploadProgress(totalTracks, totalTracks);
    } catch (err) {
        if (overlay) overlay.classList.add('hidden');
        if (submitBtn) submitBtn.disabled = false;
        if (err.message === 'AUTH_REQUIRED') return;
        showNotification('Error', err.message || 'Failed to upload audio files', 'error');
        return;
    }
    if (overlay) overlay.classList.add('hidden');

    const newRelease = {
        id: Date.now(),
        userId: currentUser.id,
        title, artist, featuring, format,
        releaseDate: date,
        language,
        genres: selectedGenres,
        tracks: currentReleaseData.tracks.map((track, index) => ({
            id: track.id,
            title: track.title,
            artist: track.artist,
            composer: track.composer,
            lyricists: track.lyricists,
            producer: track.producer,
            duration: track.duration || 'Unknown',
            explicit: track.explicit || false,
            isrc: (track.isrc || '').trim(),
            audioUrl: audioUrls[index],
            listens: 0,
            listensByPlatform: {},
            listensHistory: []
        })),
        status: 'pending',
        createdAt: new Date().toISOString(),
        upc: '',
        cover: currentReleaseData.cover,
        wishes
    };

    try {
        const response = await fetch('releases.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'create', release: newRelease })
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && data.release_id) {
            newRelease.id = data.release_id;
            newRelease.status = 'pending';
            releases.push(newRelease);
            saveData();
            showNotification('Success', 'Release submitted for moderation.', 'success');
            closeNewReleaseModal();
            syncReleasesFromServer();
            loadReleasesPage();
            loadDashboardData();
            updateDashboardStats();
            loadRecentReleases();
        } else {
            showNotification('Save Error', (data && data.error) || 'Error saving release.', 'error');
        }
    } catch (e) {
        showNotification('Save Error', 'Could not submit release. Check your server connection.', 'error');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

function resetReleaseForm() {
    currentReleaseData = { cover: null, tracks: [] };
    currentStep = 1;
    trackIdCounter = 0;
    document.getElementById('coverUpload').value = '';
    document.getElementById('coverPreview').classList.add('hidden');
    document.getElementById('coverText').textContent = 'Drag image here';
    document.getElementById('coverDropzone').classList.remove('dragover');
    document.getElementById('releaseTitle').value = '';
    document.getElementById('releaseFeaturing').value = '';
    document.getElementById('releaseWishes').value = '';
    document.getElementById('tracksListForm').innerHTML = '';
    if (currentUser) document.getElementById('releaseArtist').value = currentUser.nickname;
}

function formatDateSafe(dateValue, options = {}) {
    if (!dateValue) return 'No data';
    try {
        const date = new Date(dateValue);
        if (isNaN(date.getTime())) {
            if (typeof dateValue === 'string' && dateValue.match(/^\d{4}-\d{2}-\d{2}/)) {
                const parts = dateValue.split('T')[0].split('-');
                const parsedDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                if (!isNaN(parsedDate.getTime())) return parsedDate.toLocaleDateString('en-US', options);
            }
            return 'Invalid date';
        }
        return date.toLocaleDateString('en-US', options);
    } catch (e) { return 'Date error'; }
}

// ========== Release Details ==========
function showReleaseDetails(releaseId) {
    const release = releases.find(r => r.id === releaseId);
    if (!release) { showNotification('Error', 'Release not found', 'error'); return; }
    const isOwner = currentUser.id === release.userId;
    if (!isOwner) { showNotification('Error', 'Access denied', 'error'); return; }
    history.pushState({ releaseId: releaseId }, '', '?release=' + releaseId);
    const modal = document.getElementById('releaseDetailsModal');
    const content = document.getElementById('releaseDetailsContent');
    const releaseDate = formatDateSafe(release.releaseDate);
    const createdDate = formatDateSafe(release.createdAt, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const statusText = getStatusText(release.status);
    const normalizedStatus = getNormalizedStatus(release);
    const statusClass = `status-${normalizedStatus}`;
    const formatText = { 'single': 'Single', 'maxi': 'Maxi Single', 'ep': 'EP', 'album': 'Album' }[release.format] || release.format;
    const languageText = { 'ru': 'Russian', 'en': 'English', 'other': 'Other' }[release.language] || release.language;
    const genresList = Array.isArray(release.genres) && release.genres.length > 0 ? release.genres.join(', ') : 'Not specified';

    content.innerHTML = `
        <div class="release-details-grid">
            <div class="release-details-cover">
                ${release.cover ? `<img src="${release.cover}" alt="${escapeHtml(release.title)}">` : `<div class="release-cover-placeholder"><i class="fas fa-compact-disc"></i></div>`}
            </div>
            <div class="release-details-info">
                <div class="release-detail-item"><div class="release-detail-label">Release ID</div><div class="release-detail-value">#${release.id}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Title</div><div class="release-detail-value">${escapeHtml(release.title)}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Artist</div><div class="release-detail-value">${escapeHtml(release.artist)}</div></div>
                ${release.featuring ? `<div class="release-detail-item"><div class="release-detail-label">Featuring</div><div class="release-detail-value">${escapeHtml(release.featuring)}</div></div>` : ''}
                <div class="release-detail-item"><div class="release-detail-label">Format</div><div class="release-detail-value">${formatText}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Release Date</div><div class="release-detail-value">${releaseDate}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Language</div><div class="release-detail-value">${languageText}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Genres</div><div class="release-detail-value">${genresList}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Status</div><div class="release-status ${statusClass}">${statusText}</div></div>
                ${release.statusComment ? `<div class="release-detail-item"><div class="release-detail-label">Moderator Comment</div><div class="release-detail-value text-muted" style="white-space: pre-wrap;">${escapeHtml(release.statusComment)}</div></div>` : ''}
                <div class="release-detail-item"><div class="release-detail-label">UPC</div><div class="release-detail-value">${release.upc ? escapeHtml(release.upc) : '—'}</div></div>
                <div class="release-detail-item"><div class="release-detail-label">Submitted</div><div class="release-detail-value">${createdDate}</div></div>
                ${release.wishes ? `<div class="release-detail-item"><div class="release-detail-label">Notes</div><div class="release-detail-value" style="white-space: pre-wrap;">${escapeHtml(release.wishes)}</div></div>` : ''}
            </div>
        </div>
        <div class="tracks-section mt-xl">
            <h3 class="font-semibold mb-lg" style="color:#111827;">Tracks (${release.tracks ? release.tracks.length : 0})</h3>
            ${release.tracks && release.tracks.length > 0 ? release.tracks.map((track, index) => `
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:0.75rem;overflow:hidden;">
                    <!-- Основная строка трека — всегда видна -->
                    <div style="padding:1rem 1.25rem;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:1rem;font-weight:600;color:#111827;margin-bottom:0.25rem;">
                                ${index + 1}. ${escapeHtml(track.title)}
                            </div>
                            ${track.duration ? `<div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.15rem;">${escapeHtml(track.duration)}</div>` : ''}
                            ${track.isrc ? `<div style="font-size:0.8rem;color:#9ca3af;">ISRC: ${escapeHtml(track.isrc)}</div>` : ''}
                            <!-- Плеер под основной информацией -->
                            ${track.audioUrl ? `
                            <div class="audio-player" id="audioPlayer${release.id}_${track.id}" style="margin-top:0.75rem;">
                                <div class="audio-player-controls">
                                    <button class="audio-player-btn" id="playBtn${release.id}_${track.id}" onclick="toggleAudio(${release.id}, ${track.id}, '${track.audioUrl}')">
                                        <i class="fas fa-play" id="playIcon${release.id}_${track.id}"></i>
                                    </button>
                                    <div class="audio-player-progress">
                                        <div class="audio-player-progress-bar" id="progressBar${release.id}_${track.id}" onclick="seekAudio(event, ${release.id}, ${track.id})">
                                            <div class="audio-player-progress-filled" id="progressFill${release.id}_${track.id}"></div>
                                        </div>
                                        <div class="audio-loading-wrap" id="loadingWrap${release.id}_${track.id}" style="display:none">
                                            <div class="audio-loading-bar"><div class="audio-loading-bar-fill" id="loadingBarFill${release.id}_${track.id}"></div></div>
                                            <span class="audio-loading-text" id="loadingText${release.id}_${track.id}">Loading…</span>
                                        </div>
                                    </div>
                                    <div class="audio-player-time" id="audioTime${release.id}_${track.id}">0:00 / 0:00</div>
                                    <div class="audio-player-volume">
                                        <i class="fas fa-volume-up" id="volumeIcon${release.id}_${track.id}"></i>
                                        <div class="audio-player-volume-bar" onclick="setVolume(event, ${release.id}, ${track.id})">
                                            <div class="audio-player-volume-filled" id="volumeBar${release.id}_${track.id}"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="audio-visualizer" id="visualizer${release.id}_${track.id}">
                                    ${Array(20).fill('<div class="audio-bar"></div>').join('')}
                                </div>
                            </div>` : ''}
                        </div>
                        <!-- Стрелка для доп. информации -->
                        <button onclick="toggleTrackDetails(${release.id}, ${track.id})" style="background:none;border:none;cursor:pointer;padding:0.25rem;color:#9ca3af;flex-shrink:0;margin-top:0.1rem;">
                            <i class="fas fa-chevron-down track-chevron" id="chevron${release.id}_${track.id}" style="transition:transform 0.2s;"></i>
                        </button>
                    </div>
                    <!-- Доп. информация — скрыта по умолчанию -->
                    <div class="track-details" id="trackDetails${release.id}_${track.id}" style="display:none;border-top:1px solid #f3f4f6;padding:1rem 1.25rem;background:#f9fafb;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;">
                            <div>
                                <div style="font-size:0.68rem;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:0.2rem;">Composer</div>
                                <div style="font-size:0.9rem;color:#111827;">${escapeHtml(track.composer || '—')}</div>
                            </div>
                            <div>
                                <div style="font-size:0.68rem;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:0.2rem;">Lyricist</div>
                                <div style="font-size:0.9rem;color:#111827;">${escapeHtml(track.lyricists || '—')}</div>
                            </div>
                            <div>
                                <div style="font-size:0.68rem;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:0.2rem;">Producer</div>
                                <div style="font-size:0.9rem;color:#111827;">${escapeHtml(track.producer || '—')}</div>
                            </div>
                            <div>
                                <div style="font-size:0.68rem;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:0.2rem;">Explicit</div>
                                <div style="font-size:0.9rem;color:#111827;">${track.explicit ? 'Yes' : 'No'}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('') : '<p class="text-muted">No tracks</p>'}
        </div>
    `;
    document.getElementById('releaseDetailsTitle').textContent = escapeHtml(release.title);
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// ========== Audio Player ==========
function toggleAudio(releaseId, trackId, audioUrl) {
    const audioKey = `${releaseId}_${trackId}`;
    if (currentAudio && currentAudioId === audioKey) {
        if (currentAudio.paused) {
            currentAudio.play();
            updatePlayButton(releaseId, trackId, true);
            animateVisualizer(releaseId, trackId);
        } else {
            currentAudio.pause();
            updatePlayButton(releaseId, trackId, false);
        }
        return;
    }
    stopAllAudio();
    currentAudio = new Audio(audioUrl);
    currentAudioId = audioKey;
    const loadingWrap = document.getElementById(`loadingWrap${releaseId}_${trackId}`);
    const loadingBarFill = document.getElementById(`loadingBarFill${releaseId}_${trackId}`);
    const loadingText = document.getElementById(`loadingText${releaseId}_${trackId}`);
    if (loadingWrap) loadingWrap.style.display = 'flex';
    currentAudio.addEventListener('progress', () => {
        if (currentAudio.duration && loadingBarFill) {
            const pct = currentAudio.buffered.length > 0 ? (currentAudio.buffered.end(currentAudio.buffered.length - 1) / currentAudio.duration * 100) : 0;
            loadingBarFill.style.width = pct + '%';
            if (loadingText) loadingText.textContent = Math.round(pct) + '%';
        }
    });
    currentAudio.addEventListener('canplay', () => { if (loadingWrap) loadingWrap.style.display = 'none'; });
    currentAudio.addEventListener('timeupdate', () => updateAudioProgress(releaseId, trackId));
    currentAudio.addEventListener('ended', () => {
        updatePlayButton(releaseId, trackId, false);
        resetVisualizer(releaseId, trackId);
        const fill = document.getElementById(`progressFill${releaseId}_${trackId}`);
        if (fill) fill.style.width = '0%';
    });
    currentAudio.play().then(() => {
        updatePlayButton(releaseId, trackId, true);
        animateVisualizer(releaseId, trackId);
    }).catch(err => {
        console.error('Audio play error', err);
        showNotification('Error', 'Could not play audio', 'error');
    });
}

function stopAllAudio() {
    if (currentAudio) {
        currentAudio.pause();
        currentAudio = null;
    }
    if (currentAudioId) {
        const parts = currentAudioId.split('_');
        if (parts.length >= 2) {
            const rId = parts[0];
            const tId = parts[1];
            updatePlayButton(rId, tId, false);
            resetVisualizer(rId, tId);
        }
        currentAudioId = null;
    }
}

function pauseAudio(releaseId, trackId) {
    if (currentAudio && currentAudioId === `${releaseId}_${trackId}`) {
        currentAudio.pause();
        updatePlayButton(releaseId, trackId, false);
    }
}

function updatePlayButton(releaseId, trackId, isPlaying) {
    const btn = document.getElementById(`playBtn${releaseId}_${trackId}`);
    const icon = document.getElementById(`playIcon${releaseId}_${trackId}`);
    if (btn) btn.classList.toggle('playing', isPlaying);
    if (icon) icon.className = isPlaying ? 'fas fa-pause' : 'fas fa-play';
}

function updateAudioProgress(releaseId, trackId) {
    if (!currentAudio) return;
    const fill = document.getElementById(`progressFill${releaseId}_${trackId}`);
    const timeEl = document.getElementById(`audioTime${releaseId}_${trackId}`);
    if (fill && currentAudio.duration) {
        fill.style.width = `${(currentAudio.currentTime / currentAudio.duration) * 100}%`;
    }
    if (timeEl) {
        timeEl.textContent = `${formatTime(currentAudio.currentTime)} / ${formatTime(currentAudio.duration || 0)}`;
    }
    updateVisualizer(releaseId, trackId);
}

function seekAudio(event, releaseId, trackId) {
    if (!currentAudio || currentAudioId !== `${releaseId}_${trackId}`) return;
    const bar = event.currentTarget;
    const rect = bar.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;
    currentAudio.currentTime = ratio * currentAudio.duration;
}

function setVolume(event, releaseId, trackId) {
    if (!currentAudio || currentAudioId !== `${releaseId}_${trackId}`) return;
    const bar = event.currentTarget;
    const rect = bar.getBoundingClientRect();
    const volume = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
    currentAudio.volume = volume;
    const volumeBar = document.getElementById(`volumeBar${releaseId}_${trackId}`);
    if (volumeBar) volumeBar.style.width = `${volume * 100}%`;
    updateVolumeIcon(releaseId, trackId, volume);
}

function updateVolumeIcon(releaseId, trackId, volume) {
    const icon = document.getElementById(`volumeIcon${releaseId}_${trackId}`);
    if (icon) {
        if (volume === 0) icon.className = 'fas fa-volume-mute';
        else if (volume < 0.5) icon.className = 'fas fa-volume-down';
        else icon.className = 'fas fa-volume-up';
    }
}

function animateVisualizer(releaseId, trackId) {
    if (!currentAudio || currentAudioId !== `${releaseId}_${trackId}`) return;
    updateVisualizer(releaseId, trackId);
    if (!currentAudio.paused) requestAnimationFrame(() => animateVisualizer(releaseId, trackId));
}

function updateVisualizer(releaseId, trackId) {
    if (!currentAudio || currentAudioId !== `${releaseId}_${trackId}`) return;
    const visualizer = document.getElementById(`visualizer${releaseId}_${trackId}`);
    if (!visualizer) return;
    const bars = visualizer.querySelectorAll('.audio-bar');
    bars.forEach((bar, index) => {
        const time = currentAudio.currentTime;
        const frequency = (time * 10) + (index * 0.5);
        const amplitude = Math.sin(frequency) * 0.5 + 0.5;
        const height = 10 + (amplitude * 20);
        bar.style.height = `${height}px`;
        const progressPercent = (currentAudio.currentTime / currentAudio.duration) * 100;
        const barPosition = (index / bars.length) * 100;
        if (barPosition < progressPercent) bar.classList.add('active');
        else bar.classList.remove('active');
    });
}

function resetVisualizer(releaseId, trackId) {
    const visualizer = document.getElementById(`visualizer${releaseId}_${trackId}`);
    if (visualizer) {
        const bars = visualizer.querySelectorAll('.audio-bar');
        bars.forEach(bar => { bar.style.height = '10px'; bar.classList.remove('active'); });
    }
}

function toggleTrackDetails(releaseId, trackId) {
    const trackDetails = document.getElementById(`trackDetails${releaseId}_${trackId}`);
    const chevron = document.getElementById(`chevron${releaseId}_${trackId}`);
    const isOpen = trackDetails.style.display !== 'none';
    trackDetails.style.display = isOpen ? 'none' : 'block';
    if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
    if (isOpen && currentAudioId === `${releaseId}_${trackId}`) pauseAudio(releaseId, trackId);
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function copyReleaseLink() {
    const params = new URLSearchParams(location.search);
    const id = params.get('release');
    if (!id) { showNotification('Error', 'Open a release first', 'error'); return; }
    const url = location.origin + location.pathname + '?release=' + id;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => showNotification('Done', 'Link copied', 'success'));
    } else {
        prompt('Copy the link:', url);
    }
}

function closeReleaseDetails() {
    stopAllAudio();
    history.pushState({}, '', location.pathname);
    document.getElementById('releaseDetailsModal').style.display = 'none';
    document.body.style.overflow = '';
}

function getStatusText(status) {
    const map = {
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        ozhidaet: 'Waiting',
        need_contract_sign: 'Pending'
    };
    return map[status] || status;
}

// ========== Analytics ==========
function loadAnalyticsPage() {
    const userReleases = getVisibleReleases();
    const container = document.getElementById('analyticsContent');
    if (userReleases.length === 0) {
        container.innerHTML = `<div class="empty-state"><div class="empty-icon"><i class="fas fa-chart-line"></i></div><h4 class="empty-title">No data yet</h4><p class="empty-description">Analytics will appear after your releases are published.</p></div>`;
        return;
    }
    let totalListens = 0;
    let platformData = {};
    userReleases.forEach(release => {
        if (release.tracks) {
            release.tracks.forEach(track => {
                totalListens += track.listens || 0;
                if (track.listensByPlatform) {
                    Object.entries(track.listensByPlatform).forEach(([platform, count]) => {
                        platformData[platform] = (platformData[platform] || 0) + count;
                    });
                }
            });
        }
    });
    const platformIcons = {
        'VK Music': 'fab fa-vk', 'Spotify': 'fab fa-spotify', 'Apple Music': 'fab fa-apple',
        'YouTube Music': 'fab fa-youtube', 'Yandex Music': 'fas fa-music', 'SoundCloud': 'fab fa-soundcloud',
        'Deezer': 'fas fa-music', 'Other': 'fas fa-globe'
    };
    container.innerHTML = `
        <div class="analytics-grid">
            <div class="analytics-card">
                <h4 class="font-semibold text-white mb-md">Total Listens</h4>
                <div class="text-3xl font-bold text-white">${totalListens.toLocaleString()}</div>
                <div class="text-sm text-muted mt-sm">across all platforms</div>
            </div>
            <div class="analytics-card">
                <h4 class="font-semibold text-white mb-md">By Platform</h4>
                ${Object.keys(platformData).length === 0 ? '<p class="text-muted">No data yet</p>' : `
                <div class="platform-stats">
                    ${Object.entries(platformData).sort(([,a],[,b]) => b-a).map(([platform, count]) => `
                        <div class="platform-stat">
                            <div class="platform-name">
                                <div class="platform-icon"><i class="${platformIcons[platform] || 'fas fa-music'}"></i></div>
                                <span>${escapeHtml(platform)}</span>
                            </div>
                            <span class="listens-count">${count.toLocaleString()}</span>
                        </div>
                    `).join('')}
                </div>`}
            </div>
        </div>
    `;
}

// ========== Tariffs ==========
function loadTariffsPage() {
    const currentSubscriptionName = document.getElementById('currentSubscriptionName');
    const currentSubscriptionUntil = document.getElementById('currentSubscriptionUntil');
    const tariffsPageSubscriptionName = document.getElementById('tariffsPageSubscriptionName');
    const tariffsPageSubscriptionUntil = document.getElementById('tariffsPageSubscriptionUntil');

    if (!currentUser) return;
    const name = getTariffNameById(currentUser.tariff_id);
    const until = currentUser.tariff_until;
    let untilStr = '';
    if (until) {
        try { const d = new Date(until); untilStr = 'until ' + d.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) {}
    }
    if (currentSubscriptionName) currentSubscriptionName.textContent = name || 'Standard (Free)';
    if (currentSubscriptionUntil) currentSubscriptionUntil.textContent = untilStr ? `(active ${untilStr})` : '';
    if (tariffsPageSubscriptionName) tariffsPageSubscriptionName.textContent = name || 'Standard';
    if (tariffsPageSubscriptionUntil) tariffsPageSubscriptionUntil.textContent = untilStr;
    updateTariffButtons();
}

function getTariffNameById(id) {
    if (!id) return null;
    const n = parseInt(id, 10);
    if (n === 1) return 'Pro';
    if (n === 35) return 'Premium';
    return null;
}

function updateTariffButtons() {
    if (!currentUser) return;
    const tariffId = currentUser.tariff_id ? parseInt(currentUser.tariff_id, 10) : null;
    const btnPro = document.getElementById('tariffBtnPro');
    const btnPremium = document.getElementById('tariffBtnPremium');
    if (btnPro) {
        if (tariffId === 1) { btnPro.textContent = 'Current Plan'; btnPro.disabled = true; }
        else { btnPro.textContent = 'Choose Pro'; btnPro.disabled = false; }
    }
    if (btnPremium) {
        if (tariffId === 35) { btnPremium.textContent = 'Current Plan'; btnPremium.disabled = true; }
        else { btnPremium.textContent = 'Choose Premium'; btnPremium.disabled = false; }
    }
}

async function refreshCurrentUserProfile() {
    try {
        const r = await fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'profile' })
        });
        const d = await r.json().catch(() => ({}));
        if (r.ok && d.success && d.user) {
            currentUser.balance = typeof d.user.balance === 'number' ? d.user.balance : parseFloat(d.user.balance) || 0;
            if (d.user.nickname !== undefined) currentUser.nickname = d.user.nickname;
            if (d.user.email !== undefined) currentUser.email = d.user.email;
            if (d.user.vk !== undefined) currentUser.vk = d.user.vk;
            if (d.user.telegram !== undefined) currentUser.telegram = d.user.telegram;
            if (d.user.tariff_id !== undefined) currentUser.tariff_id = d.user.tariff_id ? parseInt(d.user.tariff_id, 10) : null;
            if (d.user.tariff_until !== undefined) currentUser.tariff_until = d.user.tariff_until;
            if (d.user.tariff_name !== undefined) currentUser.tariff_name = d.user.tariff_name || null;
            saveData();
            const balanceAmountEl = document.getElementById('balanceAmount');
            if (balanceAmountEl) balanceAmountEl.textContent = `₽${currentUser.balance.toFixed(2)}`;
            const dashBal = document.getElementById('dashboardBalance');
            if (dashBal) dashBal.textContent = `₽${currentUser.balance.toFixed(2)}`;
        }
    } catch (e) { console.warn('Could not update profile from server', e); }
}

// ========== Balance ==========
async function loadBalancePage() {
    await refreshCurrentUserProfile();
    document.getElementById('balanceAmount').textContent = `₽${(currentUser && typeof currentUser.balance === 'number' ? currentUser.balance : 0).toFixed(2)}`;
    document.getElementById('balanceDate').textContent = new Date().toLocaleDateString('en-US');
}

// ========== Subscriptions ==========
async function loadSubscriptionsPage() {
    await refreshCurrentUserProfile();
    const container = document.getElementById('subscriptionsContent');
    if (!container) return;
    const name = getTariffNameById(currentUser.tariff_id);
    const until = currentUser.tariff_until;
    if (!name) {
        container.innerHTML = `<div class="empty-state"><div class="empty-icon"><i class="fas fa-credit-card"></i></div><h4 class="empty-title">No active subscription</h4><p class="empty-description">Contact support to subscribe</p></div>`;
        return;
    }
    let untilStr = '';
    if (until) {
        try { const d = new Date(until); untilStr = 'Active until ' + d.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' }); } catch (e) {}
    }
    if (!untilStr) untilStr = 'Active until: —';
    container.innerHTML = `<div class="card p-lg" style="max-width: 400px;"><div class="flex items-center gap-md mb-md"><div class="stat-icon" style="width: 48px; height: 48px;"><i class="fas fa-crown"></i></div><div><div class="text-sm text-muted">Plan</div><div class="text-xl font-semibold text-white">${escapeHtml(name)}</div><div class="text-sm text-muted mt-1">${untilStr}</div></div></div><div class="flex items-center justify-between p-sm bg-black-90 rounded-lg"><span class="text-muted">Status</span><span class="text-green-400 font-medium">Active</span></div></div>`;
}

// ========== Support ==========
function loadSupportPage() {
    const userTickets = tickets.filter(t => t.userId === currentUser.id);
    const container = document.getElementById('ticketsList');
    if (userTickets.length === 0) {
        container.innerHTML = `<div class="empty-state"><div class="empty-icon"><i class="fas fa-comments"></i></div><h4 class="empty-title">No tickets</h4><p class="empty-description">You have no support tickets yet.</p><button class="btn btn-primary mt-md" onclick="openNewTicketModal()"><i class="fas fa-plus"></i> Create New Ticket</button></div>`;
    } else {
        container.innerHTML = `<div class="space-y-md">${userTickets.map(ticket => `<div class="card"><div class="flex items-center justify-between mb-md"><h4 class="font-semibold text-white">${escapeHtml(ticket.subject)}</h4><span class="text-sm ${getPriorityClass(ticket.priority)}">${getPriorityText(ticket.priority)}</span></div><p class="text-muted mb-md">${escapeHtml(ticket.message)}</p><div class="flex items-center justify-between text-sm"><span class="text-muted">${new Date(ticket.createdAt).toLocaleDateString('en-US')}</span><span class="${getStatusClass(ticket.status)}">${getTicketStatusText(ticket.status)}</span></div><button class="btn btn-secondary btn-sm mt-md" onclick="showTicketDetails(${ticket.id})"><i class="fas fa-eye"></i> View</button></div>`).join('')}</div>`;
    }
}

function openNewTicketModal() { document.getElementById('newTicketModal').style.display = 'block'; }
function closeNewTicketModal() {
    document.getElementById('newTicketModal').style.display = 'none';
    document.getElementById('ticketSubject').value = '';
    document.getElementById('ticketMessage').value = '';
}

async function submitTicket() {
    const subject = document.getElementById('ticketSubject').value.trim();
    const message = document.getElementById('ticketMessage').value.trim();
    const priority = document.getElementById('ticketPriority').value;
    if (!subject || !message) { showNotification('Error', 'Please fill in all fields', 'error'); return; }
    try {
        const response = await fetch('tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'create', subject, message, priority })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            showNotification('Error', (data && data.error) || 'Could not create ticket', 'error'); return;
        }
        await syncTicketsFromServer();
        showNotification('Success', 'Ticket created', 'success');
        closeNewTicketModal();
        loadSupportPage();
    } catch (e) { showNotification('Error', 'Could not connect to server', 'error'); }
}

function showTicketDetails(ticketId) {
    const ticket = tickets.find(t => t.id === ticketId);
    if (!ticket) return;
    const modal = document.getElementById('ticketDetailsModal');
    const content = document.getElementById('ticketDetailsContent');
    content.innerHTML = `<div class="space-y-md">
        <div class="card">
            <h4 class="font-semibold text-white mb-md">${escapeHtml(ticket.subject)}</h4>
            <div class="flex items-center justify-between mb-md">
                <span class="text-sm ${getPriorityClass(ticket.priority)}">${getPriorityText(ticket.priority)}</span>
                <span class="text-sm ${getStatusClass(ticket.status)}">${getTicketStatusText(ticket.status)}</span>
            </div>
            <p class="text-white mb-md">${escapeHtml(ticket.message)}</p>
            <div class="text-sm text-muted">
                <div>Date: ${new Date(ticket.createdAt).toLocaleDateString('en-US')}</div>
            </div>
        </div>
        <div class="card">
            <h5 class="font-semibold text-white mb-md">Replies (${ticket.responses ? ticket.responses.length : 0})</h5>
            ${!ticket.responses || ticket.responses.length === 0 ? '<p class="text-muted">No replies yet</p>' : ticket.responses.map(response => {
                const isSupport = response.isAdmin;
                const authorName = isSupport ? 'Support Team' : (response.authorName || 'You');
                return `<div class="ticket-response ${isSupport ? 'admin' : ''}">
                    <div class="font-medium text-white mb-sm">${escapeHtml(authorName)}</div>
                    <p class="text-white mb-sm">${escapeHtml(response.message)}</p>
                    <div class="text-xs text-muted">${new Date(response.createdAt).toLocaleDateString('en-US', {hour:'2-digit', minute:'2-digit'})}</div>
                </div>`;
            }).join('')}
        </div>
        ${ticket.status !== 'closed' ? `
        <div class="card">
            <h5 class="font-semibold text-white mb-md">Reply</h5>
            <div class="space-y-md">
                <div class="form-group">
                    <label class="form-label">Message *</label>
                    <textarea class="form-control" id="ticketResponse" rows="4" placeholder="Your reply..." required></textarea>
                </div>
                <div class="flex gap-md flex-wrap">
                    <button class="btn btn-primary" onclick="submitTicketResponse(${ticket.id})"><i class="fas fa-paper-plane"></i> Send Reply</button>
                    <button class="btn btn-secondary" onclick="closeTicketAction(${ticket.id})"><i class="fas fa-times-circle"></i> Close Ticket</button>
                </div>
            </div>
        </div>` : `
        <div class="card">
            <div class="text-center p-md">
                <p class="text-muted">This ticket is closed.</p>
            </div>
        </div>`}
    </div>`;
    document.getElementById('ticketDetailsTitle').textContent = escapeHtml(ticket.subject);
    modal.style.display = 'block';
}

async function submitTicketResponse(ticketId) {
    const message = document.getElementById('ticketResponse').value.trim();
    if (!message) { showNotification('Error', 'Enter a message', 'error'); return; }
    try {
        const response = await fetch('tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'response', ticket_id: ticketId, message })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            showNotification('Error', (data && data.error) || 'Could not send reply', 'error'); return;
        }
        await syncTicketsFromServer();
        showNotification('Success', 'Reply sent', 'success');
        showTicketDetails(ticketId);
    } catch (e) { showNotification('Error', 'Could not connect to server', 'error'); }
}

async function closeTicketAction(ticketId) {
    try {
        const response = await fetch('tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'close', ticket_id: ticketId })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            showNotification('Error', (data && data.error) || 'Could not close ticket', 'error'); return;
        }
        await syncTicketsFromServer();
        showNotification('Success', 'Ticket closed', 'success');
        closeTicketDetails();
    } catch (e) { showNotification('Error', 'Could not connect to server', 'error'); }
}

function closeTicketDetails() { document.getElementById('ticketDetailsModal').style.display = 'none'; }

function getPriorityText(priority) {
    switch(priority) {
        case 'low': return 'Low';
        case 'medium': return 'Medium';
        case 'high': return 'High';
        default: return priority;
    }
}

function getPriorityClass(priority) {
    switch(priority) {
        case 'low': return 'text-blue-400';
        case 'medium': return 'text-green-400';
        case 'high': return 'text-yellow-400';
        default: return 'text-gray-400';
    }
}

function getTicketStatusText(status) {
    switch(status) {
        case 'open': return 'Open';
        case 'closed': return 'Closed';
        case 'pending': return 'Processing';
        default: return status;
    }
}

function getStatusClass(status) {
    switch(status) {
        case 'open': return 'text-green-400';
        case 'closed': return 'text-gray-400';
        case 'pending': return 'text-yellow-400';
        default: return 'text-gray-400';
    }
}

// ========== Profile ==========
function loadProfilePage() {
    document.getElementById('profileEmail').value = currentUser.email;
    document.getElementById('profileRole').value = 'User';
    document.getElementById('profileNickname').value = currentUser.nickname || '';
    document.getElementById('profileVK').value = currentUser.vk || '';
    document.getElementById('profileTelegram').value = currentUser.telegram || '';
    const nicknameEl = document.getElementById('profileNickname');
    const hintEl = document.getElementById('profileNicknameHint');
    if (nicknameEl) nicknameEl.readOnly = true;
    if (hintEl) hintEl.textContent = 'Artist name can only be changed via support.';
    const createdAtEl = document.getElementById('profileCreatedAt');
    if (createdAtEl) {
        if (currentUser.createdAt) {
            const createdAt = new Date(currentUser.createdAt);
            createdAtEl.textContent = createdAt.toLocaleDateString('en-US') + ', ' + createdAt.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
        } else {
            createdAtEl.textContent = '—';
        }
    }
}

async function saveProfile() {
    const vk = document.getElementById('profileVK').value.trim();
    const telegram = document.getElementById('profileTelegram').value.trim();
    try {
        const r = await fetch('profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ action: 'updateUserProfile', vk, telegram })
        });
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.success) { showNotification('Error', (d && d.error) || 'Could not update profile', 'error'); return; }
        currentUser.vk = vk;
        currentUser.telegram = telegram;
        saveData();
        showNotification('Success', 'Profile updated', 'success');
    } catch (e) { showNotification('Error', 'Could not connect to server', 'error'); }
}

// ========== Tools ==========
function loadToolsPage() {}

// ========== Notifications ==========
function toggleUserMenu() { document.getElementById('userMenu').classList.toggle('hidden'); }

function showNotification(title, message, type = 'success') {
    const notification = document.getElementById('notification');
    const notificationTitle = document.getElementById('notificationTitle');
    const notificationMessage = document.getElementById('notificationMessage');
    const notificationIcon = notification.querySelector('.notification-icon i');
    notification.className = `notification notification-${type}`;
    const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    notificationIcon.className = `fas ${icons[type] || 'fa-info-circle'}`;
    notificationTitle.textContent = title;
    notificationMessage.textContent = message;
    notification.classList.remove('hidden');
    setTimeout(() => { notification.classList.add('hidden'); }, 5000);
}

function closeNotification() { document.getElementById('notification').classList.add('hidden'); }

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ========== Init ==========
async function init() {
    fetchUsdRubRate();
    setInterval(fetchUsdRubRate, 60 * 60 * 1000);
    if (!localStorage.getItem('wm_demo_setup')) { initData(); }
    loadData();

    document.getElementById('loginForm').addEventListener('submit', login);
    document.getElementById('registerForm').addEventListener('submit', register);

    let serverSessionValid = false;
    if (currentUser) {
        try {
            const r = await fetch('profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'profile' })
            });
            const d = await r.json().catch(() => ({}));
            if (r.ok && d.success && d.user) {
                currentUser.role = d.user.role || 'user';
                currentUser.balance = typeof d.user.balance === 'number' ? d.user.balance : parseFloat(d.user.balance) || 0;
                if (d.user.nickname !== undefined) currentUser.nickname = d.user.nickname;
                if (d.user.email !== undefined) currentUser.email = d.user.email;
                if (d.user.vk !== undefined) currentUser.vk = d.user.vk;
                if (d.user.telegram !== undefined) currentUser.telegram = d.user.telegram;
                if (d.user.tariff_id !== undefined) currentUser.tariff_id = d.user.tariff_id ? parseInt(d.user.tariff_id, 10) : null;
                if (d.user.tariff_until !== undefined) currentUser.tariff_until = d.user.tariff_until;
                if (d.user.tariff_name !== undefined) currentUser.tariff_name = d.user.tariff_name || null;
                saveData();
                serverSessionValid = true;
            } else {
                currentUser = null;
                localStorage.removeItem('wm_current_user');
            }
        } catch (e) {
            currentUser = null;
            localStorage.removeItem('wm_current_user');
        }
    }

    if (serverSessionValid && currentUser) {
        try { await syncReleasesFromServer(); } catch (e) {}
        try { await syncTicketsFromServer(); } catch (e) {}
        showApp();
        loadDashboardData();
        updateSidebarUser();
    } else {
        showAuth();
    }

    document.querySelectorAll('.modal, .release-details-modal').forEach(modal => {
        modal.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });

    const coverDropzone = document.getElementById('coverDropzone');
    if (coverDropzone) {
        coverDropzone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('dragover'); });
        coverDropzone.addEventListener('dragleave', function(e) { e.preventDefault(); this.classList.remove('dragover'); });
        coverDropzone.addEventListener('drop', function(e) {
            e.preventDefault(); this.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const file = files[0];
                const input = document.getElementById('coverUpload');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                input.files = dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    if (serverSessionValid && currentUser) {
        const _urlParams = new URLSearchParams(location.search);
        const _releaseId = parseInt(_urlParams.get('release'), 10);
        if (_releaseId) {
            setTimeout(() => {
                const found = releases.find(r => r.id === _releaseId && r.userId === currentUser.id);
                if (found) {
                    showReleaseDetails(_releaseId);
                } else {
                    showNotification('Error', 'Release not found or access denied', 'error');
                    history.replaceState({}, '', location.pathname);
                }
            }, 400);
        }
    }

    window.addEventListener('popstate', (e) => {
        const modal = document.getElementById('releaseDetailsModal');
        if (e.state && e.state.releaseId) {
            showReleaseDetails(e.state.releaseId);
        } else if (modal && modal.style.display === 'block') {
            stopAllAudio();
            modal.style.display = 'none';
        }
    });
}

function updateSidebarUser() {
    if (!currentUser) return;
    const userAvatar = document.getElementById('userAvatar');
    const userName = document.getElementById('userName');
    const userEmail = document.getElementById('userEmail');
    const welcomeUser = document.getElementById('welcomeUser');
    if (userAvatar) userAvatar.textContent = (currentUser.nickname || currentUser.email || 'U')[0].toUpperCase();
    if (userName) userName.textContent = currentUser.nickname || currentUser.email;
    if (userEmail) userEmail.textContent = currentUser.email;
    if (welcomeUser) welcomeUser.textContent = currentUser.nickname || currentUser.email;
}

if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }

// ========================================================
// ДОПОЛНИТЕЛЬНЫЙ ФУНКЦИОНАЛ (уведомления, история входов, запись входа)
// ========================================================

(function() {
    'use strict';

    if (typeof api !== 'function') {
        window.api = async function(payload) {
            var r = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            return r.json();
        };
    }

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmt(d) {
        if (!d) return '—';
        var dt = new Date(d);
        return dt.toLocaleDateString('en-US',{day:'2-digit',month:'short',year:'numeric'}) + ' ' + dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    }

    function isUserLoggedIn() {
        var container = document.getElementById('appContainer');
        return container && container.style.display !== 'none';
    }

    async function checkNotifications() {
        // Попап-уведомления теперь обрабатываются в index.html (checkPopup)
        return;
    }

    function showNotificationPopup(notif) {
        var container = document.getElementById('notificationPopupContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notificationPopupContainer';
            document.body.appendChild(container);
        }
        container.innerHTML = '';

        var overlay = document.createElement('div');
        overlay.className = 'notif-overlay';
        var box = document.createElement('div');
        box.className = 'notif-box';

        var closeBtn = document.createElement('button');
        closeBtn.className = 'notif-close';
        closeBtn.innerHTML = '✕';
        closeBtn.onclick = function() {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            markNotificationViewed(notif.id);
        };

        if (notif.emoji) {
            var emoji = document.createElement('div');
            emoji.className = 'notif-emoji';
            emoji.textContent = notif.emoji;
            box.appendChild(emoji);
        }

        var msg = document.createElement('div');
        msg.className = 'notif-message';
        msg.textContent = notif.message;
        box.appendChild(msg);

        if (notif.image_url) {
            var img = document.createElement('img');
            img.className = 'notif-image';
            img.src = notif.image_url;
            img.alt = 'Notification image';
            box.appendChild(img);
        }

        box.appendChild(closeBtn);
        overlay.appendChild(box);
        container.appendChild(overlay);
    }

    async function markNotificationViewed(notifId) {
        try {
            await api({ action: 'markNotificationViewed', notification_id: notifId });
        } catch (e) {
            console.warn('[NOTIF] Error:', e);
        }
    }

    async function loadLoginHistory() {
        var container = document.getElementById('loginHistoryList');
        if (!container) return;
        container.innerHTML = '<p class="text-muted">Loading...</p>';
        try {
            var res = await api({ action: 'getLoginHistory' });
            if (!res.success || !res.history || !res.history.length) {
                container.innerHTML = '<p class="text-muted">No login records yet.</p>';
                return;
            }
            var html = '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
            res.history.forEach(function(entry) {
                var date = '';
                try { date = new Date(entry.created_at).toLocaleString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); } catch(e){ date = entry.created_at; }
                var ua = (entry.user_agent || '').substring(0, 60);
                html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:0.65rem 0.85rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">'
                    + '<div><div style="color:#fff;font-family:monospace;font-size:0.85rem;">' + esc(entry.ip_address || '') + '</div>'
                    + '<div class="text-xs text-muted">' + esc(ua) + '</div></div>'
                    + '<div class="text-xs text-muted">' + date + '</div></div>';
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = '<p class="text-muted">Failed to load history.</p>';
        }
    }

    async function recordLogin() {
        if (!isUserLoggedIn()) return;
        try {
            var res = await api({ action: 'recordLogin' });
            // Если IP сменился — запросить код 2FA на почту
            if (res && res.ip_changed) {
                await trigger2FA();
            }
        } catch (e) {
            console.warn('[LOGIN] Error:', e);
        }
    }

    async function trigger2FA() {
        try {
            var sent = await api({ action: 'send2FACode' });
            if (!sent.success) return;
            // Показываем модалку ввода кода
            show2FAModal(sent.email || 'your email');
        } catch (e) {}
    }

    function show2FAModal(email) {
        // Создаём модалку если её нет
        var existing = document.getElementById('twofaModal');
        if (existing) existing.remove();
        var modal = document.createElement('div');
        modal.id = 'twofaModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
        modal.innerHTML = '<div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:2rem;max-width:380px;width:100%;text-align:center;">'
            + '<i class="fas fa-shield-alt" style="font-size:2.5rem;color:#6366f1;margin-bottom:1rem;"></i>'
            + '<h3 style="color:#fff;margin-bottom:0.5rem;">New Login Detected</h3>'
            + '<p class="text-muted" style="font-size:0.9rem;margin-bottom:1.25rem;">We detected a login from a new IP address. A verification code was sent to ' + esc(email) + '</p>'
            + '<input type="text" id="twofaCodeInput" placeholder="000000" maxlength="6" style="width:100%;background:#0a0a0a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;font-size:1.5rem;text-align:center;letter-spacing:0.3em;padding:0.75rem;font-family:monospace;margin-bottom:0.5rem;">'
            + '<div id="twofaErr" style="color:#ef4444;font-size:0.8rem;min-height:18px;margin-bottom:0.75rem;"></div>'
            + '<button onclick="submit2FACode()" style="width:100%;background:#6366f1;color:#fff;border:none;border-radius:10px;padding:0.75rem;font-weight:600;cursor:pointer;">Verify</button>'
            + '</div>';
        document.body.appendChild(modal);
        setTimeout(function(){ var i=document.getElementById('twofaCodeInput'); if(i)i.focus(); }, 100);
    }

    window.submit2FACode = async function() {
        var code = (document.getElementById('twofaCodeInput').value || '').trim();
        var err = document.getElementById('twofaErr');
        if (!code) { if(err) err.textContent = 'Enter the code'; return; }
        try {
            var res = await api({ action: 'verify2FACode', code: code });
            if (res.success) {
                var m = document.getElementById('twofaModal');
                if (m) m.remove();
            } else {
                if(err) err.textContent = res.error || 'Invalid code';
            }
        } catch (e) {
            if(err) err.textContent = 'Connection error';
        }
    };

    if (typeof showPage === 'function') {
        var originalShowPage = window.showPage;
        window.showPage = function(name) {
            originalShowPage(name);
            if (name === 'profile') {
                setTimeout(loadLoginHistory, 100);
            }
            if (name === 'news' && typeof window.loadNews === 'function') {
                setTimeout(window.loadNews, 50);
            }
            if (name === 'balance' && typeof window.loadWithdrawalHistory === 'function') {
                setTimeout(window.loadWithdrawalHistory, 100);
            }
            if (name === 'calendar' && typeof window.loadCalendarPage === 'function') {
                setTimeout(window.loadCalendarPage, 100);
            }
            if (name === 'drafts' && typeof window.loadDraftsPage === 'function') {
                setTimeout(window.loadDraftsPage, 100);
            }
        };
    }

    function init() {
        setTimeout(checkNotifications, 800);
        setInterval(checkNotifications, 30000);

        if (isUserLoggedIn()) {
            if (!sessionStorage.getItem('login_recorded')) {
                recordLogin();
                sessionStorage.setItem('login_recorded', '1');
            }
        }

        document.addEventListener('userLoggedIn', function() {
            recordLogin();
            sessionStorage.removeItem('login_recorded');
            checkNotifications();
        });

        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.attributeName === 'style') {
                    var container = document.getElementById('appContainer');
                    if (container && container.style.display !== 'none' && !sessionStorage.getItem('login_recorded')) {
                        recordLogin();
                        sessionStorage.setItem('login_recorded', '1');
                        checkNotifications();
                        observer.disconnect();
                    }
                }
            });
        });
        var target = document.getElementById('appContainer');
        if (target) {
            observer.observe(target, { attributes: true });
        }

        console.log('[INIT] Extra functions initialized');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
