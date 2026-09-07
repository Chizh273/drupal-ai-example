<?php

declare(strict_types=1);

namespace Drupal\ai_example_tools\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a content item with a title and a taxonomy tag.
 *
 * This is the generic AiFunctionCall plugin type — consumed by ai_agents
 * and ai_search, distinct from AiAssistantAction (see ListRecentContent).
 * The taxonomy field is discovered dynamically from the target bundle, so
 * this works against any content type that has an entity-reference-to-
 * taxonomy_term field, not just Article.
 */
#[FunctionCall(
  id: 'ai_example_tools:create_tagged_content',
  function_name: 'ai_example_tools_create_tagged_content',
  name: 'Create Tagged Content',
  description: 'Creates a new content item with a title and a taxonomy tag. If the tag does not already exist in the bundle\'s taxonomy vocabulary, it is created. Defaults to the "article" content type.',
  group: 'content_tools',
  context_definitions: [
    'title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('The title of the content item.'),
      required: TRUE,
    ),
    'tag' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Tag'),
      description: new TranslatableMarkup('The name of the taxonomy term to attach. Created automatically if it does not already exist.'),
      required: TRUE,
    ),
    'content_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content type'),
      description: new TranslatableMarkup('The machine name of the content type to create, e.g. "article". Defaults to "article".'),
      required: FALSE,
    ),
    'body' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Body'),
      description: new TranslatableMarkup('Optional body text for the content item.'),
      required: FALSE,
    ),
  ],
)]
class CreateTaggedContent extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * Finds the first entity-reference-to-taxonomy_term field on a bundle.
   *
   * @return array{0: string, 1: string}
   *   A tuple of [field_name, target_vocabulary_id].
   */
  protected function findTaxonomyField(string $bundle): array {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getType() !== 'entity_reference') {
        continue;
      }
      if ($field_definition->getSetting('target_type') !== 'taxonomy_term') {
        continue;
      }
      $handler_settings = $field_definition->getSetting('handler_settings') ?? [];
      $target_bundles = $handler_settings['target_bundles'] ?? [];
      $vocabulary = $target_bundles ? array_key_first($target_bundles) : NULL;
      if ($vocabulary) {
        return [$field_name, $vocabulary];
      }
    }
    throw new \Exception("The \"$bundle\" content type has no taxonomy-reference field, so it can't be tagged.");
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL) {
    $bundle = $this->getContextValue('content_type') ?: 'article';
    $title = $this->getContextValue('title');
    $tag_name = $this->getContextValue('tag');
    $body = $this->getContextValue('body');

    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new \Exception("The current user does not have permission to create \"$bundle\" content.");
    }

    $node_type_storage = $this->entityTypeManager->getStorage('node_type');
    if (!$node_type_storage->load($bundle)) {
      throw new \Exception("The content type \"$bundle\" does not exist.");
    }

    [$field_name, $vocabulary] = $this->findTaxonomyField($bundle);

    // Find or create the taxonomy term.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $term_storage->loadByProperties([
      'name' => $tag_name,
      'vid' => $vocabulary,
    ]);
    $term = $existing ? reset($existing) : NULL;
    if (!$term) {
      $term = $term_storage->create([
        'vid' => $vocabulary,
        'name' => $tag_name,
      ]);
      $term->save();
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $values = [
      'type' => $bundle,
      'title' => $title,
      'status' => 1,
      'uid' => $this->currentUser->id(),
      $field_name => [$term->id()],
    ];
    if ($body && $this->entityFieldManager->getFieldDefinitions('node', $bundle)['body'] ?? NULL) {
      $values['body'] = ['value' => $body, 'format' => 'basic_html'];
    }
    $node = $node_storage->create($values);

    if (!$node->save()) {
      throw new \Exception("The content item \"$title\" could not be created.");
    }

    $this->setOutput(sprintf(
      'Created "%s" (%s), tagged "%s". %s',
      $title,
      $bundle,
      $tag_name,
      $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ));
    $this->setStructuredOutput([
      'nid' => $node->id(),
      'title' => $title,
      'bundle' => $bundle,
      'tag' => $tag_name,
      'tid' => $term->id(),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ]);
  }

}
