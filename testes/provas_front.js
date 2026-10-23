// Provas sociais no Chromium: aba do Quadro de Resultados (resultados.html + provas.js), com a API de mentira.
//   cd testes && INTUS_DIR=../app/painel node provas_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8111, '127.0.0.1', r));
  const b = await chromium.launch();

  const META = { ok: true, resumo: { total: 1, divulgar: 1, pendentes: 0, robo: 1, destaques: 1 }, objetivos: { emagrecimento: 'Emagrecimento', hipertrofia: 'Ganho de massa', outro: 'Outro' },
    autorizacoes: { pendente: 'Sem autorização ainda', interna: 'Só uso interno (equipe e IA)', divulgar: 'Autorizou divulgar' }, usos: { robo: 'Robô de atendimento', marketing: 'Marketing', site: 'Site', ia: 'Inteligência interna (IA)' },
    limites: { fotos: 12, videos: 4, video_seg: 60, video_mb: 60 }, admin: true, eu: 1 };
  const prova = (o) => Object.assign({ idprova: 1, titulo: 'Marina: 4 meses', pessoa_nome: 'Marina <b>Alves</b>', sexo: 'F', idade: 34, objetivo: 'emagrecimento', programa: 'intus', plano_usado: '', professor: '', tempo_meses: 4, tempo_txt: '4 meses',
    peso_antes: 78.5, peso_depois: 69, gordura_antes: null, gordura_depois: null, medidas_txt: '', resultado_txt: 'Perdeu <i>9,5 kg</i>', depoimento_txt: '', contexto_txt: '', tags: ['mulher'], autorizacao: 'divulgar', autorizacao_obs: '',
    uso_robo: true, uso_marketing: false, uso_site: false, uso_ia: true, destaque: true, ativo: true, criado_por: 1, criado_por_nome: 'Luiz', midias: [], pode_robo: true, pode_marketing: false, pode_site: false, pode_ia: true,
    peso_resumo: 'de 78,5 kg para 69 kg (menos 9,5 kg)', variacao_peso: -9.5 }, o || {});
  const S = { provas: [prova()], chamadas: [], cota: 0 };

  const pg = await (await b.newContext({ viewport: { width: 1300, height: 1000 }, serviceWorkers: 'block' })).newPage();
  const erros = []; pg.on('pageerror', e => erros.push(e.message));
  let dialogos = []; pg.on('dialog', d => { dialogos.push(d.message()); d.accept(); });
  await pg.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    const json = (o, st = 200) => r.fulfill({ status: st, contentType: 'application/json', body: JSON.stringify(o) });
    if (u.includes('provas.php')) {
      const acao = (u.match(/action=(\w+)/) || [])[1];
      if (acao === 'video_parte') { S.chamadas.push({ acao, tam: (req.postDataBuffer() || Buffer.alloc(0)).length, url: u }); return json({ ok: true, recebido: (req.postDataBuffer() || Buffer.alloc(0)).length + Number((u.match(/inicio=(\d+)/) || [0, 0])[1]), concluido: true }); }
      const d = met === 'POST' ? JSON.parse(req.postData() || '{}') : {};
      S.chamadas.push({ acao, d });
      if (acao === 'resumo') return json(META);
      if (acao === 'listar') return json({ ok: true, provas: S.provas, resumo: META.resumo });
      if (acao === 'obter') { const p = S.provas.find(x => x.idprova === Number((u.match(/id=(\d+)/) || [])[1])); return p ? json({ ok: true, prova: p, texto_pronto: 'A Marina treinou por 4 meses.' }) : json({ ok: false, erro: 'Prova não encontrada.' }, 404); }
      if (acao === 'salvar') {
        if (!d.pessoa_nome) return json({ ok: false, erro: 'Diga quem é a pessoa (pode ser só o primeiro nome).' }, 400);
        let p = d.idprova && S.provas.find(x => x.idprova === d.idprova);
        if (p) Object.assign(p, d, { tags: String(d.tags || '').split(',').map(x => x.trim()).filter(Boolean) });
        else { p = prova(Object.assign({}, d, { idprova: 10 + S.provas.length, tags: [], pessoa_nome: d.pessoa_nome, criado_por: 1, midias: [] })); S.provas.push(p); }
        return json({ ok: true, idprova: p.idprova, prova: p });
      }
      if (acao === 'link') { const p = S.provas.find(x => x.idprova === d.idprova); if (!/^https:\/\//.test(d.url)) return json({ ok: false, erro: 'O link do vídeo precisa começar com https://' }, 400); p.midias.push({ idpm: 50, tipo: 'link', momento: d.momento, url: d.url, legenda: d.legenda, pronto: true }); return json({ ok: true, idpm: 50, prova: p }); }
      if (acao === 'foto') { const p = S.provas.find(x => x.idprova === d.idprova); p.midias.push({ idpm: 60 + p.midias.length, tipo: 'foto', momento: d.momento, url: 'https://intusfit.com.br/app/img/provas/x.jpg', legenda: d.legenda, pronto: true }); return json({ ok: true, idpm: 60, url: 'x', prova: p }); }
      if (acao === 'video_iniciar') return json({ ok: true, id: 7, idpm: 70, parte_bytes: 1048576 });
      if (acao === 'midia_excluir') { S.provas.forEach(p => { p.midias = p.midias.filter(m => m.idpm !== d.idpm); }); return json({ ok: true }); }
      if (acao === 'excluir') { S.provas = S.provas.filter(x => x.idprova !== d.idprova); return json({ ok: true }); }
      if (acao === 'ia') return json({ ok: true, provas: [{ id: 1, nome: 'Marina' }] });
      return json({ ok: false, erro: 'acao' }, 400);
    }
    if (u.includes('catalogo.php')) return json({ resultados: [], atletas: {}, avatars: {}, temMais: false });
    return json([]);
  });
  await pg.addInitScript(() => { localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); });
  await pg.goto('http://127.0.0.1:8111/resultados.html', { waitUntil: 'networkidle' });

  console.log('\n══ Aba Provas sociais ══');
  ver('as duas abas existem e a primeira é o quadro dos alunos', await pg.evaluate(() => !!document.getElementById('rs-aba-quadro') && !!document.getElementById('rs-aba-provas') && document.getElementById('rs-aba-quadro').classList.contains('on')));
  eq('o quadro dos alunos não chama a API de provas', S.chamadas.length, 0);
  await pg.click('#rs-aba-provas'); await pg.waitForSelector('.pv-card');
  eq('abre a lista de provas e pede só listar e o resumo', S.chamadas.map(c => c.acao).sort(), ['listar', 'resumo']);
  ver('o nome com HTML aparece como texto, sem virar tag (escape)', await pg.evaluate(() => document.querySelector('.pv-nome').textContent.includes('<b>Alves</b>') && !document.querySelector('.pv-nome b') && !document.querySelector('.pv-res i')));
  ver('mostra autorização, usos e peso', await pg.evaluate(() => { const t = document.querySelector('.pv-card').innerText; return /Autorizou divulgar/.test(t) && /robô/.test(t) && /de 78,5 kg para 69 kg/.test(t); }));
  ver('o resumo do topo conta as provas', await pg.evaluate(() => /1\s*provas ativas/.test(document.querySelector('.pv-res-linha').innerText) && /liberadas para o robô/.test(document.querySelector('.pv-res-linha').innerText)));
  ver('a aba escolhida fica lembrada', await pg.evaluate(() => localStorage.getItem('intus-resultados-aba') === 'provas'));

  // copiar texto pronto
  await pg.evaluate(() => { window.__copiado = ''; Object.defineProperty(navigator, 'clipboard', { value: { writeText: t => { window.__copiado = t; return Promise.resolve(); } }, configurable: true }); });
  await pg.click('[data-a="copiar"]'); await pg.waitForTimeout(300);
  eq('copia o texto pronto do servidor', await pg.evaluate(() => window.__copiado), 'A Marina treinou por 4 meses.');

  // filtros mandam a consulta certa
  S.chamadas.length = 0;
  await pg.selectOption('#pv-f-aut', 'pendente'); await pg.waitForTimeout(300);
  ver('filtrar por autorização refaz a consulta', S.chamadas.some(c => c.acao === 'listar'));

  // nova prova: regras de autorização na tela
  await pg.click('#pv-nova'); await pg.waitForSelector('#pv-nome');
  eq('sem autorização as chaves de uso ficam desligadas e travadas', await pg.evaluate(() => ['robo', 'marketing', 'site', 'ia'].map(u => document.getElementById('pv-u-' + u).disabled)), [true, true, true, true]);
  await pg.selectOption('#pv-aut', 'interna');
  eq('uso interno libera só a inteligência', await pg.evaluate(() => ['robo', 'marketing', 'site', 'ia'].map(u => document.getElementById('pv-u-' + u).disabled)), [true, true, true, false]);
  await pg.selectOption('#pv-aut', 'divulgar');
  eq('autorizou divulgar libera todos', await pg.evaluate(() => ['robo', 'marketing', 'site', 'ia'].map(u => document.getElementById('pv-u-' + u).disabled)), [false, false, false, false]);
  await pg.check('#pv-u-robo'); await pg.check('#pv-u-ia');
  await pg.selectOption('#pv-aut', 'pendente');
  eq('voltar para "sem autorização" desmarca tudo (nada fica ligado escondido)', await pg.evaluate(() => ['robo', 'marketing', 'site', 'ia'].map(u => document.getElementById('pv-u-' + u).checked)), [false, false, false, false]);
  ver('sem salvar, a área de mídia pede para salvar primeiro', await pg.evaluate(() => /Salve a prova primeiro/.test(document.getElementById('pv-midias').innerText)));

  // salvar sem nome mostra o erro do servidor e não fecha
  await pg.click('#pv-salvar'); await pg.waitForTimeout(300);
  ver('nome em branco: o erro aparece e a janela continua', await pg.evaluate(() => !!document.getElementById('pv-modal')));
  // salvar de verdade
  await pg.fill('#pv-nome', 'Paula Souza'); await pg.fill('#pv-pa', '90,5'); await pg.fill('#pv-pd', '80'); await pg.selectOption('#pv-aut', 'divulgar'); await pg.check('#pv-u-robo');
  S.chamadas.length = 0;
  await pg.click('#pv-salvar'); await pg.waitForSelector('#pv-foto', { state: 'attached', timeout: 4000 });
  const salvar = S.chamadas.find(c => c.acao === 'salvar').d;
  eq('envia os campos certos (peso com vírgula, autorização e robô)', [salvar.pessoa_nome, salvar.peso_antes, salvar.autorizacao, salvar.uso_robo, salvar.uso_marketing], ['Paula Souza', '90,5', 'divulgar', true, false]);
  ver('depois de salvar a área de mídia libera fotos, vídeo e link', await pg.evaluate(() => !!document.getElementById('pv-foto') && !!document.getElementById('pv-vid') && !!document.getElementById('pv-link')));

  // link de vídeo
  await pg.fill('#pv-link', 'http://inseguro.com/v'); await pg.click('#pv-link-ok'); await pg.waitForTimeout(300);
  ver('link sem https: o servidor recusa e nada entra na lista', await pg.evaluate(() => /Nenhuma foto ou vídeo/.test(document.getElementById('pv-midias').innerText)));
  await pg.fill('#pv-link', 'https://youtu.be/abc'); await pg.selectOption('#pv-mom', 'depoimento'); await pg.click('#pv-link-ok'); await pg.waitForTimeout(400);
  ver('link https entra na prova com o momento escolhido', await pg.evaluate(() => /Depoimento/.test(document.getElementById('pv-midias').innerText) && !!document.querySelector('#pv-midias a[href="https://youtu.be/abc"]')));

  // foto: reduz e envia como data URI jpeg
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  S.chamadas.length = 0;
  await pg.selectOption('#pv-mom', 'antes'); await pg.fill('#pv-leg', 'Início');
  await pg.setInputFiles('#pv-foto', { name: 'a.png', mimeType: 'image/png', buffer: png }); await pg.waitForTimeout(700);
  const fchamada = S.chamadas.find(c => c.acao === 'foto');
  ver('a foto vai reduzida em JPEG e com o momento/legenda', fchamada && /^data:image\/jpeg;base64,/.test(fchamada.d.imagem) && fchamada.d.momento === 'antes' && fchamada.d.legenda === 'Início');
  ver('a foto aparece na lista da prova', await pg.evaluate(() => !!document.querySelector('#pv-midias img')));

  // vídeo enviado em pedaços
  S.chamadas.length = 0;
  await pg.setInputFiles('#pv-vid', { name: 'v.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(300000, 1) }); await pg.waitForTimeout(900);
  const seq = S.chamadas.map(c => c.acao);
  eq('vídeo: inicia o envio e manda o pedaço', seq.slice(0, 2), ['video_iniciar', 'video_parte']);
  eq('vídeo: o pedido de início leva tipo e tamanho', [S.chamadas[0].d.mime, S.chamadas[0].d.tamanho], ['video/mp4', 300000]);

  // tirar mídia
  S.chamadas.length = 0;
  await pg.click('#pv-midias [data-del]'); await pg.waitForTimeout(400);
  ver('tirar uma mídia pede confirmação e avisa o servidor', dialogos.length >= 1 && S.chamadas.some(c => c.acao === 'midia_excluir'));

  // fechar e excluir
  await pg.click('#pv-fechar'); await pg.waitForTimeout(300);
  ver('fechar a janela volta à lista', await pg.evaluate(() => !document.getElementById('pv-modal') && document.querySelectorAll('.pv-card').length === 2));
  dialogos = []; S.chamadas.length = 0;
  await pg.locator('.pv-card').nth(1).locator('[data-a="editar"]').click(); await pg.waitForSelector('#pv-excluir');
  await pg.click('#pv-excluir'); await pg.waitForTimeout(400);
  ver('excluir pede confirmação, avisa o servidor e a prova some', dialogos.length === 1 && S.chamadas.some(c => c.acao === 'excluir') && await pg.evaluate(() => document.querySelectorAll('.pv-card').length === 1));

  // a IA (administrador)
  await pg.click('#pv-ia'); await pg.waitForSelector('.pv-json');
  ver('"O que a IA vê" mostra o pacote', await pg.evaluate(() => /Marina/.test(document.querySelector('.pv-json').textContent)));
  await pg.click('#pv-x');

  // voltar ao quadro
  await pg.click('#rs-aba-quadro'); await pg.waitForTimeout(400);
  ver('voltar ao quadro dos alunos', await pg.evaluate(() => !document.querySelector('.pv-wrap') && /Resultados e depoimentos/.test(document.body.innerText)));

  // celular: sem rolagem lateral
  await pg.setViewportSize({ width: 375, height: 800 }); await pg.click('#rs-aba-provas'); await pg.waitForSelector('.pv-card');
  ver('no celular a aba não cria rolagem lateral', await pg.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), await pg.evaluate(() => document.documentElement.scrollWidth));
  await pg.click('#pv-nova'); await pg.waitForSelector('#pv-nome');
  ver('no celular o formulário cabe na tela', await pg.evaluate(() => document.querySelector('.pv-form').getBoundingClientRect().right <= window.innerWidth + 1));

  const html = fs.readFileSync(path.join(RAIZ, 'provas.js'), 'utf8');
  eq('nenhum texto novo tem travessão', /[—–]/.test(html.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/Quadro de Resultados — Intus/, '')), false);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
