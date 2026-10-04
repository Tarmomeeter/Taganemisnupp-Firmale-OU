(function () {
    'use strict';

    function init(root) {
        if (root.dataset.firmaleInitialized) { return; }
        root.dataset.firmaleInitialized = '1';
        var globalSettings = (typeof window.FirmaleReturnForm === 'object' && window.FirmaleReturnForm)
            ? window.FirmaleReturnForm
            : {};
        var globalI18n = globalSettings.i18n || {};
        var settings = {
            ajaxUrl: globalSettings.ajaxUrl || root.dataset.ajaxUrl || '/wp-admin/admin-ajax.php',
            nonce: globalSettings.nonce || root.dataset.nonce || '',
            i18n: {
                networkError: globalI18n.networkError || 'Ühendus ebaõnnestus. Palun proovi mõne hetke pärast uuesti.',
                serverError: globalI18n.serverError || 'Serveris tekkis tellimuse kontrollimisel viga. Palun teavita klienditeenindust.',
                selectProduct: globalI18n.selectProduct || 'Vali vähemalt üks tagastatav toode.',
                working: globalI18n.working || 'Kontrollin…',
                sending: globalI18n.sending || 'Saadan…',
                confirming: globalI18n.confirming || 'Kinnitan…',
                otpError: globalI18n.otpError || 'Sisesta e-postile saadetud 6-kohaline kood.'
            }
        };
        var verifyForm = root.querySelector('[data-verify-form]');
        var otpForm = root.querySelector('[data-otp-form]');
        var returnForm = root.querySelector('[data-return-form]');
        var verified = root.querySelector('[data-verified]');
        var message = root.querySelector('[data-message]');
        var products = root.querySelector('[data-products]');
        var estimate = root.querySelector('[data-estimate]');
        var verifyButton = root.querySelector('[data-verify-button]');
        var otpButton = root.querySelector('[data-otp-button]');
        var submitButton = root.querySelector('[data-submit-button]');

        function updateEstimate() {
            if (!estimate) {
                return;
            }
            var total = 0;
            products.querySelectorAll('input[type="checkbox"]:checked').forEach(function (checkbox) {
                var quantity = products.querySelector('[data-quantity-for="' + checkbox.dataset.itemId + '"]');
                total += Number(checkbox.dataset.unitAmount || 0) * Number(quantity ? quantity.value : 0);
            });
            try {
                estimate.textContent = new Intl.NumberFormat(document.documentElement.lang || 'et-EE', {
                    style: 'currency',
                    currency: root.dataset.currency || 'EUR'
                }).format(total);
            } catch (error) {
                estimate.textContent = total.toFixed(2) + ' ' + (root.dataset.currency || 'EUR');
            }
        }

        function showMessage(text, type) {
            message.textContent = text || '';
            message.className = 'firmale-return__message is-' + (type || 'info');
            message.hidden = !text;
        }

        function setButton(button, busy, busyText) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent.trim();
            }
            button.disabled = busy;
            button.classList.toggle('is-loading', busy);
            button.textContent = busy ? busyText : button.dataset.originalText;
        }

        function post(data) {
            var body;
            var headers = {};
            if (data instanceof FormData) {
                body = data;
                body.set('nonce', settings.nonce);
            } else {
                body = new URLSearchParams();
                Object.keys(data).forEach(function (key) {
                    body.append(key, data[key]);
                });
                body.append('nonce', settings.nonce);
                body = body.toString();
                headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            }

            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var timer = controller ? setTimeout(function () { controller.abort(); }, 30000) : null;
            return fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: body,
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                return response.text().then(function (body) {
                    try {
                        return JSON.parse(body);
                    } catch (error) {
                        throw new Error(response.status >= 500 ? settings.i18n.serverError : settings.i18n.networkError);
                    }
                });
            }).catch(function (error) {
                if (error.name === 'AbortError') {
                    throw new Error('Serveri vastust ei saabunud. Avaldus võis siiski salvestuda; sama vormi uuesti saatmine ei loo topeltavaldust.');
                }
                throw error;
            }).finally(function () { if (timer) { clearTimeout(timer); } });
        }

        function createProductRow(product) {
            var row = document.createElement('label');
            row.className = 'firmale-return__product';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = String(product.item_id);
            checkbox.dataset.itemId = String(product.item_id);
            checkbox.dataset.unitAmount = String(product.unit_amount || 0);

            var imageWrap = document.createElement('span');
            imageWrap.className = 'firmale-return__product-image';
            if (product.image) {
                var image = document.createElement('img');
                image.src = product.image;
                image.alt = '';
                image.loading = 'lazy';
                imageWrap.appendChild(image);
            } else {
                imageWrap.textContent = '□';
            }

            var info = document.createElement('span');
            info.className = 'firmale-return__product-info';
            var name = document.createElement('strong');
            name.textContent = product.name;
            var ordered = document.createElement('small');
            ordered.textContent = 'Tellitud kogus: ' + product.quantity;
            info.appendChild(name);
            info.appendChild(ordered);
            if (product.policy_notice) {
                var policy = document.createElement('small');
                policy.className = 'firmale-return__policy-notice';
                policy.textContent = product.policy_notice;
                info.appendChild(policy);
            }

            var quantityWrap = document.createElement('span');
            quantityWrap.className = 'firmale-return__quantity';
            var quantityLabel = document.createElement('small');
            quantityLabel.textContent = 'Tagastan';
            var quantity = document.createElement('select');
            quantity.dataset.quantityFor = String(product.item_id);
            quantity.disabled = true;
            for (var i = 1; i <= product.quantity; i += 1) {
                var option = document.createElement('option');
                option.value = String(i);
                option.textContent = String(i);
                quantity.appendChild(option);
            }
            quantityWrap.appendChild(quantityLabel);
            quantityWrap.appendChild(quantity);

            var price = document.createElement('span');
            price.className = 'firmale-return__product-price';
            price.textContent = product.price;

            checkbox.addEventListener('change', function () {
                quantity.disabled = !checkbox.checked;
                row.classList.toggle('is-selected', checkbox.checked);
                updateEstimate();
            });
            quantity.addEventListener('change', updateEstimate);

            row.appendChild(checkbox);
            row.appendChild(imageWrap);
            row.appendChild(info);
            row.appendChild(quantityWrap);
            row.appendChild(price);
            return row;
        }

        function renderProducts(list) {
            products.replaceChildren();
            list.forEach(function (product) {
                products.appendChild(createProductRow(product));
            });
            updateEstimate();
        }

        function activateStep(number) {
            root.querySelectorAll('[data-step]').forEach(function (step) {
                var stepNumber = Number(step.dataset.step);
                step.classList.toggle('is-active', stepNumber === number);
                step.classList.toggle('is-done', stepNumber < number);
            });
        }

        function showVerifiedOrder(data) {
            returnForm.hidden = false;
            returnForm.reset();
            root.dataset.currency = data.currency || 'EUR';
            root.querySelector('[data-token]').value = data.token;
            root.querySelector('[data-right-status]').textContent = data.statusText;
            root.querySelector('[data-received-date]').textContent = data.receivedDate;
            root.querySelector('[data-deadline]').textContent = data.deadline;
            root.querySelector('[data-status-card]').classList.toggle('is-warning', data.manualReview);
            renderProducts(data.products);
            if (otpForm) {
                otpForm.hidden = true;
            }
            verified.hidden = false;
            activateStep(2);
            verified.scrollIntoView({behavior: 'smooth', block: 'start'});
        }

        function resetTurnstile() {
            if (typeof window.turnstile === 'object' && root.querySelector('.cf-turnstile')) {
                try {
                    window.turnstile.reset(root.querySelector('.cf-turnstile'));
                } catch (error) {
                    // Vidin võib olla alles laadimisel.
                }
            }
        }

        verifyForm.addEventListener('submit', function (event) {
            event.preventDefault();
            showMessage('', 'info');

            if (!verifyForm.reportValidity()) {
                return;
            }

            setButton(verifyButton, true, settings.i18n.working);
            verified.hidden = true;
            if (otpForm) { otpForm.hidden = true; otpForm.reset(); }

            var verifyData = new FormData(verifyForm);
            verifyData.set('action', 'firmale_verify_order');
            post(verifyData).then(function (response) {
                if (!response.success) {
                    throw new Error(response.data && response.data.message ? response.data.message : settings.i18n.networkError);
                }

                var data = response.data;
                if (data.requiresOtp && otpForm) {
                    otpForm.querySelector('[data-otp-token]').value = data.otpToken;
                    otpForm.hidden = false;
                    showMessage(data.message, 'info');
                    otpForm.elements.otp_code.focus();
                    return;
                }
                showVerifiedOrder(data);
            }).catch(function (error) {
                showMessage(error.message, 'error');
                activateStep(1);
                resetTurnstile();
            }).finally(function () {
                setButton(verifyButton, false, '');
                resetTurnstile();
            });
        });

        if (otpForm) {
            otpForm.addEventListener('submit', function (event) {
                event.preventDefault();
                showMessage('', 'info');
                if (!otpForm.reportValidity()) {
                    return;
                }
                setButton(otpButton, true, settings.i18n.confirming);
                post({
                    action: 'firmale_confirm_return_otp',
                    otp_token: otpForm.elements.otp_token.value,
                    otp_code: otpForm.elements.otp_code.value
                }).then(function (response) {
                    if (!response.success) {
                        throw new Error(response.data && response.data.message ? response.data.message : settings.i18n.otpError);
                    }
                    showMessage('', 'info');
                    showVerifiedOrder(response.data);
                }).catch(function (error) {
                    showMessage(error.message, 'error');
                }).finally(function () {
                    setButton(otpButton, false, '');
                });
            });
        }

        returnForm.querySelectorAll('input[name="request_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var options = returnForm.querySelector('[data-defect-options]');
                var withdrawalOptions = returnForm.querySelector('[data-withdrawal-options]');
                if (options) {
                    options.hidden = radio.value !== 'defect' || !radio.checked;
                }
                if (withdrawalOptions && radio.checked) {
                    withdrawalOptions.hidden = radio.value === 'defect';
                }
            });
        });

        returnForm.addEventListener('submit', function (event) {
            event.preventDefault();
            showMessage('', 'info');

            if (!returnForm.reportValidity()) {
                return;
            }

            var selected = [];
            products.querySelectorAll('input[type="checkbox"]:checked').forEach(function (checkbox) {
                var itemId = checkbox.dataset.itemId;
                var quantity = products.querySelector('[data-quantity-for="' + itemId + '"]');
                selected.push({item_id: Number(itemId), quantity: Number(quantity.value)});
            });

            if (!selected.length) {
                showMessage(settings.i18n.selectProduct, 'error');
                return;
            }

            setButton(submitButton, true, settings.i18n.sending);

            var submitData = new FormData(returnForm);
            submitData.set('action', 'firmale_submit_return');
            submitData.set('items', JSON.stringify(selected));
            submitData.set('confirmation', returnForm.elements.confirmation.checked ? '1' : '');
            post(submitData).then(function (response) {
                if (!response.success) {
                    throw new Error(response.data && response.data.message ? response.data.message : settings.i18n.networkError);
                }

                activateStep(3);
                returnForm.hidden = true;
                showMessage(response.data.message, 'success');
                message.scrollIntoView({behavior: 'smooth', block: 'center'});
            }).catch(function (error) {
                showMessage(error.message, 'error');
            }).finally(function () {
                setButton(submitButton, false, '');
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-firmale-return]').forEach(init);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
}());
