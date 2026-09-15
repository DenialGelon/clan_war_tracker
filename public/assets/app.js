// Frontend for the clan war tracker. Plain JavaScript, Chart.js from a CDN.
// Reads api/dashboard.php for the page and api/lookup.php for the search box.
(function () {
  'use strict';

  const MAX_FAME = 3600; // one player cannot earn more than this in a week
  const MIN_FAME = 1200; // chart floor - below this is basically not playing, and 1200..3600 in steps of 400 gives tidy gridlines
  let dashboard = null;
  let openChart = null; // the single Chart.js instance that exists at a time
  let openRow = null;

  // ---------- helpers ----------

  // Clash Royale names can carry colour codes like "<c7>Name". Strip them for display.
  function cleanName(name) {
    return String(name || '').replace(/<\/?c\d*>/g, '').trim() || '(unknown)';
  }

  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else node.setAttribute(k, v);
      }
    }
    (children || []).forEach((c) => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
    return node;
  }

  function roleLabel(role) {
    return { leader: 'Leader', coLeader: 'Co-leader', elder: 'Elder', member: 'Member' }[role] || role || '';
  }

  function formatDate(iso) {
    if (!iso) return '';
    // API dates look like 20260907T095103.000Z; ours look like 2026-09-07T09:51:03Z.
    const m = iso.match(/^(\d{4})-?(\d{2})-?(\d{2})/);
    return m ? `${m[1]}-${m[2]}-${m[3]}` : iso;
  }

  // Fame cell with a proportional background bar. null = did not take part.
  function fameCell(fame) {
    if (fame === null || fame === undefined) {
      return el('span', { class: 'fame none', text: '-' });
    }
    const span = el('span', { class: 'fame', text: String(fame) });
    span.style.setProperty('--pct', Math.round((fame / MAX_FAME) * 100) + '%');
    return span;
  }

  // Tiny inline SVG line on a fixed 0..3600 scale. Missing weeks break the line.
  function sparkline(weekKeys, stats) {
    const ns = 'http://www.w3.org/2000/svg';
    const width = 90, height = 26, pad = 2;
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'spark');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    const n = weekKeys.length;
    const step = n > 1 ? (width - pad * 2) / (n - 1) : 0;
    let d = '';
    let pen = false;
    let lastPoint = null;
    weekKeys.forEach((key, i) => {
      const s = stats[key];
      if (!s) { pen = false; return; }
      const x = pad + i * step;
      const y = height - pad - (Math.max(0, s.fame - MIN_FAME) / (MAX_FAME - MIN_FAME)) * (height - pad * 2);
      d += (pen ? ' L' : ' M') + x.toFixed(1) + ' ' + y.toFixed(1);
      pen = true;
      lastPoint = [x, y];
    });
    if (d) {
      const path = document.createElementNS(ns, 'path');
      path.setAttribute('d', d.trim());
      svg.appendChild(path);
    }
    if (lastPoint) {
      const c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', lastPoint[0].toFixed(1));
      c.setAttribute('cy', lastPoint[1].toFixed(1));
      c.setAttribute('r', '1.8');
      svg.appendChild(c);
    }
    return svg;
  }

  // The full 0..3600 line chart. `points` is [{label, fame|null, provisional}].
  function drawChart(canvas, points) {
    if (openChart) { openChart.destroy(); openChart = null; }
    openChart = new Chart(canvas, {
      type: 'line',
      data: {
        labels: points.map((p) => p.label + (p.provisional ? '*' : '')),
        datasets: [{
          data: points.map((p) => p.fame),
          borderColor: '#2f6fed',
          backgroundColor: 'rgba(47,111,237,0.12)',
          pointRadius: 3,
          spanGaps: false,
          tension: 0.2,
          fill: true,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { display: false } },
        scales: {
          // Fixed axis on purpose: every chart on the site should be comparable at a glance.
          // Points below MIN_FAME are clipped to the floor by Chart.js.
          y: { min: MIN_FAME, max: MAX_FAME, ticks: { stepSize: 400 } },
          x: { ticks: { autoSkip: true, maxRotation: 45 } },
        },
      },
    });
  }

  function weeksTable(rows, showClan) {
    const head = el('tr', null, [
      el('th', { text: 'Week' }),
      ...(showClan ? [el('th', { text: 'Clan' })] : []),
      el('th', { text: 'Fame' }),
      el('th', { text: 'Decks' }),
      el('th', { text: 'Boats' }),
    ]);
    head.querySelectorAll('th').forEach((th, i) => { if (showClan && i === 1) th.style.textAlign = 'left'; });
    const body = rows.map((r) => {
      const tr = el('tr', null, [
        el('td', { text: r.label + (r.provisional ? '*' : '') }),
        ...(showClan ? [el('td', { text: r.clanName, style: 'text-align:left' })] : []),
        el('td', { text: r.fame === null ? '-' : String(r.fame) }),
        el('td', { text: r.fame === null ? '-' : String(r.decks) }),
        el('td', { text: r.fame === null ? '-' : String(r.boats) }),
      ]);
      return tr;
    });
    return el('table', { class: 'weeks' }, [el('thead', null, [head]), el('tbody', null, body)]);
  }

  // ---------- member tables ----------

  function renderMembers(tbody, members, kind) {
    tbody.textContent = '';
    const weekKeys = dashboard.weeks.map((w) => w.key);
    const labelByKey = Object.fromEntries(dashboard.weeks.map((w) => [w.key, w.label]));
    members.forEach((m, i) => {
      const tr = el('tr', { class: 'row', 'data-tag': m.tag });
      tr.appendChild(el('td', { class: 'rank', text: String(i + 1) }));
      const sub = kind === 'current'
        ? `${m.displayTag} · ${roleLabel(m.role)} · ${m.weeksRecorded} wk`
        : `${m.displayTag} · ${m.weeksRecorded} wk`;
      tr.appendChild(el('td', null, [
        el('div', { class: 'player-name', text: cleanName(m.name) }),
        el('div', { class: 'player-sub', text: sub }),
      ]));
      const numCell = el('td', { class: 'num' });
      if (kind === 'current') numCell.appendChild(fameCell(m.lastFame));
      else numCell.textContent = labelByKey[m.lastSeenKey] || m.lastSeenKey || '';
      tr.appendChild(numCell);
      tr.appendChild(el('td', { class: 'trend' }, [sparkline(weekKeys.slice(-10), m.weeks)]));
      tr.addEventListener('click', () => toggleDetail(tr, m));
      tbody.appendChild(tr);
    });
  }

  function toggleDetail(tr, member) {
    const wasOpen = openRow === tr;
    closeDetail();
    if (wasOpen) return;
    openRow = tr;
    tr.classList.add('open');
    const points = dashboard.weeks.map((w) => {
      const s = member.weeks[w.key];
      return { label: w.label, fame: s ? s.fame : null, decks: s ? s.decks : 0, boats: s ? s.boats : 0, provisional: w.provisional };
    });
    const canvas = el('canvas');
    const detail = el('tr', { class: 'detail' }, [
      el('td', { colspan: '4' }, [
        el('div', { class: 'chart-box' }, [canvas]),
        weeksTable(points.filter((p) => p.fame !== null).reverse(), false),
        el('p', { class: 'note', text: '* week still in progress' }),
      ]),
    ]);
    tr.after(detail);
    drawChart(canvas, points);
  }

  function closeDetail() {
    if (openChart) { openChart.destroy(); openChart = null; }
    if (openRow) {
      openRow.classList.remove('open');
      const next = openRow.nextElementSibling;
      if (next && next.classList.contains('detail')) next.remove();
      openRow = null;
    }
  }

  // ---------- lookup ----------

  async function lookup(tag, previousClan) {
    const box = document.getElementById('lookup-result');
    const button = document.getElementById('lookup-button');
    box.hidden = false;
    box.textContent = '';
    box.appendChild(el('p', { class: 'note', text: 'Searching...' }));
    button.disabled = true;
    try {
      let url = 'api/lookup.php?tag=' + encodeURIComponent(tag);
      if (previousClan) url += '&clan=' + encodeURIComponent(previousClan);
      const res = await fetch(url);
      const data = await res.json();
      box.textContent = '';
      if (data.error) {
        box.appendChild(el('p', { class: 'note error', text: data.error }));
        return;
      }
      const head = el('div', { class: 'lookup-head' });
      head.appendChild(el('strong', { text: data.name ? cleanName(data.name) : data.displayTag }));
      head.appendChild(el('span', { class: 'player-sub', text: ' ' + data.displayTag }));
      if (data.clan) {
        const ours = data.clan.tag === data.ourClanTag;
        head.appendChild(el('div', { class: 'player-sub', text: 'Currently in ' + cleanName(data.clan.name) + (ours ? ' (that is us)' : '') }));
      }
      box.appendChild(head);
      data.notes.forEach((n) => box.appendChild(el('p', { class: 'note', text: n })));
      if (data.weeks.length) {
        if (openChart) closeDetail();
        const canvas = el('canvas');
        box.appendChild(el('div', { class: 'chart-box' }, [canvas]));
        box.appendChild(weeksTable(data.weeks.slice().reverse(), true));
        box.appendChild(el('p', { class: 'note', text: '* week still in progress' }));
        // Plot on the full axis of the clans involved so skipped weeks show as gaps.
        const byKey = Object.fromEntries(data.weeks.map((w) => [w.key, w]));
        const axis = data.axis && data.axis.length ? data.axis : data.weeks;
        drawChart(canvas, axis.map((a) => {
          const w = byKey[a.key];
          return { label: a.label, fame: w ? w.fame : null, provisional: a.provisional };
        }));
      }
    } catch (err) {
      box.textContent = '';
      box.appendChild(el('p', { class: 'note error', text: 'Lookup failed: ' + err.message }));
    } finally {
      button.disabled = false;
    }
  }

  // ---------- boot ----------

  async function load() {
    const res = await fetch('api/dashboard.php');
    dashboard = await res.json();
    if (dashboard.error) throw new Error(dashboard.error);

    document.title = cleanName(dashboard.clan.name) + ' - Clan War Tracker';
    document.getElementById('clan-name').textContent = cleanName(dashboard.clan.name);
    const finished = dashboard.weeks.filter((w) => !w.provisional).length;
    document.getElementById('clan-meta').textContent =
      `#${dashboard.clan.tag} · ${finished} war weeks recorded · updated ${formatDate(dashboard.snapshotAt) || 'never'}`;

    document.getElementById('current-count').textContent = `(${dashboard.current.length})`;
    document.getElementById('former-count').textContent = `(${dashboard.former.length})`;
    renderMembers(document.querySelector('#current-table tbody'), dashboard.current, 'current');
    renderMembers(document.querySelector('#former-table tbody'), dashboard.former, 'former');

    document.getElementById('footnote').textContent =
      'Fame is capped at 3600 per week (16 decks). Data from the official Clash Royale API, stored weekly so history outlives the API’s ten-week window.';
  }

  document.getElementById('former-toggle').addEventListener('click', (e) => {
    const wrap = document.getElementById('former-wrap');
    wrap.hidden = !wrap.hidden;
    e.target.textContent = wrap.hidden ? 'Show former members' : 'Hide former members';
  });

  document.getElementById('lookup-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const tag = document.getElementById('lookup-tag').value.trim();
    const previousClan = document.getElementById('lookup-clan').value.trim();
    if (tag) lookup(tag, previousClan);
  });

  load().catch((err) => {
    document.getElementById('clan-meta').textContent = 'Could not load data: ' + err.message;
  });
})();
