jQuery(function($) {
    var acc = document.getElementsByClassName("accordion");
    var i;

    for (i = 0; i < acc.length; i++) {
      acc[i].addEventListener("click", function() {
        this.classList.toggle("active");
        var panel = this.nextElementSibling;
        if (panel.style.maxHeight){
          panel.style.maxHeight = null;
        } else {
          panel.style.maxHeight = panel.scrollHeight + "px";
        } 
      });
    }

    $(document).on('click', '#insert-utm-fields', function(event) {
      console.log('HandL UTM: Insert UTM Fields button clicked (modal JS)');
      event.preventDefault();
      event.stopPropagation();
      var $panel = $(this).closest('#handl-utm-fields-panel');
      var formId = $panel.data('form-id');
      var utmFields = [
          '[hidden utm_source_cf7 utm_source_cf7-' + formId + ' class:utm_source id:utm_source]',
          '[hidden utm_medium_cf7 utm_medium_cf7-' + formId + ' class:utm_medium id:utm_medium]',
          '[hidden utm_term_cf7 utm_term_cf7-' + formId + ' class:utm_term id:utm_term]',
          '[hidden utm_content_cf7 utm_content_cf7-' + formId + ' class:utm_content id:utm_content]',
          '[hidden utm_campaign_cf7 utm_campaign_cf7-' + formId + ' class:utm_campaign id:utm_campaign]',
          '[hidden gclid_cf7 gclid_cf7-' + formId + ' class:gclid id:gclid]'
      ];
      var $formTextarea = window.parent && window.parent.jQuery ? window.parent.jQuery('#wpcf7-form') : $('#wpcf7-form');
      if ($formTextarea.length) {
          var content = $formTextarea.val();
          var submitPos = content.indexOf('[submit');
          if (submitPos === -1) {
              submitPos = content.length;
          }
          utmFields.forEach(function(field) {
              var fieldName = field.match(/\[hidden ([^\s]+)/)[1];
              content = content.replace(new RegExp('\\[hidden ' + fieldName + '[^\\]]*\\]', 'g'), '');
          });
          var newContent = content.slice(0, submitPos) + '\n' + utmFields.join('\n') + '\n' + content.slice(submitPos);
          $formTextarea.val(newContent);
      } else {
          alert('Could not find the Contact Form 7 editor textarea.');
      }
      if (window.parent && typeof window.parent.tb_remove === 'function') {
          window.parent.tb_remove();
      }
      return false;
  });
});

