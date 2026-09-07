<?php

declare(strict_types=1);

namespace Drupal\ai_example_tools\Plugin\AiAssistantAction;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets the AI Assistant look up the site's most recently published content.
 *
 * This is the plugin type ai_assistant_api's own function-calling toggle
 * (use_function_calling) actually consumes — distinct from the generic
 * AiFunctionCall plugin type used by ai_agents/ai_search.
 */
#[AiAssistantAction(
  id: 'ai_example_tools_list_recent_content',
  label: new TranslatableMarkup('List Recent Content'),
)]
class ListRecentContent extends AiAssistantActionBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function listActions(): array {
    return [
      'list_recent_content' => [
        'id' => 'list_recent_content',
        'label' => 'List Recent Content',
        'description' => 'Lists the most recently published content on this site, optionally filtered by content type.',
        'plugin' => 'ai_example_tools_list_recent_content',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function provideFewShotLearningExample(): array {
    return [
      [
        'description' => 'The visitor asks what content exists on the site, or what was published recently.',
        'schema' => [
          'actions' => [
            [
              'plugin' => 'ai_example_tools_list_recent_content',
              'action' => 'list_recent_content',
              'limit' => 5,
              'content_type' => '',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctionCallSchema(): array {
    return [
      'limit' => [
        'type' => 'integer',
        'description' => 'The maximum number of items to return. Defaults to 5.',
      ],
      'content_type' => [
        'type' => 'string',
        'description' => 'Optional content type machine name to filter by (e.g. "article" or "page"). Leave empty for all types.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function triggerAction(string $action_id, array $parameters = []): void {
    $limit = (int) ($parameters['limit'] ?? 5);
    $limit = max(1, min($limit, 20));
    $content_type = $parameters['content_type'] ?? '';

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit);
    if ($content_type) {
      $query->condition('type', $content_type);
    }
    $nids = $query->execute();

    if (!$nids) {
      $this->setOutputContext('list_recent_content', 'No published content was found.');
      return;
    }

    $lines = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $lines[] = sprintf(
        '- "%s" (%s), published %s — %s',
        $node->label(),
        $node->bundle(),
        $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'Y-m-d'),
        $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      );
    }

    $this->setOutputContext('list_recent_content', "Most recently published content:\n" . implode("\n", $lines));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

}
