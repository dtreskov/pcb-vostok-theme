/* =================================
   Production cycle — сквозная трасса (добавлено 2026-09-25)

   Рисует SVG-дорожку «как на печатной плате» через все
   карточки .cycle-step внутри .cycle-grid, в порядке DOM.
   Концы трассы — сами карточки 01 и 08 (без внешних узлов).
   - в одном ряду — прямой отрезок;
   - при переносе ряда — выход вправо за сетку, обратный ход
     в зазоре между рядами, вход в следующую карточку слева;
   - в одну колонку — вертикальная шина слева с отводами.
   Геометрия пересчитывается при изменении размеров сетки.

   По дорожке бежит импульс. Карточка, через которую он
   проходит, получает класс .is-active, пройденные — .is-done,
   прогресс прохождения — CSS-переменная --p (0…1).

   Атрибуты .cycle-grid:
   - data-joint: none | pin | arrow | tick — оформление стыков;
   - data-trace-y: num | center | top — уровень трассы;
   - data-parallel: link | bus | fork | group — две карточки с классом
     .cycle-step--parallel (соседние, в одном ряду) проходятся
     одновременно: link — трасса прежняя, связь между парой
     сплошная; bus / fork / group — шина, вилка или общий контур;
   - data-bus: split | diff | solid | edge — оформление шины (для bus):
     отводы со скосом / двойная дорожка над парой / сплошная
     магистраль над парой / шина по верхним кромкам пары (кромки
     заливаются одновременно, см. .cycle-grid--fill). Без атрибута — прямые отводы и
     подпись «параллельно».
   ================================= */

(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var OUT = 14;       // вынос обратного хода за край сетки, px
    var CHAMFER = 10;   // скос углов, px
    var TAIL = 70;      // длина импульса, px
    var SPEED = 380;    // скорость импульса, px/с
    var PAUSE = 1400;   // пауза между пробегами, мс

    var reduce = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function el(name, cls) {
        var n = document.createElementNS(NS, name);
        if (cls) n.setAttribute('class', cls);
        return n;
    }

    // Полилиния -> path с 45°-скосами + список прямых отрезков
    function chamfered(pts) {
        var d = 'M' + pts[0][0] + ' ' + pts[0][1];
        var segs = [], cur = pts[0];
        function to(x, y) {
            if (x === cur[0] && y === cur[1]) return;
            segs.push([cur[0], cur[1], x, y]);
            d += ' L' + x + ' ' + y;
            cur = [x, y];
        }
        for (var i = 1; i < pts.length - 1; i++) {
            var p = pts[i - 1], c = pts[i], n = pts[i + 1];
            var ax = c[0] - p[0], ay = c[1] - p[1];
            var bx = n[0] - c[0], by = n[1] - c[1];
            var la = Math.hypot(ax, ay), lb = Math.hypot(bx, by);
            if (!la || !lb || Math.abs(ax * by - ay * bx) < 0.01) continue;
            var k = Math.min(CHAMFER, la / 2, lb / 2);
            to(c[0] - ax / la * k, c[1] - ay / la * k);
            to(c[0] + bx / lb * k, c[1] + by / lb * k);
        }
        var e = pts[pts.length - 1];
        to(e[0], e[1]);
        var len = 0;
        segs.forEach(function (s) { s.push(len); len += Math.hypot(s[2] - s[0], s[3] - s[1]); });
        return { d: d, segs: segs, len: Math.max(len, 1) };
    }

    // Расстояние вдоль трассы до точки, лежащей на ней (или null)
    function along(tr, x, y) {
        for (var i = 0; i < tr.segs.length; i++) {
            var s = tr.segs[i];
            if (x >= Math.min(s[0], s[2]) - 0.5 && x <= Math.max(s[0], s[2]) + 0.5 &&
                y >= Math.min(s[1], s[3]) - 0.5 && y <= Math.max(s[1], s[3]) + 0.5) {
                return s[4] + Math.hypot(x - s[0], y - s[1]);
            }
        }
        return null;
    }

    function init(grid) {
        var joint = grid.getAttribute('data-joint') || 'none';
        var level = grid.getAttribute('data-trace-y') || 'num';
        var parMode = grid.getAttribute('data-parallel') || ((grid.className.match(/\bcycle-grid--par-([a-z]+)\b/) || [])[1]) || '';  // в Gutenberg — классом cycle-grid--par-*
        var busStyle = grid.getAttribute('data-bus') || '';

        var svg = el('svg', 'cycle-trace');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var gLines = el('g'), gLit = el('g', 'cycle-trace__lit'),
            gMarks = el('g', 'cycle-trace__marks'), gPulses = el('g'),
            gLabels = el('g');
        [gLines, gLit, gMarks, gPulses, gLabels].forEach(function (g) { svg.appendChild(g); });
        grid.insertBefore(svg, grid.firstChild);
        grid.classList.add('cycle-grid--traced');

        var steps = [], legs = [], lit = [], cardRanges = [], total = 1;

        function clear(g) { while (g.firstChild) g.removeChild(g.firstChild); }
        function linePath(g, d, cls) {
            var p = el('path', cls || 'cycle-trace__line');
            p.setAttribute('d', d); g.appendChild(p); return p;
        }
        function label(x, y, text) {
            var g = el('g', 'cycle-trace__label');
            var t = el('text'); t.textContent = text;
            t.setAttribute('x', x); t.setAttribute('y', y);
            t.setAttribute('text-anchor', 'middle');
            t.setAttribute('dominant-baseline', 'central');
            var r = el('rect');
            g.appendChild(r); g.appendChild(t); gLabels.appendChild(g);
            var w = (t.getComputedTextLength ? t.getComputedTextLength() : text.length * 7) + 16;
            r.setAttribute('x', x - w / 2); r.setAttribute('y', y - 9);
            r.setAttribute('width', w); r.setAttribute('height', 18);
            r.setAttribute('rx', 9);
        }
        function mark(x, y, side) {
            if (joint === 'pin') {
                var r = el('rect', 'cycle-trace__pin');
                r.setAttribute('x', x - 4); r.setAttribute('y', y - 6);
                r.setAttribute('width', 8); r.setAttribute('height', 12);
                r.setAttribute('rx', 1.5);
                gMarks.appendChild(r);
            } else if (joint === 'tick') {
                var t = el('path', 'cycle-trace__tick');
                var tx = x + side * 3;
                t.setAttribute('d', 'M' + tx + ' ' + (y - 7) + ' V' + (y + 7));
                gMarks.appendChild(t);
            }
        }
        function arrow(x, y, angle) {
            if (joint !== 'arrow') return;
            var a = el('path', 'cycle-trace__arrow');
            a.setAttribute('d', 'M-4 -5 L2 0 L-4 5');
            a.setAttribute('transform', 'translate(' + x + ' ' + y + ') rotate(' + angle + ')');
            gMarks.appendChild(a);
        }

        // фаза = набор одновременно идущих ног; нога = полилиния
        function build() {
            steps = [].slice.call(grid.children).filter(function (n) {
                return n.classList && n.classList.contains('cycle-step');
            });
            if (steps.length < 2) return;
            [gLines, gLit, gMarks, gPulses, gLabels].forEach(clear);

            var W = grid.clientWidth, H = grid.clientHeight;
            svg.setAttribute('width', W); svg.setAttribute('height', H);
            svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

            var b = steps.map(function (s) {
                var num = s.querySelector('.cycle-step__num');
                var y = level === 'center' ? s.offsetTop + s.offsetHeight / 2
                    : level === 'top' ? s.offsetTop + 1
                    : s.offsetTop + (num ? num.offsetTop + num.offsetHeight / 2 : 38);
                var l = s.offsetLeft, r = l + s.offsetWidth;
                return { l: l, r: r, cx: (l + r) / 2, t: s.offsetTop,
                         btm: s.offsetTop + s.offsetHeight, y: y };
            });
            var N = b.length, z = b[N - 1];
            var sameRow = function (a, c) { return Math.abs(a.t - c.t) < 2; };
            var cols = 1;
            while (cols < N && sameRow(b[0], b[cols])) cols++;
            var xL = -OUT, xR = W + OUT;

            // пара параллельных этапов
            var P = -1;
            if (parMode) {
                steps.forEach(function (s, i) {
                    if (P < 0 && s.classList.contains('cycle-step--parallel') &&
                        steps[i + 1] && steps[i + 1].classList.contains('cycle-step--parallel')) P = i;
                });
                if (P < 1 || P + 2 >= N) P = -1;
                // пара должна стоять в одном ряду, а следующий этап — в другом
                if (P >= 0 && cols > 1 &&
                    (!sameRow(b[P], b[P + 1]) || sameRow(b[P + 1], b[P + 2]))) P = -1;
            }

            function chain(from, to, S, E) {
                var pts = [S];
                for (var i = from; i < to; i++) {
                    var a = b[i], n = b[i + 1];
                    if (!sameRow(a, n)) {
                        var yG = (a.btm + n.t) / 2;
                        pts.push([xR, a.y], [xR, yG], [xL, yG], [xL, n.y]);
                    }
                }
                pts.push(E);
                return pts;
            }

            var phases = [];   // [{legs:[{pts}], glow:bool}]
            var stubD = '';

            if (cols === 1) {
                var x = b[0].l - 18;
                phases.push({ legs: [{ pts: [[x, b[0].y], [x, z.y]] }] });
                b.forEach(function (c, i) {
                    stubD += 'M' + x + ' ' + c.y + ' H' + c.l + ' ';
                    mark(c.l, c.y, -1);
                    if (i < N - 1) arrow(x, (c.btm + b[i + 1].t) / 2, 90);
                });
                // пара на телефоне проходится одновременно, без доп. графики
            } else if (P < 0 || parMode === 'link') {
                var pts = chain(0, N - 1, [b[0].l + 20, b[0].y], [z.r - 20, z.y]);
                phases.push({ legs: [{ pts: pts }] });
                for (var i = 0; i < N - 1; i++) {
                    var a = b[i], n = b[i + 1];
                    mark(a.r, a.y, 1); mark(n.l, n.y, -1);
                    if (sameRow(a, n)) arrow((a.r + n.l) / 2, a.y, 0);
                    else arrow(W / 2, (a.btm + n.t) / 2, 180);
                }
            } else {
                var pv = b[P - 1], A = b[P], B = b[P + 1], nx = b[P + 2];
                var fromRow = sameRow(pv, A);              // 02 в том же ряду, что пара
                var gx = (A.r + B.l) / 2;                  // зазор между 03 и 04
                var yM = (B.btm + nx.t) / 2;               // обратный ход после пары
                var leg1 = chain(0, P - 1, [b[0].l + 20, b[0].y], [pv.r, pv.y]);
                var leg3, branches, glow = false;

                if (parMode === 'bus') {
                    // со стилями шины — выше, чтобы отводы читались
                    var yIn = busStyle === 'edge' ? A.t + 1 : A.t - (busStyle ? 24 : 14);
                    var R = CHAMFER, fx;
                    // вход в шину: снизу (02 в том же ряду) или сверху
                    // (обратный ход после 02 — планшет)
                    var y0 = busStyle ? (fromRow ? yIn + R : yIn - R) : yIn;
                    if (fromRow) {
                        fx = (pv.r + A.l) / 2;
                        leg1.push([fx, pv.y], [fx, y0]);
                    } else {
                        var yG1 = pv.btm + 12;
                        fx = xL;
                        leg1.push([xR, pv.y], [xR, yG1], [xL, yG1], [xL, y0]);
                    }
                    var litD = '';
                    if (!busStyle) {
                        var busD = 'M' + fx + ' ' + yIn + ' H' + xR +
                            ' M' + A.cx + ' ' + yIn + ' V' + A.t +
                            ' M' + B.cx + ' ' + yIn + ' V' + B.t;
                        linePath(gLines, busD);
                        litD = busD;
                        branches = [{ pts: [[fx, yIn], [xR, yIn]], hidden: true }];
                        leg3 = [[xR, yIn], [xR, yM], [xL, yM], [xL, nx.y]];
                        label(gx, yIn, 'параллельно');
                    } else {
                        // шина со скошенными углами входа и выхода
                        var busPts = [[fx, y0], [fx, yIn], [xR, yIn], [xR, yIn + R]];
                        var busTr = chamfered(busPts);
                        litD = busTr.d;
                        branches = [{ pts: busPts }];
                        leg3 = [[xR, yIn + R], [xR, yM], [xL, yM], [xL, nx.y]];
                        // отводы к карточкам: скос 45° от шины
                        // (у шины по кромкам отводов нет — она идёт по самим карточкам)
                        var tapD = busStyle === 'edge' ? '' : [A, B].map(function (c) {
                            return 'M' + (c.cx - R) + ' ' + yIn + ' L' + c.cx + ' ' + (yIn + R) + ' V' + c.t;
                        }).join(' ');
                        if (tapD) { linePath(gLines, tapD); litD += ' ' + tapD; }
                        if (busStyle === 'diff') {
                            // вторая дорожка над парой — «дифпара»
                            var d2 = 6;
                            var outer = chamfered([[A.l - 4, yIn], [A.l - 4 + d2, yIn - d2],
                                                   [B.r + 4 - d2, yIn - d2], [B.r + 4, yIn]]).d;
                            linePath(gLines, outer);
                            litD += ' ' + outer;
                        } else if (busStyle === 'solid') {
                            var mag = 'M' + (A.l - 4) + ' ' + yIn + ' H' + (B.r + 4);
                            linePath(gLines, mag, 'cycle-trace__line cycle-trace__line--solid');
                            linePath(gLines, tapD, 'cycle-trace__line cycle-trace__line--solid');
                        }
                    }
                    lit.push(linePath(gLit, litD, 'cycle-trace__glow'));
                    glow = true;
                } else if (parMode === 'fork') {
                    var yHi = A.t - 16, F;
                    var aPts, bPts;
                    if (fromRow) {
                        F = [(pv.r + A.l) / 2, pv.y];
                        leg1.push(F);
                        aPts = [F, [gx, A.y], [gx, yM]];
                        bPts = [F, [F[0], yHi], [B.cx, yHi], [B.cx, B.y], [xR, B.y], [xR, yM], [gx, yM]];
                    } else {
                        var yG2 = (pv.btm + A.t) / 2;
                        F = [B.cx, yG2];
                        leg1.push([xR, pv.y], [xR, yG2], F);
                        aPts = [F, [xL, yG2], [xL, A.y], [gx, A.y], [gx, yM]];
                        bPts = [F, [B.cx, B.y], [xR, B.y], [xR, yM], [gx, yM]];
                    }
                    branches = [{ pts: aPts }, { pts: bPts }];
                    leg3 = [[gx, yM], [xL, yM], [xL, nx.y]];
                } else { // group
                    var fl = A.l - 8, fr = B.r + 8, ft = A.t - 8, fb = A.btm + 8;
                    var my = (ft + fb) / 2;
                    if (fromRow) {
                        var ax0 = (pv.r + fl) / 2;
                        leg1.push([ax0, pv.y], [ax0, my], [fl, my]);
                    } else {
                        var yG3 = (pv.btm + ft) / 2;
                        leg1.push([xR, pv.y], [xR, yG3], [xL, yG3], [xL, my], [fl, my]);
                    }
                    branches = [
                        { pts: [[fl, my], [fl, ft], [fr, ft], [fr, my]] },
                        { pts: [[fl, my], [fl, fb], [fr, fb], [fr, my]] }
                    ];
                    yM = (fb + nx.t) / 2;
                    leg3 = [[fr, my], [xR, my], [xR, yM], [xL, yM], [xL, nx.y]];
                    label((fl + fr) / 2, ft, 'параллельно');
                }
                leg3 = leg3.concat(chain(P + 2, N - 1, [nx.l, nx.y], [z.r - 20, z.y]).slice(1));
                phases.push({ legs: [{ pts: leg1 }] });
                phases.push({ legs: branches, glow: glow, par: true });
                phases.push({ legs: [{ pts: leg3 }] });
            }
            if (stubD) linePath(gLines, stubD);

            // link: сплошная связь между параллельными этапами
            if (P >= 0 && parMode === 'link') {
                var la = b[P], lb = b[P + 1];
                var solidD = cols === 1
                    ? 'M' + (b[0].l - 18) + ' ' + la.y + ' V' + lb.y
                    : 'M' + la.r + ' ' + la.y + ' H' + lb.l;
                linePath(gLabels, solidD, 'cycle-trace__line cycle-trace__line--link');
            }

            // ноги -> пути и тайминг
            legs = []; cardRanges = [];
            var t0 = 0;
            phases.forEach(function (ph) {
                var trs = ph.legs.map(function (lg) { return chamfered(lg.pts); });
                var D = Math.max.apply(null, trs.map(function (t) { return t.len; }));
                ph.t0 = t0; ph.D = D;
                trs.forEach(function (tr, k) {
                    if (!ph.legs[k].hidden) linePath(gLines, tr.d);
                    var pulse = null;
                    if (!ph.glow) {
                        pulse = linePath(gPulses, tr.d, 'cycle-trace__pulse');
                        pulse.style.strokeDasharray = TAIL + ' ' + (tr.len + TAIL * 2);
                        pulse.style.strokeDashoffset = TAIL;
                    }
                    legs.push({ tr: tr, t0: t0, ratio: tr.len / D, pulse: pulse, ph: ph });
                });
                t0 += D;
            });
            total = t0;

            // диапазоны карточек в «глобальном» времени (px)
            b.forEach(function (c, i) {
                if (cols === 1) {
                    var y0 = b[0].y;
                    var r0 = Math.max(0, c.t - y0), r1 = c.btm - y0;
                    if (P >= 0 && (i === P || i === P + 1)) {
                        r0 = Math.max(0, b[P].t - y0); r1 = b[P + 1].btm - y0;
                    }
                    cardRanges.push([r0, r1]); return;
                }
                if (P >= 0 && (i === P || i === P + 1)) {
                    if (parMode === 'link') {
                        var l0 = legs[0];
                        var s0 = along(l0.tr, b[P].l, b[P].y), s1 = along(l0.tr, b[P + 1].r, b[P + 1].y);
                        cardRanges.push([l0.t0 + (s0 || 0), l0.t0 + (s1 === null ? l0.tr.len : s1)]);
                        return;
                    }
                    var ph = phases[1];
                    cardRanges.push([ph.t0, ph.t0 + ph.D]); return;
                }
                var lg = P >= 0 ? (i < P ? legs[0] : legs[legs.length - 1]) : legs[0];
                var sIn = along(lg.tr, c.l, c.y), sOut = along(lg.tr, c.r, c.y);
                if (sIn === null) sIn = 0;
                if (sOut === null) sOut = lg.tr.len;
                cardRanges.push([lg.t0 + sIn / lg.ratio, lg.t0 + sOut / lg.ratio]);
            });
        }

        function paint(t) {
            legs.forEach(function (lg) {
                if (!lg.pulse) return;
                var s = Math.max(0, Math.min(lg.tr.len + TAIL, (t - lg.t0) * lg.ratio));
                lg.pulse.style.strokeDashoffset = TAIL - s;
            });
            legs.forEach(function (lg) {
                if (lg.ph.glow) {
                    var on = t >= lg.ph.t0 && t <= lg.ph.t0 + lg.ph.D;
                    gLit.classList.toggle('is-lit', on);
                }
            });
            steps.forEach(function (st, i) {
                var r = cardRanges[i]; if (!r) return;
                var p = Math.max(0, Math.min(1, (t - r[0]) / Math.max(1, r[1] - r[0])));
                st.style.setProperty('--p', p.toFixed(3));
                st.classList.toggle('is-active', t >= r[0] && t <= r[1] + TAIL * 0.6);
                st.classList.toggle('is-done', t > r[1]);
            });
        }

        var queued = false;
        function schedule() {
            if (queued) return;
            queued = true;
            requestAnimationFrame(function () { queued = false; build(); });
        }

        build();
        if ('ResizeObserver' in window) new ResizeObserver(schedule).observe(grid);
        else window.addEventListener('resize', schedule);
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);

        if (reduce) { paint(Infinity); gLit.classList.remove('is-lit'); return; }

        var visible = true, start = null, raf = 0;
        function frame(ts) {
            if (start === null) start = ts;
            var run = (total + TAIL) / (grid.classList.contains('cycle-grid--slow') ? SPEED / 2 : SPEED) * 1000;  // cycle-grid--slow — вдвое медленнее (мини-цикл этапа 1)
            var el2 = (ts - start) % (run + PAUSE);
            paint(Math.min(el2 / run, 1) * (total + TAIL));
            raf = visible ? requestAnimationFrame(frame) : 0;
        }
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) {
                visible = en[0].isIntersecting;
                if (visible && !raf) { start = null; raf = requestAnimationFrame(frame); }
            }).observe(grid);
        } else {
            raf = requestAnimationFrame(frame);
        }
    }

    function boot() {
        [].forEach.call(document.querySelectorAll('.cycle-grid'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
