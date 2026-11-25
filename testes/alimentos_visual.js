// Biblioteca de alimentos e dieta visual (25/11/2026): imagens por alimento, faixa dentro da refeição, ligar e desligar, cardápio intacto.
//   cd testes && INTUS_DIR=../app/painel node alimentos_visual.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.route(/alimentos-fotos\/manifest\.json/, r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ banana: 'banana.jpg' }) }));
  await page.route(/alimentos-fotos\/banana\.jpg/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.AlimentoVisual && typeof _nutriHojeHtml === 'function').catch(() => false)) break; await page.waitForTimeout(250); }

  console.log('\n══ Reconhecimento por nome ══');
  const v = (n) => page.evaluate((n) => { const r = AlimentoVisual.para(n); return [r.emoji, r.id, r.grupo, r.reconhecido]; }, n);
  eq('arroz integral cozido', await v('Arroz, integral, cozido'), ['🍚', 'arroz', 'cereal', true]);
  eq('batata doce vem antes de batata', (await v('Batata, doce, cozida'))[1], 'batatadoce');
  eq('pão de queijo vem antes de pão e queijo', (await v('Pão de queijo, assado'))[1], 'paodequeijo');
  eq('couve manteiga é verdura (não manteiga)', (await v('Couve, manteiga, crua'))[1], 'folha');
  eq('manteiga de amendoim é oleaginosa (não manteiga)', (await v('Manteiga de amendoim'))[1], 'amendoim');
  eq('água de coco é bebida (não coco)', (await v('Água de coco'))[1], 'agua');
  eq('peito de frango, whey e ovo', [(await v('Peito de frango sem pele grelhado'))[1], (await v('Whey protein isolado'))[1], (await v('Ovo, de galinha, inteiro, cozido'))[1]], ['frango', 'whey', 'ovo']);
  eq('nome desconhecido usa a imagem genérica do prato (nunca uma errada)', await v('Xyzzy especial da casa'), ['🍽️', 'prato', 'prato', false]);
  eq('legenda curta: as duas primeiras partes do nome', await page.evaluate(() => [AlimentoVisual.curto('Arroz, integral, cozido'), AlimentoVisual.curto('Frango, peito, sem pele, grelhado'), AlimentoVisual.curto('Carne bovina de segunda cozida com legumes e molho')]), ['Arroz integral', 'Frango peito', 'Carne bovina de segunda…']);

  console.log('\n══ Dentro da refeição ══');
  const plano = { idplano: 1, ativo: 1, calorias: 2000, refeicoes: [
    { horario: '07:30', nome: 'Café da manhã', itens_estruturados: [
      { food_id: 1, nome: 'Pão, trigo, francês', quantidade: 2, medida_id: 'un', medida_nome: 'unidade', gramas: 100, nutrientes: { energia_kcal: 300 } },
      { food_id: 2, nome: 'Ovo, de galinha, inteiro, cozido', quantidade: 2, gramas: 100, nutrientes: { energia_kcal: 143 } },
      { food_id: 3, nome: 'Banana, prata, crua', quantidade: 1, gramas: 80, nutrientes: { energia_kcal: 70 } },
      { food_id: 4, nome: 'Café, infusão', quantidade: 100, gramas: 100, nutrientes: { energia_kcal: 9 } }] },
    { horario: '12:00', nome: 'Almoço', itens_estruturados: [
      { food_id: 5, nome: 'Arroz, integral, cozido', quantidade: 150, gramas: 150, nutrientes: { energia_kcal: 185 } },
      { food_id: 6, nome: 'Peito de frango sem pele grelhado', quantidade: 120, gramas: 120, nutrientes: { energia_kcal: 190 } }] }] };
  await page.evaluate((p) => { _nutri.carregado = true; _nutri.plano = p; _nutri.regs = []; _nutri.agua = []; _nutri.pesos = []; _nutri.dia = 'hoje'; _nutri.aberta = '07:30|café da manhã'; localStorage.removeItem('intus-nutri-imagens-7'); }, plano);
  const html = () => page.evaluate(() => { const d = document.createElement('div'); d.innerHTML = _nutriHojeHtml(); return { faixa: d.querySelectorAll('.alim-faixa').length, cartoes: d.querySelectorAll('.alim-faixa > div').length, itens: d.querySelectorAll('.nutri-item').length, nomes: Array.from(d.querySelectorAll('.nutri-item')).map(x => x.querySelector('div[style*="font-size:16px"]').textContent.trim()), chip: (d.querySelector('[aria-pressed]') || {}).textContent || null }; });
  let h = await html();
  eq('a refeição aberta ganha uma faixa de imagens com um cartão por alimento', [h.faixa, h.cartoes], [1, 4]);
  eq('a lista do cardápio continua com os 4 alimentos, com o nome que a nutri escreveu', [h.itens, h.nomes], [4, ['Pão, trigo, francês', 'Ovo, de galinha, inteiro, cozido', 'Banana, prata, crua', 'Café, infusão']]);
  eq('o chip mostra que as imagens estão ligadas', /ligadas/.test(h.chip || ''), true);
  await page.evaluate(() => nutriImagens());
  h = await html();
  eq('desligando, a faixa some e o cardápio fica igual', [h.faixa, h.itens, /desligadas/.test(h.chip || '')], [0, 4, true]);
  eq('a preferência fica guardada para o próximo acesso', await page.evaluate(() => localStorage.getItem('intus-nutri-imagens-7')), '0');
  await page.evaluate(() => nutriImagens());
  eq('ligando de novo, a faixa volta', (await html()).faixa, 1);
  eq('refeição fechada não mostra a faixa (nada de poluição na lista do dia)', await page.evaluate(() => { _nutri.aberta = null; const d = document.createElement('div'); d.innerHTML = _nutriHojeHtml(); return d.querySelectorAll('.alim-faixa').length; }), 0);
  eq('alimento repetido na refeição vira um só cartão', await page.evaluate(() => { const f = AlimentoVisual.faixaHtml([{ nome: 'Arroz, integral, cozido' }, { nome: 'Arroz, integral, cozido' }, { nome: 'Feijão, carioca, cozido' }]); const d = document.createElement('div'); d.innerHTML = f; return d.querySelectorAll('.alim-faixa > div').length; }), 2);
  eq('com a foto da biblioteca no manifesto, o cartão usa a foto (e se ela falhar, o quadro de recarregar assume)', await page.evaluate(async () => { AlimentoVisual.carregarFotos(); await new Promise(r => setTimeout(r, 400)); const f = AlimentoVisual.faixaHtml([{ nome: 'Banana prata' }]); return /<img[^>]+alimentos-fotos\/banana\.jpg/.test(f); }), true);

  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
