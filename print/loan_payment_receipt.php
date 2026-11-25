<?php
require_once __DIR__ . '/../config/database.php';

$isAdmin = !empty($_SESSION['admin_id']);
$sessionEmployeeId = (int)($_SESSION['employee_id'] ?? 0);

if ($isAdmin) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role('superadmin', 'finance');
} elseif ($sessionEmployeeId <= 0) {
    header('Location: ../employee/login.php');
    exit;
}

$repaymentId = (int)($_GET['id'] ?? 0);
if ($repaymentId <= 0) {
    exit('Receipt record not found.');
}

// 1. Fetch Repayment and Linked Loan Details
$receiptQuerySql = "
    SELECT 
        lr.repayment_id,
        lr.loan_id,
        lr.payroll_id,
        lr.employee_id,
        lr.amount,
        lr.repayment_date,
        lr.notes,
        lr.created_at AS recorded_timestamp,
        el.principal AS original_principal,
        el.remaining_balance AS current_balance,
        el.installment AS installment_amount,
        el.status AS loan_status,
        el.loan_date,
        lt.loan_type_name,
        e.employee_no,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.department,
        e.position,
        pp.period_code,
        p.period_start,
        p.period_end
    FROM loan_repayments lr
    JOIN employee_loans el ON el.loan_id = lr.loan_id
    JOIN loan_types lt ON lt.loan_type_id = el.loan_type_id
    JOIN employees e ON e.employee_id = lr.employee_id
    LEFT JOIN payroll_records p ON p.payroll_id = lr.payroll_id
    LEFT JOIN payroll_periods pp ON pp.period_id = p.period_id
    WHERE lr.repayment_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($receiptQuerySql);
$stmt->bind_param('i', $repaymentId);
$stmt->execute();
$receiptData = $stmt->get_result()->fetch_assoc();

if (!$receiptData) {
    exit('Payment receipt record was not found.');
}

// Employee access check: employee can only view their own receipt
if (!$isAdmin && (int)$receiptData['employee_id'] !== $sessionEmployeeId) {
    http_response_code(403);
    exit('Access denied to this payment receipt.');
}

$employeeFullName = trim($receiptData['first_name'] . ' ' . ($receiptData['middle_name'] ?? '') . ' ' . $receiptData['last_name']);
$officialReceiptNumber = 'OR-LR-' . str_pad($receiptData['repayment_id'], 6, '0', STR_PAD_LEFT);
$formattedPaymentDate = date('F d, Y', strtotime($receiptData['repayment_date']));
$paymentMethodDescription = !empty($receiptData['period_code']) 
    ? 'Automated Payroll Deduction (Period: ' . $receiptData['period_code'] . ')' 
    : 'Direct Cashier / Over-The-Counter Payment';

$paymentAmount = (float)$receiptData['amount'];
$remainingBalance = (float)$receiptData['current_balance'];
$originalPrincipal = (float)$receiptData['original_principal'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
  <title>Payment Receipt · <?php echo e($officialReceiptNumber); ?></title>
  <style>
    * {
      box-sizing: border-box;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    body {
      margin: 0;
      padding: 24px;
      background: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
      color: #0f172a;
    }
    .print-toolbar {
      max-width: 680px;
      margin: 0 auto 16px auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      font-size: 13px;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid #cbd5e1;
      background: #ffffff;
      color: #1e293b;
    }
    .btn-primary {
      background: #0f766e;
      color: #ffffff;
      border-color: #0f766e;
    }
    .btn-primary:hover {
      background: #0d9488;
    }
    .receipt-container {
      max-width: 680px;
      margin: 0 auto;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 36px 40px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    .receipt-header {
      display: flex;
      align-items: center;
      gap: 18px;
      border-bottom: 2px solid #0f766e;
      padding-bottom: 18px;
      margin-bottom: 22px;
    }
    .receipt-logo {
      width: 58px;
      height: 58px;
      object-fit: contain;
    }
    .receipt-school-title {
      font-size: 18px;
      font-weight: 800;
      color: #0f766e;
      margin: 0;
      letter-spacing: -0.2px;
    }
    .receipt-school-sub {
      font-size: 12px;
      color: #64748b;
      margin: 3px 0 0 0;
    }
    .receipt-title-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      background: #f8fafc;
      padding: 10px 14px;
      border-radius: 6px;
      border: 1px solid #e2e8f0;
    }
    .receipt-headline {
      font-size: 14px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0f172a;
      margin: 0;
    }
    .receipt-number {
      font-family: monospace;
      font-size: 13px;
      font-weight: 700;
      color: #0f766e;
    }
    .receipt-info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      font-size: 13px;
      margin-bottom: 24px;
    }
    .info-group label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      color: #64748b;
      margin-bottom: 2px;
    }
    .info-group span {
      font-weight: 600;
      color: #0f172a;
    }
    .payment-highlight-box {
      background: #f0fdfa;
      border: 1.5px solid #99f6e4;
      border-radius: 8px;
      padding: 18px 20px;
      margin-bottom: 24px;
      text-align: center;
    }
    .highlight-amount-label {
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0f766e;
      margin-bottom: 4px;
    }
    .highlight-amount-value {
      font-size: 32px;
      font-weight: 800;
      color: #0f766e;
      letter-spacing: -0.5px;
    }
    .receipt-table-wrapper {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin-bottom: 28px;
    }
    .receipt-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    .receipt-table th {
      background: #f8fafc;
      padding: 9px 12px;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border: 1px solid #e2e8f0;
      font-size: 12px;
    }
    .receipt-table td {
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      color: #1e293b;
    }
    .receipt-footer-signatures {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      margin-top: 36px;
      padding-top: 18px;
      font-size: 12px;
    }
    .signature-line {
      border-top: 1px solid #94a3b8;
      margin-top: 40px;
      padding-top: 6px;
      text-align: center;
      color: #475569;
    }
    .official-stamp-notice {
      text-align: center;
      font-size: 11px;
      color: #94a3b8;
      margin-top: 24px;
    }
    @media (max-width: 640px) {
      body {
        padding: 12px;
      }
      .receipt-container {
        padding: 20px 16px;
        border-radius: 8px;
      }
      .receipt-header {
        flex-direction: column;
        text-align: center;
        gap: 12px;
      }
      .receipt-info-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      .receipt-footer-signatures {
        grid-template-columns: 1fr;
        gap: 24px;
        margin-top: 24px;
      }
      .payment-highlight-box {
        padding: 14px 16px;
      }
      .highlight-amount-value {
        font-size: 26px;
      }
    }
    @media (max-width: 480px) {
      body {
        padding: 8px;
      }
      .print-toolbar {
        flex-direction: column;
        gap: 8px;
      }
      .print-toolbar .btn {
        width: 100%;
        justify-content: center;
      }
      .receipt-title-bar {
        flex-direction: column;
        align-items: center;
        gap: 6px;
        text-align: center;
      }
      .receipt-headline {
        font-size: 13px;
      }
      .receipt-table {
        min-width: 520px;
      }
    }
    @media print {
      body {
        background: #ffffff;
        padding: 0;
      }
      .print-toolbar {
        display: none !important;
      }
      .receipt-container {
        border: none;
        box-shadow: none;
        padding: 10px 0;
        max-width: 100%;
      }
      .receipt-table-wrapper {
        overflow: visible !important;
      }
      .receipt-table {
        min-width: 0 !important;
        width: 100% !important;
      }
    }
  </style>
</head>
<body>

<div class="print-toolbar">
  <button type="button" class="btn btn-primary" onclick="window.print()">
    <span>🖨️</span> Print Official Receipt
  </button>
  <button type="button" class="btn" onclick="window.close()">
    Close Window
  </button>
</div>

<article class="receipt-container">
  
  <!-- Institutional Header -->
  <header class="receipt-header">
    <img src="../uploads/logo.png" alt="Lipa City Colleges Logo" class="receipt-logo">
    <div>
      <h1 class="receipt-school-title">LIPA CITY COLLEGES</h1>
      <p class="receipt-school-sub">Office of the Treasurer & Finance · Official Payment Acknowledgment</p>
      <p class="receipt-school-sub">G.A. Solis Street, Lipa City, Batangas, Philippines</p>
    </div>
  </header>

  <!-- Receipt Number & Date Banner -->
  <div class="receipt-title-bar">
    <div class="receipt-headline">Official Loan Payment Receipt</div>
    <div class="receipt-number"><?php echo e($officialReceiptNumber); ?></div>
  </div>

  <!-- Employee and Payment Method Information -->
  <section class="receipt-info-grid">
    <div class="info-group">
      <label>Employee Name</label>
      <span><?php echo e($employeeFullName); ?></span>
    </div>
    <div class="info-group">
      <label>Employee Number</label>
      <span><?php echo e($receiptData['employee_no']); ?></span>
    </div>
    <div class="info-group">
      <label>Department</label>
      <span><?php echo e($receiptData['department'] ?: 'Not assigned'); ?></span>
    </div>
    <div class="info-group">
      <label>Position</label>
      <span><?php echo e($receiptData['position'] ?: 'Not assigned'); ?></span>
    </div>
    <div class="info-group">
      <label>Date of Payment</label>
      <span><?php echo e($formattedPaymentDate); ?></span>
    </div>
    <div class="info-group">
      <label>Payment Channel</label>
      <span><?php echo e($paymentMethodDescription); ?></span>
    </div>
  </section>

  <!-- Payment Highlight Box -->
  <div class="payment-highlight-box">
    <div class="highlight-amount-label">Total Amount Paid</div>
    <div class="highlight-amount-value"><?php echo money($paymentAmount); ?></div>
  </div>

  <!-- Loan Account Breakdown Table -->
  <div class="receipt-table-wrapper">
    <table class="receipt-table">
      <thead>
        <tr>
          <th>Loan Description</th>
          <th style="text-align:right;">Original Principal</th>
          <th style="text-align:right;">Amount Credited</th>
          <th style="text-align:right;">Remaining Balance</th>
          <th>Loan Status</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <strong><?php echo e($receiptData['loan_type_name']); ?> (Loan #<?php echo (int)$receiptData['loan_id']; ?>)</strong>
            <?php if (!empty($receiptData['notes'])): ?>
              <div style="font-size:11px; color:#64748b; margin-top:2px;">
                Reference / Note: <?php echo e($receiptData['notes']); ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;"><?php echo money($originalPrincipal); ?></td>
          <td style="text-align:right; font-weight:700; color:#0f766e;"><?php echo money($paymentAmount); ?></td>
          <td style="text-align:right; font-weight:700; color:<?php echo $remainingBalance > 0 ? '#b91c1c' : '#0f766e'; ?>;">
            <?php echo money($remainingBalance); ?>
          </td>
          <td>
            <span style="font-weight:600; color:<?php echo $receiptData['loan_status'] === 'Paid' ? '#16a34a' : '#0284c7'; ?>;">
              <?php echo e($receiptData['loan_status'] === 'Paid' ? 'Fully Settled' : 'Active'); ?>
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Signatures Section -->
  <footer class="receipt-footer-signatures">
    <div>
      <div class="signature-line">
        <strong>Authorized Finance Officer</strong><br>
        Office of the Treasurer & Finance
      </div>
    </div>
    <div>
      <div class="signature-line">
        <strong><?php echo e($employeeFullName); ?></strong><br>
        Employee Signature / Payer
      </div>
    </div>
  </footer>

  <div class="official-stamp-notice">
    This document serves as an official proof of payment recorded in the Lipa City Colleges HR & Payroll System.<br>
    Printed on <?php echo date('M d, Y h:i A'); ?>
  </div>

</article>

</body>
</html>
