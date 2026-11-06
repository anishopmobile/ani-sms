jQuery(function($) {
    const phoneInput = $('#account_phone');
    const verifyBtn = $('#verify-phone-btn');
    const otpSection = $('#otp-verification');
    const otpInput = $('#otp-input');
    const submitOtpBtn = $('#submit-otp-btn');
    const resendOtpBtn = $('#resend-otp');
    const verifiedPhoneInput = $('#verified-phone');
    let currentPhone = smsir.currentPhone;

    phoneInput.on('input', function() {
        const newPhone = $(this).val();
        if (newPhone !== currentPhone && isValidPhone(newPhone)) {
            verifyBtn.show();
            verifiedPhoneInput.val('');
        } else {
            verifyBtn.hide();
            otpSection.hide();
            verifiedPhoneInput.val(currentPhone);
        }
    });

    verifyBtn.on('click', async function() {
        try {
            const response = await $.post(smsir.ajaxUrl, {
                action: 'smsir_request_change_phone_otp',
                phone: phoneInput.val(),
                nonce: smsir.nonce
            });

            if (response.success) {
                otpSection.show();
                startCountdown();
            } else {
                alert(response.data.message);
            }
        } catch (err) {
            alert(err.message);
        }
    });

    submitOtpBtn.on('click', async function() {
        try {
            const response = await $.post(smsir.ajaxUrl, {
                action: 'smsir_verify_change_phone',
                phone: phoneInput.val(),
                otp: otpInput.val(),
                nonce: smsir.nonce
            });

            if (response.success) {
                verifiedPhoneInput.val(phoneInput.val());
                otpSection.hide();
                verifyBtn.hide();
                alert(smsir.translations.phoneVerified);
            } else {
                alert(response.data.message);
            }
        } catch (err) {
            alert(err.message);
        }
    });

    resendOtpBtn.on('click', async function(e) {
        e.preventDefault();
        try {
            const response = await $.post(smsir.ajaxUrl, {
                action: 'smsir_request_change_phone_otp',
                phone: phoneInput.val(),
                nonce: smsir.nonce
            });

            if (response.success) {
                startCountdown();
            } else {
                alert(response.data.message);
            }
        } catch (err) {
            alert(err.message);
        }
    });

    function isValidPhone(phone) {
        return /^\[0-9]{10}$/.test(phone);
    }

    function startCountdown() {
        let countdown = 60;
        resendOtpBtn.parent().hide();
        
        const timer = setInterval(() => {
            countdown--;
            if (countdown <= 0) {
                clearInterval(timer);
                resendOtpBtn.parent().show();
            }
        }, 1000);
    }

    verifiedPhoneInput.val(currentPhone);
}); 