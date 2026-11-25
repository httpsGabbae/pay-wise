// Dashboard Privacy Mask and Interactive Charts
// Handles asterisk (*) privacy masking, department filtering, and hover tooltips for SVG charts.

document.addEventListener('DOMContentLoaded', function () {
  var privacyToggleButton = document.getElementById('privacyToggleButton');
  var maskableAmountElements = document.querySelectorAll('.maskable-financial-amount');
  var departmentFilterSelect = document.getElementById('departmentFilterSelect');
  var recentEmployeeRows = document.querySelectorAll('.recent-employee-row');

  // Cache original financial strings
  maskableAmountElements.forEach(function (element) {
    if (!element.getAttribute('data-original-amount')) {
      element.setAttribute('data-original-amount', element.textContent.trim());
    }
  });

  function applyPrivacyMask(isPrivacyActive) {
    maskableAmountElements.forEach(function (element) {
      var originalAmount = element.getAttribute('data-original-amount');
      if (isPrivacyActive) {
        element.textContent = '******';
        element.classList.add('is-masked');
      } else {
        element.textContent = originalAmount;
        element.classList.remove('is-masked');
      }
    });

    if (privacyToggleButton) {
      if (isPrivacyActive) {
        privacyToggleButton.classList.add('is-active-privacy');
        privacyToggleButton.textContent = 'Show Amounts';
      } else {
        privacyToggleButton.classList.remove('is-active-privacy');
        privacyToggleButton.textContent = 'Hide Amounts';
      }
    }
  }

  // Check saved privacy preference
  var savedPrivacyPreference = localStorage.getItem('paywise_dashboard_privacy_active');
  var isPrivacyCurrentlyActive = savedPrivacyPreference === 'true';

  if (isPrivacyCurrentlyActive) {
    applyPrivacyMask(true);
  }

  if (privacyToggleButton) {
    privacyToggleButton.addEventListener('click', function () {
      isPrivacyCurrentlyActive = !isPrivacyCurrentlyActive;
      localStorage.setItem('paywise_dashboard_privacy_active', isPrivacyCurrentlyActive ? 'true' : 'false');
      applyPrivacyMask(isPrivacyCurrentlyActive);
    });
  }

  // Department filter for employee table
  if (departmentFilterSelect) {
    departmentFilterSelect.addEventListener('change', function () {
      var selectedDepartment = departmentFilterSelect.value.trim().toLowerCase();

      recentEmployeeRows.forEach(function (row) {
        var rowDepartment = (row.getAttribute('data-department-name') || '').trim().toLowerCase();

        if (selectedDepartment === '' || selectedDepartment === 'all' || rowDepartment === selectedDepartment) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
  }

  // Interactive Hover Tooltips for Charts
  var chartTooltip = document.createElement('div');
  chartTooltip.className = 'quickroll-chart-tooltip';
  document.body.appendChild(chartTooltip);

  function showTooltip(htmlContent, event) {
    chartTooltip.innerHTML = htmlContent;
    chartTooltip.style.display = 'block';
    positionTooltip(event);
  }

  function positionTooltip(event, anchorElement) {
    var tooltipWidth = chartTooltip.offsetWidth;
    var tooltipHeight = chartTooltip.offsetHeight;
    var baseX = event.pageX;
    var baseY = event.pageY;

    if ((!baseX && !baseY) && anchorElement) {
      var rect = anchorElement.getBoundingClientRect();
      baseX = rect.left + (rect.width / 2) + window.scrollX;
      baseY = rect.top + window.scrollY;
    }

    var pageX = baseX + 12;
    var pageY = baseY - tooltipHeight - 10;

    if (pageX + tooltipWidth > window.scrollX + window.innerWidth - 12) {
      pageX = baseX - tooltipWidth - 12;
    }
    if (pageX < window.scrollX + 12) {
      pageX = window.scrollX + 12;
    }
    if (pageY < window.scrollY + 10) {
      pageY = baseY + 18;
    }

    chartTooltip.style.left = pageX + 'px';
    chartTooltip.style.top = pageY + 'px';
  }

  function hideTooltip() {
    chartTooltip.style.display = 'none';
  }

  document.addEventListener('click', function (event) {
    if (!event.target.closest('.quickroll-bar-interactive-group, .quickroll-request-point-dot')) {
      hideTooltip();
    }
  });

  function buildPayrollTooltipHtml(barGroup) {
    var period = barGroup.getAttribute('data-period') || 'Pay Period';
    var net = barGroup.getAttribute('data-net') || '₱ 0.00';
    var gross = barGroup.getAttribute('data-gross') || '₱ 0.00';
    var deductions = barGroup.getAttribute('data-deductions') || '₱ 0.00';
    var records = barGroup.getAttribute('data-records') || '0';

    return '<strong>' + period + ' (' + records + ' staff)</strong>' +
           '<div>Net Payout: ' + net + '</div>' +
           '<div style="color:#9ca3af;font-size:10px;">Gross: ' + gross + ' · Ded: ' + deductions + '</div>';
  }

  // Payroll Bar Chart Hover & Mobile Tap
  var payrollBarGroups = document.querySelectorAll('.quickroll-bar-interactive-group');
  payrollBarGroups.forEach(function (barGroup) {
    barGroup.addEventListener('mouseenter', function (event) {
      showTooltip(buildPayrollTooltipHtml(barGroup), event);
    });

    barGroup.addEventListener('mousemove', function (event) {
      positionTooltip(event, barGroup);
    });

    barGroup.addEventListener('mouseleave', function () {
      hideTooltip();
    });

    barGroup.addEventListener('click', function (event) {
      event.stopPropagation();
      chartTooltip.innerHTML = buildPayrollTooltipHtml(barGroup);
      chartTooltip.style.display = 'block';
      positionTooltip(event, barGroup);
    });
  });

  // Requests Line Chart Point Hover & Mobile Tap
  var requestPointDots = document.querySelectorAll('.quickroll-request-point-dot');
  requestPointDots.forEach(function (dot) {
    function buildRequestTooltipHtml() {
      var dateLabel = dot.getAttribute('data-date') || 'Date';
      var count = dot.getAttribute('data-count') || '0';
      return '<strong>' + dateLabel + '</strong>' +
             '<div>Requests / Exceptions: ' + count + '</div>';
    }

    dot.addEventListener('mouseenter', function (event) {
      showTooltip(buildRequestTooltipHtml(), event);
    });

    dot.addEventListener('mousemove', function (event) {
      positionTooltip(event, dot);
    });

    dot.addEventListener('mouseleave', function () {
      hideTooltip();
    });

    dot.addEventListener('click', function (event) {
      event.stopPropagation();
      chartTooltip.innerHTML = buildRequestTooltipHtml();
      chartTooltip.style.display = 'block';
      positionTooltip(event, dot);
    });
  });
});
