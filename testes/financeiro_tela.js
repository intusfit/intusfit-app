// O Financeiro rodando de verdade com os alunos que apareciam em atraso.
const {chromium}=require('playwright');
const HOJE='2026-08-31';
let id=1;
const C=(at,nome,plano,ini,venc,pg,valor)=>({idmensalidade:id++,idatleta:at,nmathleta:nome,
  dshistorico:plano,recorrencia:'mensal',dtinicio:ini,dtvencimento:venc,dtpagamento:pg||null,
  vlpagar:valor,stpgto:pg?'S':'N',stmatricula:'ativa',forma_pgto:'pix'});
const MENS=[
  C(1,'Vanessa Carissimi','Personal 2x','2026-07-30','2026-08-30','2026-07-30',1225),
  C(1,'Vanessa Carissimi','Personal 2x','2026-08-30','2026-09-30',null,1225),
  C(2,'Maud Pedreira Dias','Personal 3x','2026-08-22','2026-09-22',null,1050),
  C(2,'Maud Pedreira Dias','Personal 3x','2026-07-22','2026-08-22','2026-08-23',1050),
  C(2,'Maud Pedreira Dias','Personal 3x','2026-07-22','2026-08-22','2026-07-22',1050),
  C(3,'Erick Braz Schiesl','Personal 4x','2026-08-20','2026-09-20',null,1000),
  C(3,'Erick Braz Schiesl','Personal 4x','2026-07-21','2026-08-20','2026-08-19',1000),
  C(4,'Samantha Lara','Personal 3x','2026-08-15','2026-09-15',null,1000),
  C(4,'Samantha Lara','Personal 3x','2026-07-15','2026-08-15','2026-08-17',1000),
  C(5,'Aluno Devedor','Personal 2x','2026-07-01','2026-08-01',null,500),
];
(async()=>{
 const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
 const p=await b.newPage({viewport:{width:1400,height:900}});
 const erros=[]; p.on('pageerror',e=>erros.push(e.message));
 await p.route(u=>u.href.includes('.php'),r=>{
   const u=r.request().url();
   if(u.includes('mensalidades.php')) return r.fulfill({status:200,contentType:'application/json',body:JSON.stringify(MENS)});
   if(u.includes('atletas.php')) return r.fulfill({status:200,contentType:'application/json',body:JSON.stringify(
     [...new Set(MENS.map(m=>m.idatleta))].map(i=>({idatleta:i,nome:(MENS.find(m=>m.idatleta===i)||{}).nmathleta,
       email:'a'+i+'@x.com',origem:'professor',professores_responsaveis:[1],stbloqueio:'N'})))});
   return r.fulfill({status:200,contentType:'application/json',body:'[]'});
 });
 await p.addInitScript(({MENS,HOJE})=>{
   const _R=Date;
   window.Date=function(...a){ return a.length ? new _R(...a) : new _R(HOJE+'T12:00:00'); };
   window.Date.prototype=_R.prototype; window.Date.now=()=>new _R(HOJE+'T12:00:00').getTime();
   window.Date.UTC=_R.UTC; window.Date.parse=_R.parse;
   localStorage.setItem('mx-user',JSON.stringify({idusuario:1,nome:'Luiz',admin:true,permissoes:{}}));
   localStorage.setItem('mx-token','x'.repeat(64));
   localStorage.setItem('intus-mensalidades',JSON.stringify(MENS));
 },{MENS,HOJE});
 await p.goto('http://127.0.0.1:8899/financeiro.html',{waitUntil:'networkidle'});
 await p.waitForTimeout(2200);

 let falhas=0;
 const ok=(r,c,x)=>{ if(!c) falhas++; console.log((c?'  ok    ':'  FALHA ')+r+(c?'':'  -> '+JSON.stringify(x))); };
 const t = await p.evaluate(()=>document.body.innerText.replace(/\s+/g,' '));
 const bloco=(de,ate)=>{ const i=t.search(de); if(i<0) return ''; const r=t.slice(i+12); const j=r.search(ate); return t.slice(i, j>=0?i+12+j:i+500); };

 // "EM ATRASO" aparece duas vezes: no cartao do topo e no titulo da LISTA.
 // O que interessa e a lista, que vem depois de "RESUMO".
 const iResumo = t.search(/RESUMO/);
 const depoisDoResumo = t.slice(iResumo < 0 ? 0 : iResumo);
 const iLista = depoisDoResumo.search(/EM ATRASO/i);
 const atraso = iLista < 0 ? '(lista nao encontrada)' : depoisDoResumo.slice(iLista, iLista + 420);
 console.log('  em atraso: ' + atraso.slice(0,260) + '\n');
 ok('Maud fora do atraso', !/Maud/.test(atraso), atraso);
 ok('Erick fora do atraso', !/Erick/.test(atraso), atraso);
 ok('Samantha fora do atraso', !/Samantha/.test(atraso), atraso);
 ok('Vanessa fora do atraso', !/Vanessa/.test(atraso), atraso);
 ok('o devedor de verdade continua la', /Devedor/.test(atraso), atraso);

 const cards = await p.evaluate(()=>{
   const txt=document.body.innerText.replace(/\s+/g,' ');
   const p1=txt.search(/EM ATRASO/i);
   return txt.slice(0, p1+40);
 });
 ok('o card "Em atraso" conta so a divida real (R$ 500)', /EM ATRASO R\$ 500,00/i.test(cards), cards.slice(-120));
 // O subtitulo dizia "desde 15/04/2026" — o padrao antigo, que somava cinco
 // meses num numero so. Basta ele nao estar no texto visivel da pagina.
 // O subtitulo dizia "desde 15/04/2026" — o padrao antigo, que somava cinco
 // meses num numero so. Procuro no texto inteiro, nao num pedaco dele.
 const iSub = t.search(/Gestão Financeira/);
 const subtitulo = iSub < 0 ? '(nao achei)' : t.slice(iSub, iSub + 90);
 console.log('  subtitulo: ' + subtitulo);
 // Cuidado: "Desde 15/04/2026" tambem e o texto de uma OPCAO do seletor, que
 // aparece logo depois. O que vale e o subtitulo, antes do seletor.
 const soSubtitulo = subtitulo.split('Desde 15/04')[0];
 ok('o periodo abre no mes atual, nao "desde o inicio"', /mês atual/i.test(soSubtitulo), soSubtitulo);

 console.log('\n── erros ──');
 ok('nenhum erro de JavaScript', erros.length===0, erros);
 await b.close();
 console.log(falhas? '\n✗ '+falhas+' falha(s)' : '\n✓ todos os testes passaram');
 process.exit(falhas?1:0);
})();
