document.addEventListener('DOMContentLoaded', function () {
    const trend_chart_card = document.querySelector('.report-trend-card');
    if (!trend_chart_card) {
        return;
    }

    const floating_tooltip = trend_chart_card.querySelector('.payroll-chart-floating-tooltip');
    const tooltip_title_element = trend_chart_card.querySelector('.tooltip-month-title');
    const tooltip_gross_value_element = trend_chart_card.querySelector('.tooltip-gross-value');
    const tooltip_net_value_element = trend_chart_card.querySelector('.tooltip-net-value');

    const month_bar_groups_container = trend_chart_card.querySelector('.chart-months-bar-groups');
    const month_bar_group_elements = trend_chart_card.querySelectorAll('.month-bar-group');

    const user_prefers_reduced_motion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function start_chart_entrance_animation() {
        trend_chart_card.classList.remove('is-animated');

        let current_month_index = 0;
        month_bar_group_elements.forEach(function (month_group) {
            const stagger_delay_milliseconds = user_prefers_reduced_motion ? 0 : current_month_index * 55;
            const single_bars_in_group = month_group.querySelectorAll('.single-payroll-bar');

            single_bars_in_group.forEach(function (payroll_bar) {
                payroll_bar.style.transitionDelay = stagger_delay_milliseconds + 'ms';
            });

            current_month_index = current_month_index + 1;
        });

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                trend_chart_card.classList.add('is-animated');
            });
        });
    }

    start_chart_entrance_animation();

    window.addEventListener('pageshow', function (page_transition_event) {
        if (page_transition_event.persisted) {
            start_chart_entrance_animation();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            start_chart_entrance_animation();
        }
    });

    function show_tooltip_for_month_group(month_group) {
        const full_month_name = month_group.getAttribute('data-month-name');
        const formatted_gross_amount = month_group.getAttribute('data-gross-formatted');
        const formatted_net_amount = month_group.getAttribute('data-net-formatted');

        tooltip_title_element.textContent = full_month_name;
        tooltip_gross_value_element.textContent = formatted_gross_amount;
        tooltip_net_value_element.textContent = formatted_net_amount;

        const chart_card_bounding_rectangle = trend_chart_card.getBoundingClientRect();
        const month_group_bounding_rectangle = month_group.getBoundingClientRect();

        const month_group_center_x = (month_group_bounding_rectangle.left + month_group_bounding_rectangle.width / 2) - chart_card_bounding_rectangle.left;
        const highest_bar_top_y = month_group_bounding_rectangle.top - chart_card_bounding_rectangle.top;

        const tooltip_minimum_left_padding = 95;
        const tooltip_maximum_right_padding = chart_card_bounding_rectangle.width - 95;

        let safe_tooltip_horizontal_position = month_group_center_x;
        if (safe_tooltip_horizontal_position < tooltip_minimum_left_padding) {
            safe_tooltip_horizontal_position = tooltip_minimum_left_padding;
        }
        if (safe_tooltip_horizontal_position > tooltip_maximum_right_padding) {
            safe_tooltip_horizontal_position = tooltip_maximum_right_padding;
        }

        floating_tooltip.style.left = safe_tooltip_horizontal_position + 'px';
        floating_tooltip.style.top = highest_bar_top_y + 'px';

        floating_tooltip.classList.add('is-visible');
        month_bar_groups_container.classList.add('has-hovered-item');
        month_group.classList.add('is-hovered');
    }

    function hide_tooltip() {
        floating_tooltip.classList.remove('is-visible');
        month_bar_groups_container.classList.remove('has-hovered-item');
        month_bar_group_elements.forEach(function (month_group) {
            month_group.classList.remove('is-hovered');
        });
    }

    month_bar_group_elements.forEach(function (month_group) {
        month_group.addEventListener('mouseenter', function () {
            show_tooltip_for_month_group(month_group);
        });

        month_group.addEventListener('mouseleave', function () {
            hide_tooltip();
        });

        month_group.addEventListener('focus', function () {
            show_tooltip_for_month_group(month_group);
        });

        month_group.addEventListener('blur', function () {
            hide_tooltip();
        });
    });

    trend_chart_card.addEventListener('mouseleave', function () {
        hide_tooltip();
    });
});
