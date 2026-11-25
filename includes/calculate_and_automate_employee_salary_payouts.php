<?php

if (!function_exists('get_fixed_salary_types')) {
    function get_fixed_salary_types(): array
    {
        return [
            'Monthly' => 'Monthly (Paid every end of month)',
            'Every 15th Day' => 'Every 15th Day (Paid every 15th & end of month)',
            'Daily' => 'Daily (Paid every end of shift)',
            'Per Hour' => 'Per Hour (Paid every week)',
        ];
    }
}

if (!function_exists('normalize_salary_type')) {
    function normalize_salary_type(?string $raw_salary_type): string
    {
        $allowed_types = array_keys(get_fixed_salary_types());
        $cleaned_type = trim((string)$raw_salary_type);
        if (in_array($cleaned_type, $allowed_types, true)) {
            return $cleaned_type;
        }
        return 'Monthly';
    }
}

if (!function_exists('get_salary_payout_schedule_label')) {
    function get_salary_payout_schedule_label(?string $salary_type): string
    {
        $normalized_type = normalize_salary_type($salary_type);
        $labels = [
            'Monthly' => 'End of Month',
            'Every 15th Day' => 'Every 15th & Month-End',
            'Daily' => 'End of Shift',
            'Per Hour' => 'Weekly',
        ];
        return $labels[$normalized_type] ?? 'End of Month';
    }
}

if (!function_exists('get_default_payout_window_for_salary_type')) {
    function get_default_payout_window_for_salary_type(string $salary_type, ?string $reference_date = null): array
    {
        $normalized_type = normalize_salary_type($salary_type);
        $anchor_timestamp = $reference_date ? strtotime($reference_date) : time();
        $anchor_date = date('Y-m-d', $anchor_timestamp);
        $year_month = date('Y-m', $anchor_timestamp);
        $day_of_month = (int)date('j', $anchor_timestamp);
        $last_day_of_month = date('Y-m-t', $anchor_timestamp);

        if ($normalized_type === 'Monthly') {
            return [
                'period_start' => $year_month . '-01',
                'period_end' => $last_day_of_month,
                'payout_date' => $last_day_of_month,
                'schedule_label' => 'End of Month',
            ];
        }

        if ($normalized_type === 'Every 15th Day') {
            if ($day_of_month <= 15) {
                return [
                    'period_start' => $year_month . '-01',
                    'period_end' => $year_month . '-15',
                    'payout_date' => $year_month . '-15',
                    'schedule_label' => '15th of the Month',
                ];
            }
            return [
                'period_start' => $year_month . '-16',
                'period_end' => $last_day_of_month,
                'payout_date' => $last_day_of_month,
                'schedule_label' => 'End of Month (2nd 15-Day Cutoff)',
            ];
        }

        if ($normalized_type === 'Daily') {
            return [
                'period_start' => $anchor_date,
                'period_end' => $anchor_date,
                'payout_date' => $anchor_date,
                'schedule_label' => 'End of Shift (Daily)',
            ];
        }

        // Per Hour: Monday through Sunday weekly window (works across month boundaries)
        $iso_weekday = (int)date('N', $anchor_timestamp);
        $week_start_ts = strtotime('-' . ($iso_weekday - 1) . ' days', $anchor_timestamp);
        $week_end_ts = strtotime('+' . (7 - $iso_weekday) . ' days', $anchor_timestamp);

        return [
            'period_start' => date('Y-m-d', $week_start_ts),
            'period_end' => date('Y-m-d', $week_end_ts),
            'payout_date' => date('Y-m-d', $week_end_ts),
            'schedule_label' => 'Weekly (Mon – Sun)',
        ];
    }
}

if (!function_exists('calculate_attendance_hours_for_shift')) {
    function calculate_attendance_hours_for_shift(array $attendance_row): float
    {
        $status = (string)($attendance_row['status'] ?? '');
        if ($status === 'Absent' || $status === 'On Leave') {
            return 0.0;
        }

        $time_in = trim((string)($attendance_row['time_in'] ?? ''));
        $time_out = trim((string)($attendance_row['time_out'] ?? ''));
        $undertime_hours = (float)($attendance_row['undertime_hours'] ?? 0);

        if ($time_in !== '' && $time_out !== '') {
            $in_seconds = strtotime('1970-01-01 ' . $time_in . ' UTC');
            $out_seconds = strtotime('1970-01-01 ' . $time_out . ' UTC');
            if ($in_seconds !== false && $out_seconds !== false) {
                if ($out_seconds < $in_seconds) {
                    $out_seconds += 86400;
                }
                $raw_hours = ($out_seconds - $in_seconds) / 3600.0;
                if ($raw_hours > 5.0) {
                    $raw_hours -= 1.0;
                }
                return round(max(0.0, $raw_hours), 2);
            }
        }

        if ($status === 'Half Day') {
            return round(max(0.0, 4.0 - $undertime_hours), 2);
        }
        return round(max(0.0, 8.0 - $undertime_hours), 2);
    }
}

if (!function_exists('calculate_employee_salary_for_period')) {
    function calculate_employee_salary_for_period($database_connection, array $employee_record, string $period_start, string $period_end, ?int $period_id = null): array
    {
        $employee_id = (int)($employee_record['employee_id'] ?? 0);
        $salary_type = normalize_salary_type($employee_record['salary_type'] ?? 'Monthly');
        $stored_salary_rate = round(max(0.0, (float)($employee_record['basic_salary'] ?? 0)), 2);
        $allowances_amount = round(max(0.0, (float)($employee_record['allowances'] ?? 0)), 2);

        // Step 1: Load attendance rows within the period to compute hours, shifts, overtime, and attendance deductions
        $attendance_deductions_amount = 0.0;
        $overtime_pay_amount = 0.0;
        $worked_days_count = 0.0;
        $worked_hours_total = 0.0;

        if ($employee_id > 0 && $period_start !== '' && $period_end !== '') {
            $attendance_query = $database_connection->prepare(
                'SELECT status, time_in, time_out, undertime_hours, computed_deduction, computed_overtime
                 FROM attendance
                 WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?'
            );
            $attendance_query->bind_param('iss', $employee_id, $period_start, $period_end);
            $attendance_query->execute();
            $attendance_result = $attendance_query->get_result();

            while ($attendance_row = $attendance_result->fetch_assoc()) {
                $attendance_deductions_amount += (float)($attendance_row['computed_deduction'] ?? 0);
                $overtime_pay_amount += (float)($attendance_row['computed_overtime'] ?? 0);

                $row_status = (string)($attendance_row['status'] ?? '');
                if ($row_status === 'Present' || $row_status === 'Late') {
                    $worked_days_count += 1.0;
                } elseif ($row_status === 'Half Day') {
                    $worked_days_count += 0.5;
                }
                $worked_hours_total += calculate_attendance_hours_for_shift($attendance_row);
            }
        }

        $attendance_deductions_amount = round($attendance_deductions_amount, 2);
        $overtime_pay_amount = round($overtime_pay_amount, 2);
        $worked_hours_total = round($worked_hours_total, 2);

        // Step 2: Calculate cutoff basic salary strictly according to the employee's fixed Salary Type
        if ($salary_type === 'Monthly') {
            $computed_basic_salary = $stored_salary_rate;
            $calculation_summary = 'Monthly Rate (100% · Paid End of Month)';
        } elseif ($salary_type === 'Every 15th Day') {
            $computed_basic_salary = round($stored_salary_rate / 2, 2);
            $calculation_summary = 'Every 15th Day (50% of ₱ ' . number_format($stored_salary_rate, 2) . ')';
        } elseif ($salary_type === 'Daily') {
            $effective_days = $worked_days_count > 0
                ? $worked_days_count
                : (($period_start !== '' && $period_start === $period_end) ? 1.0 : 0.0);
            $computed_basic_salary = round($stored_salary_rate * $effective_days, 2);
            $calculation_summary = 'Daily Rate ₱ ' . number_format($stored_salary_rate, 2) . ' × ' . $effective_days . ' shift(s)';
        } else {
            // Per Hour (Weekly payout)
            $computed_basic_salary = round($stored_salary_rate * $worked_hours_total, 2);
            $calculation_summary = 'Hourly Rate ₱ ' . number_format($stored_salary_rate, 2) . ' × ' . $worked_hours_total . ' hr(s) (Weekly)';
        }

        // Step 3: Sum active loan installments due on or before period end
        $loan_deductions_amount = 0.0;
        if ($employee_id > 0) {
            if ($period_end !== '') {
                $loan_query = $database_connection->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN installment < remaining_balance THEN installment ELSE remaining_balance END), 0) AS loan_sum
                     FROM employee_loans
                     WHERE employee_id = ? AND status = 'Active' AND remaining_balance > 0
                       AND (repayment_start IS NULL OR repayment_start <= ?)"
                );
                $loan_query->bind_param('is', $employee_id, $period_end);
            } else {
                $loan_query = $database_connection->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN installment < remaining_balance THEN installment ELSE remaining_balance END), 0) AS loan_sum
                     FROM employee_loans
                     WHERE employee_id = ? AND status = 'Active' AND remaining_balance > 0"
                );
                $loan_query->bind_param('i', $employee_id);
            }
            $loan_query->execute();
            $loan_deductions_amount = round((float)$loan_query->get_result()->fetch_assoc()['loan_sum'], 2);
        }

        // Step 4: Sum itemized other income & other deductions for this period
        $other_income_amount = 0.0;
        $other_deductions_amount = 0.0;

        if ($employee_id > 0 && $period_id !== null && $period_id > 0 && $period_start !== '' && $period_end !== '') {
            $income_query = $database_connection->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total_amount FROM other_income WHERE employee_id = ? AND (period_id = ? OR (pay_date BETWEEN ? AND ?))'
            );
            $income_query->bind_param('iiss', $employee_id, $period_id, $period_start, $period_end);
            $income_query->execute();
            $other_income_amount = round((float)$income_query->get_result()->fetch_assoc()['total_amount'], 2);

            $deduction_query = $database_connection->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total_amount FROM other_deductions WHERE employee_id = ? AND (period_id = ? OR (pay_date BETWEEN ? AND ?))'
            );
            $deduction_query->bind_param('iiss', $employee_id, $period_id, $period_start, $period_end);
            $deduction_query->execute();
            $other_deductions_amount = round((float)$deduction_query->get_result()->fetch_assoc()['total_amount'], 2);
        } elseif ($employee_id > 0 && $period_start !== '' && $period_end !== '') {
            $income_query = $database_connection->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total_amount FROM other_income WHERE employee_id = ? AND pay_date BETWEEN ? AND ?'
            );
            $income_query->bind_param('iss', $employee_id, $period_start, $period_end);
            $income_query->execute();
            $other_income_amount = round((float)$income_query->get_result()->fetch_assoc()['total_amount'], 2);

            $deduction_query = $database_connection->prepare(
                'SELECT COALESCE(SUM(amount), 0) AS total_amount FROM other_deductions WHERE employee_id = ? AND pay_date BETWEEN ? AND ?'
            );
            $deduction_query->bind_param('iss', $employee_id, $period_start, $period_end);
            $deduction_query->execute();
            $other_deductions_amount = round((float)$deduction_query->get_result()->fetch_assoc()['total_amount'], 2);
        }

        // Step 5: Compute gross, total deductions, and net pay
        $total_deductions_amount = round($loan_deductions_amount + $attendance_deductions_amount + $other_deductions_amount, 2);
        $gross_pay_amount = round($computed_basic_salary + $allowances_amount + $overtime_pay_amount + $other_income_amount, 2);
        $net_pay_amount = round(max(0.0, $gross_pay_amount - $total_deductions_amount), 2);

        return [
            'salary_type' => $salary_type,
            'payout_schedule' => get_salary_payout_schedule_label($salary_type),
            'stored_rate' => $stored_salary_rate,
            'basic_salary' => $computed_basic_salary,
            'calculation_summary' => $calculation_summary,
            'worked_days' => $worked_days_count,
            'worked_hours' => $worked_hours_total,
            'allowances' => $allowances_amount,
            'overtime_pay' => $overtime_pay_amount,
            'other_income' => $other_income_amount,
            'loan_deductions' => $loan_deductions_amount,
            'attendance_deductions' => $attendance_deductions_amount,
            'other_deductions' => $other_deductions_amount,
            'total_deductions' => $total_deductions_amount,
            'gross_pay' => $gross_pay_amount,
            'net_pay' => $net_pay_amount,
        ];
    }
}

if (!function_exists('run_automatic_due_payroll_payouts')) {
    function run_automatic_due_payroll_payouts($database_connection, ?string $reference_date = null): array
    {
        $anchor_timestamp = $reference_date ? strtotime($reference_date) : time();
        $today_date = date('Y-m-d', $anchor_timestamp);
        $current_year_month = date('Y-m', $anchor_timestamp);
        $current_day = (int)date('j', $anchor_timestamp);
        $current_month_last_day = date('Y-m-t', $anchor_timestamp);

        $previous_month_first_ts = strtotime('first day of last month', $anchor_timestamp);
        $previous_year_month = date('Y-m', $previous_month_first_ts);
        $previous_month_last_day = date('Y-m-t', $previous_month_first_ts);

        $iso_weekday = (int)date('N', $anchor_timestamp);
        $this_week_monday = date('Y-m-d', strtotime('-' . ($iso_weekday - 1) . ' days', $anchor_timestamp));
        $this_week_sunday = date('Y-m-d', strtotime('+' . (7 - $iso_weekday) . ' days', $anchor_timestamp));
        $last_week_monday = date('Y-m-d', strtotime('-7 days', strtotime($this_week_monday)));
        $last_week_sunday = date('Y-m-d', strtotime('-7 days', strtotime($this_week_sunday)));

        $active_employees_query = $database_connection->query(
            "SELECT employee_id, employee_no, first_name, last_name, salary_type, basic_salary, date_hired
             FROM employees
             WHERE COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive', 'Archived')
               AND basic_salary > 0"
        );

        $generated_count = 0;
        $generated_items = [];

        if (!$active_employees_query) {
            return ['generated_count' => 0, 'generated_items' => []];
        }

        $duplicate_check_stmt = $database_connection->prepare(
            'SELECT payroll_id FROM payroll_records WHERE employee_id = ? AND period_start = ? AND period_end = ? LIMIT 1'
        );
        $insert_payroll_stmt = $database_connection->prepare(
            "INSERT INTO payroll_records (
                employee_id, period_id, period_start, period_end,
                basic_salary, allowances, other_earnings, overtime_pay, other_income_amount,
                deductions, loan_deductions, attendance_deductions, other_deductions_amount,
                gross_pay, net_pay, status, notes
             ) VALUES (?, NULL, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)"
        );

        while ($employee_record = $active_employees_query->fetch_assoc()) {
            $employee_id = (int)$employee_record['employee_id'];
            $salary_type = normalize_salary_type($employee_record['salary_type'] ?? 'Monthly');
            $date_hired = trim((string)($employee_record['date_hired'] ?? ''));

            $due_windows = [];

            if ($salary_type === 'Monthly') {
                // Due at every end of month (automatically handles 28, 29, 30, 31-day months)
                if ($today_date === $current_month_last_day) {
                    $due_windows[] = [
                        'start' => $current_year_month . '-01',
                        'end' => $current_month_last_day,
                        'note' => 'Auto-payout: Monthly (End of Month)',
                    ];
                }
                if ($date_hired === '' || $date_hired <= $previous_month_last_day) {
                    $due_windows[] = [
                        'start' => $previous_year_month . '-01',
                        'end' => $previous_month_last_day,
                        'note' => 'Auto-payout: Monthly (End of Month)',
                    ];
                }
            } elseif ($salary_type === 'Every 15th Day') {
                // Due on the 15th (1st–15th) and at end of month (16th–last day)
                if ($current_day >= 15) {
                    $due_windows[] = [
                        'start' => $current_year_month . '-01',
                        'end' => $current_year_month . '-15',
                        'note' => 'Auto-payout: Every 15th Day (1st Cutoff)',
                    ];
                }
                if ($today_date === $current_month_last_day) {
                    $due_windows[] = [
                        'start' => $current_year_month . '-16',
                        'end' => $current_month_last_day,
                        'note' => 'Auto-payout: Every 15th Day (Month-End Cutoff)',
                    ];
                }
                if ($date_hired === '' || $date_hired <= $previous_month_last_day) {
                    $due_windows[] = [
                        'start' => $previous_year_month . '-16',
                        'end' => $previous_month_last_day,
                        'note' => 'Auto-payout: Every 15th Day (Month-End Cutoff)',
                    ];
                }
            } elseif ($salary_type === 'Daily') {
                // Due every end of shift (completed attendance shift with time_out or Present/Late/Half Day)
                $lookback_date = date('Y-m-d', strtotime('-14 days', $anchor_timestamp));
                $shift_query = $database_connection->prepare(
                    "SELECT attendance_date
                     FROM attendance
                     WHERE employee_id = ?
                       AND attendance_date BETWEEN ? AND ?
                       AND status IN ('Present', 'Late', 'Half Day')
                       AND (time_out IS NOT NULL OR time_in IS NULL OR attendance_date < ?)
                     ORDER BY attendance_date ASC"
                );
                $shift_query->bind_param('isss', $employee_id, $lookback_date, $today_date, $today_date);
                $shift_query->execute();
                $shift_result = $shift_query->get_result();
                while ($shift_row = $shift_result->fetch_assoc()) {
                    $shift_date = $shift_row['attendance_date'];
                    $due_windows[] = [
                        'start' => $shift_date,
                        'end' => $shift_date,
                        'note' => 'Auto-payout: Daily (End of Shift ' . $shift_date . ')',
                    ];
                }
            } elseif ($salary_type === 'Per Hour') {
                // Due every week (Monday – Sunday) regardless of month
                if ($today_date === $this_week_sunday) {
                    $due_windows[] = [
                        'start' => $this_week_monday,
                        'end' => $this_week_sunday,
                        'note' => 'Auto-payout: Per Hour (Weekly)',
                    ];
                }
                if ($date_hired === '' || $date_hired <= $last_week_sunday) {
                    $due_windows[] = [
                        'start' => $last_week_monday,
                        'end' => $last_week_sunday,
                        'note' => 'Auto-payout: Per Hour (Weekly)',
                    ];
                }
            }

            foreach ($due_windows as $window) {
                $window_start = $window['start'];
                $window_end = $window['end'];

                if ($date_hired !== '' && $date_hired > $window_end) {
                    continue;
                }

                $duplicate_check_stmt->bind_param('iss', $employee_id, $window_start, $window_end);
                $duplicate_check_stmt->execute();
                if ($duplicate_check_stmt->get_result()->fetch_assoc()) {
                    continue;
                }

                $calculated = calculate_employee_salary_for_period($database_connection, $employee_record, $window_start, $window_end, null);

                // For Per Hour / Daily, only auto-generate a payout if actual pay > 0
                if ($calculated['gross_pay'] <= 0) {
                    continue;
                }

                $note_text = $window['note'] . ' · ' . $calculated['calculation_summary'];
                $insert_payroll_stmt->bind_param(
                    'issdddddddddds',
                    $employee_id,
                    $window_start,
                    $window_end,
                    $calculated['basic_salary'],
                    $calculated['allowances'],
                    $calculated['overtime_pay'],
                    $calculated['other_income'],
                    $calculated['total_deductions'],
                    $calculated['loan_deductions'],
                    $calculated['attendance_deductions'],
                    $calculated['other_deductions'],
                    $calculated['gross_pay'],
                    $calculated['net_pay'],
                    $note_text
                );

                if ($insert_payroll_stmt->execute()) {
                    $generated_count++;
                    $generated_items[] = [
                        'employee_name' => $employee_record['last_name'] . ', ' . $employee_record['first_name'],
                        'salary_type' => $salary_type,
                        'period' => $window_start . ' – ' . $window_end,
                        'net_pay' => $calculated['net_pay'],
                    ];
                }
            }
        }

        return [
            'generated_count' => $generated_count,
            'generated_items' => $generated_items,
        ];
    }
}
