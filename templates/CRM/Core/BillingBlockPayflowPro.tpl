{crmScope extensionKey='payflowpro'}
  {if isset($form.payment_token)}
  <div class="crm-section {$form.payment_token.name}-section">
    <div class="label">
        {$form.payment_token.label}
    </div>
    <div class="content">
        {$form.payment_token.html}
    </div>
    <div class="clear"></div>
  </div>
  {/if}
  {if $form.save_payment_token}
  <div class="crm-section {$form.save_payment_token.name}-section">
    <div class="label">
        {$form.save_payment_token.html} {$form.save_payment_token.label}
    </div>
    <div class="content">

    </div>
    <div class="clear"></div>
  </div>
  {/if}

{literal}
  <script>

    (function() {
      // Re-prep form when we've loaded a new payproc via ajax or via webform
      document.addEventListener('ajaxComplete', (event, xhr, settings) => {
        if (CRM.payment.isAJAXPaymentForm(settings.url)) {
          CRM.payment.debugging('payflowpro', 'triggered via ajax');
          savedCardSelector();
        }
      });

      // Run immediately if DOM is ready, otherwise wait
      if (document.readyState !== 'loading') {
        savedCardSelector();
        updateCardFields();
      } else {
        document.addEventListener('DOMContentLoaded', (event) => {
          console.log('DOMContentLoaded from payflowpro');
          savedCardSelector();
          updateCardFields();
        });
      }

      function savedCardSelector() {
        const paymentTokenElement = document.querySelector('select#payment_token');
        if (paymentTokenElement) {
          paymentTokenElement.addEventListener('change', (event) => {
            updateCardFields();
          });
        }
      }

      function updateCardFields() {
        const paymentTokenElement = document.querySelector('select#payment_token');
        if (!paymentTokenElement || paymentTokenElement.value == 0) {
          document.querySelector('input#credit_card_number').classList.add('required');
          document.querySelector('input#cvv2').classList.add('required');
          document.querySelector('select#credit_card_exp_date_m').classList.add('required');
          document.querySelector('select#credit_card_exp_date_Y').classList.add('required');
          document.querySelector('div.credit_card_info-section').hidden = false;
          document.querySelector('div.save_payment_token-section').hidden = false;
        }
        else {
          document.querySelector('div.credit_card_info-section').hidden = true;
          document.querySelector('div.save_payment_token-section').hidden = true;
          document.querySelector('input#credit_card_number').classList.remove('required');
          document.querySelector('input#cvv2').classList.remove('required');
          document.querySelector('select#credit_card_exp_date_m').classList.remove('required');
          document.querySelector('select#credit_card_exp_date_Y').classList.remove('required');
        }
      }
    })();
  </script>
{/literal}

{/crmScope}