<?php
session_start();
$ADMIN_PASS = 'Quebot33##';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASS) {
        $_SESSION['admin_auth'] = true;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin.php');
    exit;
}

$authenticated = isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QueBot Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; }
        
        /* Login */
        .login-container {
            display: flex; align-items: center; justify-content: center; min-height: 100vh;
        }
        .login-box {
            background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            width: 360px; text-align: center;
        }
        .login-box h1 { font-size: 24px; margin-bottom: 8px; color: #1F3A5F; }
        .login-box p { color: #666; margin-bottom: 24px; font-size: 14px; }
        .login-box input {
            width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px;
            font-size: 16px; margin-bottom: 16px; outline: none;
        }
        .login-box input:focus { border-color: #1F3A5F; }
        .login-box button {
            width: 100%; padding: 12px; background: #1F3A5F; color: white; border: none;
            border-radius: 8px; font-size: 16px; cursor: pointer; font-weight: 600;
        }
        .login-box button:hover { background: #2a4f7f; }
        
        /* Dashboard */
        .header {
            background: #1F3A5F; color: white; padding: 16px 24px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { font-size: 20px; font-weight: 600; }
        .header a { color: rgba(255,255,255,0.7); text-decoration: none; font-size: 14px; }
        .header a:hover { color: white; }
        
        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px; padding: 24px; max-width: 1200px; margin: 0 auto;
        }
        .stat-card {
            background: white; border-radius: 10px; padding: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .stat-card .label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #1F3A5F; margin-top: 4px; }
        
        .content { max-width: 1200px; margin: 0 auto; padding: 0 24px 24px; }
        
        .tabs {
            display: flex; gap: 4px; margin-bottom: 16px; background: white;
            border-radius: 10px; padding: 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .tab {
            padding: 10px 20px; border: none; background: transparent; cursor: pointer;
            border-radius: 8px; font-size: 14px; font-weight: 500; color: #666;
        }
        .tab.active { background: #1F3A5F; color: white; }
        .tab:hover:not(.active) { background: #f0f2f5; }
        
        .table-wrap {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f7f8fa; padding: 12px 16px; text-align: left; font-weight: 600; color: #555; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 16px; border-top: 1px solid #f0f0f0; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        tr:hover td { background: #fafbfc; }
        
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;
        }
        .badge-open { background: #e8f5e9; color: #2e7d32; }
        .badge-closed { background: #fce4ec; color: #c62828; }
        .badge-user { background: #e3f2fd; color: #1565c0; }
        .badge-assistant { background: #f3e5f5; color: #7b1fa2; }
        .badge-anon { background: #fff3e0; color: #e65100; }
        .badge-google { background: #e8f5e9; color: #2e7d32; }
        .profile-link { color: #1F3A5F; cursor: pointer; text-decoration: underline; }
        .profile-detail { background: #f7f8fa; padding: 12px; border-radius: 8px; margin: 8px 0; font-size: 13px; }
        .profile-detail dt { font-weight: 600; color: #1F3A5F; margin-top: 8px; }
        .profile-detail dd { margin-left: 12px; color: #444; }
        
        .loading { text-align: center; padding: 40px; color: #888; }
        .empty { text-align: center; padding: 40px; color: #aaa; }
        
        .detail-link { color: #1F3A5F; cursor: pointer; text-decoration: underline; }
        
        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 100; align-items: center; justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: white; border-radius: 12px; padding: 24px; width: 90%; max-width: 700px;
            max-height: 80vh; overflow-y: auto;
        }
        .modal h3 { margin-bottom: 16px; color: #1F3A5F; }
        .modal .close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; color: #888; }
        .msg-bubble { margin: 8px 0; padding: 10px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5; }
        .msg-user { background: rgba(31,58,95,0.1); margin-left: 40px; }
        .msg-assistant { background: #f7f8fa; margin-right: 40px; }
        .msg-meta { font-size: 11px; color: #999; margin-top: 4px; }
    </style>
</head>
<body>

<?php if (!$authenticated): ?>
<div class="login-container">
    <form class="login-box" method="POST">
        <h1>Q! Admin</h1>
        <p>Panel de administración de QueBot</p>
        <input type="password" name="password" placeholder="Contraseña" autofocus>
        <button type="submit">Entrar</button>
    </form>
</div>

<?php else: ?>
<div class="header">
    <h1>Q! Admin Dashboard</h1>
    <a href="?logout=1">Cerrar sesión</a>
</div>

<div class="stats" id="stats">
    <div class="stat-card"><div class="label">Usuarios</div><div class="value" id="stat-users">-</div></div>
    <div class="stat-card"><div class="label">Casos</div><div class="value" id="stat-cases">-</div></div>
    <div class="stat-card"><div class="label">Mensajes</div><div class="value" id="stat-messages">-</div></div>
    <div class="stat-card"><div class="label">Runs</div><div class="value" id="stat-runs">-</div></div>
</div>

<div class="content">
    <div class="tabs">
        <button class="tab active" onclick="showTab('cases')">Casos</button>
        <button class="tab" onclick="showTab('users')">Usuarios</button>
        <button class="tab" onclick="showTab('messages')">Mensajes</button>
        <button class="tab" onclick="showTab('runs')">Runs</button>
    </div>
    <div class="table-wrap" id="table-container">
        <div class="loading">Cargando datos...</div>
    </div>
</div>

<!-- Message detail modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <button class="close" onclick="closeModal()">✕</button>
        <h3 id="modal-title">Mensajes del caso</h3>
        <div id="modal-body"></div>
    </div>
</div>

<script>
const API_KEY = 'AIzaSyC2Ud-lC4jnCQIwA5QyufVczfiovGHZRXI';
const PROJECT = 'quebot-2d931';
const BASE = `https://firestore.googleapis.com/v1/projects/${PROJECT}/databases/(default)/documents`;

let allData = { cases: [], users: [], messages: [], runs: [] };
let currentTab = 'cases';

// Firestore value parser
function parseVal(v) {
    if (!v) return '';
    if (v.stringValue !== undefined) return v.stringValue;
    if (v.integerValue !== undefined) return parseInt(v.integerValue);
    if (v.doubleValue !== undefined) return v.doubleValue;
    if (v.booleanValue !== undefined) return v.booleanValue ? '✅' : '❌';
    if (v.timestampValue) return new Date(v.timestampValue).toLocaleString('es-CL');
    if (v.nullValue !== undefined) return '-';
    if (v.mapValue) return JSON.stringify(Object.fromEntries(Object.entries(v.mapValue.fields || {}).map(([k,val]) => [k, parseVal(val)])));
    if (v.arrayValue) return (v.arrayValue.values || []).map(parseVal).join(', ');
    return JSON.stringify(v);
}

function parseDoc(doc) {
    const id = doc.name.split('/').pop();
    const fields = {};
    for (const [k, v] of Object.entries(doc.fields || {})) {
        fields[k] = parseVal(v);
    }
    return { id, ...fields };
}

async function fetchCollection(name, pageSize = 300) {
    const docs = [];
    let pageToken = '';
    do {
        const url = `${BASE}/${name}?key=${API_KEY}&pageSize=${pageSize}${pageToken ? '&pageToken=' + pageToken : ''}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.documents) docs.push(...data.documents.map(parseDoc));
        pageToken = data.nextPageToken || '';
    } while (pageToken);
    return docs;
}

async function loadAll() {
    const [cases, users, messages, runs] = await Promise.all([
        fetchCollection('cases'),
        fetchCollection('users'),
        fetchCollection('messages'),
        fetchCollection('runs')
    ]);
    allData = { cases, users, messages, runs };
    
    document.getElementById('stat-users').textContent = users.length;
    document.getElementById('stat-cases').textContent = cases.length;
    document.getElementById('stat-messages').textContent = messages.length;
    document.getElementById('stat-runs').textContent = runs.length;
    
    renderTable();
}

function showTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    renderTable();
}

function renderTable() {
    const data = allData[currentTab];
    const container = document.getElementById('table-container');
    
    if (!data || data.length === 0) {
        container.innerHTML = '<div class="empty">No hay datos</div>';
        return;
    }
    
    // Sort by created_at desc if available
    data.sort((a, b) => {
        const da = a.created_at || a.timestamp || '';
        const db = b.created_at || b.timestamp || '';
        return db.localeCompare(da);
    });
    
    let cols;
    switch(currentTab) {
        case 'cases':
            cols = ['id', 'user_id', 'status', 'channel', 'vertical', 'created_at', 'messages'];
            break;
        case 'users':
            cols = ['id', 'tipo', 'display_name', 'email', 'last_seen_at', 'casos', 'perfil'];
            break;
        case 'messages':
            cols = ['id', 'case_id', 'role', 'text', 'created_at'];
            break;
        case 'runs':
            cols = ['id', 'case_id', 'provider', 'models', 'timing_ms', 'cost_estimate', 'created_at'];
            break;
    }
    
    // Computed columns always shown regardless of data
    const computedCols = ['tipo', 'casos', 'perfil', 'messages'];
    const availCols = cols.filter(c => computedCols.includes(c) || data.some(row => row[c] !== undefined && row[c] !== ''));
    
    let html = '<table><thead><tr>';
    availCols.forEach(c => html += `<th>${c}</th>`);
    html += '</tr></thead><tbody>';
    
    data.forEach(row => {
        html += '<tr>';
        availCols.forEach(c => {
            let val = row[c] !== undefined ? row[c] : '-';
            
            // Special formatting
            if (c === 'status') {
                const cls = val === 'open' ? 'badge-open' : 'badge-closed';
                val = `<span class="badge ${cls}">${val}</span>`;
            } else if (c === 'role') {
                const cls = val === 'user' ? 'badge-user' : 'badge-assistant';
                val = `<span class="badge ${cls}">${val}</span>`;
            } else if (c === 'text' && typeof val === 'string' && val.length > 80) {
                val = val.substring(0, 80) + '...';
            } else if (c === 'perfil' && currentTab === 'users') {
                const sp = row.search_profile;
                if (sp && sp !== '-') {
                    val = '<span class="profile-link" onclick="showProfile(\'' + row.id.replace(/'/g, "\\'") + '\')">Ver perfil</span>';
                } else {
                    val = '<span style="color:#aaa">Sin perfil</span>';
                }
            } else if (c === 'tipo' && currentTab === 'users') {
                const provider = row.auth_provider || '';
                const email = row.email || '';
                if (provider === 'google.com' || email.includes('@')) {
                    val = '<span class="badge badge-google">Google</span>';
                } else {
                    val = '<span class="badge badge-anon">Anónimo</span>';
                }
            } else if (c === 'casos' && currentTab === 'users') {
                const count = allData.cases.filter(cs => cs.user_id === row.id).length;
                val = count;
            } else if (c === 'messages' && currentTab === 'cases') {
                val = `<span class="detail-link" onclick="showMessages('${row.id}')">Ver mensajes</span>`;
            } else if (c === 'id' && typeof val === 'string' && val.length > 12) {
                val = val.substring(0, 12) + '...';
            } else if (c === 'user_id' && typeof val === 'string' && val.length > 12) {
                val = val.substring(0, 12) + '...';
            } else if (c === 'case_id' && typeof val === 'string' && val.length > 12) {
                val = val.substring(0, 12) + '...';
            }
            
            html += `<td title="${String(row[c] || '').replace(/"/g, '&quot;')}">${val}</td>`;
        });
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function showMessages(caseId) {
    const msgs = allData.messages
        .filter(m => m.case_id === caseId)
        .sort((a, b) => (a.created_at || '').localeCompare(b.created_at || ''));
    
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    document.getElementById('modal-title').textContent = `Caso: ${caseId.substring(0, 16)}...`;
    
    if (msgs.length === 0) {
        body.innerHTML = '<p style="color:#888">No se encontraron mensajes para este caso</p>';
    } else {
        body.innerHTML = msgs.map(m => `
            <div class="msg-bubble msg-${m.role || 'user'}">
                <strong>${m.role || '?'}</strong>: ${(m.text || '').substring(0, 500)}${(m.text || '').length > 500 ? '...' : ''}
                <div class="msg-meta">${m.created_at || '-'}</div>
            </div>
        `).join('');
    }
    
    modal.classList.add('show');
}

function closeModal() {
    document.getElementById('modal').classList.remove('show');
}

document.getElementById('modal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('modal')) closeModal();
});


function showProfile(userId) {
    const user = allData.users.find(u => u.id === userId);
    if (!user) return;
    
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    document.getElementById('modal-title').textContent = user.display_name || user.email || 'Usuario ' + userId.substring(0, 12);
    
    let html = '<div class="profile-detail">';
    
    // User info
    html += '<h4 style="margin-bottom:12px;color:#1F3A5F">Información del usuario</h4>';
    html += '<dl>';
    html += '<dt>ID</dt><dd>' + userId + '</dd>';
    html += '<dt>Tipo</dt><dd>' + (user.auth_provider === 'google.com' || (user.email && user.email.includes('@')) ? '🟢 Google' : '🟡 Anónimo') + '</dd>';
    if (user.email) html += '<dt>Email</dt><dd>' + user.email + '</dd>';
    if (user.display_name) html += '<dt>Nombre</dt><dd>' + user.display_name + '</dd>';
    if (user.last_seen_at) html += '<dt>Última visita</dt><dd>' + user.last_seen_at + '</dd>';
    html += '<dt>Casos</dt><dd>' + allData.cases.filter(c => c.user_id === userId).length + '</dd>';
    html += '<dt>Mensajes</dt><dd>' + allData.messages.filter(m => {
        const userCases = allData.cases.filter(c => c.user_id === userId).map(c => c.id);
        return userCases.includes(m.case_id);
    }).length + '</dd>';
    html += '</dl>';
    
    // Search profile
    const sp = user.search_profile;
    if (sp && sp !== '-') {
        html += '<h4 style="margin:16px 0 12px;color:#1F3A5F">Perfil de búsqueda</h4>';
        html += '<dl>';
        try {
            const profile = typeof sp === 'string' ? JSON.parse(sp) : sp;
            const labels = {
                locations: '📍 Ubicaciones',
                property_types: '🏠 Tipos de propiedad',
                bedrooms: '🛏️ Dormitorios',
                bathrooms: '🚿 Baños',
                budget: '💰 Presupuesto',
                min_area_m2: '📐 Área mínima (m²)',
                purpose: '🎯 Propósito',
                family_info: '👨‍👩‍👧 Info familiar',
                key_requirements: '📋 Requisitos clave',
                top_searches: '🔍 Búsquedas principales'
            };
            for (const [key, value] of Object.entries(profile)) {
                const label = labels[key] || key;
                let display = Array.isArray(value) ? value.join(', ') : String(value);
                html += '<dt>' + label + '</dt><dd>' + display + '</dd>';
            }
        } catch(e) {
            html += '<dt>Raw</dt><dd>' + sp + '</dd>';
        }
        html += '</dl>';
    } else {
        html += '<p style="color:#aaa;margin-top:16px">Este usuario aún no tiene perfil de búsqueda.</p>';
    }
    
    html += '</div>';
    body.innerHTML = html;
    modal.classList.add('show');
}

loadAll();
</script>

<?php endif; ?>
</body>
</html>
