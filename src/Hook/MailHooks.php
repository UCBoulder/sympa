<?php

namespace Drupal\sympa\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for mail composition.
 */
class MailHooks {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    switch ($key) {
      case 'node-update':
        $message['from'] = $this->configFactory->get('system.site')->get('mail');
        $message['headers'] = [
          'From' => $message['from'],
          'Reply-To' => 'no-reply@colorado.edu',
          'Content-Type' => 'text/html; charset=UTF-8',
        ] + $message['headers'];
        $message['subject'] = $params['title'];
        $message['body'][] = $params['body'];
        break;
    }
  }

}
