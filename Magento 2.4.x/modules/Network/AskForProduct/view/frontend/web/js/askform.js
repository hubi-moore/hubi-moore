require(['jquery', 'Magento_Ui/js/modal/modal', 'Magento_Ui/js/modal/alert', 'mage/translate', 'mage/validation'], function ($, modal,alert, $t) {
    $(document).ready(function () {
        var options = {
            type: 'popup',
            responsive: true,
            innerScroll: true,
            title: $t('Ask about this product'),
            modalClass: 'ask4product-modal',
            buttons: [{
                text: $t('Submit'),
                class: 'action primary',
                click: function () {
                    var form = $('#ask-for-product-form');

                    if (form.validation() && form.validation('isValid')) {
                        var formData = form.serialize();

                        $.ajax({
                            url: form.attr('action'),
                            type: 'POST',
                            data: formData,
                            success: function (response) {
                                console.log(response);
                                if (response.success) {
                                    alert({
                                        title: $t('Success'),
                                        content: response.message,
                                        actions: {
                                            always: function () {
                                                if(response.status !== 3) {
                                                    $('#ask-for-product-modal').modal('closeModal');
                                                }
                                            }
                                        }
                                    });
                                } else {
                                    alert({
                                        title: $t('Error'),
                                        content: response.message,
                                        actions: {
                                            always: function () {
                                                if(response.status !== 3) {
                                                    $('#ask-for-product-modal').modal('closeModal');
                                                }
                                            }
                                        }
                                    });
                                }
                            },
                            error: function (jqXHR, textStatus, errorThrown) {
                                console.error("AJAX error: ", textStatus, errorThrown);
                                console.error(jqXHR.responseText);
                                alert({
                                    title: $t('Error'),
                                    content: $t('Something went wrong. Please try again later.'),
                                    actions: {
                                        always: function () {
                                            if(response.status !== 3) {
                                                $('#ask-for-product-modal').modal('closeModal');
                                            }
                                        }
                                    }
                                });
                            }
                        });
                    }
                }
            }]
        };

        var popup = modal(options, $('#ask-for-product-modal'));

        $('#ask-for-product-button').on('click', function () {
            var elements = $('#ask-for-product-modal').find('input, textarea, button');
            elements.each(function(index,elem) {
                $(elem).removeAttr('disabled');
            });
            $('#ask-for-product-modal').modal('openModal');
        });
    });
});
