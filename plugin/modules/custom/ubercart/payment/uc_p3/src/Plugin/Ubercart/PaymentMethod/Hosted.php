<?php

namespace Drupal\uc_p3\Plugin\Ubercart\PaymentMethod;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\Annotation\UbercartPaymentMethod;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;

/**
 * Defines the 2Checkout payment method.
 *
 * @UbercartPaymentMethod(
 *   id = "paymentnetwork_hosted",
 *   name = @Translation("Payment Network Hosted Integration"),
 *   redirect = "\Drupal\uc_p3\Form\DirectForm",
 * )
 */
class Hosted extends PaymentMethodPluginBase implements OffsitePaymentMethodPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getDisplayLabel($label) {
    $build['#attached']['library'][] = 'uc_p3/payment_network.styles';

    $build['label'] = array(
      '#plain_text' => $label,
    );

    $build['image'] = array(
      '#theme' => 'image',
      '#uri' => drupal_get_path('module', 'uc_p3') . '/images/logo.png',
      '#alt' => $this->t('Payment Network'),
      '#attributes' => array('class' => array('uc-p3-logo')),
    );

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'uc_p3_merchant_id' => '100856',
      'uc_p3_secret' => 'Circle4Take40Idea',
      'uc_p3_integration_type' => 'hosted',
      'uc_p3_country_code' => '',
      'debug' => false,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['uc_p3_merchant_id']   = array(
      '#type'          => 'textfield',
      '#title'         => $this->t( 'Merchant ID' ),
      '#description'   => $this->t( 'Your Merchant ID' ),
      '#default_value' => $this->configuration[ 'uc_p3_merchant_id'],
      '#size'          => 16,
    );
    $form['uc_p3_secret']       = array(
      '#type'          => 'textfield',
      '#title'         => $this->t( 'Pre Shared Key' ),
      '#description'   => $this->t( 'Your Pre Shared Key for signatures' ),
      '#default_value' => $this->configuration[ 'uc_p3_secret'],
      '#size'          => 16,
    );

    $form['uc_p3_integration_type'] = array(
      '#type' => 'select',
      '#title' => $this->t('Select Integration Type:'),
      '#default_value' => $this->configuration[ 'uc_p3_integration_type'],
      '#options' => array(
        'hosted' => $this->t('Hosted'),
        'hosted_v2' => $this->t('Hosted (Embedded)'),
        'hosted_v3' => $this->t('Hosted (Modal)'),
      ),
    );

    $form['uc_p3_country_code'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t( 'Country Code' ),
      '#description'   => $this->t( 'Your Country Code' ),
      '#default_value' => $this->configuration['uc_p3_country_code'],
      '#size'          => 16,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['uc_p3_merchant_id'] = $form_state->getValue('uc_p3_merchant_id');
    $this->configuration['uc_p3_secret'] = $form_state->getValue('uc_p3_secret');
    $this->configuration['uc_p3_integration_type'] = $form_state->getValue('uc_p3_integration_type');
    $this->configuration['uc_p3_currency_code'] = $form_state->getValue('uc_p3_currency_code');
    $this->configuration['uc_p3_country_code'] = $form_state->getValue('uc_p3_country_code');
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, OrderInterface $order = NULL) {
    $parameters = $this->captureOrder($order);

    $form['#action'] = "https://gateway.cardstream.com/hosted/";
    $cardId = \Drupal::service('uc_cart.manager')->get()->getId();
    $generatedUrl = Url::fromRoute('uc_p3.complete', ['cart_id' => $cardId], ['absolute' => TRUE])->toString();

    $parameters = array_merge($parameters, [
      'redirectURL'    => $generatedUrl,
      'callbackURL'    => $generatedUrl,
      'threeDSVersion' => 2,
      'submit'         => 'Submit order',
    ]);

    $parameters['signature'] = $this->createSignature($parameters);

    if ('hosted_v3' == $this->configuration['uc_p3_integration_type']) {
      $form['#action'] = "https://gateway.cardstream.com/hosted/modal/";
    }

    unset($form['submit']);

    $form['#token'] = FALSE;
    foreach ($parameters as $name => $value) {
      $form[$name] = array('#type' => 'hidden', '#value' => $value);
    }

    global $p3IntegrationType;
    $p3IntegrationType = $this->configuration['uc_p3_integration_type'];

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#name' => 'submit',
      '#value' => $this->t('Submit order'),
    );

    return $form;
  }

  protected function captureOrder(OrderInterface $order) {
    $address = $order->getAddress('billing');
    if ($address->country) {
      $country = \Drupal::service('country_manager')->getCountry($address->country)->getAlpha3();
    }
    else {
      $country = $this->configuration['uc_p3_country_code'];
    }

    $billingAddress  = $address->street1;

    if (!empty($address->street2)) {
      $billingAddress .= "\n" . $address->street2;
    }

    $billingAddress .= "\n" . $address->city;
    if (!empty($address->zone)) {
      $billingAddress .= "\n" . $address->zone;
    }
    if (!empty($address->country)) {
      $billingAddress .= "\n" . $address->country;
    }

    $params = [
      'action'            => 'SALE',
      'merchantID'        => $this->configuration['uc_p3_merchant_id'],
      'amount'            => (int) round($order->getTotal(),2)  * 100,
      'countryCode'       => $country,
      'currencyCode'      => $order->getCurrency(),
      'transactionUnique' => $order->id() . '-' . time(),
      'orderRef'          => $order->id(),
      'customerName'      => mb_substr($address->first_name . ' ' . $address->last_name, 0, 128),
      'customerAddress'   => $billingAddress,
      'customerEmail'     => $order->getEmail(),
    ];

    if (!empty($address->phone)) {
      $params['customerPhone'] = $address->getPhone();
    }

    $params['customerPostcode'] = $address->getPostalCode();

    return $params;
  }

  /**
   * Sign requests with a SHA512 hash
   * @param array $data Request data
   *
   * @return string|null
   */
  protected function createSignature(array $data) {
    $key = $this->configuration['uc_p3_secret'];

    if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
      return null;
    }

    ksort($data);

    // Create the URL encoded signature string
    $ret = http_build_query($data, '', '&');

    // Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
    $ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);
    // Hash the signature string and the key together
    return hash('SHA512', $ret . $key);
  }
}
