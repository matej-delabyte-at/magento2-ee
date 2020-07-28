/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/LICENSE
 */

define(
    [
        "jquery",
        "Wirecard_ElasticEngine/js/view/payment/method-renderer/default",
        "Wirecard_ElasticEngine/js/validator/min-age-validator",
        "mage/translate",
    ],
    function ($, Component, minAgeValidator, $t) {
        "use strict";
        return Component.extend({
            termsChecked: false,
            customerData: {},
            customerDob: null,
            defaults: {
                template: "Wirecard_ElasticEngine/payment/method-ratepay",
                redirectAfterPlaceOrder: false
            },
            onTermsCheckboxClick: function () {
                $("#wirecard_elasticengine_ratepayinvoice_submit").attr("disabled", !this.termsChecked);
                $("#wirecard_elasticengine_ratepayinvoice_submit").toggleClass("disabled", !this.termsChecked);
                return true;
            },
            initObservable: function () {
                this._super().observe("customerDob");
                return this;
            },
            initialize: function () {
                this._super();
                this.config = window.checkoutConfig.payment[this.getCode()];
                this.customerData = window.customerData;
                this.customerDob(this.customerData.dob);
                return this;
            },
            getData: function () {
                return {
                    "method": this.getCode(),
                    "po_number": null,
                    "additional_data": {
                        "customerDob": this.customerDob()
                    }
                };
            },
            getRatepayScript: function () {
                return this.config.ratepay_script;
            },
            validate: function () {
                var errorPane = $("#" + this.getCode() + "-dob-error");
                if (!minAgeValidator.validate(this.customerDob())) {
                    errorPane.html($t("text_min_age_notice"));
                    errorPane.css("display", "block");
                    return false;
                }
                if (this.config.billing_equals_shipping
                    && $("#billing-address-same-as-shipping-wirecard_elasticengine_ratepayinvoice").is(":checked") === false) {
                    errorPane.html($t("text_need_same_address_notice"));
                    errorPane.css("display", "block");
                    return false;
                }
                let form = $("#" + this.getCode() + "-form");
                return $(form).validation() && $(form).validation("isValid");
            }
        });
    }
);
