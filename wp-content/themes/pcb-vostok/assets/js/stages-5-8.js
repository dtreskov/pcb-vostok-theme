/* PCB Восток · этапы 05–08: интерактив (итерация 13) */
(function(){
var RM=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
document.querySelectorAll('.x3-ba').forEach(function(w){var r=w.querySelector('input');
 function set(v){r.value=v;w.style.setProperty('--x',v+'%');}
 r.addEventListener('input',function(){w.dataset.auto='';set(r.value);});
 if(w.dataset.auto&&!RM){var seq=[[0,0],[100,2600],[100,3400],[50,4600]],t0=null,i=0;
  function step(n){if(!w.dataset.auto)return;if(t0===null)t0=n;var t=n-t0;
   while(i<seq.length-1&&t>seq[i+1][1])i++; if(i>=seq.length-1){set(50);return;}
   var a=seq[i],b=seq[i+1],p=(t-a[1])/(b[1]-a[1]);p=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;set(a[0]+(b[0]-a[0])*p);requestAnimationFrame(step);}
  var io=new IntersectionObserver(function(es){if(es[0].isIntersecting){io.disconnect();set(0);setTimeout(function(){requestAnimationFrame(step);},400);}},{threshold:.4});io.observe(w);}
});
/* ---- ПМИ ---- */
var DUR=[2600,3400,3000,3000,2600];
function q(r,s){return [].slice.call(r.querySelectorAll(s));}
function setT(r,s,v){q(r,s).forEach(function(e){e.textContent=v;});}
function pmi(r){
 function paint(step,p){
  r.dataset.step=step;
  for(var i=1;i<=4;i++) r.classList.toggle('pd'+i,step>i||step===5);
  var v1=step>1?1:(step===1?p:0), v2=step>2?1:(step===2?p:0), v3=step>3?1:(step===3?p:0), v4=step>4?1:(step===4?p:0);
  setT(r,'.psu-v',(5*v1).toFixed(2)); setT(r,'.psu-a',(0.12*v1).toFixed(2)+' A');
  q(r,'.psu-arc').forEach(function(e){e.setAttribute('stroke-dashoffset',251-251*v1*5/6);});
  setT(r,'.prg-p',Math.round(100*v2)+' %'); q(r,'.prg-bar').forEach(function(e){e.setAttribute('width',152*Math.max(0,v2));});
  q(r,'.prg-r').forEach(function(e){e.setAttribute('stroke-dashoffset',276.5*(1-v2));});
  var mv='-.--',mm=' ',ang=-40;
  if(step===3){ if(p<.5){mv='0.3 Ω';mm='прозвонка цепей';ang=-40;} else {mv=(3.3*Math.min(1,(p-.5)*4)).toFixed(2)+' V';mm='замер уровня 3,3 В';ang=40;} }
  else if(step>3){mv='3.30 V';mm='в норме';ang=40;}
  setT(r,'.mm-v',mv); setT(r,'.mm-m',mm); q(r,'.mm-d').forEach(function(e){e.style.transform='rotate('+ang+'deg)';});
  q(r,'.osc-w').forEach(function(e){var L=e._L||(e._L=e.getTotalLength());e.style.strokeDasharray=L;e.style.strokeDashoffset=L*(1-v4);});
  setT(r,'.osc-f',v4>=.7?'16.000 MHz':(v4>0?(16*v4/.7).toFixed(3)+' MHz':'--.--- MHz'));
 }
 if(RM){paint(5,1);return;}
 var t0=performance.now(),T=DUR.reduce(function(a,b){return a+b;},0);
 (function loop(now){var t=(now-t0)%T,acc=0,step=1;
  for(var i=0;i<DUR.length;i++){ if(t<acc+DUR[i]){step=i+1;break;} acc+=DUR[i]; }
  paint(step,(t-acc)/DUR[step-1]); requestAnimationFrame(loop);})(t0);
}

/* ---- ОТК ---- */
function otk(r){
 var P=JSON.parse(r.dataset.pts),z=r.querySelector('.otk__z'),f=r.querySelector('.otk__f'),ms=r.querySelector('.otk__ms'),lb=f.querySelector('b'),
     zoom=r.classList.contains('otk--zoom'),host=r.closest('.otk-2')||r,log=r.parentNode.querySelector('.otk-log ol'),phw=host.parentNode.querySelector('.otk-ph__s'),ph=phw,i=0;
 ms.style.cssText='position:absolute;inset:0';
 function box(p,inf){var cx=p[1]+p[3]/2,cy=p[2]+p[4]/2,w=p[3]*inf,h=p[4]*inf;return {l:cx-w/2,t:cy-h/2,w:w,h:h,cx:cx,cy:cy};}
 function place(b){f.style.left=b.l+'%';f.style.top=b.t+'%';f.style.width=b.w+'%';f.style.height=b.h+'%';}
 function reset(){ms.innerHTML='';if(log)log.innerHTML='';if(ph)ph.innerHTML='';i=0;}
 function mark(p){var m=document.createElement('span');m.className='otk__m '+(p[5]?'ok':'bad');m.textContent=p[5]?'✓':'✕';
   if(p[7]==='geo'){m.style.left='97%';m.style.top='5%';}else{m.style.left=(p[1]+p[3])+'%';m.style.top=p[2]+'%';}ms.appendChild(m);
   if(log){var li=document.createElement('li');li.className=p[5]?'ok':'bad';var s=p[0].split(' · ');li.innerHTML='<em>'+(p[5]?'✓':'✕')+'</em><span><b>'+s[0]+'</b> · '+s[1]+(p[5]?' — норма':' — замечание')+'<u>'+p[6]+'</u></span>';log.appendChild(li);}if(ph){var sv=z.querySelector('svg').cloneNode(true),pad=1.6,bx=14+(p[1]-pad)*5.32,by=14+(p[2]-pad*1.5)*3.52,bw=(p[3]+2*pad)*5.32,bh=(p[4]+3*pad)*3.52;if(bw/bh<1.47){var nw=bh*1.47;bx-=(nw-bw)/2;bw=nw;}else{var nh=bw/1.47;by-=(nh-bh)/2;bh=nh;}sv.setAttribute('viewBox',bx+' '+by+' '+bw+' '+bh);sv.setAttribute('preserveAspectRatio','xMidYMid slice');var t=document.createElement('div');t.className='otk-ph__t '+(p[5]?'ok':'bad');t.appendChild(sv);var e=document.createElement('em');e.textContent=p[5]?'✓':'✕';t.appendChild(e);var c=document.createElement('span');c.textContent=p[0].split(' · ')[0];t.appendChild(c);ph.appendChild(t);r.classList.remove('flash');void r.offsetWidth;r.classList.add('flash');}}
 function step(){
  if(i>=P.length){var w=r.closest('.otk-wrap');if(w){w.classList.remove('done');void w.offsetWidth;w.classList.add('done');}f.className='otk__f';lb.textContent='';place({l:0,t:0,w:100,h:100});if(zoom)z.style.transform='';setTimeout(function(){reset();step();},2600);return;}
  var p=P[i];f.className='otk__f';lb.textContent='';r.classList.remove('ms-on','geo-on');
  if(zoom&&p[7]!=='geo'){var c=box(p,1);z.style.transformOrigin=c.cx+'% '+c.cy+'%';z.style.transform='scale(2.1)';place(box(p,2.6));}else if(p[7]==='geo'){z.style.transform='';place(box(p,1));}
  else{place(box(p,2.4));setTimeout(function(){place(box(p,1.25));},500);}
  var rt=(p[1]+p[3]/2)>55;lb.style.left=rt?'auto':'0';lb.style.right=rt?'0':'auto';var geo=p[7]==='geo';lb.style.top=geo?'8px':'-26px';if(geo){lb.style.left='12px';lb.style.right='auto';}setTimeout(function(){f.className='otk__f '+(p[5]?'ok':'bad');lb.textContent=p[0];if(p[7]==='ms')r.classList.add('ms-on');if(geo)r.classList.add('geo-on');},1350);
  setTimeout(function(){mark(p);if(zoom){z.style.transform='';place(box(p,1.25));}},2200);
  setTimeout(function(){r.classList.remove('ms-on','geo-on');i++;step();},(zoom?3100:2700)+(p[7]==='ms'?900:0));
 }
 place({l:0,t:0,w:100,h:100});
 if(RM){P.forEach(mark);f.style.display='none';return;}
 setTimeout(step,600);
}

/* ---- Доставка ---- */
q(document,'.x4-del').forEach(function(r){
 var ns=q(r,'.stg-path__n'),ps=q(r,'.x4-dp__i'),dp=r.querySelector('.x4-dp'),bar=r.querySelector('.x4-prog i'),cur=0,auto=r.dataset.auto&&!RM,t0=performance.now(),D=5200;
 function sel(i){cur=i;ns.forEach(function(n,k){n.classList.toggle('on',k===i);n.setAttribute('aria-selected',k===i);});ps.forEach(function(p,k){p.classList.toggle('on',k===i);});
   var n=ns[i];dp.style.setProperty('--ax',(n.offsetLeft+n.offsetWidth/2-7)+'px');}
 ns.forEach(function(n,k){n.addEventListener('click',function(){auto=false;if(bar)bar.style.width='0';sel(k);});
   n.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();n.click();}});});
 setTimeout(function(){sel(0);},50);window.addEventListener('resize',function(){sel(cur);});
 if(auto)(function loop(now){if(!auto)return;var t=now-t0;if(t>D){t0=now;sel((cur+1)%ns.length);t=0;}if(bar)bar.style.width=(100*t/D)+'%';requestAnimationFrame(loop);})(t0);
});
})();
(function(){
var RM=window.matchMedia('(prefers-reduced-motion: reduce)').matches,NS='http://www.w3.org/2000/svg';
[].slice.call(document.querySelectorAll('.ox10 .ox-b')).forEach(function(r){
 var P=JSON.parse(r.dataset.pts),f=r.querySelector('.otk__f'),sc=f.querySelector('.ox-scan'),xw=r.querySelector('.ox-xw'),host=r.closest('.ox10'),
     G=[].slice.call(host.querySelectorAll('.ox-g')),mask=document.getElementById('oxdm'),xrL=r.querySelector('.xrL'),xrR=r.querySelector('.xrR'),
     i=0,T=[],gen=0,rects=[];
 function box(p,inf){var cx=p[0]+p[2]/2,cy=p[1]+p[3]/2,w=p[2]*inf,h=p[3]*inf;return {l:cx-w/2,t:cy-h/2,w:w,h:h};}
 function place(b){f.style.left=b.l+'%';f.style.top=b.t+'%';f.style.width=b.w+'%';f.style.height=b.h+'%';
   xw.style.clipPath='inset('+b.t+'% '+(100-b.l-b.w)+'% '+(100-b.t-b.h)+'% '+b.l+'% round 10px)';}
 function vb(b){return {x:16+b.l*3.68,y:16+b.t*2.88,w:b.w*3.68,h:b.h*2.88};}
 function gs(gi,c){var g=G[gi];if(!g)return;g.classList.remove('cur','bad','ok');if(c)g.classList.add(c);}
 function at(t,fn){T.push(setTimeout(fn,t));}
 function tween(d,fn,done){var g=gen,t0=performance.now();(function s(n){if(g!==gen)return;var q=Math.min(1,(n-t0)/d);fn(q);if(q<1)requestAnimationFrame(s);else if(done)done();})(t0);}
 function addRect(v){var e=document.createElementNS(NS,'rect');e.setAttribute('x',v.x);e.setAttribute('y',v.y);e.setAttribute('height',v.h);e.setAttribute('width',0);e.setAttribute('fill','#000');mask.appendChild(e);rects.push(e);return e;}
 function xr(X){xrL.setAttribute('width',Math.max(0,X-16));xrR.setAttribute('x',X);xrR.setAttribute('width',Math.max(0,384-X));}
 function fixNow(p){if(p[5]==='xr'){xr(400);return;}if(p[5])return;var v=vb(box(p,1.2)),e=addRect(v);e.setAttribute('width',v.w);}
 function reset(){gen++;T.forEach(clearTimeout);T=[];rects.forEach(function(e){e.remove();});rects=[];xr(16);
   G.forEach(function(g,k){gs(k,null);});r.classList.remove('xray','ms-on','rep-on','ms-vf');f.className='otk__f';sc.style.opacity=0;place({l:0,t:0,w:100,h:100});}
 function scan(p,d,done){var v=vb(box(p,1.2)),e=p[5]==='xr'?null:addRect(v);sc.style.left='0%';sc.style.opacity=1;
   tween(d,function(q){sc.style.left=(q*100)+'%';if(e)e.setAttribute('width',v.w*Math.max(0,q));else xr(v.x+v.w*q);},function(){sc.style.opacity=0;if(done)done();});}
 function step(){
  T.forEach(clearTimeout);T=[];
  if(i>=P.length){at(2600,function(){reset();i=0;step();});return;}
  r.classList.remove('xray','ms-on','ms-vf');
  var p=P[i],k=p[5],gi=p[4];gs(gi,'cur');f.className='otk__f';
  if(k==='ms'||k==='rep'){place({l:0,t:0,w:100,h:100});r.classList.remove('rep-on');void r.offsetWidth;r.classList.add(k+'-on');
    var D=window.pvSceneDur?pvSceneDur(k):(k==='ms'?6200:6500);at(D-700,function(){gs(gi,'ok');});at(D,function(){i++;step();});return;}
  var xk=k==='xr',t1=xk?1700:1200,ds=xk?1500:1100;
  place(box(p,2.2));at(500,function(){place(box(p,1.2));});
  if(xk)at(900,function(){r.classList.add('xray');});
  at(t1,function(){f.className='otk__f bad';gs(gi,'bad');});
  at(t1+800,function(){scan(p,ds,function(){f.className='otk__f ok';gs(gi,'ok');});});
  if(xk)at(t1+800+ds+700,function(){r.classList.remove('xray');});
  at(t1+800+ds+(xk?1300:900),function(){i++;step();});
 }
 function jumpG(gi){reset();var n0=0,n;for(n=0;n<P.length;n++){if(P[n][4]===gi){n0=n;break;}}
   for(n=0;n<n0;n++){fixNow(P[n]);gs(P[n][4],'ok');}i=n0;step();}
 G.forEach(function(g,gi){g.addEventListener('click',function(){jumpG(gi);});});
 r._restart=function(){reset();i=0;step();};
 place({l:0,t:0,w:100,h:100});
 if(RM){P.forEach(fixNow);G.forEach(function(g,k){gs(k,'ok');});return;}
 setTimeout(step,500);
});
})();
(function(){
var RM=window.matchMedia('(prefers-reduced-motion: reduce)').matches,NS='http://www.w3.org/2000/svg';
function E(t,a){var e=document.createElementNS(NS,t);for(var k in a)e.setAttribute(k,a[k]);return e;}
function ease(t){return t<.5?2*t*t:1-Math.pow(-2*t+2,2)/2;}
var SP=2;
var ORDER=['pwr','kz','cur','volt','v12','v5','v33','fw','wire','wl','osc'];
var START={pwr:400,kz:400,cur:5200,volt:7700,v12:7700,v5:9600,v33:11500,fw:14600,wire:19400,wl:22200,osc:25200};
function engine(r){
 var C=JSON.parse(r.dataset.cfg),svg=r.querySelector('svg'),fx=svg.querySelector('.fx'),tip=svg.querySelector('.mm-tip'),cab=svg.querySelector('.mm-cab'),tipB=svg.querySelector('.mm-tipb'),cabB=svg.querySelector('.mm-cabb'),RB=[].slice.call(svg.querySelectorAll('.rb')),idc=svg.querySelector('.idc'),tb,
     pwc=[].slice.call(svg.querySelectorAll('.pwc')),T=[],R=[],tp,gen=0,LN=[];
 function $(s){return svg.querySelector(s);} function txt(s,v){var e=$(s);if(e)e.textContent=v;}
 function tween(d,fn,done){d*=SP;var g=gen,t0=performance.now();(function f(n){if(g!==gen)return;var p=Math.min(1,(n-t0)/d);fn(ease(p));if(p<1)R.push(requestAnimationFrame(f));else if(done)done();})(t0);}
 function add(c){r.classList.add(c);} function st(s){r.dataset.st=s;}
 function draw(){for(var i=0;i<4;i++)txt('.c'+i,LN[i]||' ');}function con(a){LN=a.slice(-4);draw();}function push(s){LN.push(s);LN=LN.slice(-4);draw();}function last(s){LN[LN.length-1]=s;draw();}
 function barT(n){var k=Math.round(n*10);return new Array(k+1).join('#')+new Array(11-k).join('_');}
 function drawTip(){tip.setAttribute('transform','translate('+tp[0]+' '+tp[1]+')');var j=C.jack;cab.setAttribute('d','M'+j[0]+' '+j[1]+' C'+j[0]+' '+(j[1]-90)+' '+(tp[0]-60)+' '+(tp[1]+80)+' '+tp[0]+' '+tp[1]);}
 function park(){return [C.jack[0]+30,C.jack[1]-60];}
 function parkB(){return [C.jackB[0]-26,C.jackB[1]-56];}
 function drawB(){tipB.setAttribute('transform','translate('+tb[0]+' '+tb[1]+')');var j=C.jackB;cabB.setAttribute('d','M'+j[0]+' '+j[1]+' C'+j[0]+' '+(j[1]-80)+' '+(tb[0]-60)+' '+(tb[1]+60)+' '+tb[0]+' '+tb[1]);}
 function moveB(to,d){var a=tb.slice();tween(d||700,function(p){tb=[a[0]+(to[0]-a[0])*p,a[1]+(to[1]-a[1])*p];drawB();});}
 function x2(q){var c=C.rib,dx=82*q,dy=-38*q,xc=c[0]+dx,y1=c[1]+dy,sx=c[2],sy=c[3];
   var d='M'+xc+' '+(y1+3)+' C'+xc+' '+(y1+30)+' '+(sx-34)+' '+sy+' '+sx+' '+sy;
   RB.forEach(function(p){p.setAttribute('d',d);});idc.setAttribute('transform','translate('+dx+' '+dy+')');}
 function moveTip(to,d){var a=tp.slice();tween(d||700,function(p){tp=[a[0]+(to[0]-a[0])*p,a[1]+(to[1]-a[1])*p];drawTip();});}
 var OC=[].slice.call(svg.querySelectorAll('.osc-cab')),OCP=[].slice.call(svg.querySelectorAll('.osc-cabp')),OT=[].slice.call(svg.querySelectorAll('.osc-tip')),ot=[[0,0],[0,0]];
 function oA(i){var j=C.ojack[i];return [j[0]-150,j[1]+34+i*5];}
 function opark(i){var a=oA(i);return [a[0]-18+i*4,a[1]+3];}
 function drawO(){if(!C.ojack)return;for(var i=0;i<2;i++){var j=C.ojack[i],A=oA(i),t=ot[i],dx=Math.max(1,A[0]-t[0]),dy=t[1]-A[1],
   d='M'+j[0]+' '+j[1]+' C'+j[0]+' '+(j[1]+30)+' '+(j[0]-40)+' '+A[1]+' '+A[0]+' '+A[1]+' C'+(A[0]-.4*dx)+' '+(A[1]-.12*dx)+' '+(t[0]+.18*dx)+' '+(t[1]-dy-.25*dx)+' '+t[0]+' '+t[1];
   if(OC[i])OC[i].setAttribute('d',d);if(OCP[i])OCP[i].setAttribute('d',d);if(OT[i])OT[i].setAttribute('transform','translate('+t[0]+' '+t[1]+')');}}
 function moveO(to,d){var a=ot.map(function(p){return p.slice();});tween(d||900,function(p){for(var i=0;i<2;i++)ot[i]=[a[i][0]+(to[i][0]-a[i][0])*p,a[i][1]+(to[i][1]-a[i][1])*p];drawO();});}
 function oPads(){return [C.P.pa.slice(),C.P.pb.slice()];}
 var HL=[];
 function hlOn(names){hlOff();names.forEach(function(n){var b=C.H[n];if(!b)return;var g=E('g',{class:'hl8'});
   g.appendChild(E('rect',{class:'u',x:b[0],y:b[1],width:b[2],height:b[3],rx:6}));g.appendChild(E('rect',{class:'d',x:b[0],y:b[1],width:b[2],height:b[3],rx:6}));fx.appendChild(g);HL.push(g);});}
 function hlOff(){HL.forEach(function(g){g.remove();});HL=[];}
 function ring(pt){var g=E('g',{class:'fx-chk',transform:'translate('+pt[0]+' '+pt[1]+')'});fx.appendChild(g);return g;}
 function toCheck(g){while(g.firstChild)g.removeChild(g.firstChild);g.appendChild(E('circle',{r:11,fill:'#22C55E'}));g.appendChild(E('path',{d:'M-5 0l3.3 3.3 6.6-6.6',fill:'none',stroke:'#fff','stroke-width':2.8,'stroke-linecap':'round','stroke-linejoin':'round'}));}
 function pos(g){var m=/translate\(([-\d.]+) ([-\d.]+)\)/.exec(g.getAttribute('transform'));return [+m[1],+m[2]];}
 function row(k,on){var e=r.querySelector('.ck-list .row-'+k);if(e)e.classList.toggle('on',on!==false);}
 function bar(k){var y=k?C.rows[k][1]:C.y0;$('.pbar-f').setAttribute('d','M'+C.bx+' '+C.y0+' V'+y);$('.pbar-k').setAttribute('cy',y);$('.pbar-h').setAttribute('cy',y);}
 function cur(k){[].slice.call(r.querySelectorAll('.ck-list .row')).forEach(function(e){e.classList.remove('cur');});if(k){var e=r.querySelector('.ck-list .row-'+k);if(e)e.classList.add('cur');bar(k);}}
 function rowPt(k){var e=r.querySelector('.ck-list .row-'+k+' i'),m=svg.getScreenCTM();if(!e||!m||!e.offsetParent)return null;var q=e.getBoundingClientRect(),p=svg.createSVGPoint();p.x=q.left+q.width/2;p.y=q.top+q.height/2;p=p.matrixTransform(m.inverse());return [p.x,p.y];}
 function fly(g,k){var a=pos(g),b=rowPt(k)||C.rows[k];tween(500,function(p){var x=a[0]+(b[0]-a[0])*p,y=a[1]+(b[1]-a[1])*p-Math.sin(p*Math.PI)*60,s=1-.35*p;g.setAttribute('transform','translate('+x+' '+y+') scale('+s+')');},function(){g.remove();row(k);});}
 var OFF=0;
 function at(t,f){if(t<OFF)return;T.push(setTimeout(f,(t-OFF)*SP));}
 function check(k,names,t0,d){if(t0<OFF)return;var g;at(t0,function(){hlOn(names);cur(k);});
   at(t0+d,function(){var b=C.H[names[0]];g=ring([b[0]+b[2]/2,b[1]+b[3]/2]);toCheck(g);});at(t0+d+350,function(){hlOff();fly(g,k);});}
 function cycle(off){
  gen++;T.forEach(clearTimeout);T=[];OFF=off||0;fx.innerHTML='';HL=[];
  r.className='x4 pm3 pm4';st('');cur('');
  ORDER.forEach(function(k){row(k,false);});
  txt('.psu-v','--.--');txt('.psu-a','-.--');txt('.mm-v','-.--');con(['Ready']);
  $('.mm-d').style.transform='rotate(-40deg)';tp=park();drawTip();
  tb=(OFF>400&&OFF<14500)?C.P.xm.slice():parkB();drawB();x2(OFF>=14600?0:1);
  if(OFF>400&&OFF<14500)tp=C.P.xp.slice(),drawTip();
  if(C.ojack){ot=OFF>25200?oPads():[opark(0),opark(1)];drawO();}
  var drawn=OFF>=4250;
  pwc.forEach(function(p){var L=p.getTotalLength();p.style.strokeDasharray=L;p.style.strokeDashoffset=drawn?0:L;});
  if(drawn)add('conn');
  if(OFF>=5200){add('pw');txt('.psu-v','12.00');txt('.psu-a','0.18');}
  if(OFF>=14600)add('x2on');
  if(OFF>=17600)add('fwr');
  ORDER.forEach(function(k){if(k==='volt'){if(OFF>14300)row(k);}else if(k==='pwr'){if(OFF>14500)row(k);}else if(START[k]<OFF)row(k);});
  if(OFF>0){var cK=ORDER.filter(function(k){return START[k]===OFF&&k!=='pwr'&&k!=='volt';})[0];if(cK)bar(cK);}
  at(400,function(){st('kz');moveTip(C.P.xp);moveB(C.P.xm);});
  at(1100,function(){txt('.mm-v','1.18 kΩ');});
  check('kz',['mmScr','x1'],1200,1100);
  at(3600,function(){st('conn');txt('.mm-v','-.--');pwc.forEach(function(p){var L=parseFloat(p.style.strokeDasharray);tween(650,function(q){p.style.strokeDashoffset=L*(1-q);});});});
  at(4250,function(){add('conn');});
  at(5200,function(){st('pw');add('pw');tween(350,function(p){txt('.psu-v',(12*p).toFixed(2));txt('.psu-a',(0.18*p).toFixed(2));});});
  check('cur',['psuA'],5900,1000);
  [['xp','11.96 V','v12',7700],['v5','5.01 V','v5',9600],['v33','3.30 V','v33',11500]].forEach(function(q){var t0=q[3];
    at(t0,function(){$('.mm-d').style.transform='rotate(40deg)';moveTip(C.P[q[0]]);txt('.mm-v','-.--');});
    at(t0+700,function(){txt('.mm-v',q[1]);});
    check(q[2],['p_'+(q[2]==='v12'?'xp':q[2]),'mmScr'],t0+600,800);});
  at(14300,function(){row('volt');});at(14500,function(){row('pwr');moveTip(park(),600);moveB(parkB(),600);txt('.mm-v','-.--');});
  at(14600,function(){st('fw');add('x2on');cur('fw');tween(800,function(p){x2(1-p);});});
  at(15100,function(){push('Loading');});at(15500,function(){push('firmware');});
  at(15900,function(){push(barT(0));tween(1600,function(p){last(barT(p));});});
  at(17600,function(){add('fwr');push('Running');});
  check('fw',['pcScr','prg','ledO'],14700,3800);
  at(19400,function(){st('wire');push('RS-485');});at(19800,function(){push('Connecting');});at(20500,function(){last('Connected');});
  check('wire',['pcScr','rs','x2'],19500,1800);
  at(22200,function(){st('wl');push('Radio');});at(22500,function(){push('Connecting');});at(23100,function(){last('Linked');});
  check('wl',['pcScr','rad','ant'],22300,1500);
  at(25200,function(){st('osc');add('osc');if(C.ojack)moveO(oPads(),1000);});
  at(30400,function(){if(C.ojack)moveO([opark(0),opark(1)],800);});
  check('osc',['scScr','p_osc'],26100,1500);
  at(28400,function(){st('done');cur('');bar('osc');add('done');});
  at(32500,function(){cycle(0);});
 }
 function jump(k){cycle(START[k]);}
 ORDER.forEach(function(k){var e=r.querySelector('.ck-list .row-'+k);if(e)e.addEventListener('click',function(){jump(k);});});
 function sy(ev){var pt=svg.createSVGPoint();pt.x=ev.clientX;pt.y=ev.clientY;return pt.matrixTransform(svg.getScreenCTM().inverse()).y;}
 function near(y){var best=null,bd=1e9;ORDER.forEach(function(k){var d=Math.abs(C.rows[k][1]-y);if(d<bd){bd=d;best=k;}});return best;}
 var pb=$('.pbar');
 pb.addEventListener('pointerdown',function(e){e.preventDefault();pb.classList.add('drag');pb.setPointerCapture(e.pointerId);gen++;T.forEach(clearTimeout);T=[];
   function mv(ev){var y=Math.max(C.y0,Math.min(C.y1,sy(ev)));$('.pbar-f').setAttribute('d','M'+C.bx+' '+C.y0+' V'+y);$('.pbar-k').setAttribute('cy',y);$('.pbar-h').setAttribute('cy',y);}
   mv(e);pb.onpointermove=mv;pb.onpointerup=function(ev){pb.onpointermove=null;pb.onpointerup=null;pb.classList.remove('drag');jump(near(sy(ev)));};});
 r._restart=function(){cycle(0);};
 if(RM){r.className='x4 pm3 pm4 conn pw fwr osc done x2on';ORDER.forEach(function(k){row(k);});tp=park();drawTip();tb=parkB();drawB();x2(0);bar('osc');return;}
 cycle(0);
}
[].slice.call(document.querySelectorAll('.pm4')).forEach(engine);
})();
(function(){
function rs(svg){var c=svg.cloneNode(true);svg.parentNode.replaceChild(c,svg);}
[].slice.call(document.querySelectorAll('details')).forEach(function(d){d.addEventListener('toggle',function(){if(!d.open)return;
  [].slice.call(d.querySelectorAll('.pm4,.ox-b')).forEach(function(r){if(r._restart)r._restart();});
  [].slice.call(d.querySelectorAll('svg.fa,svg.ca,svg.tra,svg.tra2,svg.dra,svg.tra3,svg.dra2,svg.tra4')).forEach(rs);});});
[].slice.call(document.querySelectorAll('.x4-del .stg-path__n')).forEach(function(n){n.addEventListener('click',function(){n.closest('.x4-del').classList.add('touched');setTimeout(function(){
  var on=n.closest('.x4-del').querySelector('.x4-dp__i.on');if(on)[].slice.call(on.querySelectorAll('svg.fa,svg.ca,svg.tra,svg.tra2,svg.dra,svg.tra3,svg.dra2,svg.tra4')).forEach(rs);},20);});});
})();
/* 08: стрелка панели — напротив выбранного этапа (вертикальный маршрут) */
(function(){
  function sync(r){var n=r.querySelector('.stg-path__n.on'),dp=r.querySelector('.x4-dp');if(!n||!dp)return;
    var a=n.getBoundingClientRect(),b=dp.getBoundingClientRect();if(!a.height)return;dp.style.setProperty('--ay',Math.round(a.top+a.height/2-b.top-7)+'px');}
  [].slice.call(document.querySelectorAll('.x4-del')).forEach(function(r){
    r.addEventListener('click',function(){setTimeout(function(){sync(r);},30);});
    if(window.ResizeObserver)new ResizeObserver(function(){sync(r);}).observe(r);
    window.addEventListener('resize',function(){sync(r);});
  });
})();

