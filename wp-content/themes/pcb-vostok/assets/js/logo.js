/* Логотип в подвале — концепция 25 «24 · белый PCB»: дорожки подгоняются под ширину слов по замеру шрифта Inter.
   Монтируется в каждый .site-logo25[data-h] (высота в px). */
(function(){
'use strict';
var LS=[14,26,38,50];
function T(k, after, gap, cls, size, y, txt, extra){
  return '<text ' + (k ? 'data-k="' + k + '" ' : '') + (after ? 'data-after="' + after + '" data-gap="' + gap + '" ' : '')
    + 'class="' + cls + '" font-size="' + size + '" x="0" y="' + y + '"' + (extra || '') + '>' + txt + '</text>';
}
function G(k, after, gap, inner){
  return '<g ' + (k ? 'data-k="' + k + '" ' : '') + (after ? 'data-after="' + after + '" data-gap="' + gap + '"' : '') + '>' + inner + '</g>';
}

/* ---------- SOIC8 ---------- */
var LS = [14,26,38,50];
function soic(o){
  o = o || {}; var l = o.l || 's-cu', r = o.r || 's-au', mode = o.mode || 'none', s = '';
  var fl = l.replace('s-','f-'), fr = r.replace('s-','f-');
  var A = ' stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"';
  if (mode === 'fan'){
    var C = [23,29,35,41];
    LS.forEach(function(L,i){ var d = Math.abs(L-C[i]);
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H24L'+(24-d)+' '+C[i]+'H6"/><circle class="'+fl+'" cx="4" cy="'+C[i]+'" r="2.8"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H92L'+(92+d)+' '+C[i]+'H110"/><circle class="'+fr+'" cx="112" cy="'+C[i]+'" r="2.8"/>';
    });
  } else if (mode === 'stagger'){
    var EL = [6,16,6,16], ER = [110,100,110,100];
    LS.forEach(function(L,i){
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H'+(EL[i]+3.4)+'"/><circle class="'+l+'" stroke-width="2.2" style="fill:var(--bg)" cx="'+EL[i]+'" cy="'+L+'" r="3.4"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H'+(ER[i]-3.4)+'"/><circle class="'+r+'" stroke-width="2.2" style="fill:var(--bg)" cx="'+ER[i]+'" cy="'+L+'" r="3.4"/>';
    });
  } else if (mode === 'stub'){
    (o.stubs || [0,1,2]).forEach(function(i){ var L = LS[i];
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H22.4"/><circle class="'+(o.vl||l)+'" stroke-width="2.2" style="fill:var(--bg)" cx="19" cy="'+L+'" r="3.4"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H93.6"/><circle class="'+(o.vr||r)+'" stroke-width="2.2" style="fill:var(--bg)" cx="97" cy="'+L+'" r="3.4"/>';
    });
  } else if (mode === 'ul'){
    LS.slice(0,3).forEach(function(L){
      s += '<path class="'+l+'"'+A+' d="M31 '+L+'H21"/><circle class="'+fl+'" cx="19" cy="'+L+'" r="2.8"/>';
      s += '<path class="'+r+'"'+A+' d="M85 '+L+'H95"/><circle class="'+fr+'" cx="97" cy="'+L+'" r="2.8"/>';
    });
  }
  LS.forEach(function(L){
    s += '<rect class="f-au" x="30" y="'+(L-2.5)+'" width="8" height="5" rx="1"/><rect class="f-au2" x="35.5" y="'+(L-2.5)+'" width="2.5" height="5"/>';
    s += '<rect class="f-au" x="78" y="'+(L-2.5)+'" width="8" height="5" rx="1"/><rect class="f-au2" x="78" y="'+(L-2.5)+'" width="2.5" height="5"/>';
  });
  s += '<rect class="'+(o.body || 'f-body')+'" x="38" y="5" width="40" height="54" rx="3"/>';
  s += '<rect class="s-hi" stroke-width="1" x="40.5" y="7.5" width="35" height="49" rx="2"/>';
  s += '<circle class="f-hi" cx="45.5" cy="14" r="2.8"/>';
  if (o.mark) s += '<text class="mo f-mk" font-weight="600" font-size="10.5" x="58" y="36" text-anchor="middle" style="letter-spacing:.06em">'+o.mark+'</text>';
  return s;
}
/* дорожка-подчёркивание: от точки на соседнем элементе под всё слово */
function UL(textKey, relKey, x0, y1, y2, dir, mode, cls, capCls){
  return '<path data-ul="'+textKey+'" data-rel="'+relKey+'" data-x0="'+x0+'" data-y1="'+y1+'" data-y2="'+y2+'" data-dir="'+dir+'" data-mode="'+mode+'" class="'+cls+'" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" d="M0 0"/>'
    + '<circle data-cap="1" class="'+(capCls||cls)+'" stroke-width="2.2" style="fill:var(--bg)" r="3.4" cx="0" cy="0"/>';
}

var FULL=T('a','',0,'i8 f-wt',44,48,'PCB') + G('u','a',10,soic({mode:'stub',stubs:[1,2],l:'s-cu',r:'s-cu',vl:'s-au',vr:'s-au'})) + T('w','u',10,'i8 f-ink',44,48,'ВОСТОК') + UL('a','u',30,14,6,-1,'h','s-cu','s-au') + UL('w','u',86,14,6,1,'h','s-cu','s-au') + UL('a','u',30,50,58,-1,'h','s-cu','s-au') + UL('w','u',86,50,58,1,'h','s-cu','s-au');
var NS='http://www.w3.org/2000/svg';
function bb(el){ try { return el.getBBox(); } catch(e){ return {x:0,y:0,width:0,height:0}; } }
function layout(svg){
  var map = {};
  [].forEach.call(svg.querySelectorAll('[data-k]'), function(n){ map[n.getAttribute('data-k')] = n; n._dx = 0; });
  var ok = true;
  [].forEach.call(svg.querySelectorAll('[data-after]'), function(n){
    var r = map[n.getAttribute('data-after')]; if (!r) return;
    var rb = bb(r), nb = bb(n);
    if (!rb.width || !nb.width) { ok = false; return; }
    var x = (r._dx || 0) + rb.x + rb.width + (+n.getAttribute('data-gap')) - nb.x;
    n.setAttribute('transform','translate(' + x.toFixed(2) + ' 0)'); n._dx = x;
  });
  if (!ok) return false;
  [].forEach.call(svg.querySelectorAll('[data-ul]'), function(p){
    var t = map[p.getAttribute('data-ul')], rel = map[p.getAttribute('data-rel')]; if (!t || !rel) return;
    var x0 = +p.getAttribute('data-x0'), y1 = +p.getAttribute('data-y1'), y2 = +p.getAttribute('data-y2'), dir = +p.getAttribute('data-dir');
    var sx = (rel._dx || 0) + x0, tb = bb(t), tx = tb.x + (t._dx || 0);
    var end = dir < 0 ? tx + 3 : tx + tb.width - 3, dy = Math.abs(y2 - y1), d, md = p.getAttribute('data-mode');
    if (md === 's'){ y2 = y1; d = 'M'+sx+' '+y1+'H'+(end - dir*3.4); }
    else if (md === 'v') d = 'M'+sx+' '+y1+'V'+(y1+2)+'L'+(sx+dir*(dy-2))+' '+y2+'H'+end;
    else d = 'M'+sx+' '+y1+'H'+(sx+dir*5)+'L'+(sx+dir*(5+dy))+' '+y2+'H'+(end - dir*3.4);
    p.setAttribute('d', d);
    var c = p.nextElementSibling; if (c && c.hasAttribute('data-cap')){ c.setAttribute('cx', end); c.setAttribute('cy', y2); }
  });
  var b = bb(svg.querySelector('g.root'));
  if (!b.width || !b.height) return false;
  var x = b.x, y = b.y, w = b.width, h = b.height;
  if (svg._sq){ var m = Math.max(w,h); x -= (m-w)/2; y -= (m-h)/2; w = h = m; }
  else { var pd = h*0.04; x -= pd; y -= pd; w += 2*pd; h += 2*pd; }
  svg.setAttribute('viewBox', [x,y,w,h].map(function(v){return v.toFixed(2);}).join(' '));
  svg.setAttribute('height', svg._h);
  svg.setAttribute('width', (svg._h * w / h).toFixed(1));
  return true;
}

var all=[];
[].forEach.call(document.querySelectorAll('.site-logo25'),function(host){
  var s=document.createElementNS(NS,'svg');
  s.setAttribute('class','lg'); s.setAttribute('role','img'); s.setAttribute('aria-label','PCB Восток');
  s.innerHTML='<g class="root">'+FULL+'</g>';
  s._h=+host.getAttribute('data-h')||30; s._sq=false;
  s.setAttribute('viewBox','-4 0 400 64'); s.setAttribute('height',s._h); s.setAttribute('width',Math.round(s._h*400/64));
  host.appendChild(s); all.push(s);
});
var tries=0;
function run(){ var done=true; all.forEach(function(s){ if(!s._ok){ if(layout(s)) s._ok=true; else done=false; } }); if(!done && tries++<120) setTimeout(run,250); }
function again(){ all.forEach(function(s){ s._ok=false; }); tries=0; run(); }
if(document.fonts && document.fonts.ready){ document.fonts.ready.then(again); ['800 44px Inter'].forEach(function(f){ try{ document.fonts.load(f).then(again); }catch(e){} }); }
run();
})();
