/* Этап 02 «Аудит файлов»: интерактивная плата «Типичные ошибки» (итерация 8) */
(function(){
var T={"1": ["Компонент недоступен", "Позиция из BOM снята с производства или отсутствует в файле установки."], "2": ["Разрыв дорожки в Gerber", "Проводник прерван в слое меди — часто это след ручной правки или неудачного экспорта."], "3": ["Зазор меньше нормы", "Расстояние между проводниками или до переходного отверстия меньше технологической нормы фабрики."], "4": ["Отверстие в площадке", "Незаполненное переходное отверстие в контактной площадке SMD-компонента."], "5": ["Посадочное место ≠ корпус", "Посадочное место рассчитано на другой корпус: шаг или габарит выводов не совпадает с документацией."], "6": ["Смещены окна маски", "Окна паяльной маски сдвинуты относительно площадок и частично их закрывают."], "7а": ["Нет метки полярности", "Метка полярности отсутствует и на корпусе, и в шелкографии."], "or": ["Неверная ориентация компонента", "Угол поворота в файле установки или метка на плате не совпадают с корпусом компонента."], "8": ["Шелкография на площадке", "Контур или позиционное обозначение нанесены поверх контактных площадок."], "10": ["Смещение сверловки", "Отверстие смещено относительно центра площадки и рвёт поясок."], "11": ["Медь слишком близко к краю", "Медь заходит в запретную зону у кромки платы или у крепёжного отверстия."]}, ORDER=["1", "2", "3", "4", "5", "6", "7\u0430", "or", "8", "10", "11"], HINT="Наведите на выделенное место на плате";
function Widget(root,kind){
  var marks=[].slice.call(root.querySelectorAll('.hl')), pairs=[].slice.call(root.querySelectorAll('[data-k]:not(.hl)'));
  var cur=null, pinned=null;
  function paint(k){
    marks.concat(pairs).forEach(function(el){ el.classList.toggle('on', k!==null && el.getAttribute('data-k')===k); });
    cur=k; render(k);
  }
  function render(k){
    if(kind==='A'){
      var tip=root.querySelector('.tip'), bw=root.querySelector('.bw');
      if(k===null){ tip.classList.remove('show'); return; }
      tip.querySelector('b').textContent=T[k][0]; tip.querySelector('span').textContent=T[k][1];
      var r=bw.getBoundingClientRect(), g=root.querySelector('.hl[data-k="'+k+'"]').getBoundingClientRect();
      var tw=tip.offsetWidth, th=tip.offsetHeight, x=g.right-r.left+12, y=(g.top+g.bottom)/2-r.top-th/2;
      if(x+tw>r.width-8) x=g.left-r.left-tw-12;
      if(x<8) x=Math.min(Math.max(8,(g.left+g.right)/2-r.left-tw/2), r.width-tw-8), y=g.bottom-r.top+10;
      y=Math.min(Math.max(8,y), r.height-th-8);
      tip.style.left=x+'px'; tip.style.top=y+'px'; tip.classList.add('show');
    }
    if(kind==='B'){
      root.classList.toggle('act',k!==null);
      root.querySelector('.bt b').textContent=k===null?HINT:T[k][0];
      root.querySelector('.bt span').textContent=k===null?'11 типичных ошибок, которые находит аудит':T[k][1];
      root.querySelector('.cnt').textContent=k===null?'':(ORDER.indexOf(k)+1)+' / '+ORDER.length;
    }
    if(kind==='D'){
      root.querySelector('.desc').innerHTML=k===null?'<span>'+HINT+'</span>':'<b>'+T[k][0]+'.</b> '+T[k][1];
    }
  }
  marks.concat(pairs).forEach(function(el){
    var k=el.getAttribute('data-k');
    el.addEventListener('mouseenter',function(){ if(pinned===null) paint(k); });
    el.addEventListener('mouseleave',function(){ if(pinned===null) paint(null); });
    // не даём группе получать фокус по клику — иначе браузер рисует рамку по её габариту
    el.addEventListener('mousedown',function(e){ if(el.classList.contains('hl')) e.preventDefault(); });
    if(!el.classList.contains('hl')) el.addEventListener('focus',function(){ paint(k); });
    el.addEventListener('click',function(e){ e.stopPropagation(); pinned=(pinned===k)?null:k; paint(pinned); });
  });
  [].slice.call(root.querySelectorAll('.nav')).forEach(function(b){
    b.addEventListener('click',function(e){ e.stopPropagation();
      var i=cur===null?-1:ORDER.indexOf(cur), n=ORDER.length;
      i=b.classList.contains('next')?(i+1)%n:(i<=0?n-1:i-1);
      pinned=ORDER[i]; paint(pinned); });
  });
  document.addEventListener('click',function(){ if(pinned!==null){ pinned=null; paint(null); } });
}
[].slice.call(document.querySelectorAll('.vw')).forEach(function(r){ Widget(r, r.className.match(/v([A-D])/)[1]); });
var tabs=[];
tabs.forEach(function(b){ b.addEventListener('click',function(){
  tabs.forEach(function(t){ t.setAttribute('aria-selected', t===b?'true':'false'); });
  [].slice.call(document.querySelectorAll('section.v')).forEach(function(s){ s.classList.toggle('show', s.id==='v'+b.dataset.v); });
  document.querySelector('.vd').textContent=b.dataset.d;
}); });
})();