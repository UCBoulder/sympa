<?php

namespace Drupal\sympa;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds and sends the Sympa notification emails for news and alerts.
 */
class SympaMailer {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected MailManagerInterface $mailManager,
    protected MessengerInterface $messenger,
    protected LoggerChannelInterface $logger,
    protected AccountInterface $currentUser,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Sends a Sympa email for a node if the send flag was set.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The news or service_alert node being saved.
   */
  public function sendForNode(NodeInterface $node): void {
    if (!$node->isPublished()) {
      $this->logger->notice(
        'Sympa email skipped for unpublished node @nid.',
        ['@nid' => $node->id()]
      );
      $this->messenger->addWarning($this->t(
        'Sympa Send was checked but this node is unpublished, so no email was sent. Re-check Sympa Send when you publish.'
      ));
      return;
    }

    $type = $node->bundle();
    $img_tag = '';

    $body_value = $node->get('body')->value ?? '';
    if ($body_value !== '') {
      $body_value = Html::transformRootRelativeUrlsToAbsolute(
        $body_value,
        $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost()
      );
    }
    $sympa_nid = $node->id();

    if ($type === 'service_alert') {
      $status = $node->get('field_service_alert_status')->value ?? '';
      $sympa_title = ($status !== '' ? $status . ': ' : '') . $node->getTitle();
    }
    else {
      $sympa_title = $node->getTitle();
      $img_tag = $this->buildHeroImage($node);
    }
    $this->buildEmail($type, $sympa_nid, $sympa_title, $body_value, $img_tag);
  }

  /**
   * Builds the hero image markup for a news node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The news node.
   *
   * @return string
   *   The image tag, or an empty string when there is no usable image.
   */
  protected function buildHeroImage(NodeInterface $node): string {
    $style = ImageStyle::load('fn_large');
    if (!$style || $node->get('field_media_hero_image')->isEmpty()) {
      return '';
    }
    $media_id = $node->get('field_media_hero_image')->target_id;
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media || $media->get('field_media_image_2')->isEmpty()) {
      return '';
    }
    $image_field = $media->get('field_media_image_2');
    $file = File::load($image_field->target_id);
    if (!$file) {
      return '';
    }
    $url = $style->buildUrl($file->getFileUri());
    if (!$url) {
      return '';
    }
    $alt = $image_field->alt ?? '';
    return '<img src="' . Html::escape($url) . '" alt="' . Html::escape($alt) . '" style="max-width:100%;height:auto;" /><br /><br />';
  }

  /**
   * Build Sympa Email.
   */
  public function buildEmail(string $type, int $sympa_nid, string $sympa_title, string $body, string $img_tag): void {
    $options = ['absolute' => TRUE];
    $here = Url::fromRoute('entity.node.canonical', ['node' => $sympa_nid], $options);
    $more_info = Url::fromRoute('entity.node.canonical', ['node' => 2514], $options);
    $un_options = ['absolute' => TRUE, 'fragment' => 'unsubscribe'];
    $unsubscribe_n = Url::fromRoute('entity.node.canonical', ['node' => 31540], $un_options);
    $unsubscribe_sa = Url::fromRoute('entity.node.canonical', ['node' => 31539], $un_options);
    $config = $this->configFactory->get('system.site');
    $body_xss = $body !== '' ? Xss::filterAdmin($body) : NULL;
    $body_stripped = $body_xss ? preg_replace("/<img[^>]+\>/i", " ", $body_xss) : '';
    $build = [
      '#theme' => 'sympa_email',
      '#type' => $type,
      '#title' => $type === 'service_alert' ? $sympa_title : NULL,
      '#hero_image' => Markup::create($img_tag),
      '#body' => Markup::create($body_stripped),
      '#view_link' => Link::fromTextAndUrl(
        $type === 'service_alert'
          ? $this->t('View full service alert here')
          : $this->t('View news story here'),
        $here
      )->toString(),
      '#site_name' => $config->get('name'),
      '#unsubscribe' => Link::fromTextAndUrl(
        $this->t('Unsubscribe now'),
        $type === 'service_alert' ? $unsubscribe_sa : $unsubscribe_n
      )->toString(),
      '#learn' => Link::fromTextAndUrl($this->t('Learn more about these alerts'), $more_info)->toString(),
    ];
    $body_rendered = $this->renderer->renderInIsolation($build);
    if ($this->configFactory->get('sympa.settings')->get('sympa_email_prod')) {
      $to_email = $type === 'service_alert'
        ? 'oit-service-alert@colorado.edu'
        : 'oit-news@colorado.edu';
    }
    else {
      $to_email = 'oit-web-test@colorado.edu';
    }
    $params = [
      'to' => $to_email,
      'body' => $body_rendered,
      'title' => $sympa_title,
    ];
    $this->send('node-update', $params);
    $this->logger->notice('Sympa email sent to: ' . $to_email . ' regarding node: ' . $sympa_nid);
  }

  /**
   * Send email.
   */
  public function send(string $key, array $params): array {
    $to = $params['to'];
    // Symfony Mailer Lite reads these params ahead of the Content-Type header,
    // so the HTML body and plain-text alternative survive regardless of the
    // symfony_mailer_lite.message defaults.
    $params['content_type'] = 'text/html';
    $params['generate_plain'] = TRUE;
    $langcode = $this->currentUser->getPreferredLangcode();
    return $this->mailManager->mail('sympa', $key, $to, $langcode, $params, NULL, TRUE);
  }

}
