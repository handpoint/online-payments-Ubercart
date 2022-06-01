<?php

namespace Drupal\uc_p3\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\uc_cart\Cart;
use Drupal\uc_cart\CartManagerInterface;
use Drupal\uc_order\Entity\Order;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for uc_p3.
 */
class P3Controller extends ControllerBase
{
  /**
   * The cart manager.
   *
   * @var \Drupal\uc_cart\CartManager
   */
  protected $cartManager;

  /**
   * Constructs a TwoCheckoutController.
   *
   * @param \Drupal\uc_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   */
  public function __construct(CartManagerInterface $cart_manager) {
    $this->cartManager = $cart_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // @todo: Also need to inject logger
    return new static(
      $container->get('uc_cart.manager')
    );
  }

  /**
   * @param Request $request The request of the page.
   *
   * @return Response
   */
  public function complete(Request $request) {
    \Drupal::logger('uc_p3')->notice(sprintf('Receiving new order notification for order %s.', $request->get('orderRef')));

    /** @var Order $order */
    $order = Order::load($request->get('orderRef'));

    /** @var Cart $cart */
    $cart = $this->cartManager->get($request->get('cart_id'));
    $cart->getContents();

    if (!$order || $order->getStateId() !== 'in_checkout' || $request->get('responseCode') != 0) {

      $translatableMarkup = $this->t('An error has occurred during payment. Please contact us to ensure your order has submitted.');
      $this->messenger()->addError($translatableMarkup);

      $url = \Drupal\Core\Url::fromRoute('<front>')->toString();

      return new RedirectResponse($url);
    } else {

      $plugin = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);
      if (!in_array($plugin->getPluginId() , ['paymentnetwork_hosted'])) {
        throw new AccessDeniedHttpException();
      }

      $configuration = $plugin->getConfiguration();

      $comment = $this->t('Paid, order #@order.', ['@order' => $order->id()]);
      uc_payment_enter($order->id(), $plugin->getPluginId(), $order->getTotal(), 0, NULL, $comment);

      // Add a comment to let sales team know this came in through the site.
      uc_order_comment_save($order->id(), 0, $this->t('Order created through website.'), 'admin');

      uc_order_action_update_status($order, 'completed');

      $completeSale = $this->cartManager->completeSale($order);
      $this->cartManager->emptyCart($cart->getId());

      if ($configuration['uc_p3_integration_type'] === 'hosted_v2') {
        // This lets us know it's a legitimate access of the complete page.
        $session = \Drupal::service('session');
        $session->set('uc_checkout_complete_' . $order->id(), TRUE);

        $generatedUrl = Url::fromRoute('uc_cart.checkout_complete', [], ['absolute' => TRUE])->toString();

        $html = <<<HTML
<html>
<body>
<script>window.top.location.href = "$generatedUrl"</script>
</body>
</html>
HTML;

        return new Response($html);
      }

      return $completeSale;
    }
  }
}
