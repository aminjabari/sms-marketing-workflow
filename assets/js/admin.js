// assets/js/admin.js

jQuery(document).ready(function($) {

    var templateCount = $('#sms-templates-container').children('.sms-template-item').length;

    /**
     * افزودن تمپلیت جدید
     */
    $('#add-template-btn').on('click', function() {
        var newTemplateHtml = '<div class="sms-template-item">' +
            '<h3>تمپلیت #' + (templateCount + 1) + '</h3>' +
            '<table class="form-table">' +
            '<tr>' +
            '<th scope="row"><label for="template_name_' + templateCount + '">نام تمپلیت</label></th>' +
            '<td><input name="templates[' + templateCount + '][name]" type="text" id="template_name_' + templateCount + '" class="regular-text" required /></td>' +
            '</tr>' +
            '<tr>' +
            '<th scope="row"><label for="template_text_' + templateCount + '">متن پیامک</label></th>' +
            '<td><textarea name="templates[' + templateCount + '][text]" id="template_text_' + templateCount + '" rows="4" cols="50" class="large-text" required></textarea></td>' +
            '</tr>' +
            '</table>' +
            '<button type="button" class="button button-secondary remove-template">حذف تمپلیت</button>' +
            '<hr>' +
            '</div>';

        $('#sms-templates-container').append(newTemplateHtml);
        templateCount++;
    });

    /**
     * حذف تمپلیت
     */
    $('#sms-templates-container').on('click', '.remove-template', function() {
        if (confirm('آیا مطمئن هستید که می‌خواهید این تمپلیت را حذف کنید؟')) {
            $(this).closest('.sms-template-item').remove();
            
            // به‌روزرسانی شماره‌های تمپلیت‌ها
            $('#sms-templates-container').children('.sms-template-item').each(function(index) {
                $(this).find('h3').text('تمپلیت #' + (index + 1));
                $(this).find('input, textarea').each(function() {
                    var oldName = $(this).attr('name');
                    if (oldName) {
                        var newName = oldName.replace(/templates\[\d+\]/g, 'templates[' + index + ']');
                        $(this).attr('name', newName);
                        $(this).attr('id', newName.replace(/[\[\]]/g, '_'));
                    }
                });
            });
            templateCount = $('#sms-templates-container').children('.sms-template-item').length;
        }
    });
});