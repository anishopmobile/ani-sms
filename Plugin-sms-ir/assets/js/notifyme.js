(function($) {
  'use strict';
  
  $(document).ready(function() {
    // Create subscribe popup container if it doesn't exist
    if (!$('#smsir-notifyme-popup').length) {
      $('body').append(`
        <div id="smsir-notifyme-popup" class="smsir-notifyme-popup">
          <div class="smsir-notifyme-popup-container">
            <div class="smsir-notifyme-popup-header">
              <h3>${smsir_notifyme.popup_title || 'Inventory notification'}</h3>
              <button type="button" class="smsir-notifyme-popup-close">&times;</button>
            </div>
            <div class="smsir-notifyme-popup-content">
              <p>${smsir_notifyme.popup_text || 'Enter your mobile number so we can notify you as soon as the product is available.'}</p>
              <form class="smsir-notifyme-form">
                <input type="text" name="phone" class="smsir-notifyme-phone" placeholder="${smsir_notifyme.phone_placeholder || 'Enter your mobile number.'}" />
                <button type="submit" class="smsir-notifyme-submit">
                  <i class="icon-smsir-save"></i>
                  <span>${smsir_notifyme.subscribe_text || 'Registration'}</span>
                </button>
              </form>
              <div class="smsir-notifyme-message-area" style="margin-top: 15px;"></div>
            </div>
          </div>
        </div>
      `);
    }

    // Helper function to display messages in popup
    function showPopupMessage(message, isSuccess) {
      const $messageArea = $('#smsir-notifyme-popup .smsir-notifyme-message-area');
      $messageArea.text(message)
                  .removeClass('success error')
                  .addClass(isSuccess ? 'success' : 'error')
                  .show();
      setTimeout(() => {
        $messageArea.hide().text('');
      }, 3000);
    }

    // Variable products: handle variation changes
    if ($('.variations_form').length) {
      const variationContainer = $('#smsir-notifyme-variation-container');
      const $notifyButtonInVariations = variationContainer.find('.smsir-notifyme-button');
      
      $('.variations_form').on('show_variation', function(event, variation) {
        if (variation && !variation.is_in_stock) {
          variationContainer.slideDown();
          $notifyButtonInVariations.attr('data-variation-id', variation.variation_id);
          
          $.ajax({
            url: smsir_notifyme.ajax_url,
            type: 'POST',
            data: {
              action: 'smsir_check_specific_variation_subscription',
              nonce: smsir_notifyme.nonce,
              product_id: $notifyButtonInVariations.attr('data-product-id'),
              variation_id: variation.variation_id
            },
            beforeSend: function() {
              $notifyButtonInVariations.prop('disabled', true).text(smsir_notifyme.loading_text || 'please wait...');
            },
            success: function(response) {
              if (response.success && response.data.subscribed) {
                $notifyButtonInVariations
                  .text(smsir_notifyme.unsubscribe_button_text || 'Unsubscribe')
                  .addClass('smsir-notifyme-unsubscribe');
              } else {
                $notifyButtonInVariations
                  .text(smsir_notifyme.button_text || 'Let me know when its available.')
                  .removeClass('smsir-notifyme-unsubscribe');
              }
            },
            error: function() {
              $notifyButtonInVariations
                .text(smsir_notifyme.button_text || 'Let me know when its available.')
                .removeClass('smsir-notifyme-unsubscribe');
            },
            complete: function() {
              $notifyButtonInVariations.prop('disabled', false);
              if($notifyButtonInVariations.hasClass('smsir-notifyme-unsubscribe')) {
                $notifyButtonInVariations.text(smsir_notifyme.unsubscribe_button_text || 'Unsubscribe');
              } else {
                $notifyButtonInVariations.text(smsir_notifyme.button_text || 'Let me know when its available.');
              }
            }
          });
        } else {
          variationContainer.slideUp();
        }
      });

      $('.variations_form').on('hide_variation', function() {
        variationContainer.slideUp();
        $notifyButtonInVariations
          .text(smsir_notifyme.button_text || 'Let me know when its available.')
          .removeClass('smsir-notifyme-unsubscribe')
          .removeAttr('data-variation-id'); 
      });
    }
    
    $(document).on('click', '.smsir-notifyme-button', function(e) {
      e.preventDefault();
      const $button = $(this);
      const product_id = $button.attr('data-product-id');
      const variation_id = $button.attr('data-variation-id') || 0;

      if ($button.hasClass('smsir-notifyme-unsubscribe')) {
        // Direct Unsubscribe Action - No confirmation, straight to AJAX
        const originalButtonText = $button.text();
        $button.prop('disabled', true).text(smsir_notifyme.loading_text || 'please wait...');
        
        $.ajax({
          url: smsir_notifyme.ajax_url,
          type: 'POST',
          data: {
            action: 'smsir_notifyme_direct_unsubscribe',
            nonce: smsir_notifyme.nonce,
            product_id: product_id,
            variation_id: variation_id
          },
          success: function(response) {
            if (response.success) {
              $button.text(smsir_notifyme.button_text || 'Let me know when its available.')
                     .removeClass('smsir-notifyme-unsubscribe');
            } else {
              $button.text(response.data.message || 'Error unsubscribing');
              setTimeout(() => {
                $button.text(originalButtonText);
              }, 2000);
            }
          },
          error: function() {
            $button.text('Connection error');
            setTimeout(() => {
              $button.text(originalButtonText);
            }, 2000);
          },
          complete: function() {
            $button.prop('disabled', false);
            if ($button.hasClass('smsir-notifyme-unsubscribe')) {
                $button.text(smsir_notifyme.unsubscribe_button_text || 'Unsubscribe');
            } else {
                $button.text(smsir_notifyme.button_text || 'Let me know when its available.');
            }
          }
        });

      } else {
        // Show Subscribe Popup
        const targetPopup = '#smsir-notifyme-popup';
        $(targetPopup).attr({
          'data-product-id': product_id,
          'data-variation-id': variation_id
        });
        $(targetPopup).find('input[name="phone"]').val(''); 
        $(targetPopup).find('.smsir-notifyme-message-area').hide().text('');
        $(targetPopup).css('display', 'flex').hide().fadeIn(300);
      }
    });
    
    $('.smsir-notifyme-form').on('submit', function(e) {
      e.preventDefault();
      const $form = $(this);
      const $popup = $form.closest('#smsir-notifyme-popup');
      const $submitButton = $form.find('button[type="submit"]');
      const $buttonTextSpan = $submitButton.find('span');
      const originalButtonText = $buttonTextSpan.text();
      const $phone = $form.find('input[name="phone"]');
      
      const product_id = $popup.attr('data-product-id');
      const variation_id = $popup.attr('data-variation-id') || 0;
      
      $buttonTextSpan.text(smsir_notifyme.loading_text || 'please wait...');
      $submitButton.prop('disabled', true);
      $('#smsir-notifyme-popup .smsir-notifyme-message-area').hide().text('');
      
      $.ajax({
        url: smsir_notifyme.ajax_url,
        type: 'POST',
        data: {
          action: 'smsir_notifyme_subscribe',
          nonce: smsir_notifyme.nonce,
          phone: $phone.val(),
          product_id: product_id,
          variation_id: variation_id
        },
        success: function(response) {
          showPopupMessage(response.data.message, response.success);
          if (response.success) {
            setTimeout(() => { $popup.fadeOut(300); }, 3000);
            
            let $mainPageButton = $('.smsir-notifyme-button[data-product-id="' + product_id + '"]');
            if (variation_id && variation_id != 0) {
                $mainPageButton = $('#smsir-notifyme-variation-container .smsir-notifyme-button[data-product-id="' + product_id + '"]');
            }
            $mainPageButton.text(smsir_notifyme.unsubscribe_button_text || 'Unsubscribe')
                         .addClass('smsir-notifyme-unsubscribe');

          } else {
            // Error message shown by showPopupMessage
          }
        },
        error: function() {
          showPopupMessage('An error occurred. Please try again.', false);
        },
        complete: function() {
          $buttonTextSpan.text(originalButtonText);
          $submitButton.prop('disabled', false);
        }
      });
    });
    
    $('.smsir-notifyme-popup-close').on('click', function() {
      $(this).closest('.smsir-notifyme-popup').fadeOut(300);
    });
    
    $(document).on('click', '.smsir-notifyme-popup', function(e) {
      if ($(e.target).is('.smsir-notifyme-popup')) {
        $(this).fadeOut(300);
      }
    });
  });
})(jQuery); 