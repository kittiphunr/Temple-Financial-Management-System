/* กราฟด้วย Chart.js (assets/vendor/chart.umd.min.js, MIT — ดูเครดิตใน CREDITS.md)
 * แบบ progressive enhancement: เซิร์ฟเวอร์ใส่กราฟสำรอง (SVG/รายการ) ไว้ใน [data-chart-fallback] ไม่มี JS หรือสร้างกราฟไม่ได้ก็ยังเห็นข้อมูลเหมือนเดิม
 * <div data-chart='{"type":"bar|doughnut","labels":[...],"datasets":[{"label":"..","data":[..],"color":"#hex"|"colors":[..]}],"unit":"บาท","legend":false}'
 *      data-chart-label="คำอธิบายสำหรับโปรแกรมอ่านหน้าจอ" data-chart-height="18rem"> */
(function () {
  'use strict';
  var boxes = document.querySelectorAll('[data-chart]');
  if (!boxes.length || typeof Chart === 'undefined') return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily || 'sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#57534e';
  if (reduce) Chart.defaults.animation = false;

  var nf = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function short(v) {
    var a = Math.abs(v), s = v < 0 ? '-' : '';
    if (a >= 1e6) return s + (a / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return s + (a / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(v);
  }

  function build(cfg) {
    var unit = cfg.unit || '';
    var legend = { display: cfg.legend !== false, position: 'bottom' };
    if (cfg.type === 'doughnut') {
      var total = (cfg.datasets[0].data || []).reduce(function (a, b) { return a + b; }, 0) || 1;
      return {
        type: 'doughnut',
        data: { labels: cfg.labels, datasets: [{ data: cfg.datasets[0].data, backgroundColor: cfg.datasets[0].colors, borderColor: '#ffffff', borderWidth: 2 }] },
        options: {
          responsive: true, maintainAspectRatio: false, cutout: '60%',
          plugins: {
            legend: legend,
            tooltip: { callbacks: { label: function (c) { return c.label + ': ' + nf.format(c.parsed) + ' ' + unit + ' (' + (c.parsed / total * 100).toFixed(1) + '%)'; } } }
          }
        }
      };
    }
    return {
      type: 'bar',
      data: {
        labels: cfg.labels,
        datasets: cfg.datasets.map(function (d) { return { label: d.label, data: d.data, backgroundColor: d.color, borderRadius: 4, maxBarThickness: 26 }; })
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: legend,
          tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + nf.format(c.parsed.y) + ' ' + unit; } } }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: '#e7e5e4' }, ticks: { callback: short } }
        }
      }
    };
  }

  Array.prototype.forEach.call(boxes, function (box) {
    var cfg, fb = box.querySelector('[data-chart-fallback]');
    try { cfg = JSON.parse(box.getAttribute('data-chart')); } catch (e) { return; }
    var wrap = document.createElement('div');
    wrap.style.position = 'relative';
    wrap.style.height = box.getAttribute('data-chart-height') || '18rem';
    var canvas = document.createElement('canvas');
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', box.getAttribute('data-chart-label') || 'กราฟ');
    wrap.appendChild(canvas);
    try {
      box.appendChild(wrap);
      new Chart(canvas, build(cfg));
    } catch (e) {
      if (wrap.parentNode) wrap.parentNode.removeChild(wrap);   // สร้างกราฟไม่ได้: ใช้กราฟสำรองต่อไป
      return;
    }
    if (fb) fb.style.display = 'none';
    box.setAttribute('data-chart-ready', '1');
  });
})();
