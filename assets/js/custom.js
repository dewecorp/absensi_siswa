/**
 *
 * You can write your JS code here, DO NOT touch the default style file
 * because it will make it harder for you to update.
 *
 */

"use strict";

// Fix modal backdrop issues
$(document).ready(function() {
  // Ensure proper cleanup of modal backdrop
  $('.modal').on('hidden.bs.modal', function () {
    // Remove any remaining backdrop if it wasn't properly cleaned up
    setTimeout(function() {
      if ($('.modal-backdrop').length > $('.modal.show').length) {
        $('.modal-backdrop').remove();
      }
      
      // Ensure body scroll is restored
      $('body').removeClass('modal-open');
      
      // Remove any fixed positioning issues
      $('body').css({
        'padding-right': '',
        'overflow': '',
        'position': '',
        'top': '',
        'width': '',
        'pointer-events': ''
      });
    }, 100); // Small delay to ensure proper cleanup
  });
  
  // Handle modal show event to ensure proper backdrop
  $('.modal').on('show.bs.modal', function() {
    // Make sure there's only one backdrop
    if ($('.modal-backdrop').length > 1) {
      $('.modal-backdrop:not(:first)').remove();
    }
    
    // Remove pointer-events from body to ensure it remains interactive
    $('body').css('pointer-events', '');
  });
  
  // Ensure proper z-index for modals
  $('.modal').on('shown.bs.modal', function() {
    // Bring the current modal to front
    var highest = 0;
    $('.modal:visible').each(function() {
      var zindex = parseInt($(this).css('z-index')) || 0;
      if (zindex > highest) {
        highest = zindex;
      }
    });
    
    // Set this modal to be higher than others
    $(this).css('z-index', highest + 10);
    
    // Ensure backdrop is behind the modal
    $('.modal-backdrop').css('z-index', highest + 5);
    
    // Remove pointer-events from body to ensure it remains interactive
    $('body').css('pointer-events', '');
  });
  
  // Handle multiple modals
  $(document).on('click', '.modal', function(e) {
    // Don't close modal if clicking inside modal content
    if (e.target !== this) return;
    
    // Close the topmost modal
    var modals = $('.modal.show');
    if (modals.length > 0) {
      $(modals[modals.length - 1]).modal('hide');
    }
  });
  
  // Ensure page remains interactive when modal is shown
  $(document).on('show.bs.modal', '.modal', function() {
    $('body').css('pointer-events', 'auto');
  });
  
  // Keep Bootstrap's body scroll lock and backdrop click behavior.
  // Long forms use .modal-dialog-scrollable on the dialog itself.
});
