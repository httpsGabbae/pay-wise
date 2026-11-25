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

  function positionTooltip(event) {
    var tooltipWidth = chartTooltip.offsetWidth;
    var tooltipHeight = chartTooltip.offsetHeight;
    var pageX = event.pageX + 12;
    var pageY = event.pageY - tooltipHeight - 8;

    // Prevent overflow off the right edge of window
    if (pageX + tooltipWidth > window.innerWidth - 16) {
      pageX = event.pageX - tooltipWidth - 12;
    }
    if (pageY < 10) {
      pageY = event.pageY + 16;
    }

    chartTooltip.style.left = pageX + 'px';
    chartTooltip.style.top = pageY + 'px';
  }

  function hideTooltip() {
    chartTooltip.style.display = 'none';
  }

  // Payroll Bar Chart Hover
  var payrollBarGroups = document.querySelectorAll('.quickroll-bar-interactive-group');
  payrollBarGroups.forEach(function (barGroup) {
    barGroup.addEventListener('mouseenter', function (event) {
      var period = barGroup.getAttribute('data-period') || 'Pay Period';
      var net = barGroup.getAttribute('data-net') || '₱ 0.00';
      var gross = barGroup.getAttribute('data-gross') || '₱ 0.00';
      var deductions = barGroup.getAttribute('data-deductions') || '₱ 0.00';
      var records = barGroup.getAttribute('data-records') || '0';

      var tooltipHtml = '<strong>' + period + ' (' + records + ' staff)</strong>' +
                        '<div>Net Payout: ' + net + '</div>' +
                        '<div style="color:#9ca3af;font-size:10px;">Gross: ' + gross + ' · Ded: ' + deductions + '</div>';
      showTooltip(tooltipHtml, event);
    });

    barGroup.addEventListener('mousemove', function (event) {
      positionTooltip(event);
    });

    barGroup.addEventListener('mouseleave', function () {
      hideTooltip();
    });
  });

  // Requests Line Chart Point Hover
  var requestPointDots = document.querySelectorAll('.quickroll-request-point-dot');
  requestPointDots.forEach(function (dot) {
    dot.addEventListener('mouseenter', function (event) {
      var dateLabel = dot.getAttribute('data-date') || 'Date';
      var count = dot.getAttribute('data-count') || '0';

      var tooltipHtml = '<strong>' + dateLabel + '</strong>' +
                        '<div>Requests / Exceptions: ' + count + '</div>';
      showTooltip(tooltipHtml, event);
    });

    dot.addEventListener('mousemove', function (event) {
      positionTooltip(event);
    });

    dot.addEventListener('mouseleave', function () {
      hideTooltip();
    });
  });
});
