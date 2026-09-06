<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();

// Auth check
if (empty($_SESSION['auth']) || (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$c = json_decode(file_get_contents(CONTENT_FILE), true);
$saved = isset($_GET['saved']);
$imgList = array_filter(scandir(IMG_DIR), fn($f) => preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f));
sort($imgList);

$blocos_ocultos = $c['blocos_ocultos'] ?? [];
function blocoOculto($slug, $blocos) { return in_array($slug, $blocos); }
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Intus Fit</title>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0A0A0A;--bg2:#111;--bg3:#161616;--border:#222;--red:#CC2936;--orange:#E8560A;--white:#F5F5F5;--gray-m:#7A7A7A;--gray-l:#C4C4C4}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--white);font-size:14px}
a{color:inherit;text-decoration:none}

/* TOPBAR */
.topbar{position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(10,10,10,.97);border-bottom:1px solid var(--border);padding:0 24px;height:54px;display:flex;align-items:center;justify-content:space-between}
.topbar-left{display:flex;align-items:center;gap:16px}
.topbar img{height:24px}
.topbar-title{font-size:13px;font-weight:600;color:var(--gray-l)}
.topbar-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;letter-spacing:.5px;text-transform:uppercase}
.topbar-right{display:flex;align-items:center;gap:10px}
.btn-publish{background:var(--red);color:#fff;border:none;border-radius:8px;padding:8px 20px;font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;display:flex;align-items:center;gap:6px}
.btn-publish:hover{background:#991F2A}
.btn-publish svg{width:14px;height:14px;stroke:#fff;fill:none;stroke-width:2.5}
.btn-logout{background:transparent;border:1px solid var(--border);color:var(--gray-m);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer;transition:all .2s}
.btn-logout:hover{border-color:var(--gray-m);color:var(--white)}

/* LAYOUT */
.layout{display:flex;margin-top:54px;min-height:calc(100vh - 54px)}

/* SIDEBAR */
.sidebar{width:220px;flex-shrink:0;background:var(--bg2);border-right:1px solid var(--border);padding:20px 0;position:sticky;top:54px;height:calc(100vh - 54px);overflow-y:auto}
.sidebar-section{font-size:10px;font-weight:700;color:var(--gray-m);letter-spacing:2px;text-transform:uppercase;padding:12px 20px 6px}
.sidebar-item{display:flex;align-items:center;justify-content:space-between;border-left:2px solid transparent;transition:all .15s}
.sidebar-item:hover{background:rgba(255,255,255,.03)}
.sidebar-item.active{border-left-color:var(--red);background:rgba(204,41,54,.08)}
.sidebar-item a{display:flex;align-items:center;gap:10px;padding:9px 14px 9px 18px;color:var(--gray-l);font-size:13px;flex:1}
.sidebar-item.active a{color:var(--white)}
.sidebar-item a svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0}
.sidebar-eye{background:none;border:none;padding:0 14px 0 0;cursor:pointer;color:var(--gray-m);font-size:14px;line-height:1;flex-shrink:0;opacity:.7;transition:opacity .2s}
.sidebar-eye:hover{opacity:1}
.sidebar-item.bloco-oculto a{color:#555}
.sidebar-item.bloco-oculto{border-left-color:#333}
.badge-oculto-sidebar{font-size:9px;font-weight:700;background:#333;color:#888;border-radius:3px;padding:1px 5px;letter-spacing:.5px;text-transform:uppercase;margin-right:4px}

/* MAIN */
.main{flex:1;padding:32px;max-width:900px}
.section-block{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:28px;margin-bottom:24px;display:none}
.section-block.active{display:block}
.block-title{font-size:16px;font-weight:700;margin-bottom:4px}
.block-desc{font-size:12px;color:var(--gray-m);margin-bottom:24px}

/* FIELDS */
.field{margin-bottom:18px}
.field label{display:block;font-size:11px;font-weight:700;color:var(--gray-m);letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px}
.field input[type=text],.field input[type=email],.field input[type=tel],.field input[type=url],.field textarea,.field select{width:100%;background:var(--bg3);border:1px solid #2a2a2a;border-radius:8px;padding:10px 12px;color:var(--white);font-size:14px;font-family:inherit;outline:none;transition:border-color .2s;resize:vertical}
.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--red)}
.field textarea{min-height:80px;line-height:1.5}
.field select{appearance:auto;cursor:pointer}

/* GRID */
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.field-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}

/* CARD ITEMS */
.card-list{display:flex;flex-direction:column;gap:16px}
.card-item{background:var(--bg3);border:1px solid #2a2a2a;border-radius:10px;padding:20px;position:relative;transition:opacity .2s}
.card-item.card-hidden{opacity:.45;border-style:dashed}
.card-item-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.card-item-label{font-size:13px;font-weight:700;color:var(--orange)}
.card-item-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.badge-hidden{background:#CC2936;color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:3px;letter-spacing:.5px;text-transform:uppercase}

/* ITEM ACTION BUTTONS */
.btn-action{background:transparent;border:1px solid #333;border-radius:5px;padding:4px 9px;font-size:11px;color:var(--gray-l);cursor:pointer;transition:all .15s;font-family:inherit}
.btn-action:hover{border-color:var(--orange);color:var(--orange)}
.btn-action.btn-del:hover{border-color:var(--red);color:var(--red)}
.btn-action.btn-hide-active{border-color:#555;color:#666}

/* ADD BUTTON */
.btn-add{border:1.5px dashed #333;color:var(--gray-m);background:transparent;border-radius:8px;padding:10px;width:100%;cursor:pointer;font-size:13px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;margin-top:8px;transition:all .2s}
.btn-add:hover{border-color:var(--orange);color:var(--orange)}

/* IMG PICKER */
.img-picker-wrap{position:relative}
.img-preview{width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #2a2a2a;cursor:pointer;background:#1a1a1a}
.img-picker-btn{display:flex;align-items:center;gap:6px;background:transparent;border:1px solid #2a2a2a;border-radius:6px;padding:6px 12px;font-size:12px;color:var(--gray-l);cursor:pointer;margin-top:8px;transition:all .2s}
.img-picker-btn:hover{border-color:var(--orange);color:var(--orange)}

/* MODAL IMG PICKER */
.modal-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.85);align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:700px;max-width:95vw;max-height:85vh;overflow:hidden;display:flex;flex-direction:column}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header h2{font-size:15px;font-weight:700}
.modal-close{background:transparent;border:none;color:var(--gray-m);font-size:22px;cursor:pointer;line-height:1;padding:0 4px}
.modal-close:hover{color:var(--white)}
.modal-body{padding:20px;overflow-y:auto;flex:1}
.modal-upload{border:2px dashed #2a2a2a;border-radius:10px;padding:20px;text-align:center;margin-bottom:20px;transition:border-color .2s;cursor:pointer}
.modal-upload:hover{border-color:var(--orange)}
.modal-upload input{display:none}
.modal-upload p{font-size:13px;color:var(--gray-m);margin-top:8px}
.img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px}
.img-grid-item{cursor:pointer;border-radius:8px;overflow:hidden;border:2px solid transparent;transition:border-color .2s;position:relative}
.img-grid-item:hover{border-color:var(--orange)}
.img-grid-item.selected{border-color:var(--red)}
.img-grid-item img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.img-grid-item span{display:block;font-size:10px;padding:4px;color:var(--gray-m);text-overflow:ellipsis;overflow:hidden;white-space:nowrap;background:var(--bg3)}

/* TOAST */
.toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%);background:#1a1a1a;border:1px solid #333;border-radius:10px;padding:12px 24px;font-size:14px;font-weight:600;z-index:999;display:none;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.5)}
.toast.show{display:flex}
.toast.success{border-color:rgba(37,211,102,.4);color:#25D366}
.toast.error{border-color:rgba(204,41,54,.4);color:#ff6b7a}

/* TABS (planos) */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px}
.tab-btn{background:transparent;border:none;padding:10px 18px;font-size:13px;font-weight:600;color:var(--gray-m);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .2s}
.tab-btn.active{color:var(--white);border-bottom-color:var(--red)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* SEPARATOR */
.sep{border:none;border-top:1px solid var(--border);margin:20px 0}

/* DRAG & DROP */
.card-item[draggable]{cursor:grab}
.card-item[draggable]:active{cursor:grabbing}
.card-item.drag-over{border-color:var(--orange)!important;background:rgba(232,86,10,.06)}
.card-item.dragging{opacity:.35;border-style:dashed}
.drag-handle{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;cursor:grab;color:var(--gray-d);font-size:15px;border-radius:4px;user-select:none;flex-shrink:0}
.drag-handle:hover{color:var(--gray-m)}

/* Pill tag */
.pill{display:inline-block;background:rgba(232,86,10,.12);color:var(--orange);border-radius:4px;font-size:10px;font-weight:700;padding:2px 8px;letter-spacing:.5px;text-transform:uppercase}

/* NOTICE */
.notice{background:rgba(232,86,10,.08);border:1px solid rgba(232,86,10,.2);border-radius:8px;padding:12px 16px;font-size:12px;color:var(--gray-l);margin-bottom:20px}
.notice strong{color:var(--orange)}

/* LINK FIELDS (payment) */
.link-fields{background:rgba(232,86,10,.04);border:1px solid rgba(232,86,10,.15);border-radius:8px;padding:16px;margin-top:10px}
.link-fields-title{font-size:11px;font-weight:700;color:var(--orange);letter-spacing:.5px;text-transform:uppercase;margin-bottom:12px}

/* CROP MODAL */
.crop-modal{background:var(--bg2);border:1px solid var(--border);border-radius:16px;width:820px;max-width:96vw;max-height:92vh;overflow:hidden;display:flex;flex-direction:column}
.crop-modal-body{padding:20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px}
.crop-canvas-wrap{background:#0a0a0a;border-radius:10px;overflow:hidden;max-height:420px;display:flex;align-items:center;justify-content:center}
.crop-canvas-wrap img{max-width:100%;max-height:420px;display:block}
.crop-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.crop-ratio-btns{display:flex;gap:6px;flex-wrap:wrap}
.crop-ratio-btn{background:transparent;border:1px solid #333;border-radius:6px;padding:5px 12px;font-size:12px;color:var(--gray-m);cursor:pointer;transition:all .15s;font-family:inherit}
.crop-ratio-btn:hover{border-color:var(--orange);color:var(--orange)}
.crop-ratio-btn.active{background:var(--orange);border-color:var(--orange);color:#fff}
.crop-actions{display:flex;gap:8px;margin-left:auto}
.crop-rotate-btn{background:transparent;border:1px solid #333;border-radius:6px;padding:6px 10px;font-size:13px;color:var(--gray-m);cursor:pointer;transition:all .15s}
.crop-rotate-btn:hover{border-color:var(--gray-l);color:var(--white)}
.btn-crop-apply{background:var(--red);color:#fff;border:none;border-radius:8px;padding:9px 22px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s}
.btn-crop-apply:hover{background:#991F2A}
.btn-crop-cancel{background:transparent;border:1px solid #333;color:var(--gray-m);border-radius:8px;padding:9px 18px;font-size:14px;cursor:pointer;transition:all .2s}
.btn-crop-cancel:hover{border-color:var(--gray-m);color:var(--white)}
.crop-filename-row{display:flex;align-items:center;gap:10px;background:var(--bg3);border-radius:8px;padding:10px 14px}
.crop-filename-row label{font-size:11px;font-weight:700;color:var(--gray-m);letter-spacing:.5px;text-transform:uppercase;white-space:nowrap}
.crop-filename-row input{flex:1;background:transparent;border:none;color:var(--white);font-size:14px;outline:none;font-family:inherit}
.crop-loading{display:none;align-items:center;gap:10px;font-size:13px;color:var(--gray-m)}
.crop-loading.show{display:flex}
.img-grid-item .crop-btn{display:none;position:absolute;top:4px;right:4px;background:rgba(0,0,0,.75);border:none;border-radius:4px;padding:3px 7px;font-size:11px;color:#fff;cursor:pointer;z-index:2}
.img-grid-item:hover .crop-btn{display:block}
.img-grid-item .crop-btn:hover{background:var(--orange)}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <img src="/app/painel/logo-intus-dark.png" alt="Intus Fit">
    <span class="topbar-title">Painel Admin</span>
    <span class="topbar-badge">v2.0</span>
  </div>
  <div class="topbar-right">
    <a href="https://intusfit.com.br" target="_blank" class="btn-logout">Ver site</a>
    <button class="btn-publish" onclick="publishSite()">
      <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      Publicar alterações
    </button>
    <a href="?logout=1" class="btn-logout">Sair</a>
  </div>
</div>

<div class="layout">

<!-- SIDEBAR -->
<nav class="sidebar">
  <div class="sidebar-section">Conteúdo</div>

  <div class="sidebar-item active" id="sbi-hero">
    <a href="#" onclick="showSection('hero',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>Hero
    </a>
  </div>

  <?php
  $sidebarSections = [
    'proof'        => ['slug'=>'proof_bar',      'label'=>'Proof Bar',      'icon'=>'<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
    'transf'       => ['slug'=>'transformacoes', 'label'=>'Transformações', 'icon'=>'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>'],
    'consultoria'  => ['slug'=>'consultoria',    'label'=>'Consultoria',    'icon'=>'<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>'],
    'equipe'       => ['slug'=>'equipe',         'label'=>'Equipe',         'icon'=>'<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>'],
    'depoimentos'  => ['slug'=>'depoimentos',    'label'=>'Depoimentos',    'icon'=>'<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>'],
    'planos'       => ['slug'=>'planos',         'label'=>'Planos',         'icon'=>'<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'],
  ];
  foreach ($sidebarSections as $sid => $info):
    $oculto = blocoOculto($info['slug'], $blocos_ocultos);
  ?>
  <div class="sidebar-item<?= $oculto ? ' bloco-oculto' : '' ?>" id="sbi-<?= $sid ?>">
    <a href="#" onclick="showSection('<?= $sid ?>',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><?= $info['icon'] ?></svg>
      <?= $info['label'] ?>
      <?php if ($oculto): ?><span class="badge-oculto-sidebar">oculto</span><?php endif; ?>
    </a>
    <button class="sidebar-eye" title="Mostrar/ocultar seção no site" onclick="toggleBloco('<?= $info['slug'] ?>','sbi-<?= $sid ?>')">👁</button>
  </div>
  <?php endforeach; ?>

  <div class="sidebar-section">Páginas & Modelos</div>
  <div class="sidebar-item" id="sbi-paginas">
    <a href="#" onclick="showSection('paginas',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Gerenciar Páginas
    </a>
  </div>
  <div class="sidebar-item" id="sbi-modelos">
    <a href="#" onclick="showSection('modelos',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Modelos de Página
    </a>
  </div>

  <div class="sidebar-section">Site</div>
  <div class="sidebar-item" id="sbi-contato">
    <a href="#" onclick="showSection('contato',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2A19.79 19.79 0 013.09 4.18 2 2 0 015.07 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L9.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
      Contato / WhatsApp
    </a>
  </div>
  <div class="sidebar-item" id="sbi-seo">
    <a href="#" onclick="showSection('seo',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      SEO / Meta Tags
    </a>
  </div>
  <div class="sidebar-item" id="sbi-imagens">
    <a href="#" onclick="showSection('imagens',this.closest('.sidebar-item'));return false">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      Imagens
    </a>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main">
<?php if ($saved): ?>
<div class="notice"><strong>Site publicado!</strong> As alterações já estão no ar em intusfit.com.br</div>
<?php endif; ?>

<!-- HERO -->
<div id="sec-hero" class="section-block active">
  <div class="block-title">Hero — Cabeçalho principal</div>
  <div class="block-desc">Primeira seção que o visitante vê ao entrar no site.</div>
  <div class="field"><label>Tag acima do título</label>
    <input type="text" id="hero_tag" value="<?= he($c['hero']['tag']) ?>"></div>
  <div class="field-row">
    <div class="field"><label>Título linha 1</label>
      <input type="text" id="hero_title1" value="<?= he($c['hero']['title_line1']) ?>"></div>
    <div class="field"><label>Título linha 2 (destaque vermelho)</label>
      <input type="text" id="hero_title2" value="<?= he($c['hero']['title_line2']) ?>"></div>
  </div>
  <div class="field"><label>Subtítulo</label>
    <textarea id="hero_sub"><?= he($c['hero']['subtitle']) ?></textarea></div>
  <div class="field"><label>Texto do botão CTA</label>
    <input type="text" id="hero_cta" value="<?= he($c['hero']['cta_text']) ?>"></div>
</div>

<!-- PROOF BAR -->
<div id="sec-proof" class="section-block">
  <div class="block-title">Proof Bar — Barra de prova social</div>
  <div class="block-desc">Fatos rápidos exibidos em linha abaixo do hero.</div>
  <div class="card-list" id="proof-list"></div>
  <button class="btn-add" onclick="addProofItem()">+ Adicionar item</button>
</div>

<!-- TRANSFORMAÇÕES -->
<div id="sec-transf" class="section-block">
  <div class="block-title">Transformações — Galeria principal</div>
  <div class="block-desc">Cards com fotos de resultados na seção "Resultados reais".</div>
  <div class="card-list" id="transf-list"></div>
  <button class="btn-add" onclick="addTransfItem()">+ Adicionar transformação</button>
</div>

<!-- CONSULTORIA -->
<div id="sec-consultoria" class="section-block">
  <div class="block-title">Consultoria Premium</div>
  <div class="block-desc">Seção central com a promessa e os pilares.</div>
  <div class="field-row">
    <div class="field"><label>Badge</label>
      <input type="text" id="cons_badge" value="<?= he($c['consultoria']['badge']) ?>"></div>
    <div class="field"><label>Destaque em laranja</label>
      <input type="text" id="cons_em" value="<?= he($c['consultoria']['promise_em']) ?>"></div>
  </div>
  <div class="field-row">
    <div class="field"><label>Promessa — linha 1</label>
      <input type="text" id="cons_p1" value="<?= he($c['consultoria']['promise_line1']) ?>"></div>
    <div class="field"><label>Promessa — linha 2</label>
      <input type="text" id="cons_p2" value="<?= he($c['consultoria']['promise_line2']) ?>"></div>
  </div>
  <div class="field"><label>Nota explicativa</label>
    <textarea id="cons_nota"><?= he($c['consultoria']['nota']) ?></textarea></div>
  <div class="field"><label>Texto do botão</label>
    <input type="text" id="cons_cta" value="<?= he($c['consultoria']['cta_text']) ?>"></div>
  <hr class="sep">
  <div class="block-title" style="margin-bottom:16px">Pilares da Consultoria</div>
  <div class="card-list" id="pilares-list"></div>
  <button class="btn-add" onclick="addPilarItem()">+ Adicionar pilar</button>
</div>

<!-- EQUIPE -->
<div id="sec-equipe" class="section-block">
  <div class="block-title">Equipe</div>
  <div class="block-desc">Cards de profissionais. Para trocar a foto, clique em "Trocar imagem".</div>
  <div class="card-list" id="equipe-list"></div>
  <button class="btn-add" onclick="addEquipeMembro()">+ Adicionar membro</button>
</div>

<!-- DEPOIMENTOS -->
<div id="sec-depoimentos" class="section-block">
  <div class="block-title">Depoimentos / Antes e Depois</div>
  <div class="block-desc">Cards com par de fotos + citação.</div>
  <div class="card-list" id="dep-list"></div>
  <button class="btn-add" onclick="addDepoimento()">+ Adicionar depoimento</button>
</div>

<!-- PLANOS -->
<div id="sec-planos" class="section-block">
  <div class="block-title">Planos e Preços</div>
  <div class="block-desc">Títulos, preços e benefícios de cada plano.</div>
  <div class="field-row">
    <div class="field"><label>Título da seção</label>
      <input type="text" id="planos_titulo" value="<?= he($c['planos']['titulo']) ?>"></div>
    <div class="field"><label>Subtítulo</label>
      <input type="text" id="planos_sub" value="<?= he($c['planos']['subtitulo']) ?>"></div>
  </div>
  <div class="field"><label>Texto da garantia</label>
    <input type="text" id="planos_garantia" value="<?= he($c['planos']['garantia']) ?>"></div>
  <hr class="sep">
  <div class="tabs" id="planos-tabs"></div>
  <div id="planos-panels"></div>
</div>

<!-- CONTATO -->
<div id="sec-contato" class="section-block">
  <div class="block-title">Contato e Redes Sociais</div>
  <div class="block-desc">WhatsApp, e-mail, Instagram e rodapé.</div>
  <div class="field-row">
    <div class="field"><label>WhatsApp (somente números, com DDI)</label>
      <input type="text" id="ct_wa" value="<?= he($c['contato']['whatsapp']) ?>"></div>
    <div class="field"><label>E-mail</label>
      <input type="email" id="ct_email" value="<?= he($c['contato']['email']) ?>"></div>
  </div>
  <div class="field"><label>Saudação padrão (botão flutuante WhatsApp e CTA final)</label>
    <input type="text" id="ct_wa_saudacao" value="<?= he($c['contato']['whatsapp_saudacao_site'] ?? 'Olá! Vim pelo site e quero saber mais sobre a Consultoria Intus Fit.') ?>"></div>
  <div class="field-row">
    <div class="field"><label>Instagram da marca (sem @)</label>
      <input type="text" id="ct_ig" value="<?= he($c['contato']['instagram']) ?>"></div>
    <div class="field"><label>CNPJ</label>
      <input type="text" id="ct_cnpj" value="<?= he($c['contato']['cnpj']) ?>"></div>
  </div>
  <div class="field"><label>Endereço (rodapé)</label>
    <input type="text" id="ct_end" value="<?= he($c['contato']['endereco']) ?>"></div>
  <div class="field"><label>Linha de crédito (rodapé)</label>
    <input type="text" id="ct_cref" value="<?= he($c['contato']['cref_rodape']) ?>"></div>
</div>

<!-- SEO -->
<div id="sec-seo" class="section-block">
  <div class="block-title">SEO e Meta Tags</div>
  <div class="block-desc">Título e descrição para Google, WhatsApp e Instagram.</div>
  <div class="field"><label>Título da página (aba do navegador)</label>
    <input type="text" id="seo_title" value="<?= he($c['seo']['title']) ?>"></div>
  <div class="field"><label>Meta description (Google)</label>
    <textarea id="seo_desc"><?= he($c['seo']['description']) ?></textarea></div>
  <hr class="sep">
  <div class="field"><label>OG Title (título no WhatsApp/Instagram)</label>
    <input type="text" id="seo_ogt" value="<?= he($c['seo']['og_title']) ?>"></div>
  <div class="field"><label>OG Description</label>
    <textarea id="seo_ogd"><?= he($c['seo']['og_description']) ?></textarea></div>
  <div class="field">
    <label>Imagem de compartilhamento (OG Image)</label>
    <img src="/img/<?= he($c['seo']['og_image']) ?>" class="img-preview" id="prev_og" style="width:200px;height:105px;object-fit:cover" onerror="this.style.opacity=.3">
    <button type="button" class="img-picker-btn" onclick="openImgPicker('seo_ogimg','prev_og')">Trocar imagem</button>
    <input type="hidden" id="seo_ogimg" value="<?= he($c['seo']['og_image']) ?>">
  </div>
</div>

<!-- IMAGENS -->
<div id="sec-imagens" class="section-block">
  <div class="block-title">Biblioteca de imagens</div>
  <div class="block-desc">Todas as imagens disponíveis no servidor. Faça upload de novas imagens aqui.</div>
  <div class="modal-upload" onclick="document.getElementById('bulk_upload').click()">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#7A7A7A" stroke-width="1.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
    <p>Clique ou arraste para fazer upload de imagens</p>
    <input type="file" id="bulk_upload" multiple accept="image/*" onchange="uploadImages(this)">
  </div>
  <div class="img-grid" id="imgLibrary">
    <?php foreach ($imgList as $img): ?>
    <div class="img-grid-item">
      <img src="/img/<?= he($img) ?>" alt="<?= he($img) ?>" loading="lazy">
      <button class="crop-btn" onclick="openCropper('/img/<?= he($img) ?>','<?= he($img) ?>')" title="Recortar">✂</button>
      <span title="<?= he($img) ?>"><?= he($img) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- PÁGINAS -->
<div id="sec-paginas" class="section-block">
  <div class="block-title">Gerenciar Páginas</div>
  <div class="block-desc">Crie novas URLs a partir de um modelo, edite ou exclua páginas existentes.</div>
  <div style="display:flex;gap:10px;margin-bottom:20px">
    <button class="btn-publish" onclick="openNewPageModal()" style="font-size:13px;padding:8px 18px">+ Nova página</button>
    <button class="btn-action" onclick="loadPagesList()" style="padding:8px 14px;font-size:13px">↻ Atualizar lista</button>
  </div>
  <div id="pages-list" style="display:flex;flex-direction:column;gap:10px">
    <div style="color:var(--gray-m);font-size:13px">Carregando...</div>
  </div>
</div>

<!-- MODELOS -->
<div id="sec-modelos" class="section-block">
  <div class="block-title">Modelos de Página</div>
  <div class="block-desc">Salve o estado atual como modelo para reutilizar em novas páginas.</div>
  <div style="display:flex;gap:10px;margin-bottom:20px">
    <button class="btn-publish" onclick="openSaveModelModal()" style="font-size:13px;padding:8px 18px">💾 Salvar modelo atual</button>
    <button class="btn-action" onclick="loadModelsList()" style="padding:8px 14px;font-size:13px">↻ Atualizar lista</button>
  </div>
  <div id="models-list" style="display:flex;flex-direction:column;gap:10px">
    <div style="color:var(--gray-m);font-size:13px">Carregando...</div>
  </div>
</div>

</main>
</div>

<!-- IMAGE PICKER MODAL -->
<div class="modal-overlay" id="imgModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Selecionar imagem</h2>
      <button class="modal-close" onclick="closeImgPicker()">×</button>
    </div>
    <div class="modal-body">
      <div class="modal-upload" onclick="document.getElementById('modal_upload').click()">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7A7A7A" stroke-width="1.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
        <p>Fazer upload de nova imagem</p>
        <input type="file" id="modal_upload" accept="image/*" onchange="uploadFromModal(this)">
      </div>
      <div class="img-grid" id="modalImgGrid">
        <?php foreach ($imgList as $img): ?>
        <div class="img-grid-item">
          <img src="/img/<?= he($img) ?>" alt="<?= he($img) ?>" loading="lazy" onclick="selectImg('<?= he($img) ?>')">
          <button class="crop-btn" onclick="openCropper('/img/<?= he($img) ?>','<?= he($img) ?>')" title="Recortar">✂</button>
          <span onclick="selectImg('<?= he($img) ?>')"><?= he($img) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- CROP MODAL -->
<div class="modal-overlay" id="cropModal">
  <div class="crop-modal">
    <div class="modal-header">
      <h2>✂ Recortar imagem</h2>
      <button class="modal-close" onclick="closeCropper()">×</button>
    </div>
    <div class="crop-modal-body">
      <div class="crop-toolbar">
        <div class="crop-ratio-btns">
          <button class="crop-ratio-btn active" onclick="setRatio(1,1,this)" title="Quadrado — equipe">1:1 Equipe</button>
          <button class="crop-ratio-btn" onclick="setRatio(3,4,this)" title="Retrato — transformações">3:4 Transformação</button>
          <button class="crop-ratio-btn" onclick="setRatio(4,5,this)" title="Depoimentos">4:5 Depoimento</button>
          <button class="crop-ratio-btn" onclick="setRatio(16,9,this)" title="Banner horizontal">16:9 Banner</button>
          <button class="crop-ratio-btn" onclick="setRatio(NaN,NaN,this)" title="Livre">Livre</button>
        </div>
        <div class="crop-actions">
          <button class="crop-rotate-btn" onclick="rotateCrop(-90)" title="Girar 90° esquerda">↺ 90°</button>
          <button class="crop-rotate-btn" onclick="rotateCrop(90)" title="Girar 90° direita">↻ 90°</button>
          <button class="crop-rotate-btn" onclick="flipCropX()" title="Espelhar horizontal">⇔</button>
        </div>
      </div>
      <div class="crop-canvas-wrap">
        <img id="cropImg" src="" alt="Recortar">
      </div>
      <div class="crop-filename-row">
        <label>Salvar como:</label>
        <input type="text" id="cropFilename" placeholder="nome-do-arquivo.jpg">
        <span style="font-size:12px;color:var(--gray-m)">.jpg</span>
      </div>
      <div style="display:flex;gap:10px;align-items:center;justify-content:flex-end">
        <div class="crop-loading" id="cropLoading">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
          Salvando...
        </div>
        <button class="btn-crop-cancel" onclick="closeCropper()">Cancelar</button>
        <button class="btn-crop-apply" onclick="applyCrop()">Salvar recorte</button>
      </div>
    </div>
  </div>
</div>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
// ─── DATA INICIAL (PHP → JS) ─────────────────────────────────────────────────
const INIT = <?= json_encode([
  'proof_bar'      => $c['proof_bar'],
  'transformacoes' => $c['transformacoes'],
  'pilares'        => $c['consultoria']['pilares'],
  'equipe'         => $c['equipe'],
  'depoimentos'    => $c['depoimentos'],
  'planos_items'   => $c['planos']['items'],
  'blocos_ocultos' => $blocos_ocultos,
], JSON_UNESCAPED_UNICODE) ?>;

// Arrays em memória
let proofArr    = INIT.proof_bar.map(x => ({...x}));
let transfArr   = INIT.transformacoes.map(x => ({...x}));
let pilaresArr  = INIT.pilares.map(x => ({...x}));
let equipeArr   = INIT.equipe.map(x => ({...x}));
let depArr      = INIT.depoimentos.map(x => ({...x}));
let planosArr   = INIT.planos_items.map(x => ({...x}));
let blocosArr   = [...INIT.blocos_ocultos];

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function v(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }
function readArr(listEl, selector) {
  return Array.from(listEl.querySelectorAll(selector)).map(el => el.value.trim());
}

// ─── SECTION NAV ─────────────────────────────────────────────────────────────
function showSection(id, sidebarItem) {
  document.querySelectorAll('.section-block').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.sidebar-item').forEach(a => a.classList.remove('active'));
  document.getElementById('sec-' + id).classList.add('active');
  if (sidebarItem) sidebarItem.classList.add('active');
  return false;
}

// ─── TABS ─────────────────────────────────────────────────────────────────────
function switchTab(prefix, idx, el) {
  const parent = el.closest('.section-block');
  parent.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  parent.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById(prefix + '_tab_' + idx).classList.add('active');
}

// ─── DRAG & DROP REORDER ─────────────────────────────────────────────────────
function initDrag(listEl, arr, renderFn) {
  const items = listEl.querySelectorAll('.card-item[draggable]');
  let dragIdx = null;

  items.forEach((el, i) => {
    el.addEventListener('dragstart', e => {
      dragIdx = i;
      el.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    el.addEventListener('dragend', () => {
      el.classList.remove('dragging');
      listEl.querySelectorAll('.card-item').forEach(c => c.classList.remove('drag-over'));
    });
    el.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      listEl.querySelectorAll('.card-item').forEach(c => c.classList.remove('drag-over'));
      el.classList.add('drag-over');
    });
    el.addEventListener('drop', e => {
      e.preventDefault();
      if (dragIdx === null || dragIdx === i) return;
      const moved = arr.splice(dragIdx, 1)[0];
      arr.splice(i, 0, moved);
      dragIdx = null;
      renderFn();
    });
  });
}

// ─── TOAST ────────────────────────────────────────────────────────────────────
function toast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.classList.remove('show'), 3500);
}

// ─── IMAGE PICKER ─────────────────────────────────────────────────────────────
let currentImgTarget = null, currentPreviewTarget = null;
function openImgPicker(inputId, previewId) {
  currentImgTarget = inputId;
  currentPreviewTarget = previewId;
  document.getElementById('imgModal').classList.add('open');
}
function closeImgPicker() {
  document.getElementById('imgModal').classList.remove('open');
  currentImgTarget = null; currentPreviewTarget = null;
}
function selectImg(filename) {
  if (currentImgTarget) {
    const inp = document.getElementById(currentImgTarget);
    if (inp) inp.value = filename;
    const prev = document.getElementById(currentPreviewTarget);
    if (prev) { prev.src = '/img/' + filename; prev.style.opacity = 1; }
  }
  closeImgPicker();
  toast('Imagem selecionada: ' + filename);
}

// ─── UPLOAD ───────────────────────────────────────────────────────────────────
async function uploadImages(input) {
  const files = Array.from(input.files);
  for (const file of files) { await uploadFile(file); }
  refreshImgGrid();
}
async function uploadFromModal(input) {
  if (input.files[0]) {
    const filename = await uploadFile(input.files[0]);
    refreshImgGrid();
    if (filename) selectImg(filename);
  }
}
async function uploadFile(file) {
  const fd = new FormData();
  fd.append('img', file);
  try {
    const r = await fetch('upload.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (j.ok) { toast('Upload: ' + j.filename); return j.filename; }
    else { toast(j.error || 'Erro no upload', 'error'); }
  } catch(e) { toast('Erro de conexão', 'error'); }
  return null;
}
async function refreshImgGrid() {
  const r = await fetch('imglist.php');
  const imgs = await r.json();
  ['modalImgGrid','imgLibrary'].forEach(gridId => {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = imgs.map(img =>
      `<div class="img-grid-item" onclick="selectImg('${img}')">
        <img src="/img/${img}" loading="lazy">
        <span>${img}</span>
      </div>`
    ).join('');
  });
}

// ─── BLOCOS OCULTOS (visibilidade de seções) ──────────────────────────────────
function toggleBloco(slug, sbiId) {
  const idx = blocosArr.indexOf(slug);
  const sbi = document.getElementById(sbiId);
  if (idx === -1) {
    blocosArr.push(slug);
    sbi.classList.add('bloco-oculto');
    // add badge if not present
    const a = sbi.querySelector('a');
    if (!a.querySelector('.badge-oculto-sidebar')) {
      const badge = document.createElement('span');
      badge.className = 'badge-oculto-sidebar';
      badge.textContent = 'oculto';
      a.appendChild(badge);
    }
    toast('Seção ocultada (não aparece no site)');
  } else {
    blocosArr.splice(idx, 1);
    sbi.classList.remove('bloco-oculto');
    const badge = sbi.querySelector('.badge-oculto-sidebar');
    if (badge) badge.remove();
    toast('Seção visível novamente');
  }
}

// ─── RENDER: PROOF BAR ────────────────────────────────────────────────────────
function renderProof() {
  const list = document.getElementById('proof-list');
  list.innerHTML = proofArr.map((item, i) => `
    <div class="card-item${item.oculto ? ' card-hidden' : ''}" id="proof-card-${i}" draggable="true">
      <div class="card-item-header">
        <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
        <span class="card-item-label">Item ${i+1}${item.oculto ? ' <span class="badge-hidden">OCULTO</span>' : ''}</span>
        <div class="card-item-actions">
          <button class="btn-action${item.oculto ? ' btn-hide-active' : ''}" onclick="toggleHideProof(${i})">${item.oculto ? 'Mostrar' : 'Ocultar'}</button>
          <button class="btn-action btn-del" onclick="deleteProof(${i})">Excluir</button>
        </div>
      </div>
      <div class="field-row">
        <div class="field"><label>Texto em destaque (negrito)</label>
          <input type="text" id="proof_bold_${i}" value="${esc(item.bold)}" oninput="proofArr[${i}].bold=this.value"></div>
        <div class="field"><label>Texto complementar</label>
          <input type="text" id="proof_text_${i}" value="${esc(item.text)}" oninput="proofArr[${i}].text=this.value"></div>
      </div>
    </div>`).join('');
  initDrag(list, proofArr, renderProof);
}
function addProofItem() {
  proofArr.push({ bold: 'Novo dado', text: 'descrição', oculto: false });
  renderProof();
}
function deleteProof(i) {
  if (!confirm('Excluir este item?')) return;
  proofArr.splice(i, 1);
  renderProof();
}
function toggleHideProof(i) {
  proofArr[i].oculto = !proofArr[i].oculto;
  renderProof();
}

// ─── RENDER: TRANSFORMAÇÕES ───────────────────────────────────────────────────
function renderTransf() {
  const list = document.getElementById('transf-list');
  list.innerHTML = transfArr.map((item, i) => `
    <div class="card-item${item.oculto ? ' card-hidden' : ''}" draggable="true">
      <div class="card-item-header">
        <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
        <span class="card-item-label">Card ${i+1}${item.oculto ? ' <span class="badge-hidden">OCULTO</span>' : ''}</span>
        <div class="card-item-actions">
          <button class="btn-action" onclick="dupTransf(${i})">Duplicar</button>
          <button class="btn-action${item.oculto ? ' btn-hide-active' : ''}" onclick="toggleHideTransf(${i})">${item.oculto ? 'Mostrar' : 'Ocultar'}</button>
          <button class="btn-action btn-del" onclick="deleteTransf(${i})">Excluir</button>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Foto</label>
          <div class="img-picker-wrap">
            <img src="/img/${esc(item.img)}" class="img-preview" id="prev_transf_${i}" onerror="this.style.opacity=.3" style="cursor:pointer" onclick="openImgPicker('transf_img_${i}','prev_transf_${i}')">
            <div style="display:flex;gap:6px;margin-top:8px">
              <button type="button" class="img-picker-btn" style="flex:1" onclick="openImgPicker('transf_img_${i}','prev_transf_${i}')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Trocar
              </button>
              <button type="button" class="img-picker-btn" onclick="item.img&&openCropper('/img/'+transfArr[${i}].img, transfArr[${i}].img)" title="Recortar esta imagem">✂ Recortar</button>
            </div>
            <input type="hidden" id="transf_img_${i}" value="${esc(item.img)}" onchange="transfArr[${i}].img=this.value">
          </div>
        </div>
        <div>
          <div class="field"><label>Nome do aluno</label>
            <input type="text" id="transf_nome_${i}" value="${esc(item.nome)}" oninput="transfArr[${i}].nome=this.value"></div>
          <div class="field"><label>Resultado</label>
            <input type="text" id="transf_res_${i}" value="${esc(item.resultado)}" oninput="transfArr[${i}].resultado=this.value"></div>
        </div>
      </div>
    </div>`).join('');
  initDrag(list, transfArr, renderTransf);
}
function addTransfItem() {
  transfArr.push({ img: '', nome: 'Novo aluno', resultado: 'Resultado', oculto: false });
  renderTransf();
}
function dupTransf(i) {
  transfArr.splice(i+1, 0, {...transfArr[i]});
  renderTransf();
}
function deleteTransf(i) {
  if (!confirm('Excluir este item?')) return;
  transfArr.splice(i, 1);
  renderTransf();
}
function toggleHideTransf(i) {
  transfArr[i].oculto = !transfArr[i].oculto;
  renderTransf();
}

// ─── RENDER: PILARES ──────────────────────────────────────────────────────────
function renderPilares() {
  const list = document.getElementById('pilares-list');
  list.innerHTML = pilaresArr.map((item, i) => `
    <div class="card-item${item.oculto ? ' card-hidden' : ''}" style="margin-bottom:12px" draggable="true">
      <div class="card-item-header">
        <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
        <span class="card-item-label">Pilar ${i+1}${item.oculto ? ' <span class="badge-hidden">OCULTO</span>' : ''}</span>
        <div class="card-item-actions">
          <button class="btn-action" onclick="dupPilar(${i})">Duplicar</button>
          <button class="btn-action${item.oculto ? ' btn-hide-active' : ''}" onclick="toggleHidePilar(${i})">${item.oculto ? 'Mostrar' : 'Ocultar'}</button>
          <button class="btn-action btn-del" onclick="deletePilar(${i})">Excluir</button>
        </div>
      </div>
      <div class="field"><label>Número exibido</label>
        <input type="text" id="pilar_num_${i}" value="${esc(item.num)}" oninput="pilaresArr[${i}].num=this.value" style="max-width:80px"></div>
      <div class="field"><label>Título</label>
        <input type="text" id="pilar_titulo_${i}" value="${esc(item.titulo)}" oninput="pilaresArr[${i}].titulo=this.value"></div>
      <div class="field"><label>Descrição</label>
        <input type="text" id="pilar_desc_${i}" value="${esc(item.desc)}" oninput="pilaresArr[${i}].desc=this.value"></div>
    </div>`).join('');
  initDrag(list, pilaresArr, renderPilares);
}
function addPilarItem() {
  pilaresArr.push({ num: String(pilaresArr.length+1), titulo: 'Novo pilar', desc: 'Descrição do pilar', oculto: false });
  renderPilares();
}
function dupPilar(i) {
  pilaresArr.splice(i+1, 0, {...pilaresArr[i]});
  renderPilares();
}
function deletePilar(i) {
  if (!confirm('Excluir este item?')) return;
  pilaresArr.splice(i, 1);
  renderPilares();
}
function toggleHidePilar(i) {
  pilaresArr[i].oculto = !pilaresArr[i].oculto;
  renderPilares();
}

// ─── RENDER: EQUIPE ───────────────────────────────────────────────────────────
function renderEquipe() {
  const list = document.getElementById('equipe-list');
  list.innerHTML = equipeArr.map((m, i) => `
    <div class="card-item${m.oculto ? ' card-hidden' : ''}" draggable="true">
      <div class="card-item-header">
        <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
        <span class="card-item-label">${esc(m.nome) || 'Membro ' + (i+1)}${m.oculto ? ' <span class="badge-hidden">OCULTO</span>' : ''}</span>
        <div class="card-item-actions">
          <button class="btn-action" onclick="dupEquipe(${i})">Duplicar</button>
          <button class="btn-action${m.oculto ? ' btn-hide-active' : ''}" onclick="toggleHideEquipe(${i})">${m.oculto ? 'Mostrar' : 'Ocultar'}</button>
          <button class="btn-action btn-del" onclick="deleteEquipe(${i})">Excluir</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:100px 1fr;gap:20px;align-items:start">
        <div>
          <img src="/img/${esc(m.img)}" class="img-preview" id="prev_eq_${i}" style="width:100px;height:100px;cursor:pointer" onerror="this.style.opacity=.3" onclick="openImgPicker('eq_img_${i}','prev_eq_${i}')">
          <div style="display:flex;gap:5px;margin-top:6px;flex-wrap:wrap">
            <button type="button" class="img-picker-btn" style="flex:1" onclick="openImgPicker('eq_img_${i}','prev_eq_${i}')">Trocar</button>
            <button type="button" class="img-picker-btn" onclick="equipeArr[${i}].img&&openCropper('/img/'+equipeArr[${i}].img,equipeArr[${i}].img)" title="Recortar 1:1">✂</button>
          </div>
          <input type="hidden" id="eq_img_${i}" value="${esc(m.img)}" onchange="equipeArr[${i}].img=this.value">
        </div>
        <div>
          <div class="field"><label>Nome</label>
            <input type="text" id="eq_nome_${i}" value="${esc(m.nome)}" oninput="equipeArr[${i}].nome=this.value;this.closest('.card-item').querySelector('.card-item-label').textContent=this.value||'Membro ${i+1}'"></div>
          <div class="field-row">
            <div class="field"><label>Credencial (CREF/CRN)</label>
              <input type="text" id="eq_cred_${i}" value="${esc(m.cred)}" oninput="equipeArr[${i}].cred=this.value"></div>
            <div class="field"><label>Especialidade</label>
              <input type="text" id="eq_spec_${i}" value="${esc(m.spec)}" oninput="equipeArr[${i}].spec=this.value"></div>
          </div>
          <div class="field"><label>Bio</label>
            <textarea id="eq_bio_${i}" oninput="equipeArr[${i}].bio=this.value">${esc(m.bio)}</textarea></div>
          <div class="field"><label>Instagram (sem @)</label>
            <input type="text" id="eq_ig_${i}" value="${esc(m.ig)}" oninput="equipeArr[${i}].ig=this.value"></div>
        </div>
      </div>
    </div>`).join('');
  initDrag(list, equipeArr, renderEquipe);
}
function addEquipeMembro() {
  equipeArr.push({ img: '', nome: 'Novo Membro', cred: '', spec: '', bio: '', ig: '', oculto: false });
  renderEquipe();
}
function dupEquipe(i) {
  equipeArr.splice(i+1, 0, {...equipeArr[i]});
  renderEquipe();
}
function deleteEquipe(i) {
  if (!confirm('Excluir este item?')) return;
  equipeArr.splice(i, 1);
  renderEquipe();
}
function toggleHideEquipe(i) {
  equipeArr[i].oculto = !equipeArr[i].oculto;
  renderEquipe();
}

// ─── RENDER: DEPOIMENTOS ─────────────────────────────────────────────────────
function renderDep() {
  const list = document.getElementById('dep-list');
  list.innerHTML = depArr.map((d, i) => `
    <div class="card-item${d.oculto ? ' card-hidden' : ''}" draggable="true">
      <div class="card-item-header">
        <span class="drag-handle" title="Arrastar para reordenar">⠿</span>
        <span class="card-item-label">Depoimento ${i+1}${d.oculto ? ' <span class="badge-hidden">OCULTO</span>' : ''}</span>
        <div class="card-item-actions">
          <button class="btn-action" onclick="dupDep(${i})">Duplicar</button>
          <button class="btn-action${d.oculto ? ' btn-hide-active' : ''}" onclick="toggleHideDep(${i})">${d.oculto ? 'Mostrar' : 'Ocultar'}</button>
          <button class="btn-action btn-del" onclick="deleteDep(${i})">Excluir</button>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Foto antes</label>
          <img src="/img/${esc(d.img1)}" class="img-preview" id="prev_dep1_${i}" onerror="this.style.opacity=.3">
          <button type="button" class="img-picker-btn" onclick="openImgPicker('dep_img1_${i}','prev_dep1_${i}')">Trocar</button>
          <button type="button" class="img-picker-btn" onclick="depArr[${i}].img1&&openCropper('/img/'+depArr[${i}].img1,depArr[${i}].img1)" title="Recortar 4:5">✂</button>
          <input type="hidden" id="dep_img1_${i}" value="${esc(d.img1)}" onchange="depArr[${i}].img1=this.value">
        </div>
        <div class="field">
          <label>Foto depois</label>
          <img src="/img/${esc(d.img2)}" class="img-preview" id="prev_dep2_${i}" onerror="this.style.opacity=.3">
          <button type="button" class="img-picker-btn" onclick="openImgPicker('dep_img2_${i}','prev_dep2_${i}')">Trocar</button>
          <button type="button" class="img-picker-btn" onclick="depArr[${i}].img2&&openCropper('/img/'+depArr[${i}].img2,depArr[${i}].img2)" title="Recortar 4:5">✂</button>
          <input type="hidden" id="dep_img2_${i}" value="${esc(d.img2)}" onchange="depArr[${i}].img2=this.value">
        </div>
      </div>
      <div class="field"><label>Título do resultado</label>
        <input type="text" id="dep_res_${i}" value="${esc(d.resultado)}" oninput="depArr[${i}].resultado=this.value"></div>
      <div class="field"><label>Citação (sem aspas)</label>
        <textarea id="dep_quote_${i}" oninput="depArr[${i}].quote=this.value">${esc(d.quote)}</textarea></div>
      <div class="field"><label>Autor (ex: Aluna, 12 semanas)</label>
        <input type="text" id="dep_autor_${i}" value="${esc(d.autor)}" oninput="depArr[${i}].autor=this.value"></div>
    </div>`).join('');
  initDrag(list, depArr, renderDep);
}
function addDepoimento() {
  depArr.push({ img1: '', img2: '', resultado: 'Novo resultado', quote: '', autor: '', oculto: false });
  renderDep();
}
function dupDep(i) {
  depArr.splice(i+1, 0, {...depArr[i]});
  renderDep();
}
function deleteDep(i) {
  if (!confirm('Excluir este item?')) return;
  depArr.splice(i, 1);
  renderDep();
}
function toggleHideDep(i) {
  depArr[i].oculto = !depArr[i].oculto;
  renderDep();
}

// ─── RENDER: PLANOS ───────────────────────────────────────────────────────────
function renderPlanos() {
  const tabsEl   = document.getElementById('planos-tabs');
  const panelsEl = document.getElementById('planos-panels');
  tabsEl.innerHTML = planosArr.map((pl, i) =>
    `<button class="tab-btn${i===0?' active':''}" onclick="switchTab('planos',${i},this)">${esc(pl.nome||'Plano '+(i+1))}</button>`
  ).join('');
  panelsEl.innerHTML = planosArr.map((pl, i) => {
    const tipo = pl.btn_tipo || 'whatsapp';
    const showLinks = tipo === 'link' || tipo === 'ambos';
    return `
    <div id="planos_tab_${i}" class="tab-panel${i===0?' active':''}">
      <div class="field-row">
        <div class="field"><label>Nome do plano</label>
          <input type="text" id="pl_nome_${i}" value="${esc(pl.nome)}" oninput="planosArr[${i}].nome=this.value;renderPlanos()"></div>
        <div class="field"><label>Badge (deixe vazio se não tiver)</label>
          <input type="text" id="pl_badge_${i}" value="${esc(pl.badge)}"></div>
      </div>
      <div class="field-row-3">
        <div class="field"><label>Trimestral total (R$)</label>
          <input type="text" id="pl_trim_t_${i}" value="${esc(pl.trimestral_total)}"></div>
        <div class="field"><label>Parcela (3x)</label>
          <input type="text" id="pl_trim_p_${i}" value="${esc(pl.trimestral_parcela)}"></div>
      </div>
      <div class="field-row-3">
        <div class="field"><label>Semestral total (R$)</label>
          <input type="text" id="pl_sem_t_${i}" value="${esc(pl.semestral_total)}"></div>
        <div class="field"><label>Parcela (6x)</label>
          <input type="text" id="pl_sem_p_${i}" value="${esc(pl.semestral_parcela)}"></div>
      </div>
      <div class="field-row-3">
        <div class="field"><label>Anual total (R$)</label>
          <input type="text" id="pl_anu_t_${i}" value="${esc(pl.anual_total)}"></div>
        <div class="field"><label>Parcela (12x)</label>
          <input type="text" id="pl_anu_p_${i}" value="${esc(pl.anual_parcela)}"></div>
      </div>
      <hr style="border:none;border-top:1px solid #222;margin:16px 0">
      <div class="field">
        <label>Tipo de botão</label>
        <select id="pl_tipo_${i}" onchange="onChangeBtnTipo(${i},this.value)">
          <option value="whatsapp"${tipo==='whatsapp'?' selected':''}>Apenas WhatsApp</option>
          <option value="link"${tipo==='link'?' selected':''}>Link de pagamento</option>
          <option value="ambos"${tipo==='ambos'?' selected':''}>WhatsApp + Link de pagamento</option>
        </select>
      </div>
      <div class="field"><label>Mensagem WhatsApp</label>
        <input type="text" id="pl_wa_${i}" value="${esc(pl.whatsapp_msg)}"></div>
      <div class="link-fields" id="pl_linkfields_${i}" style="${showLinks?'':'display:none'}">
        <div class="link-fields-title">Links de pagamento por periodicidade</div>
        <div class="field"><label>Link Trimestral</label>
          <input type="url" id="pl_ltrim_${i}" value="${esc(pl.link_trimestral||'')}" placeholder="https://..."></div>
        <div class="field"><label>Link Semestral</label>
          <input type="url" id="pl_lsem_${i}" value="${esc(pl.link_semestral||'')}" placeholder="https://..."></div>
        <div class="field"><label>Link Anual</label>
          <input type="url" id="pl_lanu_${i}" value="${esc(pl.link_anual||'')}" placeholder="https://..."></div>
      </div>
      <div class="field"><label>Benefícios (um por linha)</label>
        <textarea id="pl_feat_${i}" style="min-height:120px">${esc((pl.features||[]).join('\n'))}</textarea></div>
    </div>`;
  }).join('');
}
function onChangeBtnTipo(i, val) {
  planosArr[i].btn_tipo = val;
  const lf = document.getElementById('pl_linkfields_' + i);
  if (lf) lf.style.display = (val === 'link' || val === 'ambos') ? '' : 'none';
}

// ─── COLLECT DATA ─────────────────────────────────────────────────────────────
function collectData() {
  // sync img hidden fields → arrays antes de coletar
  transfArr.forEach((item, i) => {
    const el = document.getElementById('transf_img_' + i);
    if (el) item.img = el.value;
  });
  equipeArr.forEach((item, i) => {
    const el = document.getElementById('eq_img_' + i);
    if (el) item.img = el.value;
  });
  depArr.forEach((item, i) => {
    const e1 = document.getElementById('dep_img1_' + i);
    const e2 = document.getElementById('dep_img2_' + i);
    if (e1) item.img1 = e1.value;
    if (e2) item.img2 = e2.value;
  });

  return {
    hero: {
      tag: v('hero_tag'),
      title_line1: v('hero_title1'),
      title_line2: v('hero_title2'),
      subtitle: v('hero_sub'),
      cta_text: v('hero_cta')
    },
    proof_bar: proofArr.map((item, i) => ({
      bold: v('proof_bold_' + i) || item.bold,
      text: v('proof_text_' + i) || item.text,
      oculto: item.oculto
    })),
    transformacoes: transfArr.map((item, i) => ({
      img: item.img,
      nome: v('transf_nome_' + i) || item.nome,
      resultado: v('transf_res_' + i) || item.resultado,
      oculto: item.oculto
    })),
    consultoria: {
      badge: v('cons_badge'),
      promise_line1: v('cons_p1'),
      promise_em: v('cons_em'),
      promise_line2: v('cons_p2'),
      nota: v('cons_nota'),
      cta_text: v('cons_cta'),
      pilares: pilaresArr.map((item, i) => ({
        num: v('pilar_num_' + i) || item.num,
        titulo: v('pilar_titulo_' + i) || item.titulo,
        desc: v('pilar_desc_' + i) || item.desc,
        oculto: item.oculto
      }))
    },
    equipe: equipeArr.map((item, i) => ({
      img: item.img,
      nome: v('eq_nome_' + i) || item.nome,
      cred: v('eq_cred_' + i) || item.cred,
      spec: v('eq_spec_' + i) || item.spec,
      bio: document.getElementById('eq_bio_' + i) ? document.getElementById('eq_bio_' + i).value.trim() : item.bio,
      ig: v('eq_ig_' + i) || item.ig,
      oculto: item.oculto
    })),
    depoimentos: depArr.map((item, i) => ({
      img1: item.img1,
      img2: item.img2,
      resultado: v('dep_res_' + i) || item.resultado,
      quote: document.getElementById('dep_quote_' + i) ? document.getElementById('dep_quote_' + i).value.trim() : item.quote,
      autor: v('dep_autor_' + i) || item.autor,
      oculto: item.oculto
    })),
    planos: {
      titulo: v('planos_titulo'),
      subtitulo: v('planos_sub'),
      garantia: v('planos_garantia'),
      items: planosArr.map((pl, i) => ({
        nome: v('pl_nome_' + i) || pl.nome,
        destaque: pl.destaque || false,
        badge: v('pl_badge_' + i),
        trimestral_total: v('pl_trim_t_' + i),
        trimestral_parcela: v('pl_trim_p_' + i),
        semestral_total: v('pl_sem_t_' + i),
        semestral_parcela: v('pl_sem_p_' + i),
        anual_total: v('pl_anu_t_' + i),
        anual_parcela: v('pl_anu_p_' + i),
        whatsapp_msg: v('pl_wa_' + i),
        btn_tipo: v('pl_tipo_' + i) || pl.btn_tipo || 'whatsapp',
        link_trimestral: v('pl_ltrim_' + i),
        link_semestral: v('pl_lsem_' + i),
        link_anual: v('pl_lanu_' + i),
        features: (document.getElementById('pl_feat_' + i) ? document.getElementById('pl_feat_' + i).value : '').split('\n').map(s=>s.trim()).filter(Boolean)
      }))
    },
    contato: {
      whatsapp: v('ct_wa'),
      email: v('ct_email'),
      instagram: v('ct_ig'),
      cnpj: v('ct_cnpj'),
      endereco: v('ct_end'),
      cref_rodape: v('ct_cref'),
      whatsapp_saudacao_site: v('ct_wa_saudacao')
    },
    seo: {
      title: v('seo_title'),
      description: v('seo_desc'),
      og_title: v('seo_ogt'),
      og_description: v('seo_ogd'),
      og_image: v('seo_ogimg')
    },
    blocos_ocultos: blocosArr
  };
}

// ─── PUBLISH ──────────────────────────────────────────────────────────────────
function publishSite() {
  const data = collectData();
  fetch('save.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  }).then(r => r.json()).then(j => {
    if (j.ok) {
      toast('Site publicado com sucesso!');
      setTimeout(() => window.location.href = 'painel.php?saved=1', 1500);
    } else {
      toast(j.error || 'Erro ao publicar', 'error');
    }
  }).catch(() => toast('Erro de conexão', 'error'));
}

// ─── CROPPER ──────────────────────────────────────────────────────────────────
let cropper = null;
let cropSourceFile = '';

function openCropper(imgSrc, filename) {
  // Fechar picker modal se estiver aberto
  document.getElementById('imgModal').classList.remove('open');

  cropSourceFile = filename;
  // Sugerir nome de arquivo com sufixo -crop
  const base = filename.replace(/\.[^.]+$/, '');
  document.getElementById('cropFilename').value = base + '-crop';

  const cropImg = document.getElementById('cropImg');
  cropImg.src = imgSrc + '?t=' + Date.now(); // cache bust

  document.getElementById('cropModal').classList.add('open');

  // Aguardar imagem carregar antes de iniciar cropper
  cropImg.onload = function() {
    if (cropper) { cropper.destroy(); cropper = null; }
    cropper = new Cropper(cropImg, {
      aspectRatio: 1,
      viewMode: 1,
      dragMode: 'move',
      autoCropArea: 0.85,
      restore: false,
      guides: true,
      center: true,
      highlight: false,
      cropBoxMovable: true,
      cropBoxResizable: true,
      toggleDragModeOnDblclick: false,
      background: true,
    });
    // Marcar ratio 1:1 como ativo por padrão
    document.querySelectorAll('.crop-ratio-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.crop-ratio-btn').classList.add('active');
  };
}

function closeCropper() {
  document.getElementById('cropModal').classList.remove('open');
  if (cropper) { cropper.destroy(); cropper = null; }
}

function setRatio(w, h, btn) {
  document.querySelectorAll('.crop-ratio-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  if (cropper) cropper.setAspectRatio(isNaN(w) ? NaN : w / h);
}

function rotateCrop(deg) {
  if (cropper) cropper.rotate(deg);
}

function flipCropX() {
  if (!cropper) return;
  const data = cropper.getData();
  cropper.scaleX(-(cropper.getData().scaleX || 1));
}

async function applyCrop() {
  if (!cropper) return;

  const loading = document.getElementById('cropLoading');
  loading.classList.add('show');

  const canvas = cropper.getCroppedCanvas({ maxWidth: 1200, maxHeight: 1200, imageSmoothingQuality: 'high' });
  const base64 = canvas.toDataURL('image/jpeg', 0.85);

  const rawName = document.getElementById('cropFilename').value.trim();
  const filename = (rawName.replace(/[^a-z0-9_-]/gi, '-').toLowerCase() || 'crop') + '.jpg';

  try {
    const r = await fetch('crop.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ image: base64, filename })
    });
    const j = await r.json();
    loading.classList.remove('show');

    if (j.ok) {
      toast('Recorte salvo: ' + j.filename);
      closeCropper();
      // Se picker estava aberto com alvo, selecionar automaticamente
      if (currentImgTarget) {
        selectImg(j.filename);
      }
      // Atualizar grids de imagem
      await refreshImgGrid();
    } else {
      toast(j.error || 'Erro ao salvar recorte', 'error');
    }
  } catch(e) {
    loading.classList.remove('show');
    toast('Erro de conexão', 'error');
  }
}

// ─── INIT ─────────────────────────────────────────────────────────────────────
renderProof();
renderTransf();
renderPilares();
renderEquipe();
renderDep();
renderPlanos();

// ─── PÁGINAS ──────────────────────────────────────────────────────────────────
async function loadPagesList() {
  const r = await fetch('pages.php?action=list');
  const j = await r.json();
  if (!j.ok) { toast('Erro ao carregar páginas', 'error'); return; }
  const el = document.getElementById('pages-list');
  if (!j.pages.length) { el.innerHTML = '<div style="color:var(--gray-m);font-size:13px">Nenhuma página ainda.</div>'; return; }
  el.innerHTML = j.pages.map(p => `
    <div style="background:var(--bg3);border:1px solid #222;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
      <div style="flex:1">
        <div style="font-size:14px;font-weight:700">${p.title}</div>
        <div style="font-size:12px;color:var(--gray-m);margin-top:2px">
          <span style="color:${p.published?'#25D366':'var(--orange)'}">● ${p.published?'Publicado':'Rascunho'}</span>
          &nbsp;·&nbsp; <a href="${p.url}" target="_blank" style="color:var(--gray-m)">intusfit.com.br${p.url}</a>
          &nbsp;·&nbsp; Atualizado ${p.updated}
        </div>
      </div>
      <div style="display:flex;gap:8px">
        ${p.is_main ? '' : `<button class="btn-action" onclick="editPage('${p.slug}')">Editar</button>`}
        <button class="btn-action" onclick="publishPage('${p.slug}')" style="color:#25D366;border-color:#25D366">Publicar</button>
        ${p.is_main ? '' : `<button class="btn-action btn-del" onclick="deletePage('${p.slug}')">Excluir</button>`}
      </div>
    </div>
  `).join('');
}

async function editPage(slug) {
  const r = await fetch('pages.php?action=load&slug=' + encodeURIComponent(slug));
  const j = await r.json();
  if (!j.ok) { toast('Erro ao carregar página', 'error'); return; }
  // Load content into editor
  window._editingSlug = slug;
  loadContentIntoEditor(j.content);
  toast('Editando: ' + slug + ' — Clique em Publicar quando terminar');
  showSection('hero', document.getElementById('sbi-hero'));
}

async function publishPage(slug) {
  const content = collectData();
  const r = await fetch('page-save.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug: slug === '__main__' ? '__main__' : (window._editingSlug || slug), content })
  });
  const j = await r.json();
  if (j.ok) { toast('Publicado em ' + j.url); loadPagesList(); }
  else toast(j.error || 'Erro ao publicar', 'error');
}

async function deletePage(slug) {
  if (!confirm('Excluir a página /' + slug + '/? Esta ação não pode ser desfeita.')) return;
  const r = await fetch('pages.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug })
  });
  const j = await r.json();
  if (j.ok) { toast('Página excluída'); loadPagesList(); }
  else toast(j.error || 'Erro', 'error');
}

function openNewPageModal() {
  // Load templates for dropdown
  fetch('templates.php?action=list').then(r=>r.json()).then(j => {
    const opts = (j.templates||[]).map(t=>`<option value="${t.id}">${t.name}</option>`).join('');
    document.getElementById('newPageModal').classList.add('open');
    document.getElementById('tpl-select').innerHTML = '<option value="">— Clonar página atual —</option>' + opts;
  });
}
function closeNewPageModal() {
  document.getElementById('newPageModal').classList.remove('open');
}
async function createNewPage() {
  const slug    = document.getElementById('new-page-slug').value.trim();
  const title   = document.getElementById('new-page-title').value.trim();
  const tplId   = document.getElementById('tpl-select').value;
  if (!slug) { toast('Informe o slug da URL', 'error'); return; }
  const r = await fetch('pages.php?action=create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ slug, title, template: tplId })
  });
  const j = await r.json();
  if (j.ok) {
    toast('Página criada: /' + j.slug + '/');
    closeNewPageModal();
    loadPagesList();
  } else toast(j.error || 'Erro', 'error');
}

// ─── MODELOS ──────────────────────────────────────────────────────────────────
async function loadModelsList() {
  const r = await fetch('templates.php?action=list');
  const j = await r.json();
  const el = document.getElementById('models-list');
  if (!j.ok || !j.templates.length) {
    el.innerHTML = '<div style="color:var(--gray-m);font-size:13px">Nenhum modelo salvo ainda. Salve o estado atual para criar o primeiro modelo.</div>';
    return;
  }
  el.innerHTML = j.templates.map(t => `
    <div style="background:var(--bg3);border:1px solid #222;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
      <div style="flex:1">
        <div style="font-size:14px;font-weight:700">${t.name}</div>
        <div style="font-size:12px;color:var(--gray-m);margin-top:2px">ID: ${t.id} &nbsp;·&nbsp; Atualizado ${t.updated}</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn-action" onclick="loadModel('${t.id}')">Carregar</button>
        <button class="btn-action" onclick="renameModel('${t.id}','${t.name.replace(/'/g,'\\\'')}')" style="color:var(--gray-l)">Renomear</button>
        <button class="btn-action btn-del" onclick="deleteModel('${t.id}')">Excluir</button>
      </div>
    </div>
  `).join('');
}

function openSaveModelModal() {
  document.getElementById('saveModelModal').classList.add('open');
  document.getElementById('model-name-input').value = '';
  document.getElementById('model-name-input').focus();
}
function closeSaveModelModal() {
  document.getElementById('saveModelModal').classList.remove('open');
}
async function saveCurrentAsModel() {
  const name = document.getElementById('model-name-input').value.trim();
  if (!name) { toast('Informe o nome do modelo', 'error'); return; }
  const content = collectData();
  const r = await fetch('templates.php?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, content })
  });
  const j = await r.json();
  if (j.ok) { toast('Modelo salvo: ' + j.name); closeSaveModelModal(); loadModelsList(); }
  else toast(j.error || 'Erro', 'error');
}

async function loadModel(id) {
  if (!confirm('Carregar este modelo vai substituir o conteúdo atual no editor. Continuar?')) return;
  const r = await fetch('templates.php?action=load&id=' + encodeURIComponent(id));
  const j = await r.json();
  if (!j.ok) { toast('Erro ao carregar modelo', 'error'); return; }
  loadContentIntoEditor(j.content);
  toast('Modelo carregado — revise e clique em Publicar');
  showSection('hero', document.getElementById('sbi-hero'));
}

async function deleteModel(id) {
  if (!confirm('Excluir este modelo?')) return;
  const r = await fetch('templates.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id })
  });
  const j = await r.json();
  if (j.ok) { toast('Modelo excluído'); loadModelsList(); }
  else toast(j.error || 'Erro', 'error');
}

async function renameModel(id, currentName) {
  const name = prompt('Novo nome do modelo:', currentName);
  if (!name || name === currentName) return;
  const r = await fetch('templates.php?action=rename', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, name })
  });
  const j = await r.json();
  if (j.ok) { toast('Modelo renomeado'); loadModelsList(); }
  else toast(j.error || 'Erro', 'error');
}

function loadContentIntoEditor(c) {
  // Hero
  setV('hero_tag', c.hero?.tag);
  setV('hero_title1', c.hero?.title_line1);
  setV('hero_title2', c.hero?.title_line2);
  setV('hero_sub', c.hero?.subtitle);
  setV('hero_cta', c.hero?.cta_text);
  // Proof
  if (c.proof_bar) { proofArr = c.proof_bar; renderProof(); }
  // Transformações
  if (c.transformacoes) { transfArr = c.transformacoes; renderTransf(); }
  // Consultoria
  if (c.consultoria) {
    setV('cons_badge', c.consultoria.badge);
    setV('cons_p1', c.consultoria.promise_line1);
    setV('cons_em', c.consultoria.promise_em);
    setV('cons_p2', c.consultoria.promise_line2);
    setV('cons_nota', c.consultoria.nota);
    setV('cons_cta', c.consultoria.cta_text);
    if (c.consultoria.pilares) { pilaresArr = c.consultoria.pilares; renderPilares(); }
  }
  // Equipe
  if (c.equipe) { equipeArr = c.equipe; renderEquipe(); }
  // Depoimentos
  if (c.depoimentos) { depArr = c.depoimentos; renderDep(); }
  // Planos
  if (c.planos) {
    setV('plan_titulo', c.planos.titulo);
    setV('plan_subtitulo', c.planos.subtitulo);
    setV('plan_garantia', c.planos.garantia);
    if (c.planos.items) { planosArr = c.planos.items; renderPlanos(); }
  }
  // Contato
  if (c.contato) {
    setV('ct_wa', c.contato.whatsapp);
    setV('ct_email', c.contato.email);
    setV('ct_insta', c.contato.instagram);
    setV('ct_cnpj', c.contato.cnpj);
    setV('ct_end', c.contato.endereco);
    setV('ct_cref', c.contato.cref_rodape);
    setV('ct_wa_saudacao', c.contato.whatsapp_saudacao_site);
  }
  // SEO
  if (c.seo) {
    setV('seo_title', c.seo.title);
    setV('seo_desc', c.seo.description);
    setV('seo_ogt', c.seo.og_title);
    setV('seo_ogd', c.seo.og_description);
    setV('seo_ogim', c.seo.og_image);
  }
}
function setV(id, val) {
  const el = document.getElementById(id);
  if (el && val !== undefined) el.value = val;
}

// Init pages/models lists when sections are shown
const _origShowSection = showSection;
showSection = function(id, item) {
  _origShowSection(id, item);
  if (id === 'paginas') loadPagesList();
  if (id === 'modelos') loadModelsList();
};
</script>

<!-- MODAL: Nova Página -->
<div class="modal-overlay" id="newPageModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h2>Nova Página</h2>
      <button class="modal-close" onclick="closeNewPageModal()">×</button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="field">
        <label>Título da página</label>
        <input type="text" id="new-page-title" placeholder="Ex: Landing Promoção Verão">
      </div>
      <div class="field">
        <label>URL (slug)</label>
        <div style="display:flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid #2a2a2a;border-radius:8px;padding:10px 14px">
          <span style="color:var(--gray-m);font-size:13px">intusfit.com.br/</span>
          <input id="new-page-slug" type="text" placeholder="promo-verao" style="background:transparent;border:none;color:var(--white);font-size:13px;outline:none;flex:1;font-family:inherit" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'-')">
        </div>
      </div>
      <div class="field">
        <label>Modelo base</label>
        <select id="tpl-select" style="width:100%;background:var(--bg3);border:1px solid #2a2a2a;border-radius:8px;padding:10px 14px;color:var(--white);font-size:13px;font-family:inherit"></select>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:4px">
        <button class="btn-crop-cancel" onclick="closeNewPageModal()">Cancelar</button>
        <button class="btn-crop-apply" onclick="createNewPage()">Criar página</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Salvar Modelo -->
<div class="modal-overlay" id="saveModelModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h2>Salvar como Modelo</h2>
      <button class="modal-close" onclick="closeSaveModelModal()">×</button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <p style="font-size:13px;color:var(--gray-l)">O conteúdo atual do editor será salvo como modelo reutilizável para novas páginas.</p>
      <div class="field">
        <label>Nome do modelo</label>
        <input type="text" id="model-name-input" placeholder="Ex: Consultoria Premium — Padrão" onkeydown="if(event.key==='Enter')saveCurrentAsModel()">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:4px">
        <button class="btn-crop-cancel" onclick="closeSaveModelModal()">Cancelar</button>
        <button class="btn-crop-apply" onclick="saveCurrentAsModel()">Salvar modelo</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
<?php function he($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); } ?>
