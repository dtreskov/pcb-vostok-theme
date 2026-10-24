/*
 * Снимок онлайн-прайса фабрики-партнёра (внутренний инструмент, на сайт не выкладывается).
 * Запуск: открыть https://www.aipcba.com/pcb.html, консоль разработчика (F12) → вставить файл → Enter.
 * Только синтетические заказы, без файлов и данных клиентов. Пауза между запросами — 1,5 с.
 * Результат — CSV в буфер обмена и в window.__CSV (строка на расчёт, статьи в юанях).
 * Окно с вкладкой держать на экране: в фоновой вкладке браузер замедляет таймеры.
 */
(async () => {
  const base = getPcbSmtRequestData('.form-wrapper.pcb');
  const ask = async (o) => {
    const d = Object.assign({}, base, o);
    ['suits-num', 'suitsNum', 'productNums', 'quantity', 'pcb_quantity'].forEach((k) => { d[k] = String(o.qty); });
    delete d.qty;
    const r = await $.ajax({ url: SITEURL + '?act=pcba&op=quoteOnline&type=pcb', type: 'post', dataType: 'json', data: d });
    const f = {};
    const s = (r.data && r.data.calDetail && r.data.calDetail.fee_detail) || '';
    const re = /s:\d+:"key";s:\d+:"(\w+)";s:3:"val";[id]:([\d.]+)/g;
    let m;
    while ((m = re.exec(s))) f[m[1]] = +m[2];
    const t = s.match(/total_fee";[id]:([\d.]+)/);
    f.total = t ? +t[1] : null;
    return f;
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const jobs = [];
  for (const l of [1, 2, 4, 6, 8, 10, 12]) {
    for (const q of [5, 10, 20, 50, 100, 200, 500, 1000, 2000]) jobs.push(['grid', { noOfLayer: String(l), length: 100, width: 100, qty: q }]);
  }
  const opts = [{ thickness: '0.4' }, { thickness: '2.0' }, { thickness: '2.4' }, { thickness: '3.2' }, { copperWeight: '2' }, { copperWeight: '3' },
    { copperWeight: '4' }, { finish: 'hardGold' }, { finish: 'ag' }, { lineWeight: '3' }, { high_TG: 'TG150-160' }, { high_TG: 'TG170-180' },
    { impedance: '1' }, { edgePlating: '1' }];
  for (const b of [{ noOfLayer: '2', qty: 10 }, { noOfLayer: '2', qty: 500 }, { noOfLayer: '4', qty: 10 }, { noOfLayer: '4', qty: 500 }]) {
    jobs.push(['opt:base', Object.assign({ length: 100, width: 100 }, b)]);
    for (const o of opts) jobs.push(['opt:' + JSON.stringify(o), Object.assign({ length: 100, width: 100 }, b, o)]);
  }
  const K = ['materialFee', 'designFee', 'engneerFee', 'testingFee', 'thicknessFee', 'copperFee', 'minTrackSpacingFee', 'urgentFee', 'total'];
  const rows = ['series;layers;len;wid;qty;' + K.join(';')];
  for (const [tag, o] of jobs) {
    try {
      const f = await ask(o);
      rows.push([tag, o.noOfLayer, o.length, o.width, o.qty].concat(K.map((k) => f[k] ?? '')).join(';'));
    } catch (e) {
      rows.push([tag, o.noOfLayer, o.length, o.width, o.qty, 'error'].join(';'));
    }
    await sleep(1500);
  }
  window.__CSV = rows.join('\n');
  try { await navigator.clipboard.writeText(window.__CSV); } catch (e) { /* копировать из window.__CSV вручную */ }
  console.log('Готово: ' + (rows.length - 1) + ' расчётов. CSV — в буфере обмена и в window.__CSV.');
})();
