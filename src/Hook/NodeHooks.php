<?php

namespace Drupal\sympa\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\sympa\SympaMailer;

/**
 * Hook implementations for sending Sympa email on node save.
 */
class NodeHooks {

  public function __construct(
    protected SympaMailer $mailer,
  ) {}

  /**
   * Implements hook_entity_presave().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $type = $entity->bundle();
    if ($type !== 'news' && $type !== 'service_alert') {
      return;
    }
    if ($entity->hasField('field_sympa_send') && (bool) $entity->get('field_sympa_send')->value) {
      $entity->set('field_sympa_send', 0);
      $entity->sympaSendPending = TRUE;
    }
  }

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if (!empty($node->sympaSendPending)) {
      $this->mailer->sendForNode($node);
    }
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    if (!empty($node->sympaSendPending)) {
      $this->mailer->sendForNode($node);
    }
  }

}
