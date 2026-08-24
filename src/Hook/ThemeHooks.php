<?php

namespace Drupal\sympa\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for theme registration.
 */
class ThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'sympa_email' => [
        'variables' => [
          'type' => NULL,
          'title' => NULL,
          'hero_image' => NULL,
          'body' => NULL,
          'view_link' => NULL,
          'site_name' => NULL,
          'unsubscribe' => NULL,
          'learn' => NULL,
        ],
      ],
    ];
  }

}
