import{r as t}from"./runtime-dom.esm-bundler.js";const i={template:`
        <div id="smsir-checkout-login-container" v-if="!isLoggedIn">
            <div class="woocommerce-form-login-toggle">
                <div class="woocommerce-info">
                    {{ loginMessage }}
                </div>
            </div>
            <div class="smsir-checkout-login" v-html="loginFormHtml"></div>
        </div>
    `,data(){var o,e,n;return{isLoggedIn:((o=window.sms_ir_login)==null?void 0:o.isLoggedIn)||!1,loginMessage:((n=(e=window.sms_ir_login)==null?void 0:e.translations)==null?void 0:n.pleaseLogin)||"Please log in to proceed with your purchase.",loginFormHtml:""}},mounted(){this.hideCheckoutContent(),this.fetchLoginForm(),this.setupEventListeners()},methods:{hideCheckoutContent(){const o=document.querySelector(".wc-block-checkout__main");o&&(o.style.display="none")},async fetchLoginForm(){try{const e=await(await fetch(window.sms_ir_login.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"smsir_get_login_form",nonce:window.sms_ir_login.nonce})})).json();e.success&&(this.loginFormHtml=e.data.html)}catch(o){console.error("Failed to fetch login form:",o)}},setupEventListeners(){document.addEventListener("smsir_login_success",()=>{window.location.reload()})}},beforeUnmount(){document.removeEventListener("smsir_login_success",()=>{})}};document.addEventListener("DOMContentLoaded",()=>{const o=document.querySelector(".wp-block-woocommerce-checkout");if(!o)return;const e=document.createElement("div");e.id="smsir-checkout-login-mount",o.insertBefore(e,o.firstChild),t(i).mount("#smsir-checkout-login-mount")});
