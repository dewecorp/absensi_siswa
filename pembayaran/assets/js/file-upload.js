(function($) {
  'use strict';
  $(function() {
    $('.file-upload-browse').on('click', function() {
      var file = $(this).closest('.app-field, .rounded-lg, form').find('.file-upload-default').first();
      file.trigger('click');
    });
    $('.file-upload-default').on('change', function() {
      $(this).closest('.app-field, .rounded-lg, form').find('.app-control').first().val($(this).val().replace(/C:\\fakepath\\/i, ''));
    });
  });
})(jQuery); 
