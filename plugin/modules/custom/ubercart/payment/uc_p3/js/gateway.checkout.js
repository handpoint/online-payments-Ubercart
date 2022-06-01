(function ($, Drupal, drupalSettings) {
  'use strict';

  console.log(drupalSettings);
  if (drupalSettings.uc_p3) {
    const formId = "#"+drupalSettings.uc_p3.form_id;
    console.log($(formId));

    if ($(formId).length > 0 && $('#paymentgatewayframe').length === 0) {
      $(formId).attr('target', "paymentgatewayframe");
      $(`<iframe id="paymentgatewayframe" name="paymentgatewayframe" frameBorder="0" seamless='seamless' style="width:699px; height:1100px;margin: 0 auto; display:none;"></iframe>`).insertAfter( formId );
    }

    let submit = $(formId).find(':submit');
    submit.on('click', () => {
      $('#paymentgatewayframe').show();
      submit.hide();
    });
  }

}(jQuery, Drupal, drupalSettings));
