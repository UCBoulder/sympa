<?php

namespace Drupal\sympa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for sympa routes.
 */
class SympaController extends ControllerBase {

  /**
   * Object used to get request data, such as the hash.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Use messenger interface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messengerInterface;

  /**
   * User account info.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $accountProxyInterface;

  /**
   * Mail manager service.
   *
   * @var Drupal\Core\Mail\MailManager
   */
  protected $mailManager;

  /**
   * {@inheritdoc}
   */
  protected function getModuleName() {
    return 'sympa';
  }

  /**
   * Constructs request stuff.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Access to the current request, including to session objects.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Implement messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   Implement user interface.
   * @param \Drupal\Core\Mail\MailManager $mail_manager
   *   Implement user interface.
   */
  public function __construct(
    RequestStack $request_stack,
    MessengerInterface $messenger,
    AccountProxyInterface $account,
    MailManager $mail_manager,
  ) {
    $this->requestStack = $request_stack;
    $this->messengerInterface = $messenger;
    $this->accountProxyInterface = $account;
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * A more complex _controller callback that takes arguments.
   *
   * This callback is mapped to the path
   * 'examples/page-example/arguments/{first}/{second}'.
   *
   * The arguments in brackets are passed to this callback from the page URL.
   * The placeholder names "first" and "second" can have any value but should
   * match the callback method variable names; i.e. $first and $second.
   *
   * This function also demonstrates a more complex render array in the returned
   * values. Instead of rendering the HTML with theme('item_list'), content is
   * left un-rendered, and the theme function name is set using #theme. This
   * content will now be rendered as late as possible, giving more parts of the
   * system a chance to change it if necessary.
   *
   * Consult @link http://drupal.org/node/930760 Render Arrays documentation
   * @endlink for details.
   *
   * @param string $first
   *   A string to use, should be a number.
   * @param string $second
   *   Another string to use, should be a number.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameters are invalid.
   */
  public function arguments($first, $second) {
    // Make sure you don't trust the URL to be safe! Always check for exploits.
    if (($first == 'subscribe' || $first == 'unsubscribe') && ($second == 'news' || $second == 'service_alert')) {
      $deets = $this->getDeets();

      $url_news = Url::fromUri('internal:/news');
      $url_sa = Url::fromUri('internal:/service-alerts');
      if ($first == 'unsubscribe') {
        $sub = $this->t('unsubscribe');
        $negate = $this->t('subscribe');
        $deets['action'] = 'DEL';
        // No name needed to remove.
        $deets['name'] = '';
      }
      if ($first == 'subscribe') {
        $sub = $this->t('subscribe');
        $negate = $this->t('unsubscribe');
        $deets['action'] = 'ADD';
      }
      $sub_types = [
        'news' => 'News',
        'news_type' => 'oit-news',
        'news_link' => Link::fromTextAndUrl($this->t('News Section'), $url_news)->toString(),
        'service_alert' => 'Service Alerts',
        'service_alert_type' => 'oit-service-alert',
        'service_alert_link' => Link::fromTextAndUrl($this->t('service alerts section'), $url_sa)->toString(),
      ];
      $deets['type'] = $sub_types[$second . '_type'];
      $content = sprintf(
        '%s %s.',
        $this->t('You have been @subd to OIT @sub_type. You can @neg at anytime using the @neg link provided in the footer of the emails you receive. Return to the', [
          '@sub' => $sub,
          '@neg' => $negate,
          '@sub_type' => $sub_types[$second],
        ]),
        $sub_types[$second . '_link']
      );
      $render_array['sympa_arguments'] = [
        '#markup' => $content,
      ];
      if ($route = $this->requestStack->getCurrentRequest()->get(RouteObjectInterface::ROUTE_OBJECT)) {
        // @todo Translate string not working for some reason. Fix later.
        $stitle = $this->t('You have been :firstd to :second', [
          ':first' => $first,
          ':second' => $second,
        ])->render();
        $route->setDefault('_title', $stitle);
      }
      $key = 'subscriptions';
      $result = $this->sympaSubscribe($key, $deets);
      if ($result['result'] !== TRUE) {
        $this->messengerInterface->addMessage($this->t("There was a problem sending your message and it was not sent."));
      }
      else {
        $this->messengerInterface->addMessage($this->t("Your message has been sent."));
      }

      return $render_array;
    }
    else {
      // We will just show a standard "access denied" page in this case.
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Return deets.
   */
  private function getDeets() {
    $user = $this->accountProxyInterface;
    $deets = [];
    $deets['email'] = $user->getEmail();
    $deets['name'] = $user->getAccountName();
    return $deets;
  }

  /**
   * Subscribe a user.
   */
  private function sympaSubscribe($key, $params) {
    $to = 'sympa@lists.colorado.edu';
    $langcode = $this->accountProxyInterface->getPreferredLangcode();
    $send = TRUE;
    $module = $this->getModuleName();
    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    return $result;
  }

}
