/**
 * Черновик формы заявки и калькулятора — в браузере посетителя (window.PCBDraft).
 *
 * Чтобы введённое не терялось при переходе между страницами (главная ↔ калькулятор, случайный
 * клик по ссылке), request-form.js и calculator.js сохраняют поля и выбранные файлы в IndexedDB —
 * она хранит файлы как есть (localStorage — только строки и около 5 МБ). На сервер черновик не уходит.
 * Записи: 'form' — форма заявки, 'calc' — калькулятор. Срок хранения — 7 дней, удаляется после
 * отправки заявки и по кнопке «Очистить». Это описано в /politics (cookies-8) — меняя срок, поправьте текст.
 * Нет IndexedDB (бывает в приватном режиме) — запасной вариант в localStorage без файлов.
 */
(function () {
  'use strict';

  var TTL = 7 * 24 * 3600 * 1000;
  var DB = 'pcb-draft', ST = 'draft', LS = 'pcb_draft_';
  var dbp = null;

  function open() {
    if (dbp) return dbp;
    dbp = new Promise(function (ok, no) {
      if (!window.indexedDB) { no(new Error('idb')); return; }
      var rq;
      try { rq = indexedDB.open(DB, 1); } catch (e) { no(e); return; }
      rq.onupgradeneeded = function () { rq.result.createObjectStore(ST); };
      rq.onsuccess = function () { ok(rq.result); };
      rq.onerror = function () { no(rq.error); };
      rq.onblocked = function () { no(new Error('blocked')); };
    });
    dbp.catch(function () {});
    return dbp;
  }
  function tx(mode, fn) {
    return open().then(function (db) {
      return new Promise(function (ok, no) {
        var t = db.transaction(ST, mode), r = fn(t.objectStore(ST));
        t.oncomplete = function () { ok(r && r.result); };
        t.onerror = t.onabort = function () { no(t.error); };
      });
    });
  }
  /* запасной вариант без файлов: File в JSON не сериализуется — оставляем только имя */
  function lsStrip(v) { return JSON.stringify(v, function (k, x) { return (typeof File !== 'undefined' && x instanceof Blob) ? null : x; }); }
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(LS + k)); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(LS + k, lsStrip(v)); } catch (e) { /* хранилище закрыто или переполнено */ } }
  function lsDel(k) { try { localStorage.removeItem(LS + k); } catch (e) { /* хранилище закрыто */ } }

  function fresh(v) { return v && v.t && Date.now() - v.t < TTL ? v : null; }

  window.PCBDraft = {
    ttlDays: 7,
    /* → Promise<запись | null>; просроченная запись удаляется */
    get: function (key) {
      return tx('readonly', function (s) { return s.get(key); })
        .catch(function () { return lsGet(key); })
        .then(function (v) {
          if (v && !fresh(v)) { window.PCBDraft.del(key); return null; }
          return v || null;
        });
    },
    /* v — объект; метку времени t ставим сами */
    set: function (key, v) {
      v.t = Date.now();
      return tx('readwrite', function (s) { return s.put(v, key); })
        .then(function () { lsDel(key); })
        .catch(function () { lsSet(key, v); }); // нет IndexedDB или файлы не поместились
    },
    del: function (key) {
      lsDel(key);
      return tx('readwrite', function (s) { return s.delete(key); }).catch(function () {});
    }
  };
})();
