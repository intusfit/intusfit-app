// Central de conteúdo (/conteudo/) no Chromium, falando com o conteudo.php de verdade (PHP + SQLite, login de mentira).
//   cd testes && PHP_BIN=/caminho/php node conteudo_tela.js     (sem php com pdo_sqlite, o teste é pulado)
const { chromium } = require('playwright');
const { spawn, spawnSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
const PHP = process.env.PHP_BIN || 'php';
const PORTA = 8130, BASE = 'http://127.0.0.1:' + PORTA;
const sonda = spawnSync(PHP, ['-r', 'exit(extension_loaded("pdo_sqlite") && extension_loaded("mbstring") ? 0 : 1);']);
if (sonda.error || sonda.status !== 0) { console.log('!! php com pdo_sqlite e mbstring ausente: teste da tela da Central de conteúdo pulado.'); process.exit(0); }
const BANCO = path.join(os.tmpdir(), 'conteudo-tela-' + process.pid + '.sqlite');
const dormir = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const srv = spawn(PHP, ['-S', '127.0.0.1:' + PORTA, path.join(__dirname, '_conteudo_servidor.php')], { env: Object.assign({}, process.env, { CONT_BANCO: BANCO }), stdio: 'ignore' });
  for (let i = 0; i < 40; i++) { try { const r = await fetch(BASE + '/conteudo/'); if (r.ok) break; } catch (e) {} await dormir(150); }
  const b = await chromium.launch();
  const api = (acao, corpo, token = 'teste-admin', qs = '') => fetch(BASE + '/app/api/conteudo.php?action=' + acao + qs, { method: corpo ? 'POST' : 'GET', headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, body: corpo ? JSON.stringify(corpo) : undefined }).then(async r => ({ st: r.status, j: await r.json() }));
  const nova = async (ctx, token) => {
    const c = await b.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript(t => { try { localStorage.setItem('mx-token', t); localStorage.setItem('mx-theme', 'dark'); } catch (e) {} }, token);
    const pg = await c.newPage(); const erros = []; pg.on('pageerror', e => erros.push(e.message)); pg.on('dialog', d => d.accept());
    pg.erros = erros; return pg;
  };
  try {
    console.log('\n══ Acesso ══');
    eq('sem login: 401', (await api('inicio', null, 'x')).st, 401);
    const semLogin = await b.newPage(); await semLogin.goto(BASE + '/conteudo/'); await semLogin.waitForTimeout(500);
    ver('sem sessão a tela pede o login', await semLogin.locator('#tela-login').isVisible());
    await semLogin.close();

    console.log('\n══ Administrador: painel vazio ══');
    const pg = await nova(null, 'teste-admin');
    await pg.goto(BASE + '/conteudo/'); await pg.waitForSelector('.kpis');
    eq('sete abas', await pg.locator('#abas .aba').count(), 7);
    ver('painel mostra os 3 perfis na cadência', (await pg.locator('.cad .cartao').count()) === 3);
    ver('alerta de semana que vem sem posts', (await pg.locator('.aviso.warn').allInnerTexts()).some(t => t.includes('semana que vem')));

    console.log('\n══ Criar peça, trava de etapa e copy ══');
    await pg.click('[data-aba=quadro]'); await pg.click('[data-act=nova]'); await pg.waitForSelector('#e-titulo');
    await pg.fill('#e-titulo', '<img src=x onerror=window.__xss=1>Treino de perna');
    await pg.selectOption('#e-marca', 'intusfit'); await pg.selectOption('#e-formato', 'reel'); await pg.selectOption('#e-tipo', 'educativo');
    await pg.fill('#e-gancho', 'Sua perna cresce sem equipamento?');
    await pg.click('[data-act=ed-salvar]'); await pg.waitForSelector('#gaveta-fundo', { state: 'detached' });
    eq('texto com HTML aparece como texto, sem executar', await pg.evaluate(() => window.__xss || 0), 0);
    ver('o cartão mostra o título escapado', (await pg.locator('.coluna[data-status=ideia] .kcard .t').first().innerText()).includes('<img src=x'));
    for (const alvo of ['roteiro', 'gravacao', 'edicao']) { await pg.click('.kcard [data-act=mover][data-para=' + alvo + ']'); await pg.waitForTimeout(500); }
    eq('chegou à edição', await pg.locator('.coluna[data-status=edicao] .kcard').count(), 1);
    await pg.click('.kcard [data-act=mover][data-para=revisao]'); await pg.waitForSelector('#dlg-pend');
    ver('sem vídeo: o diálogo diz o que falta', (await pg.locator('#dlg-pend').innerText()).includes('Falta o vídeo'));
    ver('administrador vê "Mover mesmo assim"', await pg.locator('#dlg-pend [data-act=forcar]').isVisible());
    await pg.click('#dlg-pend [data-act=abrir]'); await pg.waitForSelector('#e-titulo');
    await pg.fill('#e-drive', 'https://drive.google.com/drive/folders/abc');
    await pg.fill('#e-legenda', 'Treino de perna — sem equipamento.'); await pg.waitForTimeout(900);
    ver('o conferidor de texto acusa o travessão', (await pg.locator('#e-copy .chip.erro').allInnerTexts()).some(t => t.includes('travessão')));
    await pg.selectOption('#e-status', 'aprovado'); await pg.click('[data-act=ed-salvar]'); await pg.waitForTimeout(1200);
    ver('travessão impede aprovar, mas os campos ficam salvos', await pg.locator('#gaveta-fundo').isVisible() && (await pg.locator('.bloco.pendencias').innerText()).includes('travessão'));
    eq('a peça continua na edição', (await api('inicio')).j.pecas[0].status, 'edicao');
    eq('e o drive foi salvo mesmo assim', (await api('peca', null, 'teste-admin', '&id=' + (await api('inicio')).j.pecas[0].id)).j.peca.drive_link, 'https://drive.google.com/drive/folders/abc');
    await pg.fill('#e-legenda', 'Treino de perna em casa, com o peso do corpo. Salve para fazer depois.');
    await pg.selectOption('#e-status', 'aprovado'); await pg.click('[data-act=ed-salvar]'); await pg.waitForSelector('#gaveta-fundo', { state: 'detached' });
    eq('aprovada', await pg.locator('.coluna[data-status=aprovado] .kcard').count(), 1);

    console.log('\n══ Agendar, calendário e fila ══');
    const hoje = (await api('inicio')).j.hoje; const d = new Date(hoje + 'T12:00:00'); d.setDate(d.getDate() + 1); const amanha = d.toISOString().slice(0, 10);
    await pg.click('.coluna[data-status=aprovado] .kcard'); await pg.waitForSelector('#e-data');
    await pg.fill('#e-data', amanha); await pg.fill('#e-hora', '19:30'); await pg.click('[data-act=ed-avancar]'); await pg.waitForSelector('#gaveta-fundo', { state: 'detached' });
    eq('agendada', await pg.locator('.coluna[data-status=agendado] .kcard').count(), 1);
    await pg.click('[data-aba=calendario]');
    await pg.click('[data-act=novo-evento]'); await pg.fill('#v-titulo', 'Gravação LDA'); await pg.fill('#v-data', amanha); await pg.selectOption('#v-marca', 'luiznunest'); await pg.click('[data-act=ev-salvar]'); await pg.waitForTimeout(800);
    const mesDoDia = amanha.slice(0, 7), mesAtual = hoje.slice(0, 7);
    if (mesDoDia !== mesAtual) await pg.click('[data-act=mes][data-d="1"]');
    ver('calendário mostra a peça e o evento no mesmo dia', (await pg.locator('.cal-grade .chip-cal').allInnerTexts()).filter(t => /Treino de perna|Gravação LDA/.test(t)).length === 2);
    ver('evento tem estilo tracejado', await pg.locator('.cal-grade .chip-cal.evento').count() === 1);
    await pg.click('[data-aba=fila]'); await pg.waitForSelector('.fila-item');
    const fila = await pg.locator('.fila-item').innerText();
    ver('fila mostra o texto final e o perfil', fila.includes('peso do corpo') && fila.includes('@intusfit'));
    ver('fila avisa que a publicação é manual', (await pg.locator('#conteudo').innerText()).includes('manual'));
    await pg.click('[data-act=publicar]'); await pg.fill('#pub-link', 'https://www.instagram.com/p/abc/'); await pg.click('[data-act=publicar-ok]'); await pg.waitForTimeout(900);
    ver('marcar como publicada tira da fila', (await pg.locator('#conteudo').innerText()).includes('Nenhuma peça agendada'));
    const pub = (await api('inicio')).j.pecas[0];
    eq('publicada com o dia de hoje e o link', [pub.status, pub.publicado_em, (await api('peca', null, 'teste-admin', '&id=' + pub.id)).j.peca.resultado.link_publicado], ['publicado', hoje, 'https://www.instagram.com/p/abc/']);

    console.log('\n══ Reciclar ══');
    await api('peca_salvar', { peca: { id: pub.id, reciclar: { marcar: 'sim', em: hoje } } });
    await pg.reload(); await pg.waitForSelector('#abas .aba[aria-selected=true]'); await pg.waitForTimeout(500);   // a marcação veio por fora da tela
    await pg.click('[data-aba=reciclar]'); await pg.waitForSelector('.at-item');
    ver('a peça marcada aparece com "pode reciclar"', (await pg.locator('#conteudo').innerText()).toLowerCase().includes('pode reciclar'));
    await pg.click('[data-act=reciclar]'); await pg.waitForSelector('#gaveta-fundo');
    ver('reciclar abre uma ideia nova ligada à original', (await pg.locator('#gaveta-fundo').innerText()).includes('reciclagem de outra'));
    eq('a ideia nova existe', (await api('inicio')).j.pecas.filter(p => p.status === 'ideia').length, 1);
    await pg.click('[data-act=fechar-editor]');

    console.log('\n══ Ofertas, links e ajustes (administrador) ══');
    await pg.click('[data-aba=ofertas]'); await pg.waitForSelector('#t-ofertas');
    ver('ofertas de fábrica: CDD anual e Premium', (await pg.locator('#t-ofertas input[data-k=nome]').evaluateAll(e => e.map(i => i.value))).includes('CDD anual'));
    ver('produtos que saíram de venda não estão', !(await pg.locator('#t-ofertas').innerText()).match(/Mentoria 4X|Sai Pochete/));
    await pg.click('[data-act=of-novo]'); await pg.locator('#t-ofertas tbody tr:last-child input[data-k=nome]').fill('Oferta de teste'); await pg.locator('#t-ofertas tbody tr:last-child input[data-k=link]').fill('ftp://x');
    await pg.click('[data-act=of-salvar]'); await pg.waitForTimeout(600);
    ver('link inválido é recusado com aviso', (await pg.locator('#toast').innerText()).includes('endereço http'));
    await pg.locator('#t-ofertas tbody tr:last-child input[data-k=link]').fill('https://exemplo.com/oferta'); await pg.click('[data-act=of-salvar]'); await pg.waitForTimeout(800);
    eq('oferta salva', (await api('inicio')).j.ofertas.some(o => o.nome === 'Oferta de teste'), true);
    ver('as pastas do Drive estão na lista de links', (await pg.locator('#t-links input[data-k=nome]').evaluateAll(e => e.map(i => i.value))).includes('Posts e gravações 2025'));
    await pg.click('[data-aba=ajustes]'); await pg.waitForSelector('#aj-marcas');
    await pg.locator('#aj-marcas tbody tr:first-child input[data-k=meta_semana]').fill('5'); await pg.click('[data-act=aj-salvar]'); await pg.waitForTimeout(800);
    eq('meta semanal salva', (await api('inicio')).j.cfg.marcas[0].meta_semana, 5);
    await pg.locator('#aj-marcas tbody tr:nth-child(2) [data-act=aj-del]').click(); await pg.locator('#aj-marcas tbody tr:nth-child(2) [data-act=aj-del]').click();
    await pg.click('[data-act=aj-salvar]'); await pg.waitForTimeout(800);
    eq('só sobra um perfil (o último não deixa apagar)', (await api('inicio')).j.cfg.marcas.length, 1);
    await pg.click('[data-act=aj-restaurar]'); await pg.waitForTimeout(800);
    eq('restaurar volta aos 3 perfis', (await api('inicio')).j.cfg.marcas.length, 3);
    eq('nenhum erro de página', pg.erros, []);

    console.log('\n══ Equipe (com a chave do Intus Cut, sem ser administrador) ══');
    eq('equipe lê o painel', (await api('inicio', null, 'teste-equipe')).st, 200);
    eq('equipe não altera ajustes', (await api('cfg_salvar', { cfg: {} }, 'teste-equipe')).st, 403);
    eq('equipe não altera ofertas', (await api('ofertas_salvar', { itens: [] }, 'teste-equipe')).st, 403);
    eq('equipe não exclui peça', (await api('peca_excluir', { id: pub.id }, 'teste-equipe')).st, 403);
    eq('equipe não força etapa', (await api('peca_salvar', { peca: { titulo: 'x', status: 'agendado' }, forcar: true }, 'teste-equipe')).st, 422);
    const eq1 = await nova(null, 'teste-equipe'); await eq1.goto(BASE + '/conteudo/'); await eq1.waitForSelector('.kpis');
    await eq1.click('[data-aba=ofertas]'); await eq1.waitForSelector('.link-linha');
    eq('equipe não vê botões de editar ofertas', await eq1.locator('[data-act=of-salvar]').count(), 0);
    await eq1.click('[data-aba=ajustes]'); await eq1.waitForSelector('#aj-marcas');
    eq('equipe vê os ajustes só para leitura', [await eq1.locator('[data-act=aj-salvar]').count(), await eq1.locator('#aj-marcas input[data-k=nome]').first().isDisabled()], [0, true]);
    await eq1.close();

    console.log('\n══ Celular ══');
    const mob = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
    await mob.addInitScript(() => { localStorage.setItem('mx-token', 'teste-admin'); });
    const m = await mob.newPage(); const errM = []; m.on('pageerror', e => errM.push(e.message));
    await m.goto(BASE + '/conteudo/'); await m.waitForSelector('.kpis');
    for (const aba of ['painel', 'quadro', 'calendario', 'fila', 'reciclar', 'ofertas', 'ajustes']) {
      await m.click('[data-aba=' + aba + ']'); await m.waitForTimeout(400);
      const larg = await m.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
      ver('375 px: a aba ' + aba + ' não rola para o lado', larg[0] <= larg[1], larg);
    }
    await m.click('[data-aba=calendario]'); await m.waitForTimeout(300);
    eq('no celular o calendário vira lista', await m.evaluate(() => [getComputedStyle(document.querySelector('.cal-grade')).display, getComputedStyle(document.querySelector('.cal-lista')).display]), ['none', 'block']);
    eq('nenhum erro de página no celular', errM, []);
  } finally {
    await b.close(); srv.kill(); try { fs.unlinkSync(BANCO); } catch (e) {}
  }
  console.log('\n' + (ruim === 0 ? 'TUDO OK (' + ok + ')' : ruim + ' FALHA(S) (' + ok + ' ok)'));
  process.exit(ruim === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
