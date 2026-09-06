<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= s($c['seo']['title']) ?></title>
<meta name="description" content="<?= s($c['seo']['description']) ?>">
<!-- Favicon -->
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $baseUrl ?>/">
<meta property="og:title" content="<?= s($c['seo']['og_title']) ?>">
<meta property="og:description" content="<?= s($c['seo']['og_description']) ?>">
<meta property="og:image" content="https://intusfit.com.br/img/<?= s($c['seo']['og_image']) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="pt_BR">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= s($c['seo']['og_title']) ?>">
<meta name="twitter:description" content="<?= s($c['seo']['og_description']) ?>">
<meta name="twitter:image" content="https://intusfit.com.br/img/<?= s($c['seo']['og_image']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#0A0A0A;--bg2:#111111;--bg3:#161616;
  --white:#F5F5F5;--gray-d:#3A3A3A;--gray-m:#7A7A7A;--gray-l:#C4C4C4;
  --red:#CC2936;--red-dark:#991F2A;--red-faint:rgba(204,41,54,.08);
  --orange:#E8560A;--orange-dark:#C44A08;--orange-faint:rgba(232,86,10,.10);
}
html{scroll-behavior:smooth}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--white);line-height:1.6;overflow-x:hidden}
h1,h2,h3{font-family:'Barlow Condensed',sans-serif;text-transform:uppercase;letter-spacing:-.5px}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
.nav{position:fixed;top:0;left:0;right:0;z-index:100;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;background:rgba(10,10,10,.94);backdrop-filter:blur(14px);border-bottom:1px solid #1a1a1a}
.nav-logo{height:30px}
.nav-links{display:flex;align-items:center;gap:28px;font-size:13px;color:var(--gray-m)}
.nav-links a:hover{color:var(--white)}
.nav-cta{background:var(--red);color:#fff;padding:9px 20px;border-radius:8px;font-weight:600;font-size:13px;transition:background .2s}
.nav-cta:hover{background:var(--red-dark)}
@media(max-width:640px){.nav-links{display:none}}
.hero{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:120px 24px 100px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(204,41,54,.07) 0%,transparent 65%)}
.hero-tag{font-size:12px;font-weight:700;color:var(--red);letter-spacing:3px;text-transform:uppercase;margin-bottom:20px;opacity:.9}
.hero h1{font-size:clamp(52px,9vw,100px);font-weight:900;line-height:.9;margin-bottom:24px}
.hero h1 span{color:var(--red)}
.hero-sub{font-size:clamp(16px,2vw,19px);color:var(--gray-l);max-width:520px;line-height:1.6;margin-bottom:40px}
.hero-cta{display:inline-flex;align-items:center;gap:10px;background:var(--red);color:#fff;padding:17px 40px;border-radius:12px;font-size:16px;font-weight:700;transition:transform .2s,background .2s;box-shadow:0 8px 32px rgba(204,41,54,.25)}
.hero-cta:hover{background:var(--red-dark);transform:translateY(-2px)}
.hero-scroll{margin-top:28px;font-size:12px;color:var(--gray-d);letter-spacing:2px;text-transform:uppercase}
section{padding:96px 24px}
.container{max-width:1080px;margin:0 auto}
.section-tag{font-size:11px;font-weight:700;color:var(--red);letter-spacing:3px;text-transform:uppercase;margin-bottom:10px}
.section-title{font-size:clamp(32px,5vw,54px);font-weight:900;line-height:1;margin-bottom:18px}
.section-desc{font-size:16px;color:var(--gray-l);max-width:520px;line-height:1.7}
.proof-bar{background:var(--bg2);border-top:1px solid #1c1c1c;border-bottom:1px solid #1c1c1c;padding:28px 24px}
.proof-inner{max-width:1080px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 40px}
.proof-item{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gray-m)}
.proof-item strong{color:var(--white);font-weight:600}
.proof-dot{width:4px;height:4px;border-radius:50%;background:var(--gray-d)}
.transf{background:var(--bg2)}
.transf-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:48px}
.transf-card{position:relative;border-radius:16px;overflow:hidden;aspect-ratio:3/4;background:var(--bg3)}
.transf-card img{width:100%;height:100%;object-fit:cover;filter:grayscale(20%);transition:filter .4s,transform .4s}
.transf-card:hover img{filter:grayscale(0%);transform:scale(1.03)}
.transf-overlay{position:absolute;bottom:0;left:0;right:0;padding:20px 18px 18px;background:linear-gradient(to top,rgba(0,0,0,.85) 0%,transparent 100%)}
.transf-name{font-size:15px;font-weight:700;color:var(--white);margin-bottom:2px}
.transf-result{font-size:13px;color:var(--orange);font-weight:600}
.consultoria{background:var(--bg)}
.consultoria-inner{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.badge{display:inline-block;padding:6px 14px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;margin-bottom:16px}
.badge-orange{background:var(--orange-faint);color:var(--orange)}
.consultoria-promise{font-size:clamp(30px,4vw,46px);font-weight:900;line-height:1.05;margin-bottom:16px}
.consultoria-promise em{color:var(--orange);font-style:normal}
.consultoria-note{font-size:15px;color:var(--gray-l);line-height:1.7;margin-bottom:32px}
.btn-red{display:inline-flex;align-items:center;gap:8px;background:var(--red);color:#fff;padding:14px 32px;border-radius:10px;font-size:15px;font-weight:700;transition:transform .2s,background .2s}
.btn-red:hover{background:var(--red-dark);transform:translateY(-2px)}
.pillars{display:flex;flex-direction:column;gap:14px}
.pillar{display:flex;align-items:flex-start;gap:16px;padding:18px 20px;background:var(--bg2);border:1px solid #1f1f1f;border-radius:12px;transition:border-color .3s}
.pillar:hover{border-color:rgba(232,86,10,.35)}
.pillar-num{font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:900;color:rgba(232,86,10,.45);line-height:1;min-width:28px}
.pillar h4{font-size:14px;font-weight:700;margin-bottom:3px;font-family:'Inter',sans-serif;text-transform:none;letter-spacing:0}
.pillar p{font-size:13px;color:var(--gray-m);line-height:1.5}
.team{background:var(--bg2)}
.team-intro{font-size:16px;color:var(--gray-l);max-width:540px;line-height:1.7;margin-bottom:52px}
.team-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.team-card{background:var(--bg3);border:1px solid #1f1f1f;border-radius:20px;overflow:hidden;transition:border-color .3s,transform .3s}
.team-card:hover{border-color:rgba(204,41,54,.35);transform:translateY(-4px)}
.team-photo{width:100%;aspect-ratio:1/1;overflow:hidden;background:#1a1a1a}
.team-photo img{width:100%;height:100%;object-fit:cover;object-position:top center;filter:grayscale(15%);transition:filter .3s}
.team-card:hover .team-photo img{filter:grayscale(0%)}
.team-body{padding:22px 20px 20px}
.team-name{font-size:19px;font-weight:800;margin-bottom:3px;font-family:'Inter',sans-serif;text-transform:none;letter-spacing:0}
.team-cred{font-size:11px;color:var(--orange);font-weight:600;letter-spacing:.5px;margin-bottom:10px}
.team-spec{display:inline-block;background:rgba(255,255,255,.04);border:1px solid #2a2a2a;border-radius:5px;font-size:11px;color:var(--gray-m);padding:3px 10px;margin-bottom:12px}
.team-bio{font-size:13px;color:var(--gray-m);line-height:1.6;margin-bottom:14px}
.team-ig{font-size:13px;color:var(--gray-l);font-weight:500}
.team-ig:hover{color:var(--red)}
.depo{background:var(--bg)}
.depo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:48px}
.depo-card{background:var(--bg2);border:1px solid #1f1f1f;border-radius:16px;overflow:hidden;display:flex;flex-direction:column}
.depo-photos{display:grid;grid-template-columns:1fr 1fr}
.depo-photos img{width:100%;aspect-ratio:4/5;object-fit:cover;filter:grayscale(15%)}
.depo-photos img:first-child{filter:grayscale(40%) brightness(.85)}
.depo-body{padding:18px 18px 20px;flex:1;display:flex;flex-direction:column;gap:8px}
.depo-tag{font-size:10px;font-weight:700;color:var(--orange);letter-spacing:2px;text-transform:uppercase}
.depo-result{font-size:18px;font-weight:800;font-family:'Barlow Condensed',sans-serif;text-transform:uppercase;line-height:1.1}
.depo-quote{font-size:13px;color:var(--gray-m);line-height:1.6;flex:1;font-style:italic}
.depo-author{font-size:12px;color:var(--gray-d);margin-top:4px;font-weight:600}
.platform{background:var(--bg2)}
.features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:48px}
.feature-card{background:var(--bg3);border:1px solid #1f1f1f;border-radius:16px;padding:28px 24px;transition:border-color .3s}
.feature-card:hover{border-color:rgba(204,41,54,.3)}
.feature-icon{width:44px;height:44px;background:var(--red-faint);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.feature-icon svg{width:22px;height:22px;stroke:var(--red);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.feature-card h3{font-size:17px;font-weight:700;margin-bottom:6px;text-transform:none;font-family:'Inter',sans-serif;letter-spacing:0}
.feature-card p{font-size:13px;color:var(--gray-l);line-height:1.6}
.method{background:var(--bg)}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0;margin-top:52px;position:relative}
.steps::before{content:'';position:absolute;top:28px;left:40px;right:40px;height:1px;background:linear-gradient(90deg,var(--red),var(--orange));opacity:.25;pointer-events:none}
.step{padding:0 24px 32px;position:relative;text-align:center}
.step-num{width:56px;height:56px;border-radius:50%;background:var(--bg2);border:1px solid #222;display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-size:24px;font-weight:900;color:var(--red);margin:0 auto 18px}
.step h3{font-size:16px;font-weight:700;margin-bottom:6px;text-transform:none;font-family:'Inter',sans-serif;letter-spacing:0}
.step p{font-size:13px;color:var(--gray-m);line-height:1.6}
.plans-section{background:var(--bg2)}
.plans-header{display:flex;flex-direction:column;align-items:flex-start;gap:0;margin-bottom:40px}
.plans-sub{font-size:16px;color:var(--gray-l);max-width:480px;margin-top:10px;line-height:1.7}
.plans-toggle{display:flex;align-items:center;gap:0;background:var(--bg3);border:1px solid #222;border-radius:10px;overflow:hidden;width:fit-content;margin-bottom:44px}
.plans-toggle button{padding:10px 26px;font-size:14px;font-weight:600;border:none;cursor:pointer;background:transparent;color:var(--gray-m);transition:all .2s;font-family:'Inter',sans-serif}
.plans-toggle button.active{background:var(--red);color:#fff}
.plans-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:28px}
.plan-card{background:var(--bg3);border:1px solid #1f1f1f;border-radius:20px;padding:32px 26px;text-align:center;transition:border-color .3s,transform .3s;position:relative}
.plan-card:hover{border-color:var(--red);transform:translateY(-4px)}
.plan-card.featured{border-color:var(--red);background:#130a0a}
.plan-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:4px 14px;border-radius:20px;letter-spacing:1px;text-transform:uppercase;white-space:nowrap}
.plan-type{font-size:11px;font-weight:700;color:var(--gray-m);letter-spacing:2px;text-transform:uppercase;margin-bottom:6px}
.plan-name{font-size:22px;font-weight:900;margin-bottom:22px}
.plan-price{font-family:'Barlow Condensed',sans-serif;font-size:56px;font-weight:900;color:var(--white);line-height:1}
.plan-price .currency{font-size:22px;vertical-align:super;margin-right:2px;color:var(--gray-l)}
.plan-period{font-size:12px;color:var(--gray-m);margin:4px 0 26px}
.plan-features{list-style:none;text-align:left;margin-bottom:28px}
.plan-features li{font-size:13px;color:var(--gray-l);padding:8px 0;border-bottom:1px solid #1c1c1c;display:flex;align-items:center;gap:8px}
.plan-features li:last-child{border-bottom:none}
.plan-features li::before{content:'✓';color:var(--orange);font-weight:700;min-width:14px}
.plan-btn{display:block;width:100%;padding:13px;border-radius:10px;font-size:14px;font-weight:700;text-align:center;transition:all .2s;border:none;cursor:pointer;font-family:'Inter',sans-serif}
.plan-btn-outline{background:transparent;border:1.5px solid #2a2a2a;color:var(--white)}
.plan-btn-outline:hover{border-color:var(--red);color:var(--red)}
.plan-btn-solid{background:var(--red);color:#fff}
.plan-btn-solid:hover{background:var(--red-dark)}
.plan-btn-link{background:var(--orange);color:#fff}
.plan-btn-link:hover{background:var(--orange-dark)}
.plan-btn-link-outline{background:transparent;border:1.5px solid #444;color:var(--gray-l);margin-top:8px}
.plan-btn-link-outline:hover{border-color:var(--orange);color:var(--orange)}
.plan-btn-wa{display:block;width:100%;padding:13px;border-radius:10px;font-size:14px;font-weight:700;text-align:center;transition:all .2s;border:1.5px solid #2a2a2a;background:transparent;color:var(--white);cursor:pointer;font-family:'Inter',sans-serif;margin-top:8px}
.plan-btn-wa:hover{border-color:#25D366;color:#25D366}
.plans-note{text-align:center;font-size:13px;color:var(--gray-m)}
.plans-note strong{color:var(--white)}
.plans-guarantee{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:20px;background:var(--bg3);border:1px solid #1f1f1f;border-radius:12px;padding:16px 24px;max-width:500px;margin-left:auto;margin-right:auto}
.plans-guarantee svg{stroke:var(--orange);flex-shrink:0}
.plans-guarantee p{font-size:13px;color:var(--gray-l)}
.plans-guarantee strong{color:var(--white)}
.plan-prices{display:none}
.plan-prices.active{display:block}
.plan-btns{margin-top:0}
.cta-section{text-align:center;padding:120px 24px;background:linear-gradient(180deg,var(--bg2) 0%,var(--bg) 100%)}
.cta-section h2{font-size:clamp(38px,7vw,68px);font-weight:900;margin-bottom:14px}
.cta-section p{font-size:17px;color:var(--gray-l);margin-bottom:36px}
.cta-btns{display:flex;flex-wrap:wrap;gap:14px;justify-content:center}
.cta-btn{display:inline-flex;align-items:center;gap:8px;background:var(--red);color:#fff;padding:18px 48px;border-radius:12px;font-size:17px;font-weight:700;transition:transform .2s,background .2s;box-shadow:0 8px 32px rgba(204,41,54,.22)}
.cta-btn:hover{background:var(--red-dark);transform:translateY(-2px)}
.cta-btn-ghost{background:transparent;border:1.5px solid #2a2a2a;color:var(--white);padding:18px 48px;border-radius:12px;font-size:17px;font-weight:700;display:inline-flex;align-items:center;gap:8px;transition:all .2s}
.cta-btn-ghost:hover{border-color:var(--gray-l)}
footer{border-top:1px solid #181818;padding:48px 24px;text-align:center}
.footer-logo{height:26px;margin:0 auto 18px}
.footer-links{display:flex;flex-wrap:wrap;justify-content:center;gap:20px;margin-bottom:16px;font-size:13px;color:var(--gray-m)}
.footer-links a:hover{color:var(--white)}
.footer-copy{font-size:12px;color:var(--gray-d);line-height:1.8}
.wa-float{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;align-items:center;gap:10px;background:#25D366;color:#fff;border-radius:50px;padding:13px 20px 13px 16px;font-size:14px;font-weight:700;box-shadow:0 4px 20px rgba(37,211,102,.4);transition:transform .2s,box-shadow .2s;text-decoration:none}
.wa-float:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(37,211,102,.5)}
.wa-float svg{width:22px;height:22px;fill:#fff;flex-shrink:0}
.wa-float-text{white-space:nowrap}
@media(max-width:900px){.consultoria-inner{grid-template-columns:1fr;gap:40px}.team-grid{grid-template-columns:1fr 1fr}.plans-grid{grid-template-columns:1fr}.transf-grid{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.nav{padding:12px 16px}section{padding:72px 20px}.team-grid{grid-template-columns:1fr}.transf-grid{grid-template-columns:1fr 1fr}.depo-grid{grid-template-columns:1fr}.steps{grid-template-columns:1fr 1fr}.steps::before{display:none}.cta-btns{flex-direction:column;align-items:center}.wa-float-text{display:none}.wa-float{padding:14px;border-radius:50%}}
@media(max-width:400px){.transf-grid{grid-template-columns:1fr}.steps{grid-template-columns:1fr}}
</style>
</head>
<body>

<nav class="nav">
  <img src="/app/painel/logo-intus-dark.png" alt="Intus Fit" class="nav-logo">
  <div class="nav-links">
    <a href="#consultoria">Consultoria</a>
    <a href="#equipe">Equipe</a>
    <a href="#planos">Planos</a>
  </div>
  <a href="/app/painel/login.html" class="nav-cta">Acessar App</a>
</nav>

<section class="hero">
  <div class="hero-tag"><?= s($c['hero']['tag']) ?></div>
  <h1><?= s($c['hero']['title_line1']) ?><br><span><?= s($c['hero']['title_line2']) ?></span></h1>
  <p class="hero-sub"><?= s($c['hero']['subtitle']) ?></p>
  <a href="#consultoria" class="hero-cta">
    <?= s($c['hero']['cta_text']) ?>
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
  </a>
  <div class="hero-scroll">Scroll para ver</div>
</section>

<?php if (!blocoOculto('proof_bar')): ?>
<div class="proof-bar">
  <div class="proof-inner">
<?php
$proofVisiveis = array_filter($c['proof_bar'], fn($pb) => empty($pb['oculto']));
foreach (array_values($proofVisiveis) as $i => $pb):
?>
    <?php if ($i > 0): ?><div class="proof-dot"></div><?php endif; ?>
    <div class="proof-item"><?php
      $bold = s($pb['bold']); $text = s($pb['text']);
      if (strpos($text, $bold) !== false) {
        echo str_replace($bold, "<strong>$bold</strong>", $text);
      } else {
        echo "<strong>$bold</strong> $text";
      }
    ?></div>
<?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!blocoOculto('transformacoes')): ?>
<section class="transf" id="resultados">
  <div class="container">
    <div class="section-tag">Resultados reais</div>
    <h2 class="section-title">Pessoas comuns.<br>Transformações extraordinárias.</h2>
    <p class="section-desc">Todas as fotos são de alunos reais que aplicaram o método Intus Fit.</p>
    <div class="transf-grid">
<?php foreach ($c['transformacoes'] as $t):
  if (!empty($t['oculto'])) continue; ?>
      <div class="transf-card">
        <img src="<?= $imgBase ?><?= s($t['img']) ?>" alt="Transformação — <?= s($t['nome']) ?>" loading="lazy">
        <div class="transf-overlay">
          <div class="transf-name"><?= s($t['nome']) ?></div>
          <div class="transf-result"><?= s($t['resultado']) ?></div>
        </div>
      </div>
<?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('consultoria')): ?>
<section class="consultoria" id="consultoria">
  <div class="container">
    <div class="consultoria-inner">
      <div>
        <span class="badge badge-orange"><?= s($c['consultoria']['badge']) ?></span>
        <h2 class="consultoria-promise"><?= s($c['consultoria']['promise_line1']) ?><br><em><?= s($c['consultoria']['promise_em']) ?></em><br><?= s($c['consultoria']['promise_line2']) ?></h2>
        <p class="consultoria-note"><?= s($c['consultoria']['nota']) ?></p>
        <a href="#planos" class="btn-red">
          <?= s($c['consultoria']['cta_text']) ?>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
      </div>
      <div class="pillars">
<?php foreach ($c['consultoria']['pilares'] as $p):
  if (!empty($p['oculto'])) continue; ?>
        <div class="pillar">
          <div class="pillar-num"><?= s($p['num']) ?></div>
          <div>
            <h4><?= s($p['titulo']) ?></h4>
            <p><?= s($p['desc']) ?></p>
          </div>
        </div>
<?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('equipe')): ?>
<section class="team" id="equipe">
  <div class="container">
    <div class="section-tag">Equipe</div>
    <h2 class="section-title">Quem vai te<br>acompanhar</h2>
    <p class="team-intro">Não é um app que te atende. São especialistas reais, com histórico e credencial, comprometidos com o seu resultado.</p>
    <div class="team-grid">
<?php foreach ($c['equipe'] as $m):
  if (!empty($m['oculto'])) continue; ?>
      <div class="team-card">
        <div class="team-photo">
          <img src="<?= $imgBase ?><?= s($m['img']) ?>" alt="<?= s($m['nome']) ?>">
        </div>
        <div class="team-body">
          <div class="team-name"><?= s($m['nome']) ?></div>
          <div class="team-cred"><?= s($m['cred']) ?></div>
          <span class="team-spec"><?= s($m['spec']) ?></span>
          <p class="team-bio"><?= s($m['bio']) ?></p>
          <a href="https://instagram.com/<?= s($m['ig']) ?>" target="_blank" class="team-ig">@<?= s($m['ig']) ?></a>
        </div>
      </div>
<?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('depoimentos')): ?>
<section class="depo">
  <div class="container">
    <div class="section-tag">Evoluções</div>
    <h2 class="section-title">Resultado de<br><span style="color:var(--orange)">quem aplicou</span></h2>
    <p class="section-desc">Do emagrecimento à competição — o método funciona para todos os objetivos.</p>
    <div class="depo-grid">
<?php foreach ($c['depoimentos'] as $d):
  if (!empty($d['oculto'])) continue; ?>
      <div class="depo-card">
        <div class="depo-photos">
          <img src="<?= $imgBase ?><?= s($d['img1']) ?>" alt="Antes" loading="lazy">
          <img src="<?= $imgBase ?><?= s($d['img2']) ?>" alt="Depois" loading="lazy">
        </div>
        <div class="depo-body">
          <div class="depo-tag">Consultoria Premium</div>
          <div class="depo-result"><?= s($d['resultado']) ?></div>
          <p class="depo-quote">"<?= s($d['quote']) ?>"</p>
          <div class="depo-author">— <?= s($d['autor']) ?></div>
        </div>
      </div>
<?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('plataforma')): ?>
<section class="platform">
  <div class="container">
    <div class="section-tag">Plataforma</div>
    <h2 class="section-title">Sua evolução,<br>organizada</h2>
    <p class="section-desc">O app que coloca seu treino, sua nutrição e seu profissional no mesmo lugar.</p>
    <div class="features-grid">
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg></div><h3>Treinos personalizados</h3><p>Fichas prescritas individualmente. Séries, cargas e progressão sob medida para o seu nível.</p></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><h3>Histórico completo</h3><p>Registre cada sessão. Acompanhe sua evolução com dados reais, não achismos.</p></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div><h3>Comunicação direta</h3><p>Fale com seu profissional pelo app. Dúvidas, feedbacks e ajustes sem complicação.</p></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg></div><h3>Avaliações físicas</h3><p>Acompanhe medidas, composição corporal e evolução ao longo do tempo.</p></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><h3>Agenda de treinos</h3><p>Saiba exatamente o que treinar cada dia. Organização que gera constância.</p></div>
      <div class="feature-card"><div class="feature-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/></svg></div><h3>Modo claro e escuro</h3><p>Interface adaptada para treinar de dia ou de noite, como preferir.</p></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('metodo')): ?>
<section class="method">
  <div class="container">
    <div class="section-tag">Como funciona</div>
    <h2 class="section-title">Em 4 passos</h2>
    <p class="section-desc">Simples, direto, sem enrolação.</p>
    <div class="steps">
      <div class="step"><div class="step-num">1</div><h3>Cadastre-se</h3><p>Crie sua conta em menos de 1 minuto. Sem burocracia.</p></div>
      <div class="step"><div class="step-num">2</div><h3>Avaliação inicial</h3><p>Nossa equipe analisa seu momento atual e monta sua prescrição individual.</p></div>
      <div class="step"><div class="step-num">3</div><h3>Treine com método</h3><p>Acesse suas fichas, envie vídeos e evolua com acompanhamento real.</p></div>
      <div class="step"><div class="step-num">4</div><h3>Veja a transformação</h3><p>Acompanhe seus números. Resultado mensurável, semana a semana.</p></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!blocoOculto('planos')): ?>
<section class="plans-section" id="planos">
  <div class="container">
    <div class="plans-header">
      <div class="section-tag">Planos</div>
      <h2 class="section-title"><?= s($c['planos']['titulo']) ?></h2>
      <p class="plans-sub"><?= s($c['planos']['subtitulo']) ?></p>
    </div>
    <div class="plans-toggle" role="group" aria-label="Periodicidade">
      <button class="active" onclick="switchPlan('trimestral', this)">Trimestral</button>
      <button onclick="switchPlan('semestral', this)">Semestral</button>
      <button onclick="switchPlan('anual', this)">Anual</button>
    </div>
    <div class="plans-grid">
<?php foreach ($c['planos']['items'] as $pl):
  $featured  = !empty($pl['destaque']);
  $btnClass  = $featured ? 'plan-btn-solid' : 'plan-btn-outline';
  $waMsg     = urlencode(s($pl['whatsapp_msg']));
  $btnTipo   = s($pl['btn_tipo'] ?? 'whatsapp');
  $ltrim     = s($pl['link_trimestral'] ?? '');
  $lsem      = s($pl['link_semestral']  ?? '');
  $lanu      = s($pl['link_anual']      ?? '');
?>
      <div class="plan-card<?= $featured ? ' featured' : '' ?>">
        <?php if ($pl['badge']): ?><div class="plan-badge"><?= s($pl['badge']) ?></div><?php endif; ?>
        <div class="plan-type">Modalidade</div>
        <div class="plan-name"><?= s($pl['nome']) ?></div>
        <div class="plan-prices active" data-period="trimestral">
          <div class="plan-price"><span class="currency">R$</span><?= s($pl['trimestral_total']) ?></div>
          <div class="plan-period">por trimestre · ou 3x R$<?= s($pl['trimestral_parcela']) ?>/mês</div>
        </div>
        <div class="plan-prices" data-period="semestral">
          <div class="plan-price"><span class="currency">R$</span><?= s($pl['semestral_total']) ?></div>
          <div class="plan-period">por semestre · ou 6x R$<?= s($pl['semestral_parcela']) ?>/mês</div>
        </div>
        <div class="plan-prices" data-period="anual">
          <div class="plan-price"><span class="currency">R$</span><?= s($pl['anual_total']) ?></div>
          <div class="plan-period">por ano · ou 12x R$<?= s($pl['anual_parcela']) ?>/mês</div>
        </div>
        <ul class="plan-features">
<?php foreach ($pl['features'] as $feat): ?>
          <li><?= s($feat) ?></li>
<?php endforeach; ?>
        </ul>
        <div class="plan-btns">
<?php if ($btnTipo === 'whatsapp'): ?>
          <a href="https://wa.me/<?= $wa ?>?text=<?= $waMsg ?>" target="_blank" class="plan-btn <?= $btnClass ?>">Quero esse plano</a>
<?php elseif ($btnTipo === 'link'): ?>
          <a href="<?= $ltrim ?>" class="plan-btn plan-btn-link"
             data-link-trim="<?= $ltrim ?>" data-link-sem="<?= $lsem ?>" data-link-anu="<?= $lanu ?>"
             target="_blank">Assinar agora</a>
<?php elseif ($btnTipo === 'ambos'): ?>
          <a href="<?= $ltrim ?>" class="plan-btn plan-btn-link"
             data-link-trim="<?= $ltrim ?>" data-link-sem="<?= $lsem ?>" data-link-anu="<?= $lanu ?>"
             target="_blank">Assinar agora</a>
          <a href="https://wa.me/<?= $wa ?>?text=<?= $waMsg ?>" target="_blank" class="plan-btn plan-btn-wa">Falar no WhatsApp</a>
<?php endif; ?>
        </div>
      </div>
<?php endforeach; ?>
    </div>
    <p class="plans-note">Todos os planos parcelados em <strong>até 12x</strong> no cartão de crédito.</p>
    <div class="plans-guarantee">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <p><strong>Teste por 15 dias.</strong> <?= s($c['planos']['garantia']) ?></p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="cta-section">
  <h2>PRONTO PARA O<br><span style="color:var(--red)">PROXIMO NIVEL?</span></h2>
  <p>Comece hoje. Seu profissional já está esperando.</p>
  <div class="cta-btns">
    <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode($waSauda) ?>" target="_blank" class="cta-btn">
      Falar com a equipe
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
    <a href="/app/painel/login.html" class="cta-btn-ghost">Acessar plataforma</a>
  </div>
</section>

<footer>
  <img src="/app/painel/logo-intus-dark.png" alt="Intus Fit" class="footer-logo">
  <div class="footer-links">
    <a href="/app/painel/login.html">Acessar plataforma</a>
    <a href="mailto:<?= s($c['contato']['email']) ?>"><?= s($c['contato']['email']) ?></a>
    <a href="https://wa.me/<?= $wa ?>" target="_blank">WhatsApp</a>
    <a href="https://instagram.com/<?= s($c['contato']['instagram']) ?>" target="_blank">@<?= s($c['contato']['instagram']) ?></a>
    <a href="https://instagram.com/luiznunest" target="_blank">@luiznunest</a>
  </div>
  <div class="footer-copy">
    &copy; 2026 INTUS FIT &mdash; CNPJ <?= s($c['contato']['cnpj']) ?><br>
    <?= s($c['contato']['endereco']) ?><br>
    <?= s($c['contato']['cref_rodape']) ?>
  </div>
</footer>

<a href="https://wa.me/<?= $wa ?>?text=<?= urlencode($waSauda) ?>" target="_blank" class="wa-float" aria-label="Falar no WhatsApp">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
  <span class="wa-float-text">Falar com a equipe</span>
</a>

<script>
function switchPlan(period, btn) {
  document.querySelectorAll('.plans-toggle button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.plan-prices').forEach(el => {
    el.classList.remove('active');
    if (el.dataset.period === period) el.classList.add('active');
  });
  document.querySelectorAll('.plan-btn[data-link-trim]').forEach(a => {
    const map = { trimestral: a.dataset.linkTrim, semestral: a.dataset.linkSem, anual: a.dataset.linkAnu };
    const href = map[period] || '';
    if (href) { a.href = href; a.style.display = ''; }
    else { a.style.display = 'none'; }
  });
}
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const t = document.querySelector(a.getAttribute('href'));
    if (t) { e.preventDefault(); t.scrollIntoView({behavior:'smooth', block:'start'}); }
  });
});
</script>
</body>
</html>
