// assets/js/workflow-ui.js

jQuery(document).ready(function($) {
    var stepIndex = $('#workflow-steps-container tbody tr').length;
    var templateOptions = smsTemplateOptions; // متغیر سراسری از PHP

    // تابع ساخت سطر جدید
    function createNewStepRow(index) {
        var row = '<tr class="workflow-step-row" data-index="' + index + '">' +
            '<td>' + (index + 1) + '</td>' +
            '<td>' +
            '<select name="steps[' + index + '][template_name]" required>' +
            '<option value="">انتخاب کنید</option>' +
            templateOptions + // استفاده از گزینه های تمپلیت
            '</select>' +
            '</td>' +
            '<td><input type="text" name="steps[' + index + '][send_time]" placeholder="10:00 یا now" required></td>' +
            '<td><input type="number" name="steps[' + index + '][days_after]" value="0" min="0" required></td>' +
            '<td><button type="button" class="button button-small remove-step">حذف</button></td>' +
            '</tr>';
        return row;
    }

    // افزودن مرحله
    $('#add-workflow-step').on('click', function() {
        $('#workflow-steps-container tbody').append(createNewStepRow(stepIndex));
        stepIndex++;
    });

    // حذف مرحله
    $('#workflow-steps-container').on('click', '.remove-step', function() {
        if ($('#workflow-steps-container tbody tr').length > 1) {
            $(this).closest('tr').remove();

            // 💡 به‌روزرسانی ایندکس‌ها بعد از حذف
            $('#workflow-steps-container tbody tr').each(function(i) {
                $(this).attr('data-index', i);
                $(this).find('td:first').text(i + 1);
                $(this).find('input, select').each(function() {
                    var currentName = $(this).attr('name');
                    if (currentName) {
                        var newName = currentName.replace(/steps\[\d+\]/g, 'steps[' + i + ']');
                        $(this).attr('name', newName);
                    }
                });
            });
            stepIndex = $('#workflow-steps-container tbody tr').length;
        } else {
            alert('حداقل یک مرحله برای WorkFlow مورد نیاز است.');
        }
    });
});