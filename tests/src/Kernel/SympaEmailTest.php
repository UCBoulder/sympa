<?php

namespace Drupal\Tests\sympa\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests Sympa's send-on-save behavior for news and service_alert nodes.
 *
 * Covers the three highest-value regressions from the sympa review spec:
 * a single send with the flag reset persisted (Finding 1), no re-send on a
 * subsequent save with the flag already clear (Finding 1), and no crash on
 * an empty body (Finding 2).
 *
 * @group sympa
 */
class SympaEmailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'file',
    'image',
    'media',
    'node',
    'sympa',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['field', 'node']);

    // Route mail through the test mail collector instead of a real
    // transport so sent messages land in state and can be asserted on.
    $this->config('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // sympa_build_email() reads system.site for the site name; give it a
    // value so renderInIsolation() has something to work with.
    $this->config('system.site')
      ->set('name', 'Test Site')
      ->save();

    $this->createContentTypesAndFields();
  }

  /**
   * Programmatically builds the news/service_alert bundles and fields.
   *
   * The site's real bundle/field config lives in config/sync and is far
   * larger than a Kernel test needs (menu_ui, scheduler, pathauto, domain
   * access, etc.), so rather than importing it wholesale this builds only
   * the bundles and fields sympa.module actually reads: body,
   * field_sympa_send (shipped by the module's own config/install),
   * field_service_alert_status (service_alert), and field_media_hero_image
   * (news).
   */
  protected function createContentTypesAndFields(): void {
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    NodeType::create(['type' => 'service_alert', 'name' => 'Service Alert'])->save();

    // body: text_with_summary, on both bundles.
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    foreach (['news', 'service_alert'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Body',
      ])->save();
    }

    // field_sympa_send: boolean, shipped by the sympa module itself.
    FieldStorageConfig::create([
      'field_name' => 'field_sympa_send',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    foreach (['news', 'service_alert'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_sympa_send',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Sympa Send',
      ])->save();
    }

    // field_service_alert_status: list_string, service_alert only.
    FieldStorageConfig::create([
      'field_name' => 'field_service_alert_status',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'Service Issue Reported' => 'Service Issue Reported',
          'Service Restored' => 'Service Restored',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_service_alert_status',
      'entity_type' => 'node',
      'bundle' => 'service_alert',
      'label' => 'Status',
    ])->save();

    // field_media_hero_image: entity reference to media, news only.
    // sympa_node_email_send() unconditionally calls ->isEmpty() on this
    // field for news nodes, so it must exist on the bundle even though the
    // tests below never populate it.
    FieldStorageConfig::create([
      'field_name' => 'field_media_hero_image',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media_hero_image',
      'entity_type' => 'node',
      'bundle' => 'news',
      'label' => 'Media Hero Image',
    ])->save();
  }

  /**
   * Returns the messages captured by the test mail collector.
   *
   * @return array
   *   The captured message array, keyed numerically.
   */
  protected function getCapturedMails(): array {
    return \Drupal::state()->get('system.test_mail_collector', []);
  }

  /**
   * Single send on save, and the flag-reset is persisted (Finding 1).
   *
   * Also covers the "no re-send" regression: saving the same node again
   * without re-checking the box must not send a second email.
   */
  public function testSingleSendAndFlagResetPersists(): void {
    $node = Node::create([
      'type' => 'news',
      'title' => 'Test News Story',
      'status' => 1,
      'body' => [
        'value' => '<p>Hello subscribers.</p>',
        'format' => NULL,
      ],
      'field_sympa_send' => 1,
    ]);
    $node->save();

    $mails = $this->getCapturedMails();
    $this->assertCount(1, $mails, 'Exactly one email is sent for a single save with the flag checked.');
    $this->assertSame('sympa_node-update', $mails[0]['id']);

    // Reload from storage (not just the in-memory entity) to confirm the
    // flag reset in presave was actually persisted to the database.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    /** @var \Drupal\node\NodeInterface $reloaded */
    $reloaded = $storage->load($node->id());
    $this->assertEquals(0, $reloaded->get('field_sympa_send')->value, 'field_sympa_send is persisted as 0 after save.');

    // No re-send: save the same node again without touching the checkbox.
    \Drupal::state()->set('system.test_mail_collector', []);
    $reloaded->save();
    $this->assertCount(0, $this->getCapturedMails(), 'Saving again without re-checking the box sends no new email.');
  }

  /**
   * Empty body does not crash and the send still completes (Finding 2).
   */
  public function testEmptyBodyDoesNotCrash(): void {
    $node = Node::create([
      'type' => 'service_alert',
      'title' => 'Test Service Alert',
      'status' => 1,
      'field_service_alert_status' => 'Service Issue Reported',
      'body' => [],
      'field_sympa_send' => 1,
    ]);
    $node->save();

    $mails = $this->getCapturedMails();
    $this->assertCount(1, $mails, 'A node with an empty body still sends exactly one email.');
    $this->assertSame('sympa_node-update', $mails[0]['id']);
  }

}
