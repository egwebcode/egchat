<?php
// EG CHAT - index.php
// Single-file PHP chat intended for local/dev use (not hardened for production).
// Features:
// - Mobile-first WhatsApp-like UI
// - Uses users.json and msg.json in the same folder
// - Endpoints: ?action=get_messages, ?action=send, ?action=get_users
// - Basic XSS protection: all stored and returned message content is escaped with htmlspecialchars()
// - File writes use locking (flock)
// - Client-side converts YouTube links to safe embedded iframes and makes other links clickable
// - Branding: EG CHAT

header('Content-Type: text/html; charset=utf-8');

// Simple router for AJAX endpoints
if (php_sapi_name() !== 'cli' && isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'get_messages') {
        send_json_safe(read_json_file('msg.json'));
    } elseif ($action === 'get_users') {
        send_json_safe(read_json_file('users.json'));
    } elseif ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Receive JSON payload
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $name = isset($data['name']) ? trim($data['name']) : '';
        $text = isset($data['text']) ? trim($data['text']) : '';
        if ($name === '' || $text === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Nome e mensagem são obrigatórios']);
            exit;
        }
        // Limit lengths
        $name = mb_substr($name, 0, 64);
        $text = mb_substr($text, 0, 2000);
        // Escape to prevent stored XSS
        $safe_name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe_text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $messages = read_json_file('msg.json');
        $users = read_json_file('users.json');
        $timestamp = time();
        $id = uniqid('', true);
        $msg = [
            'id' => $id,
            'name' => $safe_name,
            'text' => $safe_text,
            'ts' => $timestamp
n        ];
        $messages[] = $msg;
        if (!in_array($safe_name, $users)) {
            $users[] = $safe_name;
        }
        if (write_json_file('msg.json', $messages) && write_json_file('users.json', $users)) {
            send_json_safe(['ok' => true, 'msg' => $msg]);
        } else {
            http_response_code(500);
            send_json_safe(['error' => 'Falha ao gravar arquivos']);
        }
    }
    exit;
}

// Helper functions
function read_json_file($file)
{
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    return $data;
}

function write_json_file($file, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    $written = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $written !== false;
}

function send_json_safe($data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
?><!doctype html>

<html lang="pt-br">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1" />
<title>EG CHAT — Mobile</title>
<style>
/* Mobile-first WhatsApp-like simple UI */
:root{--bg:#e5ddd5;--me:#dcf8c6;--other:#fff;--accent:#075e54}
html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial}
.container{display:flex;flex-direction:column;height:100vh;background:var(--bg)}
.header{height:56px;background:var(--accent);color:white;display:flex;align-items:center;padding:0 12px;gap:10px}
.header .title{font-weight:700}
.chat{flex:1;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px}
.footer{padding:8px;background:transparent;display:flex;gap:8px;align-items:center}
.input{flex:1;display:flex;gap:8px}
.input input{flex:1;border-radius:20px;padding:10px 14px;border:1px solid rgba(0,0,0,0.15);outline:none}
.btn{background:var(--accent);color:white;border:none;padding:10px 12px;border-radius:8px}
.msg{max-width:85%;padding:8px 10px;border-radius:12px;box-shadow:0 1px 0 rgba(0,0,0,0.06)}
.msg.me{align-self:flex-end;background:var(--me)}
.msg.other{align-self:flex-start;background:var(--other)}
.meta{font-size:12px;color:#666;margin-top:6px}
.small{font-size:11px;color:#666}
.name-badge{font-weight:700;margin-bottom:4px}
.modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center}
.modal .card{background:white;padding:18px;border-radius:12px;max-width:360px;width:90%}
.brand{font-weight:800}
.system-note{font-size:12px;color:#333;background:transparent;text-align:center;margin:8px 0}
.link{word-break:break-all}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px">
      <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='28' height='28'><rect width='28' height='28' rx='6' fill='%23075e54'/><text x='50%' y='55%' font-size='14' text-anchor='middle' fill='white' font-family='Arial' font-weight='700'>EG</text></svg>" alt="logo" style="width:36px;height:36px;border-radius:8px;"/>
    </div>
    <div class="title">EG CHAT</div>
  </div>
  <div id="chat" class="chat" role="log" aria-live="polite"></div>
  <div class="footer">
    <div class="input">
      <input id="msgInput" placeholder="Digite uma mensagem ou cole link (YouTube funciona)" autocomplete="off" />
    </div>
    <button id="sendBtn" class="btn">Enviar</button>
  </div>
</div><!-- Name modal --><div id="nameModal" class="modal" style="display:none">
  <div class="card">
    <h3>Bem-vindo ao <span class="brand">EG CHAT</span></h3>
    <p>Digite seu nome para começar (apenas caracteres simples)</p>
    <input id="nameInput" placeholder="Seu nome" style="width:100%;padding:8px;margin:8px 0;border-radius:8px;border:1px solid #ddd" />
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button id="startBtn" class="btn">Entrar</button>
    </div>
  </div>
</div><script>
// Basic client logic
const CHAT = document.getElementById('chat');
const msgInput = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const nameModal = document.getElementById('nameModal');
const nameInput = document.getElementById('nameInput');
const startBtn = document.getElementById('startBtn');

let username = localStorage.getItem('egchat_name') || '';
if (!username) { nameModal.style.display = 'flex'; nameInput.focus(); }
else initialize();

startBtn.addEventListener('click', () => { const v = nameInput.value.trim(); if (v.length<1) return; username = v.substring(0,64); localStorage.setItem('egchat_name', username); nameModal.style.display='none'; initialize(); });
nameInput.addEventListener('keydown', e=>{ if(e.key==='Enter') startBtn.click(); });

function initialize(){ fetchMessages(); fetchUsers(); setInterval(fetchMessages, 2000); }

sendBtn.addEventListener('click', sendMessage);
msgInput.addEventListener('keydown', e=>{ if (e.key==='Enter') sendMessage(); });

function sendMessage(){ const text = msgInput.value.trim(); if (!text) return; const payload = { name: username, text }; fetch('?action=send', { method:'POST', body: JSON.stringify(payload), headers:{'Content-Type':'application/json'} }).then(r=>r.json()).then(res=>{ if(res.ok){ msgInput.value=''; fetchMessages(); } else { alert(res.error||'Erro'); } }).catch(()=>alert('Erro de rede')); }

async function fetchMessages(){ try{
    const res = await fetch('?action=get_messages');
    const data = await res.json();
    renderMessages(data);
  } catch(e){ console.error(e); }
}

async function fetchUsers(){ try{ await fetch('?action=get_users'); } catch(e){} }

// Render messages array (assumes server already escaped content with htmlspecialchars)
function renderMessages(messages){
  CHAT.innerHTML = '';
  messages = messages || [];
  messages.sort((a,b)=>a.ts - b.ts);
  for(const m of messages){
    const el = document.createElement('div');
    el.className = 'msg ' + (m.name === escapeHtml(username) ? 'me' : 'other');

    const nameEl = document.createElement('div');
    nameEl.className = 'name-badge';
    nameEl.textContent = unescapeHtml(m.name);
    el.appendChild(nameEl);

    const body = document.createElement('div');
    // m.text is escaped server-side (html entities). We'll treat it as text and create elements for links
    const raw = unescapeHtml(m.text);
    // Detect youtube links
    const yt = extractYouTubeId(raw);
    if (yt) {
      const p = document.createElement('p');
      p.textContent = raw.replace(/https?:\/\/\S+/g, '').trim();
      el.appendChild(p);
      const iframe = document.createElement('iframe');
      iframe.width = '280';
      iframe.height = '158';
      iframe.setAttribute('loading','lazy');
      iframe.setAttribute('allow','accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
      iframe.setAttribute('allowfullscreen','');
      // Use youtube-nocookie for better privacy and construct safe src
      iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(yt);
      el.appendChild(iframe);
    } else {
      // Convert other links to anchors safely
      const nodes = linkifyText(raw);
      nodes.forEach(n=>el.appendChild(n));
    }

    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = timeAgo(m.ts);
    el.appendChild(meta);

    CHAT.appendChild(el);
  }
  CHAT.scrollTop = CHAT.scrollHeight;
}

// Very small helper functions
function timeAgo(ts){ const d = new Date(ts*1000); return d.toLocaleString(); }

// Linkify text into DOM nodes (text nodes and anchors). Does not insert HTML.
function linkifyText(text){
  const parts = text.split(/(https?:\/\/[^\s]+)/g);
  return parts.map(part=>{
    if (part.match(/^https?:\/\//)){
      const a = document.createElement('a');
      a.href = part;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.className = 'link';
      a.textContent = part;
      return a;
    } else {
      return document.createTextNode(part);
    }
  });
}

// YouTube ID extractor (matches common YouTube URL patterns)
function extractYouTubeId(text){
  const re = /(?:https?:\/\/)?(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/;
  const m = text.match(re);
  return m ? m[1] : null;
}

// Helpers to escape/unescape HTML entities used because server stored escaped values
function escapeHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function unescapeHtml(s){ const txt = document.createElement('textarea'); txt.innerHTML = s; return txt.value; }

</script></body>
</html>