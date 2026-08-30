/**
 * Admin System JS — Painel Administrativo
 * Controle de Gastos
 */
(function () {
  'use strict';

  /* ---- Sidebar Toggle ---- */
  var sidebar = document.getElementById('adminSidebar');
  var toggleBtn = document.getElementById('adminSidebarToggle');
  var backdrop = document.getElementById('adminSidebarBackdrop');

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function () {
      var isOpen = sidebar.classList.contains('open');
      sidebar.classList.toggle('open', !isOpen);
      if (backdrop) backdrop.classList.toggle('open', !isOpen);
      toggleBtn.setAttribute('aria-expanded', String(!isOpen));
      document.body.style.overflow = isOpen ? '' : 'hidden';
    });
  }

  if (backdrop) {
    backdrop.addEventListener('click', function () {
      sidebar.classList.remove('open');
      backdrop.classList.remove('open');
      toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    });
  }

  /* ---- Escape key closes sidebar ---- */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
      sidebar.classList.remove('open');
      if (backdrop) backdrop.classList.remove('open');
      toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }
  });

  /* ---- Auto-dismiss alerts ---- */
  var alerts = document.querySelectorAll('.admin-alert[data-auto-dismiss]');
  alerts.forEach(function (alert) {
    var delay = parseInt(alert.getAttribute('data-auto-dismiss') || '5000', 10);
    setTimeout(function () {
      alert.style.transition = 'opacity 300ms';
      alert.style.opacity = '0';
      setTimeout(function () { alert.remove(); }, 300);
    }, delay);
  });

  /* ---- Confirm dangerous actions ---- */
  var dangerousLinks = document.querySelectorAll('[data-confirm]');
  dangerousLinks.forEach(function (link) {
    link.addEventListener('click', function (e) {
      var msg = link.getAttribute('data-confirm') || 'Tem certeza?';
      if (!confirm(msg)) {
        e.preventDefault();
        return false;
      }
    });
  });

  /* ---- Admin chart: User Growth (Line) ---- */
  var growthCtx = document.getElementById('chartGrowth');
  if (growthCtx && typeof Chart !== 'undefined') {
    var growthLabels = JSON.parse(growthCtx.getAttribute('data-labels') || '[]');
    var growthData = JSON.parse(growthCtx.getAttribute('data-values') || '[]');
    if (growthLabels.length > 0) {
      new Chart(growthCtx, {
        type: 'line',
        data: {
          labels: growthLabels,
          datasets: [{
            label: 'Novos usuários',
            data: growthData,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.08)',
            borderWidth: 2,
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#10b981',
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#0f172a',
              titleColor: '#f8fafc',
              bodyColor: '#94a3b8',
              borderColor: 'rgba(255,255,255,0.1)',
              borderWidth: 1,
              padding: 10,
              cornerRadius: 8,
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { color: '#94a3b8', font: { size: 11 } }
            },
            y: {
              grid: { color: '#f1f5f9' },
              ticks: { color: '#94a3b8', font: { size: 11 }, stepSize: 1 },
              beginAtZero: true
            }
          }
        }
      });
    }
  }

  /* ---- Admin chart: Plan Distribution (Doughnut) ---- */
  var planCtx = document.getElementById('chartPlan');
  if (planCtx && typeof Chart !== 'undefined') {
    var planLabels = JSON.parse(planCtx.getAttribute('data-labels') || '[]');
    var planData = JSON.parse(planCtx.getAttribute('data-values') || '[]');
    if (planLabels.length > 0) {
      new Chart(planCtx, {
        type: 'doughnut',
        data: {
          labels: planLabels,
          datasets: [{
            data: planData,
            backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#64748b'],
            borderWidth: 2,
            borderColor: '#ffffff',
            hoverOffset: 4,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '68%',
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: '#64748b',
                font: { size: 12 },
                padding: 16,
                usePointStyle: true,
                pointStyleWidth: 8,
              }
            },
            tooltip: {
              backgroundColor: '#0f172a',
              titleColor: '#f8fafc',
              bodyColor: '#94a3b8',
              borderColor: 'rgba(255,255,255,0.1)',
              borderWidth: 1,
              padding: 10,
              cornerRadius: 8,
            }
          }
        }
      });
    }
  }

  /* ---- Admin chart: Bug Status (Horizontal Bar) ---- */
  var bugCtx = document.getElementById('chartBugs');
  if (bugCtx && typeof Chart !== 'undefined') {
    var bugLabels = JSON.parse(bugCtx.getAttribute('data-labels') || '[]');
    var bugData = JSON.parse(bugCtx.getAttribute('data-values') || '[]');
    var bugColors = JSON.parse(bugCtx.getAttribute('data-colors') || '[]');
    if (bugLabels.length > 0) {
      new Chart(bugCtx, {
        type: 'bar',
        data: {
          labels: bugLabels,
          datasets: [{
            data: bugData,
            backgroundColor: bugColors,
            borderRadius: 6,
            borderSkipped: false,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: '#0f172a',
              titleColor: '#f8fafc',
              bodyColor: '#94a3b8',
              borderColor: 'rgba(255,255,255,0.1)',
              borderWidth: 1,
              padding: 10,
              cornerRadius: 8,
            }
          },
          scales: {
            x: {
              grid: { color: '#f1f5f9' },
              ticks: { color: '#94a3b8', font: { size: 11 }, stepSize: 1 },
              beginAtZero: true
            },
            y: {
              grid: { display: false },
              ticks: { color: '#64748b', font: { size: 12 } }
            }
          }
        }
      });
    }
  }

})();
